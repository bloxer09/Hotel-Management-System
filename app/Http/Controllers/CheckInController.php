<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\GuestProfile;
use App\Models\Booking;
use App\Models\Transaction;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Carbon\Carbon;
use DB;

class CheckInController extends Controller
{
    public function index(Request $request)
    {
        // Wizard data (for the Check-In modal)
        $rooms = Room::with('type')
            ->where('status', 'vacant')
            ->orderBy('room_number', 'asc')
            ->get();

        $roomTypes = RoomType::all();

        $prefilledGuest = null;
        if ($request->has('guest_id')) {
            $prefilledGuest = GuestProfile::find($request->input('guest_id'));
        }

        $promoCodes = \App\Models\PromoCode::where('is_active', true)
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->where(function($query) {
                $query->whereNull('max_uses')
                      ->orWhereColumn('used_count', '<', 'max_uses');
            })
            ->orderBy('code', 'asc')
            ->get(['code', 'discount_type', 'discount_value']);

        // Stay list data (paginated)
        $status = $request->input('status', 'active');
        if ($status === 'groups') $status = 'all';
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $showGroupsOnly = $request->boolean('show_groups_only', false);

        $allowedSorts = ['id', 'guest_name', 'status', 'check_in_time', 'expected_check_out', 'amount'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $bookings = Booking::with(['room', 'room.type'])
            ->when($status && $status !== 'all', fn($q) => $q->where('status', $status))
            ->when($showGroupsOnly, fn($q) => $q->whereNotNull('group_ref'))
            ->orderBy($sortBy, $sortDir)
            ->paginate(15)
            ->withQueryString();

        $groupBookingsRaw = Booking::with(['room', 'room.type', 'guestProfile'])
            ->whereNotNull('group_ref')
            ->whereIn('status', ['active', 'reserved', 'checked_out', 'completed', 'no_show', 'cancelled'])
            ->orderBy('id', 'desc')
            ->get();

        $groupBookings = [];
        foreach ($groupBookingsRaw->groupBy('group_ref') as $groupRef => $groupItems) {
            $hasActiveOrReserved = $groupItems->contains(fn($b) => in_array($b->status, ['active', 'reserved']));
            if ($hasActiveOrReserved) {
                $groupBookings[$groupRef] = $groupItems;
            }
        }

        return Inertia::render('CheckIn/Index', [
            'vacantRooms'   => $rooms,
            'roomTypes'     => $roomTypes,
            'prefilledGuest'=> $prefilledGuest,
            'promoCodes'    => $promoCodes,
            'bookings'      => $bookings,
            'groupBookings' => (object)$groupBookings,
            'currentFilter' => $status,
            'showGroupsOnly'=> $showGroupsOnly,
            'sortBy'        => $sortBy,
            'sortDir'       => $sortDir,
        ]);
    }

    public function calculate(Request $request)
    {
        $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'exists:rooms,id',
            'extra_pax' => 'nullable|array',
            'check_in' => 'nullable|date',
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
            'discount_type' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'promo_code' => 'nullable|string',
        ]);

        $user = $request->user();
        $discountType = $request->discount_type;
        if (in_array($discountType, ['promo', 'staff', 'complimentary']) && $user->role !== 'admin' && !$request->filled('promo_code')) {
            return response()->json(['error' => 'Only administrators can apply promo, staff, or complimentary discounts.'], 403);
        }

        $checkIn = $request->filled('check_in')
            ? Carbon::parse($request->check_in)->format('Y-m-d H:i:s')
            : now()->format('Y-m-d H:i:s');
        
        $rooms = Room::whereIn('id', $request->room_ids)->get();
        $numRooms = count($rooms);
        
        $reqDiscountType = $request->discount_type ?: '';
        $reqDiscountAmountTotal = (float)($request->discount_amount ?: 0);

