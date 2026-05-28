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

        $reservations = Booking::with(['room', 'room.type'])
            ->when($status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('Reservations/Index', [
            'reservations' => $reservations,
            'currentFilter' => $status,
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
            'room_id' => 'required|exists:rooms,id',
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

        $room = Room::findOrFail($request->room_id);
        
        // Parse and standardize future check-in datetime
        $checkInRaw = Carbon::parse($request->check_in);
        if ($request->booking_type === 'overnight') {
            $checkIn = $checkInRaw->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0)->format('Y-m-d H:i:s');
        } else {
            $checkIn = $checkInRaw->format('Y-m-d H:i:s');
        }
        
        $reqDiscountType = $request->discount_type ?: '';
        $reqDiscountAmount = (float)($request->discount_amount ?: 0);

        if ($request->filled('promo_code')) {
            $promo = \App\Models\PromoCode::where('code', $request->promo_code)->first();
            if ($promo && $promo->isValid()) {
                $reqDiscountType = 'promo';
                
                $tempAmounts = BookingService::calculateBookingAmounts(
                    $room,
                    $request->booking_type,
                    $checkIn,
                    $request->num_nights ?: 1,
                    $request->short_time_hours ?: 3,
                    '',
                    0
                );
                
                $subtotal = $tempAmounts['base_amount'] + $tempAmounts['peak_surcharge'];
                if ($promo->discount_type === 'percent') {
                    $reqDiscountAmount = round($subtotal * ($promo->discount_value / 100), 2);
                } else {
                    $reqDiscountAmount = min($subtotal, (float)$promo->discount_value);
                }
            } else {
                return response()->json(['error' => 'The promo code is invalid or expired.'], 422);
            }
        }

        $amounts = BookingService::calculateBookingAmounts(
            $room,
            $request->booking_type,
            $checkIn,
            $request->num_nights ?: 1,
            $request->short_time_hours ?: 3,
            $reqDiscountType,
            $reqDiscountAmount
        );

        if ($request->filled('promo_code')) {
            $amounts['promo_code'] = $request->promo_code;
        }

        // --- DOUBLE-BOOKING OVERLAP CONFLICT CHECK ---
        $expectedCheckOut = $amounts['expected_check_out'];
        $overlap = Booking::where('room_id', $room->id)
            ->whereIn('status', ['active', 'reserved'])
            ->where('check_in', '<', $expectedCheckOut)
            ->where('expected_check_out', '>', $checkIn)
            ->first();

        if ($overlap) {
            $amounts['conflict'] = [
                'booking_ref' => $overlap->booking_ref,
                'status' => $overlap->status,
                'guest_name' => $overlap->guest_name,
                'check_in' => Carbon::parse($overlap->check_in)->format('M d, Y h:i A'),
                'expected_check_out' => Carbon::parse($overlap->expected_check_out)->format('M d, Y h:i A'),
            ];
        }

        return response()->json($amounts);
    }

    public function store(Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date',
            'guest_name' => 'required|string|max:100',
            'guest_contact' => 'nullable|string|max:20',
            'guest_id_type' => 'nullable|string|max:50',
            'guest_id_number' => 'nullable|string|max:50',
            'guest_email' => 'nullable|email|max:100',
            'guest_address' => 'nullable|string',
            'num_guests' => 'required|integer|min:1',
            
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
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to process a booking.');
        }

        $room = Room::with('type')->findOrFail($request->room_id);

        return DB::transaction(function () use ($request, $room, $user) {
            $checkInRaw = Carbon::parse($request->check_in);
            if ($request->booking_type === 'overnight') {
                $checkInTime = $checkInRaw->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0);
            } else {
                $checkInTime = $checkInRaw;
            }
            
            $reqDiscountType = $request->discount_type ?: '';
            $reqDiscountAmount = (float)($request->discount_amount ?: 0);
            $promoCodeModel = null;

            if ($request->filled('promo_code')) {
                $promoCodeModel = \App\Models\PromoCode::where('code', $request->promo_code)->first();
                if ($promoCodeModel && $promoCodeModel->isValid()) {
                    $reqDiscountType = 'promo';
                    
                    $tempAmounts = BookingService::calculateBookingAmounts(
                        $room,
                        $request->booking_type,
                        $checkInTime->format('Y-m-d H:i:s'),
                        $request->num_nights ?: 1,
                        $request->short_time_hours ?: 3,
                        '',
                        0
                    );
                    
                    $subtotal = $tempAmounts['base_amount'] + $tempAmounts['peak_surcharge'];
                    if ($promoCodeModel->discount_type === 'percent') {
                        $reqDiscountAmount = round($subtotal * ($promoCodeModel->discount_value / 100), 2);
                    } else {
                        $reqDiscountAmount = min($subtotal, (float)$promoCodeModel->discount_value);
                    }
                } else {
                    throw new \Exception("The promo code is invalid or expired.");
                }
            }

            // Calculate precise amounts
            $pricing = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkInTime->format('Y-m-d H:i:s'),
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $reqDiscountType,
                $reqDiscountAmount
            );

            // Overlap safety check in transaction
            $expectedCheckOut = $pricing['expected_check_out'];
            $overlap = Booking::where('room_id', $room->id)
                ->whereIn('status', ['active', 'reserved'])
                ->where('check_in', '<', $expectedCheckOut)
                ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                ->first();

            if ($overlap) {
                throw new \Exception("Double-booking conflict: Room is already booked by {$overlap->guest_name} from {$overlap->check_in} to {$overlap->expected_check_out}.");
            }

            // Payment verification
            $totalAmount = $pricing['total_amount'];
            $paymentMethod = $request->payment_method;
            $cashAmount = 0.00;
            $gcashAmount = 0.00;
            $refNum = $request->gcash_ref ?: $request->reference_number ?: null;

            if ($paymentMethod === 'cash') {
                $cashAmount = $totalAmount;
            } elseif ($paymentMethod === 'gcash') {
                $gcashAmount = $totalAmount;
            } elseif ($paymentMethod === 'card' || $paymentMethod === 'bank_transfer') {
                // Card / Bank
            } else { // split
                $cashAmount = (float)($request->cash_amount ?: 0);
                $gcashAmount = (float)($request->gcash_amount ?: 0);
                if (abs(($cashAmount + $gcashAmount) - $totalAmount) > 0.01) {
                    throw new \Exception("Split amounts must equal the total amount ₱{$totalAmount}.");
                }
            }

            // Guest profile
            $guestProfile = GuestProfile::where('full_name', trim($request->guest_name))->first();
            if (!$guestProfile) {
                $guestProfile = GuestProfile::create([
                    'full_name' => trim($request->guest_name),
                    'contact_number' => $request->guest_contact,
                    'id_type' => $request->guest_id_type,
                    'id_number' => $request->guest_id_number,
                    'email' => $request->guest_email,
                    'address' => $request->guest_address,
                ]);
            } else {
                $guestProfile->contact_number = $request->guest_contact ?: $guestProfile->contact_number;
                $guestProfile->id_type = $request->guest_id_type ?: $guestProfile->id_type;
                $guestProfile->id_number = $request->guest_id_number ?: $guestProfile->id_number;
                $guestProfile->email = $request->guest_email ?: $guestProfile->email;
                $guestProfile->address = $request->guest_address ?: $guestProfile->address;
            }

            // Note: Stays count updates upon actual checkin. Spend updates immediately since payment is collected.
            $guestProfile->total_spent += $totalAmount;
            $guestProfile->save();

            // Create Booking
            $bookingRef = 'RES-' . strtoupper(Str::random(4)) . $checkInTime->format('ymdHis');
            
            $booking = Booking::create([
                'booking_ref' => $bookingRef,
                'room_id' => $room->id,
                'guest_profile_id' => $guestProfile->id,
                'guest_name' => $guestProfile->full_name,
                'guest_contact' => $guestProfile->contact_number,
                'guest_id_type' => $guestProfile->id_type,
                'guest_id_number' => $guestProfile->id_number,
                'num_guests' => $request->num_guests,
                'booking_type' => $request->booking_type,
                'short_time_hours' => $request->booking_type !== 'overnight' ? $request->short_time_hours : null,
                'check_in' => $checkInTime->format('Y-m-d H:i:s'),
                'expected_check_out' => $pricing['expected_check_out'],
                'status' => 'reserved',
                'payment_status' => 'paid',
                'base_amount' => $pricing['base_amount'],
                'peak_surcharge' => $pricing['peak_surcharge'],
                'discount_type' => $reqDiscountType ?: null,
                'discount_amount' => $pricing['discount_amount'],
                'total_amount' => $totalAmount,
                'amount_paid' => $totalAmount,
                'payment_method' => $paymentMethod,
                'cash_amount' => $cashAmount,
                'gcash_amount' => $gcashAmount,
                'gcash_ref' => $refNum,
                'is_peak' => $pricing['is_peak'],
                'notes' => $request->notes ? trim($request->notes . ($request->filled('promo_code') ? "\nApplied Promo: " . $request->promo_code : '')) : ($request->filled('promo_code') ? "Applied Promo: " . $request->promo_code : null),
                'checked_in_by' => $user->id,
            ]);

            // Transaction log
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'check_in',
                'description' => "Initial reservation payment for Ref: {$bookingRef}. Room {$room->room_number}" . ($request->filled('promo_code') ? " (Promo: {$request->promo_code})" : ""),
                'amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'cash_amount' => $cashAmount,
                'gcash_amount' => $gcashAmount,
                'gcash_ref' => $refNum,
                'processed_by' => $user->id,
            ]);

            if ($promoCodeModel) {
                $promoCodeModel->increment('used_count');
            }

            // Note: Room is NOT marked occupied because this is a future reservation!

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION',
                'bookings',
                $booking->id,
                null,
                $bookingRef,
                "Registered future reservation for {$guestProfile->full_name} in Room {$room->room_number} (Ref: {$bookingRef}, Stay: {$booking->check_in} to {$booking->expected_check_out}). Collected ₱{$totalAmount} via {$paymentMethod}."
            );

            return redirect()->route('reservations.index')->with('success', "Reservation {$bookingRef} registered successfully for Room {$room->room_number}!");
        });
    }

    public function checkin(Booking $booking, Request $request)
    {
        $user = $request->user();
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to perform check-in.');
        }

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only reserved bookings can be checked in.');
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
}
