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
use DB;

class CheckInController extends Controller
{
    public function index(Request $request)
    {
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

        return Inertia::render('CheckIn/Index', [
            'vacantRooms' => $rooms,
            'roomTypes' => $roomTypes,
            'prefilledGuest' => $prefilledGuest,
            'promoCodes' => $promoCodes,
        ]);
    }

    public function calculate(Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
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
        $checkIn = now()->format('Y-m-d H:i:s');
        
        $reqDiscountType = $request->discount_type ?: '';
        $reqDiscountAmount = (float)($request->discount_amount ?: 0);

        if ($request->filled('promo_code')) {
            $promo = \App\Models\PromoCode::where('code', $request->promo_code)->first();
            if ($promo && $promo->isValid()) {
                $reqDiscountType = 'promo';
                
                // Get subtotal first to compute percentage
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

        // Include the calculated/applied promo details if applicable
        if ($request->filled('promo_code')) {
            $amounts['promo_code'] = $request->promo_code;
        }

        return response()->json($amounts);
    }

    public function store(Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
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
        
        // Active shift verification for extra safety
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to process a check-in.');
        }

        $room = Room::with('type')->findOrFail($request->room_id);
        if ($room->status !== 'vacant') {
            return back()->with('error', 'Room is not vacant.');
        }

        return DB::transaction(function () use ($request, $room, $user) {
            $checkInTime = now();
            
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

            // Calculate exact pricing
            $pricing = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkInTime->format('Y-m-d H:i:s'),
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $reqDiscountType,
                $reqDiscountAmount
            );

            // Handle payment verification
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
                // Card / Bank Transfer payments use the total amount, cash/gcash remain 0
            } else { // split
                $cashAmount = (float)($request->cash_amount ?: 0);
                $gcashAmount = (float)($request->gcash_amount ?: 0);
                if (abs(($cashAmount + $gcashAmount) - $totalAmount) > 0.01) {
                    throw new \Exception("Split amounts ($cashAmount + $gcashAmount) must equal the total amount ($totalAmount).");
                }
            }

            // Find or Create guest profile
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
                // update details
                $guestProfile->contact_number = $request->guest_contact ?: $guestProfile->contact_number;
                $guestProfile->id_type = $request->guest_id_type ?: $guestProfile->id_type;
                $guestProfile->id_number = $request->guest_id_number ?: $guestProfile->id_number;
                $guestProfile->email = $request->guest_email ?: $guestProfile->email;
                $guestProfile->address = $request->guest_address ?: $guestProfile->address;
            }

            $guestProfile->total_stays += 1;
            $guestProfile->total_spent += $totalAmount;
            $guestProfile->last_visit = $checkInTime->format('Y-m-d');
            $guestProfile->save();

            // Create Booking
            $bookingRef = 'BKG-' . strtoupper(Str::random(4)) . $checkInTime->format('ymdHis');
            
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
                'status' => 'active',
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
                'notes' => $request->notes ? trim($request->notes . ($request->filled('promo_code') ? "\nApplied Promo Code: " . $request->promo_code : '')) : ($request->filled('promo_code') ? "Applied Promo Code: " . $request->promo_code : null),
                'checked_in_by' => $user->id,
            ]);

            // Create transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'check_in',
                'description' => "Initial check-in payment for Reference: {$bookingRef}. Room {$room->room_number}" . ($request->filled('promo_code') ? " (Promo: {$request->promo_code})" : ""),
                'amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'cash_amount' => $cashAmount,
                'gcash_amount' => $gcashAmount,
                'gcash_ref' => $refNum,
                'processed_by' => $user->id,
            ]);

            // Track promo code usage
            if ($promoCodeModel) {
                $promoCodeModel->increment('used_count');
            }

            // Set room status to occupied
            $room->status = 'occupied';
            $room->save();

            // Audit logging
            BookingService::auditLog(
                $user->id,
                'CHECK_IN',
                'bookings',
                $booking->id,
                null,
                $bookingRef,
                "Checked in guest {$guestProfile->full_name} into Room {$room->room_number} (Ref: {$bookingRef}). Payment: ₱{$totalAmount} via {$paymentMethod}."
            );

            return redirect()->route('rooms.index')->with('success', "Guest {$guestProfile->full_name} successfully checked into Room {$room->room_number}. Reference: {$bookingRef}");
        });
    }
}