        if ($request->filled('promo_code')) {
            $promo = \App\Models\PromoCode::where('code', $request->promo_code)->first();
            if ($promo && $promo->isValid()) {
                $reqDiscountType = 'promo';
                $combinedSubtotal = 0;
                foreach($rooms as $room) {
                    $t = BookingService::calculateBookingAmounts($room, $request->booking_type, $checkIn, $request->num_nights ?: 1, $request->short_time_hours ?: 3, '', 0);
                    $combinedSubtotal += $t['base_amount'] + $t['peak_surcharge'];
                }
                
                if ($promo->discount_type === 'percent') {
                    $reqDiscountAmountTotal = round($combinedSubtotal * ($promo->discount_value / 100), 2);
                } else {
                    $reqDiscountAmountTotal = min($combinedSubtotal, (float)$promo->discount_value);
                }
            } else {
                return response()->json(['error' => 'The promo code is invalid or expired.'], 422);
            }
        }
        
        $discountPerRoom = in_array($reqDiscountType, ['promo', 'staff']) && $numRooms > 0 
            ? round($reqDiscountAmountTotal / $numRooms, 2) 
            : 0;

        $totals = [
            'base_amount' => 0,
            'peak_surcharge' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'expected_check_out' => null,
            'is_peak' => false,
        ];

        $room_breakdown = [];

        foreach ($rooms as $room) {
            $extraPax = $request->extra_pax[$room->id] ?? 0;
            $numGuestsPerRoom = max(1, (int)$room->type->max_occupancy) + (int)$extraPax;

            $amounts = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkIn,
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $reqDiscountType,
                $discountPerRoom,
                $numGuestsPerRoom
            );
            $totals['extra_pax_charges'] = ($totals['extra_pax_charges'] ?? 0) + ($amounts['extra_pax_charges'] ?? 0);

            $totals['base_amount'] += $amounts['base_amount'];
            $totals['peak_surcharge'] += $amounts['peak_surcharge'];
            $totals['discount_amount'] += $amounts['discount_amount'];
            $totals['total_amount'] += $amounts['total_amount'];
            $totals['expected_check_out'] = $amounts['expected_check_out'];
            if ($amounts['is_peak']) $totals['is_peak'] = true;
            
