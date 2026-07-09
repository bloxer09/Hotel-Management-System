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

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'reserved');
        if ($status === 'groups') $status = 'all';

        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $showGroupsOnly = $request->boolean('show_groups_only', false);
        
        $allowedSorts = ['id', 'guest_name', 'status', 'check_in_time', 'expected_check_out', 'amount'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $reservations = Booking::with(['room', 'room.type'])
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

        // Wizard data for the New Booking modal
        $rooms = Room::with('type')
            ->orderBy('room_number', 'asc')
            ->get();

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

        return Inertia::render('Reservations/Index', [
            'reservations'  => $reservations,
            'groupBookings' => (object)$groupBookings,
            'currentFilter' => $status,
            'showGroupsOnly'=> $showGroupsOnly,
            'rooms'         => $rooms,
            'promoCodes'    => $promoCodes,
            'sortBy'        => $sortBy,
            'sortDir'       => $sortDir,
        ]);
    }

    public function create(Request $request)
    {
        // Load all rooms so staff can select any room for future booking
        $rooms = Room::with('type')
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

        return Inertia::render('Reservations/Create', [
            'rooms' => $rooms,
            'roomTypes' => $roomTypes,
            'prefilledGuest' => $prefilledGuest,
            'promoCodes' => $promoCodes,
        ]);
    }

    public function calculate(Request $request)
    {
        $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'exists:rooms,id',
            'extra_pax' => 'nullable|array',
            'check_in' => 'required|date',
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

        $checkInRaw = Carbon::parse($request->check_in);
        
        $rooms = Room::with('type')->whereIn('id', $request->room_ids)->get();
        $numRooms = count($rooms);
        
        $roomGuests = [];
        foreach ($rooms as $room) {
            $extraPax = $request->extra_pax[$room->id] ?? 0;
            $roomGuests[$room->id] = max(1, (int)$room->type->max_occupancy) + (int)$extraPax;
        }

        if ($request->booking_type === 'overnight') {
            $checkIn = $checkInRaw->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0)->format('Y-m-d H:i:s');
        } else {
            $checkIn = $checkInRaw->format('Y-m-d H:i:s');
        }
        
        $reqDiscountType = $request->discount_type ?: '';
        $reqDiscountAmountTotal = (float)($request->discount_amount ?: 0);
        
        if ($request->filled('promo_code')) {
            $promo = \App\Models\PromoCode::where('code', $request->promo_code)->first();
            if ($promo && $promo->isValid()) {
                $reqDiscountType = 'promo';
                $combinedSubtotal = 0;
                foreach($rooms as $room) {
                    $t = BookingService::calculateBookingAmounts($room, $request->booking_type, $checkIn, $request->num_nights ?: 1, $request->short_time_hours ?: 3, '', 0, $roomGuests[$room->id]);
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
            'conflicts' => [],
        ];
        
        $room_breakdown = [];

        foreach ($rooms as $room) {
            $amounts = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkIn,
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $reqDiscountType,
                $discountPerRoom,
                $roomGuests[$room->id]
            );
            $totals['extra_pax_charges'] = ($totals['extra_pax_charges'] ?? 0) + ($amounts['extra_pax_charges'] ?? 0);

            $totals['base_amount'] += $amounts['base_amount'];
            $totals['peak_surcharge'] += $amounts['peak_surcharge'];
            $totals['discount_amount'] += $amounts['discount_amount'];
            $totals['total_amount'] += $amounts['total_amount'];
            $totals['expected_check_out'] = $amounts['expected_check_out'];
            if ($amounts['is_peak']) $totals['is_peak'] = true;

            $expectedCheckOut = $amounts['expected_check_out'];
            $overlap = Booking::where('room_id', $room->id)
                ->whereIn('status', ['active', 'reserved'])
                ->where('check_in', '<', $expectedCheckOut)
                ->where('expected_check_out', '>', $checkIn)
                ->first();

            if ($overlap) {
                $totals['conflicts'][] = [
                    'room_number' => $room->room_number,
                    'booking_ref' => $overlap->booking_ref,
                    'status' => $overlap->status,
                    'guest_name' => $overlap->guest_name,
                    'check_in' => Carbon::parse($overlap->check_in)->format('M d, Y h:i A'),
                    'expected_check_out' => Carbon::parse($overlap->expected_check_out)->format('M d, Y h:i A'),
                ];
            }
            
            $room_breakdown[$room->id] = $amounts;
        }
        
        if (count($totals['conflicts']) > 0) {
            $totals['conflict'] = $totals['conflicts'][0]; // Fallback for single-room UI compatibility
        }

        if ($request->filled('promo_code')) {
            $totals['promo_code'] = $request->promo_code;
        }

        return response()->json([
            'totals' => $totals,
            'room_breakdown' => $room_breakdown,
        ]);
    }

    public function getAvailableRooms(Request $request)
    {
        $request->validate([
            'check_in' => 'required|date',
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
            'exclude_booking_id' => 'nullable|exists:bookings,id',
        ]);

        $checkInRaw = Carbon::parse($request->check_in);
        if ($request->booking_type === 'overnight') {
            $checkInTime = $checkInRaw->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0);
        } else {
            $checkInTime = $checkInRaw;
        }

        $dummyExpectedCheckOut = $request->booking_type === 'overnight' 
            ? $checkInTime->copy()->addDays($request->num_nights ?: 1)->setTime(BookingService::OVERNIGHT_CHECKOUT_HOUR, 0, 0)
            : $checkInTime->copy()->addHours($request->short_time_hours ?: 3);

        $checkInStr = $checkInTime->format('Y-m-d H:i:s');
        $checkOutStr = $dummyExpectedCheckOut->format('Y-m-d H:i:s');

        $query = Booking::whereIn('status', ['active', 'reserved'])
            ->where('check_in', '<', $checkOutStr)
            ->where('expected_check_out', '>', $checkInStr);

        if ($request->has('exclude_booking_id') && $request->exclude_booking_id) {
            $query->where('id', '!=', $request->exclude_booking_id);
        }

        $bookedRoomIds = $query->pluck('room_id')->toArray();

        $availableRooms = Room::with('type')
            ->whereNotIn('id', $bookedRoomIds)
            ->orderBy('room_number', 'asc')
            ->get();

        return response()->json([
            'available_rooms' => $availableRooms,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'exists:rooms,id',
            'check_in' => 'required|date',
            'guest_name' => 'required|string|max:100',
            'guest_contact' => 'nullable|string|max:20',
            'guest_id_type' => 'nullable|string|max:50',
            'guest_id_number' => 'nullable|string|max:50',
            'id_image' => 'nullable|image|max:5120',
            'guest_email' => 'nullable|email|max:100',
            'guest_address' => 'nullable|string',
            'extra_pax' => 'nullable|array',
            'extra_pax.*' => 'integer|min:0',
            
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
            
            'discount_type' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'promo_code' => 'nullable|string',
            
            'payment_ratio' => 'nullable|in:full,half',
            'payment_method' => 'required|in:cash,gcash,card,bank_transfer,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'transaction_notes' => 'nullable|string',
        ]);

        $user = $request->user();
        
        $discountType = $request->discount_type;
        if (in_array($discountType, ['promo', 'staff', 'complimentary']) && $user->role !== 'admin' && !$request->filled('promo_code')) {
            return back()->withErrors(['discount_type' => 'Only administrators can apply promo, staff, or complimentary discounts.']);
        }
        
        if (!\App\Services\ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to process a booking.');
        }

        $idImagePath = null;
        if ($request->hasFile('id_image')) {
            $idImagePath = $request->file('id_image')->store('id_images', 'public');
        }

        try {
            return DB::transaction(function () use ($request, $user, $idImagePath) {
                $rooms = Room::with('type')->whereIn('id', $request->room_ids)->lockForUpdate()->get();
                $numRooms = count($rooms);
                
                $roomGuests = [];
                foreach ($rooms as $room) {
                    $extraPax = $request->extra_pax[$room->id] ?? 0;
                    $roomGuests[$room->id] = max(1, (int)$room->type->max_occupancy) + (int)$extraPax;
                }
                $checkInRaw = Carbon::parse($request->check_in);
                if ($request->booking_type === 'overnight') {
                    $checkInTime = $checkInRaw->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0);
                } else {
                    $checkInTime = $checkInRaw;
                }
                
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

                    // Overlap safety check
                    $expectedCheckOut = $pricing['expected_check_out'];
                    $overlap = Booking::where('room_id', $room->id)
                        ->whereIn('status', ['active', 'reserved'])
                        ->where('check_in', '<', $expectedCheckOut)
                        ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                        ->first();

                    if ($overlap) {
                        throw new \Exception("Double-booking conflict: Room {$room->room_number} is already booked by {$overlap->guest_name} from {$overlap->check_in} to {$overlap->expected_check_out}.");
                    }
                }

                // Payment verification for combined amount
                $paymentRatio = $request->input('payment_ratio', 'full');
                $collectedAmountTotal = ($paymentRatio === 'half') ? round($totalCombinedAmount / 2, 2) : $totalCombinedAmount;

                $paymentMethod = $request->payment_method;
                $cashAmountTotal = 0.00;
                $gcashAmountTotal = 0.00;
                $refNum = $request->gcash_ref ?: $request->reference_number ?: null;

                if ($paymentMethod === 'cash') {
                    $cashAmountTotal = $collectedAmountTotal;
                } elseif ($paymentMethod === 'gcash') {
                    $gcashAmountTotal = $collectedAmountTotal;
                } elseif ($paymentMethod === 'split') {
                    $cashAmountTotal = (float)($request->cash_amount ?: 0);
                    $gcashAmountTotal = (float)($request->gcash_amount ?: 0);
                    if (abs(($cashAmountTotal + $gcashAmountTotal) - $collectedAmountTotal) > 0.01) {
                        throw new \Exception("Split amounts must equal the collected deposit amount ₱{$collectedAmountTotal}.");
                    }
                }

                // Guest profile
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

                $guestProfile->total_spent += $collectedAmountTotal;
                $guestProfile->save();

                // Create Bookings
                $groupRef = $numRooms > 1 ? 'GRP-' . strtoupper(Str::random(4)) . $checkInTime->format('ymdHi') : null;
                $createdBookingIds = [];

                // Split the deposit evenly
                $amountPaidPerRoom = round($collectedAmountTotal / $numRooms, 2);
                $cashPerRoom = round($cashAmountTotal / $numRooms, 2);
                $gcashPerRoom = round($gcashAmountTotal / $numRooms, 2);

                foreach ($rooms as $room) {
                    $pricing = $roomPricings[$room->id];
                    $bookingRef = 'RES-' . strtoupper(Str::random(4)) . $checkInTime->format('ymdHis') . $room->id;
                    
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
                        'status' => 'reserved',
                        'payment_status' => ($amountPaidPerRoom >= $pricing['total_amount'] * 0.99) ? 'paid' : 'partial',
                        'base_amount' => $pricing['base_amount'],
                        'peak_surcharge' => $pricing['peak_surcharge'],
                        'extra_pax_charges' => $pricing['extra_pax_charges'] ?? 0,
                        'discount_type' => $reqDiscountType ?: null,
                        'discount_amount' => $pricing['discount_amount'],
                        'total_amount' => $pricing['total_amount'],
                        'amount_paid' => $amountPaidPerRoom,
                        'payment_method' => $paymentMethod,
                        'cash_amount' => $cashPerRoom,
                        'gcash_amount' => $gcashPerRoom,
                        'gcash_ref' => $refNum,
                        'is_peak' => $pricing['is_peak'],
                        'notes' => $request->notes ? trim($request->notes . ($request->filled('promo_code') ? "\nApplied Promo: " . $request->promo_code : '') . ($paymentRatio === 'half' ? "\nPartial 50% deposit paid." : '')) : ($request->filled('promo_code') ? "Applied Promo: " . $request->promo_code : ($paymentRatio === 'half' ? "Partial 50% deposit paid." : null)),
                        'checked_in_by' => $user->id,
                    ]);

                    // Transaction log per room
                    Transaction::create([
                        'booking_id' => $booking->id,
                        'transaction_type' => 'check_in',
                        'description' => "Initial reservation payment (Ratio: {$paymentRatio}) for Ref: {$bookingRef}. Room {$room->room_number}" . ($request->filled('promo_code') ? " (Promo: {$request->promo_code})" : ""),
                        'amount' => $amountPaidPerRoom,
                        'payment_method' => $paymentMethod,
                        'cash_amount' => $cashPerRoom,
                        'gcash_amount' => $gcashPerRoom,
                        'gcash_ref' => $refNum,
                        'processed_by' => $user->id,
                        'notes' => $request->transaction_notes,
                    ]);
                    
                    $createdBookingIds[] = $booking->id;
                }

                if ($promoCodeModel) {
                    $promoCodeModel->increment('used_count');
                }

                $roomNumbers = $rooms->pluck('room_number')->join(', ');
                $msg = $numRooms > 1 
                    ? "Group Reservation {$groupRef} registered for Rooms: {$roomNumbers}."
                    : "Reservation registered for Room {$roomNumbers}.";

                BookingService::auditLog(
                    $user->id,
                    'BOOKING_RESERVATION',
                    'bookings',
                    $createdBookingIds[0],
                    null,
                    $groupRef ?? 'SINGLE',
                    $msg . " Collected ₱{$collectedAmountTotal} via {$paymentMethod}."
                );

                return redirect()->route('reservations.index')->with('success', $msg);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkin(Booking $booking, Request $request)
    {
        $user = $request->user();
        
        if (!\App\Services\ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to perform check-in.');
        }

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only reserved bookings can be checked in.');
        }

        if ($booking->amount_paid < $booking->total_amount) {
            return back()->with('error', "Cannot check in. Remaining balance of ₱" . number_format($booking->total_amount - $booking->amount_paid, 2) . " must be paid first. Edit the booking to settle full payment.");
        }

        $room = $booking->room;
        if ($room->status !== 'vacant') {
            return back()->with('error', "Cannot check in. Room {$room->room_number} is currently {$room->status}.");
        }

        return DB::transaction(function () use ($booking, $room, $user) {
            $now = now();

            // Set room to occupied
            $room->status = 'occupied';
            $room->save();

            // Set booking to active, and set the actual checkin time to now
            $booking->status = 'active';
            $booking->check_in = $now->format('Y-m-d H:i:s');
            
            // Re-calculate expected check_out from now if it starts today
            $pricing = BookingService::calculateBookingAmounts(
                $room,
                $booking->booking_type,
                $now->format('Y-m-d H:i:s'),
                $booking->booking_type === 'overnight' ? max(1, Carbon::parse($booking->check_in)->diffInDays(Carbon::parse($booking->expected_check_out))) : 1,
                $booking->short_time_hours ?: 3,
                $booking->discount_type ?: '',
                $booking->discount_amount
            );
            $booking->expected_check_out = $pricing['expected_check_out'];
            $booking->base_amount = $pricing['base_amount'];
            $booking->peak_surcharge = $pricing['peak_surcharge'];
            $booking->total_amount = $pricing['total_amount'];
            $booking->payment_status = ($booking->amount_paid >= $pricing['total_amount'] * 0.99) ? 'paid' : 'partial';
            $booking->save();

            // Update guest total stays
            if ($booking->guestProfile) {
                $booking->guestProfile->total_stays += 1;
                $booking->guestProfile->last_visit = $now->format('Y-m-d');
                $booking->guestProfile->save();
            }

            BookingService::auditLog(
                $user->id,
                'CHECK_IN_FROM_RESERVATION',
                'bookings',
                $booking->id,
                'reserved',
                'active',
                "Checked in guest {$booking->guest_name} from reservation Ref: {$booking->booking_ref} into Room {$room->room_number}."
            );

            return redirect()->route('rooms.index')->with('success', "Guest {$booking->guest_name} checked in successfully from reservation {$booking->booking_ref} into Room {$room->room_number}!");
        });
    }

    public function cancel(Booking $booking, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $user = $request->user();

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only reserved bookings can be cancelled.');
        }

        return DB::transaction(function () use ($booking, $request, $user) {
            $booking->status = 'cancelled';
            $booking->notes = trim($booking->notes . "\nReservation Cancelled. Reason: " . $request->reason);
            $booking->save();

            // Note: Room was not occupied, so no room status changes are needed.

            // Revert promo code use count if applicable
            if ($booking->discount_type === 'promo') {
                // Find matching promo code if stored in notes or description
                $promoCode = null;
                if (preg_match('/Applied Promo:\s*([A-Z0-9_-]+)/i', $booking->notes, $matches)) {
                    $promoCode = trim($matches[1]);
                }
                if ($promoCode) {
                    $promoModel = \App\Models\PromoCode::where('code', $promoCode)->first();
                    if ($promoModel) {
                        $promoModel->decrement('used_count');
                    }
                }
            }

            // Create refund/adjustment log transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Reservation cancelled. Ref: {$booking->booking_ref}. Reason: {$request->reason}",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION_CANCEL',
                'bookings',
                $booking->id,
                'reserved',
                'cancelled',
                "Cancelled future reservation {$booking->booking_ref} for Room {$booking->room->room_number}. Reason: {$request->reason}"
            );

            return redirect()->route('reservations.index')->with('success', "Reservation {$booking->booking_ref} cancelled successfully.");
        });
    }

    public function noshow(Booking $booking, Request $request)
    {
        $user = $request->user();

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only pending reservations can be marked as No Show.');
        }

        return DB::transaction(function () use ($booking, $user) {
            $booking->status = 'no_show';
            $booking->notes = trim($booking->notes . "\nReservation marked as No Show on " . now()->format('Y-m-d H:i:s'));
            $booking->save();

            // Create adjustment/log transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Reservation marked as No-Show. Ref: {$booking->booking_ref}.",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION_NOSHOW',
                'bookings',
                $booking->id,
                'reserved',
                'no_show',
                "Marked reservation {$booking->booking_ref} as No-Show."
            );

            return redirect()->route('reservations.index')->with('success', "Reservation {$booking->booking_ref} marked as No Show.");
        });
    }

    public function reschedule(Booking $booking, Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date',
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
        ]);

        $user = $request->user();

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only pending reservations can be rescheduled.');
        }

        $room = Room::findOrFail($request->room_id);

        return DB::transaction(function () use ($booking, $request, $room, $user) {
            $checkInRaw = Carbon::parse($request->check_in);
            if ($request->booking_type === 'overnight') {
                $checkInTime = $checkInRaw->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0);
            } else {
                $checkInTime = $checkInRaw;
            }

            // Calculate precise amounts
            $pricing = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkInTime->format('Y-m-d H:i:s'),
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $booking->discount_type ?: '',
                $booking->discount_amount ?: 0
            );

            // Double-booking check excluding this booking
            $expectedCheckOut = $pricing['expected_check_out'];
            $overlap = Booking::where('room_id', $room->id)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['active', 'reserved'])
                ->where('check_in', '<', $expectedCheckOut)
                ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                ->first();

            if ($overlap) {
                return back()->with('error', "Double-booking conflict: Room is already booked by {$overlap->guest_name} from {$overlap->check_in} to {$overlap->expected_check_out}.");
            }

            $oldBooking = $booking->toArray();

            // Update Booking details
            $booking->room_id = $room->id;
            $booking->check_in = $checkInTime->format('Y-m-d H:i:s');
            $booking->expected_check_out = $pricing['expected_check_out'];
            $booking->booking_type = $request->booking_type;
            $booking->short_time_hours = $request->booking_type !== 'overnight' ? $request->short_time_hours : null;
            $booking->num_nights = $request->booking_type === 'overnight' ? $request->num_nights : null;
            
            $booking->base_amount = $pricing['base_amount'];
            $booking->peak_surcharge = $pricing['peak_surcharge'];
            $booking->discount_amount = $pricing['discount_amount'];
            $booking->total_amount = $pricing['total_amount'];
            $booking->is_peak = $pricing['is_peak'];
            
            // Adjust payment status
            $booking->payment_status = ($booking->amount_paid >= $pricing['total_amount']) ? 'paid' : 'partial';
            $booking->notes = trim($booking->notes . "\nRescheduled on " . now()->format('Y-m-d H:i:s') . " to " . $booking->check_in . " in Room " . $room->room_number);
            $booking->save();

            // Transaction log for the adjustment
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Reservation rescheduled. New date: {$booking->check_in}. New Room: {$room->room_number}.",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION_RESCHEDULE',
                'bookings',
                $booking->id,
                $oldBooking,
                $booking->toArray(),
                "Rescheduled reservation {$booking->booking_ref} to Room {$room->room_number} on {$booking->check_in}."
            );

            return redirect()->route('reservations.index')->with('success', "Reservation {$booking->booking_ref} rescheduled successfully to Room {$room->room_number}!");
        });
    }

    public function groupCheckin($groupRef, Request $request)
    {
        $user = $request->user();
        
        if (!\App\Services\ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to perform check-in.');
        }

        $bookings = Booking::with('room')->where('group_ref', $groupRef)->where('status', 'reserved')->get();
        if ($bookings->isEmpty()) {
            return back()->with('error', 'No pending reservations found for this group.');
        }

        foreach ($bookings as $b) {
            if ($b->amount_paid < $b->total_amount) {
                return back()->with('error', "Cannot check in group. Room {$b->room->room_number} has a remaining balance. Edit the booking to settle full payment first.");
            }
            if ($b->room->status !== 'vacant') {
                return back()->with('error', "Cannot check in group. Room {$b->room->room_number} is currently {$b->room->status}.");
            }
        }

        return DB::transaction(function () use ($bookings, $user, $groupRef) {
            $now = now();
            $roomNumbers = [];

            foreach ($bookings as $booking) {
                $room = $booking->room;
                $room->status = 'occupied';
                $room->save();

                $booking->status = 'active';
                $booking->check_in = $now->format('Y-m-d H:i:s');
                
                $pricing = BookingService::calculateBookingAmounts(
                    $room,
                    $booking->booking_type,
                    $now->format('Y-m-d H:i:s'),
                    $booking->booking_type === 'overnight' ? max(1, Carbon::parse($booking->check_in)->diffInDays(Carbon::parse($booking->expected_check_out))) : 1,
                    $booking->short_time_hours ?: 3,
                    $booking->discount_type ?: '',
                    $booking->discount_amount
                );
                $booking->expected_check_out = $pricing['expected_check_out'];
                $booking->base_amount = $pricing['base_amount'];
                $booking->peak_surcharge = $pricing['peak_surcharge'];
                $booking->total_amount = $pricing['total_amount'];
                $booking->payment_status = ($booking->amount_paid >= $pricing['total_amount'] * 0.99) ? 'paid' : 'partial';
                $booking->save();
                
                $roomNumbers[] = $room->room_number;
            }

            $firstBooking = $bookings->first();
            if ($firstBooking->guestProfile) {
                $firstBooking->guestProfile->total_stays += 1;
                $firstBooking->guestProfile->last_visit = $now->format('Y-m-d');
                $firstBooking->guestProfile->save();
            }

            BookingService::auditLog(
                $user->id,
                'GROUP_CHECK_IN',
                'bookings',
                null,
                'reserved',
                'active',
                "Group {$groupRef} checked in for rooms: " . implode(', ', $roomNumbers)
            );

            return redirect()->route('rooms.index')->with('success', "Group checked in successfully for rooms: " . implode(', ', $roomNumbers));
        });
    }

    public function groupCheckout($groupRef, Request $request)
    {
        $request->validate([
            'waive_late_fee' => 'nullable|boolean',
        ]);

        $user = $request->user();
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to perform check-out.');
        }

        $waiveLateFee = (bool)($request->waive_late_fee ?? false);

        $bookings = Booking::with('room')->where('group_ref', $groupRef)->where('status', 'active')->get();
        if ($bookings->isEmpty()) {
            return back()->with('error', 'No active reservations found for this group.');
        }

        foreach ($bookings as $b) {
            $lateHours = BookingService::calculateLateCheckoutHours($b->expected_check_out, now());
            $lateFee = $waiveLateFee ? 0.00 : BookingService::calculateLateCheckoutFee($b->expected_check_out, now());
            $inventoryTotal = \App\Models\InventoryUsage::where('booking_id', $b->id)->sum('total_price');
            $balance = ($b->total_amount + $lateFee + $b->extension_fee + $inventoryTotal) - $b->amount_paid;
            
            if ($balance > 0) {
                return back()->with('error', "Cannot perform group check-out. Room {$b->room->room_number} has an outstanding balance of ₱" . number_format($balance, 2) . ". Please check out that room individually.");
            }
        }

        return DB::transaction(function () use ($bookings, $user, $groupRef, $waiveLateFee) {
            $now = now();
            $roomNumbers = [];

            foreach ($bookings as $booking) {
                $room = $booking->room;
                $room->status = 'cleaning';
                $room->save();

                $lateHours = BookingService::calculateLateCheckoutHours($booking->expected_check_out, $now);
                $originalLateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out, $now);
                $lateFee = $waiveLateFee ? 0.00 : $originalLateFee;

                $booking->status = 'checked_out';
                $booking->check_out = $now->format('Y-m-d H:i:s');
                $booking->checked_out_by = $user->id;
                $booking->late_hours = $lateHours;
                $booking->late_checkout_fee = $lateFee;
                if ($waiveLateFee && $originalLateFee > 0) {
                    $booking->notes = trim(($booking->notes ? $booking->notes . "\n" : "") . "Late check-out fee of ₱" . number_format($originalLateFee, 2) . " waived by " . $user->name . ".");
                }
                $booking->save();
                
                // Create transaction trace for bookkeeping and shift log compliance
                Transaction::create([
                    'booking_id' => $booking->id,
                    'transaction_type' => 'check_out',
                    'description' => "Group check-out completed for Room {$room->room_number}. Ref: {$booking->booking_ref}" . ($waiveLateFee && $originalLateFee > 0 ? " (Late fee of ₱" . number_format($originalLateFee, 2) . " waived)" : ""),
                    'amount' => 0.00,
                    'payment_method' => 'na',
                    'cash_amount' => 0.00,
                    'gcash_amount' => 0.00,
                    'processed_by' => $user->id,
                ]);
                
                $roomNumbers[] = $room->room_number;
            }

            BookingService::auditLog(
                $user->id,
                'GROUP_CHECK_OUT',
                'bookings',
                null,
                'active',
                'checked_out',
                "Group {$groupRef} checked out for rooms: " . implode(', ', $roomNumbers)
            );

            return redirect()->route('rooms.index')->with('success', "Group checked out successfully. Rooms sent to housekeeping: " . implode(', ', $roomNumbers));
        });
    }

    public function groupCheckoutSettle($groupRef, Request $request)
    {
        $request->validate([
            'waive_late_fee' => 'nullable|boolean',
            'payment_method' => 'required|in:cash,gcash,bank_transfer,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'bank_amount' => 'nullable|numeric|min:0',
            'bank_ref' => 'nullable|string|max:50',
            'transaction_notes' => 'nullable|string',
        ]);

        $user = $request->user();
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to perform check-out.');
        }

        $waiveLateFee = (bool)($request->waive_late_fee ?? false);

        $bookings = Booking::with('room')->where('group_ref', $groupRef)->where('status', 'active')->get();
        if ($bookings->isEmpty()) {
            return back()->with('error', 'No active stays found for this group.');
        }

        // Calculate individual balances and group balances
        $roomsData = [];
        $groupBalanceTotal = 0.00;

        foreach ($bookings as $b) {
            $lateHours = BookingService::calculateLateCheckoutHours($b->expected_check_out, now());
            $lateFee = $waiveLateFee ? 0.00 : BookingService::calculateLateCheckoutFee($b->expected_check_out, now());
            $inventoryTotal = (float)\App\Models\InventoryUsage::where('booking_id', $b->id)->sum('total_price');
            
            $roomTotal = (float)$b->total_amount + $lateFee + (float)$b->extension_fee + $inventoryTotal;
            $balance = $roomTotal - (float)$b->amount_paid;

            $roomsData[$b->id] = [
                'booking' => $b,
                'late_hours' => $lateHours,
                'late_fee' => $lateFee,
                'inventory_total' => $inventoryTotal,
                'room_total' => $roomTotal,
                'balance' => max(0.00, $balance),
            ];

            $groupBalanceTotal += max(0.00, $balance);
        }

        // If balance > 0, validate the payment sum
        if ($groupBalanceTotal > 0) {
            $payCash = (float)($request->cash_amount ?? 0.00);
            $payGcash = (float)($request->gcash_amount ?? 0.00);
            $payBank = (float)($request->bank_amount ?? 0.00);
            
            $method = $request->payment_method;
            if ($method === 'cash') {
                $payTotal = $payCash;
            } elseif ($method === 'gcash') {
                $payTotal = $payGcash;
            } elseif ($method === 'bank_transfer') {
                $payTotal = $payBank;
            } else { // split
                $payTotal = $payCash + $payGcash + $payBank;
            }

            if (round($payTotal, 2) < round($groupBalanceTotal, 2)) {
                return back()->with('error', 'Insufficient payment amount. Balance is ₱' . number_format($groupBalanceTotal, 2) . ', payment is ₱' . number_format($payTotal, 2));
            }
        } else {
            $payCash = 0.00;
            $payGcash = 0.00;
            $payBank = 0.00;
        }

        return DB::transaction(function () use ($bookings, $user, $groupRef, $waiveLateFee, $roomsData, $groupBalanceTotal, $request, $payCash, $payGcash, $payBank) {
            $now = now();
            $roomNumbers = [];

            // We will distribute the payments proportionally or simply cover the balance of each room
            $remainingCash = $payCash;
            $remainingGcash = $payGcash;
            $remainingBank = $payBank;

            foreach ($bookings as $booking) {
                $room = $booking->room;
                $room->status = 'cleaning';
                $room->save();

                $bData = $roomsData[$booking->id];
                $balance = $bData['balance'];

                $roomCash = 0.00;
                $roomGcash = 0.00;
                $roomBank = 0.00;

                if ($balance > 0) {
                    // Pull cash share
                    $roomCash = min($balance, $remainingCash);
                    $balance -= $roomCash;
                    $remainingCash -= $roomCash;

                    // Pull gcash share
                    if ($balance > 0) {
                        $roomGcash = min($balance, $remainingGcash);
                        $balance -= $roomGcash;
                        $remainingGcash -= $roomGcash;
                    }

                    // Pull bank share
                    if ($balance > 0) {
                        $roomBank = min($balance, $remainingBank);
                        $balance -= $roomBank;
                        $remainingBank -= $roomBank;
                    }
                }

                $paidShare = $roomCash + $roomGcash + $roomBank;

                // Update booking
                $booking->status = 'checked_out';
                $booking->check_out = $now->format('Y-m-d H:i:s');
                $booking->checked_out_by = $user->id;
                $booking->late_hours = $bData['late_hours'];
                $booking->late_checkout_fee = $bData['late_fee'];
                $booking->amount_paid += $paidShare;
                
                $originalLateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out, $now);
                if ($waiveLateFee && $originalLateFee > 0) {
                    $booking->notes = trim(($booking->notes ? $booking->notes . "\n" : "") . "Late check-out fee of ₱" . number_format($originalLateFee, 2) . " waived by " . $user->name . ".");
                }
                $booking->save();

                // Create individual transaction record for audit compliance
                Transaction::create([
                    'booking_id' => $booking->id,
                    'transaction_type' => 'check_out',
                    'description' => "Group check-out completed for Room {$room->room_number}. Ref: {$booking->booking_ref}" . ($waiveLateFee && $originalLateFee > 0 ? " (Late fee of ₱" . number_format($originalLateFee, 2) . " waived)" : ""),
                    'amount' => $paidShare,
                    'payment_method' => $request->payment_method,
                    'cash_amount' => $roomCash,
                    'gcash_amount' => $roomGcash,
                    'gcash_ref' => $request->gcash_ref,
                    'bank_amount' => $roomBank,
                    'bank_ref' => $request->bank_ref,
                    'processed_by' => $user->id,
                    'notes' => $request->transaction_notes,
                ]);

                $roomNumbers[] = $room->room_number;
            }

            BookingService::auditLog(
                $user->id,
                'GROUP_CHECK_OUT',
                'bookings',
                null,
                'active',
                'checked_out',
                "Group {$groupRef} checkout settled for rooms: " . implode(', ', $roomNumbers) . ". Total Settle Payment: ₱" . number_format($payCash + $payGcash + $payBank, 2)
            );

            return redirect()->route('rooms.index')->with('success', "Group checkout settled successfully. Rooms sent to housekeeping: " . implode(', ', $roomNumbers));
        });
    }

    public function groupCheckoutPreview($groupRef, Request $request)
    {
        $bookings = Booking::with(['room', 'room.type'])
            ->where('group_ref', $groupRef)
            ->where('status', 'active')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json(['error' => 'No active stays found for this group.'], 404);
        }

        $now = now();
        $rooms = [];
        $totalBase = 0.00;
        $totalLate = 0.00;
        $totalMinibar = 0.00;
        $totalExtension = 0.00;
        $totalPaid = 0.00;
        $totalDue = 0.00;

        foreach ($bookings as $b) {
            $lateHours = BookingService::calculateLateCheckoutHours($b->expected_check_out, $now);
            $lateFee = BookingService::calculateLateCheckoutFee($b->expected_check_out, $now);
            $inventoryTotal = (float)\App\Models\InventoryUsage::where('booking_id', $b->id)->sum('total_price');
            
            $roomTotal = (float)$b->total_amount + $lateFee + (float)$b->extension_fee + $inventoryTotal;
            $balance = max(0.00, $roomTotal - (float)$b->amount_paid);

            $rooms[] = [
                'id' => $b->id,
                'booking_ref' => $b->booking_ref,
                'room_number' => $b->room ? $b->room->room_number : '?',
                'guest_name' => $b->guest_name,
                'check_in' => $b->check_in ? $b->check_in->format('Y-m-d H:i:s') : null,
                'expected_check_out' => $b->expected_check_out ? $b->expected_check_out->format('Y-m-d H:i:s') : null,
                'base_amount' => (float)$b->base_amount + (float)$b->peak_surcharge + (float)$b->extra_pax_charges - (float)$b->discount_amount,
                'extension_fee' => (float)$b->extension_fee,
                'late_hours' => $lateHours,
                'late_fee' => $lateFee,
                'inventory_total' => $inventoryTotal,
                'total_amount' => $roomTotal,
                'amount_paid' => (float)$b->amount_paid,
                'balance' => $balance,
            ];

            $totalBase += ((float)$b->base_amount + (float)$b->peak_surcharge + (float)$b->extra_pax_charges - (float)$b->discount_amount);
            $totalLate += $lateFee;
            $totalMinibar += $inventoryTotal;
            $totalExtension += (float)$b->extension_fee;
            $totalPaid += (float)$b->amount_paid;
            $totalDue += $balance;
        }

        return response()->json([
            'group_ref' => $groupRef,
            'rooms' => $rooms,
            'totals' => [
                'base' => $totalBase,
                'late' => $totalLate,
                'minibar' => $totalMinibar,
                'extension' => $totalExtension,
                'paid' => $totalPaid,
                'balance' => $totalDue,
            ]
        ]);
    }
}