            $room_breakdown[$room->id] = $amounts;
        }

        if ($request->filled('promo_code')) {
            $totals['promo_code'] = $request->promo_code;
        }

        return response()->json([
            'totals' => $totals,
            'room_breakdown' => $room_breakdown,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'exists:rooms,id',
            'guest_name' => 'required|string|max:100',
            'guest_contact' => 'nullable|string|max:20',
            'guest_id_type' => 'nullable|string|max:50',
            'guest_id_number' => 'nullable|string|max:50',
            'id_image' => 'nullable|image|max:5120',
            'guest_email' => 'nullable|email|max:100',
            'guest_address' => 'nullable|string',
            'extra_pax' => 'nullable|array',
            'extra_pax.*' => 'integer|min:0',
            'check_in' => 'nullable|date',
            
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
            
            'discount_type' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'promo_code' => 'nullable|string',
            
            'payment_method' => 'required|in:cash,gcash,card,bank_transfer,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        
        $discountType = $request->discount_type;
        if (in_array($discountType, ['promo', 'staff', 'complimentary']) && $user->role !== 'admin' && !$request->filled('promo_code')) {
            return back()->withErrors(['discount_type' => 'Only administrators can apply promo, staff, or complimentary discounts.']);
        }
        
        if (!\App\Services\ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to process a check-in.');
        }

        $idImagePath = null;
        if ($request->hasFile('id_image')) {
            $idImagePath = $request->file('id_image')->store('id_images', 'public');
        }

        try {
            return DB::transaction(function () use ($request, $user, $idImagePath) {
                $rooms = Room::with('type')->whereIn('id', $request->room_ids)->get();
                $numRooms = count($rooms);
                
                $roomGuests = [];
                foreach ($rooms as $room) {
                    if ($room->status !== 'vacant') {
                        throw new \Exception("Room {$room->room_number} is not vacant.");
                    }
                    $extraPax = $request->extra_pax[$room->id] ?? 0;
                    $roomGuests[$room->id] = max(1, (int)$room->type->max_occupancy) + (int)$extraPax;
                }

                $checkInTime = $request->filled('check_in') ? Carbon::parse($request->check_in) : now();
                
                $reqDiscountType = $request->discount_type ?: '';
                $reqDiscountAmountTotal = (float)($request->discount_amount ?: 0);
                $promoCodeModel = null;

                if ($request->filled('promo_code')) {
                    $promoCodeModel = \App\Models\PromoCode::where('code', $request->promo_code)->lockForUpdate()->first();
                    if ($promoCodeModel && $promoCodeModel->isValid()) {
                        $reqDiscountType = 'promo';
                        $combinedSubtotal = 0;
                        foreach($rooms as $room) {
                            $t = BookingService::calculateBookingAmounts($room, $request->booking_type, $checkInTime->format('Y-m-d H:i:s'), $request->num_nights ?: 1, $request->short_time_hours ?: 3, '', 0, $roomGuests[$room->id]);
                            $combinedSubtotal += $t['base_amount'] + $t['peak_surcharge'];
                        }
                        
                        if ($promoCodeModel->discount_type === 'percent') {
                            $reqDiscountAmountTotal = round($combinedSubtotal * ($promoCodeModel->discount_value / 100), 2);
                        } else {
                            $reqDiscountAmountTotal = min($combinedSubtotal, (float)$promoCodeModel->discount_value);
                        }
                    } else {
                        throw new \Exception("The promo code is invalid or expired.");
                    }
                }

                $discountPerRoom = in_array($reqDiscountType, ['promo', 'staff']) && $numRooms > 0 
                    ? round($reqDiscountAmountTotal / $numRooms, 2) 
                    : 0;

                $totalCombinedAmount = 0;
                $roomPricings = [];

                foreach ($rooms as $room) {
                    $pricing = BookingService::calculateBookingAmounts(
                        $room,
                        $request->booking_type,
                        $checkInTime->format('Y-m-d H:i:s'),
                        $request->num_nights ?: 1,
                        $request->short_time_hours ?: 3,
                        $reqDiscountType,
                        $discountPerRoom,
                        $roomGuests[$room->id]
                    );
                    
                    $totalCombinedAmount += $pricing['total_amount'];
                    $roomPricings[$room->id] = $pricing;
                }

                // Handle payment verification
                $paymentMethod = $request->payment_method;
                $cashAmountTotal = 0.00;
                $gcashAmountTotal = 0.00;
                $bankAmountTotal = 0.00;
                $gcashRef = $request->gcash_ref ?: null;
                $bankRef = $request->reference_number ?: null;

                if ($paymentMethod === 'cash') {
                    $cashAmountTotal = $totalCombinedAmount;
                } elseif ($paymentMethod === 'gcash') {
                    $gcashAmountTotal = $totalCombinedAmount;
                } elseif ($paymentMethod === 'card' || $paymentMethod === 'bank_transfer') {
                    $bankAmountTotal = $totalCombinedAmount;
                } else { // split
                    $cashAmountTotal = (float)($request->cash_amount ?: 0);
                    $gcashAmountTotal = (float)($request->gcash_amount ?: 0);
                    if (abs(($cashAmountTotal + $gcashAmountTotal) - $totalCombinedAmount) > 0.01) {
                        throw new \Exception("Split amounts ($cashAmountTotal + $gcashAmountTotal) must equal the total amount ($totalCombinedAmount).");
                    }
                }

                // Find or Create guest profile
                $guestProfile = GuestProfile::firstOrCreate(
                    ['full_name' => trim($request->guest_name)],
                    [
                        'contact_number' => $request->guest_contact,
                        'id_type' => $request->guest_id_type,
                        'id_number' => $request->guest_id_number,
                        'id_image_path' => $idImagePath,
                        'email' => $request->guest_email,
                        'address' => $request->guest_address,
                    ]
                );
                
                if (!$guestProfile->wasRecentlyCreated) {
                    $guestProfile->update([
                        'contact_number' => $request->guest_contact ?: $guestProfile->contact_number,
                        'id_type' => $request->guest_id_type ?: $guestProfile->id_type,
                        'id_number' => $request->guest_id_number ?: $guestProfile->id_number,
                        'id_image_path' => $idImagePath ?: $guestProfile->id_image_path,
                        'email' => $request->guest_email ?: $guestProfile->email,
                        'address' => $request->guest_address ?: $guestProfile->address,
                    ]);
                }

                $guestProfile->total_stays += 1;
                $guestProfile->total_spent += $totalCombinedAmount;
                $guestProfile->last_visit = $checkInTime->format('Y-m-d');
                $guestProfile->save();

                // Create Bookings
                $groupRef = $numRooms > 1 ? 'GRP-' . strtoupper(Str::random(4)) . $checkInTime->format('ymdHi') : null;
                $createdBookingIds = [];

                // Split the deposit evenly
                $amountPaidPerRoom = round($totalCombinedAmount / $numRooms, 2);
                $cashPerRoom = round($cashAmountTotal / $numRooms, 2);
                $gcashPerRoom = round($gcashAmountTotal / $numRooms, 2);
                $bankPerRoom = round($bankAmountTotal / $numRooms, 2);

                foreach ($rooms as $room) {
                    $pricing = $roomPricings[$room->id];
                    $bookingRef = 'BKG-' . strtoupper(Str::random(4)) . $checkInTime->format('ymdHis') . $room->id;
                    
                    $booking = Booking::create([
                        'booking_ref' => $bookingRef,
                        'group_ref' => $groupRef,
                        'room_id' => $room->id,
                        'guest_profile_id' => $guestProfile->id,
                        'guest_name' => $guestProfile->full_name,
                        'guest_contact' => $guestProfile->contact_number,
                        'guest_id_type' => $guestProfile->id_type,
                        'guest_id_number' => $guestProfile->id_number,
                        'guest_id_image_path' => $idImagePath ?: $guestProfile->id_image_path,
                        'num_guests' => $roomGuests[$room->id],
                        'booking_type' => $request->booking_type,
                        'short_time_hours' => $request->booking_type !== 'overnight' ? $request->short_time_hours : null,
                        'check_in' => $checkInTime->format('Y-m-d H:i:s'),
                        'expected_check_out' => $pricing['expected_check_out'],
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'base_amount' => $pricing['base_amount'],
                        'peak_surcharge' => $pricing['peak_surcharge'],
                        'extra_pax_charges' => $pricing['extra_pax_charges'] ?? 0,
                        'discount_type' => $reqDiscountType ?: null,
                        'discount_amount' => $pricing['discount_amount'],
                        'total_amount' => $pricing['total_amount'],
                        'amount_paid' => $pricing['total_amount'],
                        'payment_method' => $paymentMethod,
                        'cash_amount' => $cashPerRoom,
                        'gcash_amount' => $gcashPerRoom,
                        'gcash_ref' => $gcashRef ?: $bankRef,
                        'is_peak' => $pricing['is_peak'],
                        'notes' => $request->notes ? trim($request->notes . ($request->filled('promo_code') ? "\nApplied Promo Code: " . $request->promo_code : '')) : ($request->filled('promo_code') ? "Applied Promo Code: " . $request->promo_code : null),
                        'checked_in_by' => $user->id,
                    ]);

                    // Create transaction
                    Transaction::create([
                        'booking_id' => $booking->id,
                        'transaction_type' => 'check_in',
                        'description' => "Initial check-in payment for Reference: {$bookingRef}. Room {$room->room_number}" . ($request->filled('promo_code') ? " (Promo: {$request->promo_code})" : ""),
                        'amount' => $amountPaidPerRoom,
                        'payment_method' => $paymentMethod,
                        'cash_amount' => $cashPerRoom,
                        'gcash_amount' => $gcashPerRoom,
                        'bank_amount' => $bankPerRoom,
                        'gcash_ref' => $gcashRef,
                        'bank_ref' => $bankRef,
                        'processed_by' => $user->id,
                    ]);

                    // Set room status to occupied
                    $room->status = 'occupied';
                    $room->save();
                    
                    $createdBookingIds[] = $booking->id;
                }

                if ($promoCodeModel) {
                    $promoCodeModel->increment('used_count');
                }

                $roomNumbers = $rooms->pluck('room_number')->join(', ');
                $msg = $numRooms > 1 
                    ? "Group Check-in {$groupRef} successful for Rooms: {$roomNumbers}."
                    : "Guest {$guestProfile->full_name} successfully checked into Room {$roomNumbers}.";

                BookingService::auditLog(
                    $user->id,
                    'CHECK_IN',
                    'bookings',
                    $createdBookingIds[0],
                    null,
                    $groupRef ?? 'SINGLE',
                    $msg . " Collected ₱{$totalCombinedAmount} via {$paymentMethod}."
                );

                return redirect()->route('checkin.index')->with('success', $msg);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
