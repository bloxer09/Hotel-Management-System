<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\InventoryItem;
use App\Models\InventoryUsage;
use App\Models\GuestProfile;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use DB;

class BookingController extends Controller
{

    public function show(Booking $booking, Request $request)
    {
        $booking->load(['room', 'room.type', 'guestProfile', 'transactions.processedBy']);
        
        $inventoryUsages = InventoryUsage::with('item')
            ->where('booking_id', $booking->id)
            ->get();

        $inventoryItems = InventoryItem::where('is_active', true)
            ->where('current_stock', '>', 0)
            ->get();

        // Calculate live checkout figures
        $now = now();
        $lateHours = BookingService::calculateLateCheckoutHours($booking->expected_check_out, $now);
        $lateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out, $now);
        
        $unpaidInventorySum = (float)$inventoryUsages->sum('total_price');

        $additionalDue = $lateFee + $unpaidInventorySum;
        $totalEstimatedBill = $booking->total_amount + $additionalDue;

        $vacantRooms = Room::with('type')
            ->where('status', 'vacant')
            ->orderBy('room_number', 'asc')
            ->get();

        $data = [
            'booking' => $booking,
            'inventoryUsages' => $inventoryUsages,
            'inventoryItems' => $inventoryItems,
            'vacantRooms' => $vacantRooms,
            'calculations' => [
                'current_time' => $now->format('Y-m-d H:i:s'),
                'late_hours' => $lateHours,
                'late_fee' => $lateFee,
                'unpaid_inventory' => $unpaidInventorySum,
                'additional_due' => $additionalDue,
                'total_estimated' => $totalEstimatedBill,
            ]
        ];

        if ($request->query('json') || (($request->wantsJson() || $request->ajax()) && !$request->hasHeader('X-Inertia'))) {
            return response()->json($data);
        }

        return Inertia::render('Bookings/Show', $data);
    }

    public function receipt(Booking $booking)
    {
        $booking->load(['room', 'room.type', 'guestProfile', 'transactions.processedBy', 'checkinStaff', 'checkoutStaff', 'inventoryUsages.item']);
        
        $settings = [
            'vat_enabled' => \App\Models\Setting::getValue('vat_enabled', '0') === '1',
            'vat_percent' => (float) \App\Models\Setting::getValue('vat_percent', '12'),
        ];

        return Inertia::render('Bookings/Receipt', [
            'booking' => $booking,
            'transactions' => $booking->transactions()->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get(),
            'settings' => $settings,
        ]);
    }

    public function extend(Booking $booking, Request $request)
    {
        $request->validate([
            'hours' => 'required_without:days|nullable|integer|min:1',
            'days' => 'required_without:hours|nullable|integer|min:1',
            'payment_method' => 'required|in:cash,gcash,card,bank_transfer,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to extend a booking.');
        }

        if ($booking->status !== 'active') {
            return back()->with('error', 'Only active bookings can be extended.');
        }

        return DB::transaction(function () use ($booking, $request, $user) {
            $room = $booking->room;
            $roomType = $room->type;
            
            $cost = 0.00;
            $desc = "";
            $expectedOut = new \DateTime($booking->expected_check_out);

            if ($request->hours) {
                $hours = (int)$request->hours;
                $cost = round((float)$roomType->hourly_rate * $hours, 2);
                $expectedOut->modify('+' . $hours . ' hour');
                $desc = "Extended by {$hours} hour(s) @ ₱{$roomType->hourly_rate}/hr";
            } else {
                $days = (int)$request->days;
                $cost = round((float)$roomType->base_rate * $days, 2);
                $expectedOut->modify('+' . $days . ' day');
                $desc = "Extended by {$days} night(s) @ ₱{$roomType->base_rate}/night";
            }

            // Verify payment
            $paymentMethod = $request->payment_method;
            $cashAmount = 0.00;
            $gcashAmount = 0.00;
            $refNum = $request->gcash_ref ?: $request->reference_number ?: null;

            if ($paymentMethod === 'cash') {
                $cashAmount = $cost;
            } elseif ($paymentMethod === 'gcash') {
                $gcashAmount = $cost;
            } elseif ($paymentMethod === 'card' || $paymentMethod === 'bank_transfer') {
                // Card/Bank Transfer
            } else { // split
                $cashAmount = (float)($request->cash_amount ?: 0);
                $gcashAmount = (float)($request->gcash_amount ?: 0);
                if (abs(($cashAmount + $gcashAmount) - $cost) > 0.01) {
                    throw new \Exception("Split amounts must equal extension fee ₱{$cost}.");
                }
            }

            // Update Booking details
            $booking->expected_check_out = $expectedOut->format('Y-m-d H:i:s');
            $booking->extension_fee += $cost;
            $booking->total_amount += $cost;
            $booking->amount_paid += $cost;
            $booking->save();

            // Record transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'extension',
                'description' => "Extension fee for Ref: {$booking->booking_ref}. {$desc}",
                'amount' => $cost,
                'payment_method' => $paymentMethod,
                'cash_amount' => $cashAmount,
                'gcash_amount' => $gcashAmount,
                'gcash_ref' => $refNum,
                'processed_by' => $user->id,
            ]);

            // Update Guest Profile spending
            if ($booking->guestProfile) {
                $booking->guestProfile->total_spent += $cost;
                $booking->guestProfile->save();
            }

            BookingService::auditLog(
                $user->id,
                'BOOKING_EXTENSION',
                'bookings',
                $booking->id,
                null,
                null,
                "Extended Booking {$booking->booking_ref}. Fee: ₱{$cost}. New expected checkout: " . $booking->expected_check_out
            );

            return redirect()->route('bookings.show', $booking->id)->with('success', "Booking extended successfully. Extended expected check-out: " . $booking->expected_check_out);
        });
    }

    public function addItems(Booking $booking, Request $request)
    {
        $request->validate([
            'item_id' => 'required|exists:inventory_items,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        if ($booking->status !== 'active') {
            return back()->with('error', 'Can only add inventory items to active bookings.');
        }

        $item = InventoryItem::findOrFail($request->item_id);

        if ($item->current_stock < $request->quantity) {
            return back()->with('error', "Insufficient stock for {$item->item_name}. Current stock: {$item->current_stock}");
        }

        return DB::transaction(function () use ($booking, $item, $request, $user) {
            // Deduct stock
            $oldStock = $item->current_stock;
            $item->current_stock -= $request->quantity;
            $item->save();

            // Create usage
            $unitPrice = $item->selling_price;
            $totalPrice = round($unitPrice * $request->quantity, 2);

            InventoryUsage::create([
                'booking_id' => $booking->id,
                'item_id' => $item->id,
                'quantity' => $request->quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'recorded_by' => $user->id,
                'notes' => $request->notes,
            ]);

            // Log Inventory reduction in audit trail
            BookingService::auditLog(
                $user->id,
                'STOCK_DECREASE',
                'inventory_items',
                $item->id,
                $oldStock,
                $item->current_stock,
                "Deducted {$request->quantity} {$item->unit}(s) of {$item->item_name} for Booking Ref: {$booking->booking_ref} (Minibar/Room service usage)."
            );

            return redirect()->route('bookings.show', $booking->id)->with('success', "Added {$request->quantity} x {$item->item_name} to bill (₱{$totalPrice}).");
        });
    }

    public function checkout(Booking $booking, Request $request)
    {
        $request->validate([
            'payment_method' => 'required|in:cash,gcash,card,bank_transfer,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to process checkout.');
        }

        if ($booking->status !== 'active') {
            return back()->with('error', 'Booking is already checked out or inactive.');
        }

        return DB::transaction(function () use ($booking, $request, $user) {
            $now = now();
            
            // Calculate late fees
            $lateHours = BookingService::calculateLateCheckoutHours($booking->expected_check_out, $now);
            $lateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out, $now);

            // Fetch inventory totals
            $inventoryUsages = InventoryUsage::where('booking_id', $booking->id)->get();
            $inventorySum = (float)$inventoryUsages->sum('total_price');

            // Total addition due at checkout
            $additionalDue = $lateFee + $inventorySum;

            // Validate payments
            $paymentMethod = $request->payment_method;
            $cashAmount = 0.00;
            $gcashAmount = 0.00;
            $refNum = $request->gcash_ref ?: $request->reference_number ?: null;

            if ($additionalDue > 0) {
                if ($paymentMethod === 'cash') {
                    $cashAmount = $additionalDue;
                } elseif ($paymentMethod === 'gcash') {
                    $gcashAmount = $additionalDue;
                } elseif ($paymentMethod === 'card' || $paymentMethod === 'bank_transfer') {
                    // Card/Bank Transfer
                } else { // split
                    $cashAmount = (float)($request->cash_amount ?: 0);
                    $gcashAmount = (float)($request->gcash_amount ?: 0);
                    if (abs(($cashAmount + $gcashAmount) - $additionalDue) > 0.01) {
                        throw new \Exception("Split payments must match checkout total due ₱{$additionalDue}.");
                    }
                }
            }

            // Update booking details
            $booking->check_out = $now->format('Y-m-d H:i:s');
            $booking->late_hours = $lateHours;
            $booking->late_checkout_fee = $lateFee;
            $booking->total_amount += $additionalDue;
            $booking->amount_paid += $additionalDue;
            $booking->status = 'checked_out';
            $booking->payment_status = 'paid';
            $booking->checked_out_by = $user->id;
            if ($request->notes) {
                $booking->notes = trim($booking->notes . "\nCheckout Notes: " . $request->notes);
            }
            $booking->save();

            // Create check out transaction (only if there was additional collection)
            if ($additionalDue > 0) {
                Transaction::create([
                    'booking_id' => $booking->id,
                    'transaction_type' => 'check_out',
                    'description' => "Checkout settlements (Late hours: {$lateHours}h = ₱{$lateFee}, Minibar items = ₱{$inventorySum}) for Ref: {$booking->booking_ref}",
                    'amount' => $additionalDue,
                    'payment_method' => $paymentMethod,
                    'cash_amount' => $cashAmount,
                    'gcash_amount' => $gcashAmount,
                    'gcash_ref' => $refNum,
                    'processed_by' => $user->id,
                ]);

                // Update guest profile total spent
                if ($booking->guestProfile) {
                    $booking->guestProfile->total_spent += $additionalDue;
                    $booking->guestProfile->save();
                }
            } else {
                // Log 0 transaction anyway to track checkout
                Transaction::create([
                    'booking_id' => $booking->id,
                    'transaction_type' => 'check_out',
                    'description' => "Checkout completed with no outstanding balances. Ref: {$booking->booking_ref}",
                    'amount' => 0.00,
                    'payment_method' => 'na',
                    'cash_amount' => 0.00,
                    'gcash_amount' => 0.00,
                    'processed_by' => $user->id,
                ]);
            }

            // Set room status to cleaning
            $room = $booking->room;
            $room->status = 'cleaning';
            $room->assigned_housekeeper = null;
            $room->cleaning_started_at = now();
            $room->save();

            // Audit Trail log
            BookingService::auditLog(
                $user->id,
                'CHECK_OUT',
                'bookings',
                $booking->id,
                'active',
                'checked_out',
                "Checked out guest {$booking->guest_name} from Room {$room->room_number} (Ref: {$booking->booking_ref}). Collected additional ₱{$additionalDue} via {$paymentMethod}."
            );

            return redirect()->route('rooms.index')->with('success', "Room {$room->room_number} has been checked out and queued for Cleaning.");
        });
    }

    public function cancel(Booking $booking, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $user = $request->user();

        if ($booking->status !== 'active') {
            return back()->with('error', 'Only active bookings can be cancelled.');
        }

        return DB::transaction(function () use ($booking, $request, $user) {
            $booking->status = 'cancelled';
            $booking->notes = trim($booking->notes . "\nCancellation Reason: " . $request->reason);
            $booking->save();

            // Revert room status to vacant
            $room = $booking->room;
            $room->status = 'vacant';
            $room->save();

            // Inventory reversal! We should return items to stock if cancelled
            $usages = InventoryUsage::where('booking_id', $booking->id)->get();
            foreach ($usages as $usage) {
                $item = $usage->item;
                $oldStock = $item->current_stock;
                $item->current_stock += $usage->quantity;
                $item->save();

                BookingService::auditLog(
                    $user->id,
                    'STOCK_INCREASE',
                    'inventory_items',
                    $item->id,
                    $oldStock,
                    $item->current_stock,
                    "Reverted {$usage->quantity} x {$item->item_name} back to stock due to booking cancellation (Ref: {$booking->booking_ref})."
                );
            }

            // Transaction log
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Booking cancelled. Ref: {$booking->booking_ref}. Reason: {$request->reason}",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_CANCEL',
                'bookings',
                $booking->id,
                'active',
                'cancelled',
                "Cancelled Booking {$booking->booking_ref} for Room {$room->room_number}. Reason: {$request->reason}"
            );

            return redirect()->route('rooms.index')->with('success', "Booking {$booking->booking_ref} cancelled successfully.");
        });
    }

    public function move(Booking $booking, Request $request)
    {
        $request->validate([
            'new_room_id' => 'required|exists:rooms,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        
        $activeShift = \App\Models\ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
            
        if (!$activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to reassign a room.');
        }

        if ($booking->status !== 'active') {
            return back()->with('error', 'Only active bookings can be reassigned.');
        }

        $newRoomId = $request->new_room_id;
        $reason = trim($request->reason ?: '');

        if ($newRoomId == $booking->room_id) {
            return back()->with('error', 'Please select a different room.');
        }

        return DB::transaction(function () use ($booking, $newRoomId, $reason, $user) {
            // Load and lock the old room
            $oldRoom = Room::lockForUpdate()->findOrFail($booking->room_id);
            
            // Load and lock the new room, and verify it is vacant
            $newRoom = Room::lockForUpdate()->findOrFail($newRoomId);
            if ($newRoom->status !== 'vacant') {
                throw new \Exception('Selected new room is not available.');
            }

            $transferRoomNote = "Moved guest to Room {$newRoom->room_number}" . ($reason ? " | $reason" : '');
            $transferBookingNote = "[Room Transfer] {$oldRoom->room_number} → {$newRoom->room_number}" . ($reason ? " | {$reason}" : '');
            $transferTxDesc = "Room change: {$booking->guest_name} from {$oldRoom->room_number} to {$newRoom->room_number}" . ($reason ? " | {$reason}" : '');

            // Update booking details
            $booking->room_id = $newRoomId;
            $booking->notes = trim($booking->notes . "\n" . $transferBookingNote);
            $booking->save();

            // Update old room status and notes
            $oldRoom->status = 'cleaning';
            $oldRoom->notes = $transferRoomNote;
            $oldRoom->save();

            // Update new room status and notes
            $newRoom->status = 'occupied';
            $newRoom->notes = '';
            $newRoom->save();

            // Log Transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => $transferTxDesc,
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            // Audit Trail log
            BookingService::auditLog(
                $user->id,
                'ROOM_REASSIGNED',
                'bookings',
                $booking->id,
                ['room_id' => $oldRoom->id, 'room_number' => $oldRoom->room_number],
                ['room_id' => $newRoom->id, 'room_number' => $newRoom->room_number],
                $reason ?: 'Room reassigned'
            );

            return redirect()->route('bookings.show', $booking->id)->with('success', "Room reassigned: {$oldRoom->room_number} → {$newRoom->room_number}.");
        });
    }

    public function previewExtend(Booking $booking, Request $request)
    {
        $request->validate([
            'hours' => 'nullable|integer|min:1',
            'days' => 'nullable|integer|min:1',
        ]);

        if ($booking->status !== 'active') {
            return response()->json(['error' => 'Only active stays can be extended.'], 422);
        }

        $room = $booking->room;
        if (!$room) {
            return response()->json(['error' => 'Room not found.'], 404);
        }
        $roomType = $room->type;
        if (!$roomType) {
            return response()->json(['error' => 'Room type not found.'], 404);
        }

        $cost = 0.00;
        $expectedOut = new \DateTime($booking->expected_check_out);

        if ($request->hours) {
            $hours = (int)$request->hours;
            if (in_array($hours, [3, 6, 12, 24])) {
                $cost = BookingService::getShortTimeRate($roomType, $hours);
            } else {
                $cost = round((float)$roomType->hourly_rate * $hours, 2);
            }
            $expectedOut->modify('+' . $hours . ' hour');
        } else {
            $days = (int)$request->days;
            $cost = round((float)$roomType->base_rate * $days, 2);
            $expectedOut->modify('+' . $days . ' day');
        }

        $peakDate = BookingService::isPeakDate($booking->expected_check_out);
        $peakSurcharge = BookingService::calculateSurcharge($peakDate, $cost);
        $totalAmount = round($cost + $peakSurcharge, 2);

        return response()->json([
            'base_amount' => $cost,
            'peak_surcharge' => $peakSurcharge,
            'total_amount' => $totalAmount,
            'new_expected_check_out' => $expectedOut->format('Y-m-d H:i:s'),
            'new_expected_check_out_label' => $expectedOut->format('M d, Y h:i A'),
            'is_peak' => $peakDate ? true : false,
            'peak_label' => $peakDate ? $peakDate->label : null,
        ]);
    }

    public function update(Booking $booking, Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'guest_name' => 'required|string|max:100',
            'guest_contact' => 'nullable|string|max:20',
            'guest_id_type' => 'nullable|string|max:50',
            'guest_id_number' => 'nullable|string|max:50',
            'id_image' => 'nullable|image|max:5120',
            'guest_email' => 'nullable|email|max:100',
            'guest_address' => 'nullable|string',
            'num_guests' => 'required|integer|min:1',
            
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
        ]);

        $user = $request->user();
        $room = Room::with('type')->findOrFail($request->room_id);

        $idImagePath = null;
        if ($request->hasFile('id_image')) {
            $idImagePath = $request->file('id_image')->store('id_images', 'public');
        }

        return DB::transaction(function () use ($booking, $request, $room, $user, $idImagePath) {
            $oldRoomId = $booking->room_id;
            $newRoomId = $room->id;
            
            $checkInTime = $request->has('check_in') ? \Carbon\Carbon::parse($request->check_in) : \Carbon\Carbon::parse($booking->check_in);
            
            if ($booking->status === 'reserved' && $request->booking_type === 'overnight' && $request->has('check_in')) {
                $checkInTime = $checkInTime->copy()->setTime(BookingService::OVERNIGHT_CHECKIN_HOUR, 0, 0);
            }
            
            $reqDiscountType = $request->discount_type ?: '';
            $reqDiscountAmount = (float)($request->discount_amount ?: 0);

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

            // Overlap safety check in transaction (excluding current booking being edited)
            $expectedCheckOut = $pricing['expected_check_out'];
            $overlap = Booking::where('room_id', $room->id)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['active', 'reserved'])
                ->where('check_in', '<', $expectedCheckOut)
                ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                ->first();

            if ($overlap) {
                throw new \Exception("Double-booking conflict: Room is already booked by {$overlap->guest_name} from {$overlap->check_in} to {$overlap->expected_check_out}.");
            }

            // Room status transitions
            if ($oldRoomId != $newRoomId) {
                if ($booking->status === 'active') {
                    // Update old room status and notes
                    $oldRoom = Room::findOrFail($oldRoomId);
                    $oldRoom->status = 'cleaning';
                    $oldRoom->notes = "Moved guest to Room {$room->room_number}";
                    $oldRoom->save();

                    // Update new room status and notes
                    $room->status = 'occupied';
                    $room->notes = '';
                    $room->save();
                }
            }

            // Update Guest profile details
            $guestProfile = $booking->guestProfile;
            if ($guestProfile) {
                $guestProfile->full_name = trim($request->guest_name);
                $guestProfile->contact_number = $request->guest_contact ?: $guestProfile->contact_number;
                $guestProfile->id_type = $request->guest_id_type ?: $guestProfile->id_type;
                $guestProfile->id_number = $request->guest_id_number ?: $guestProfile->id_number;
                if ($idImagePath) {
                    $guestProfile->id_image_path = $idImagePath;
                }
                $guestProfile->email = $request->guest_email ?: $guestProfile->email;
                $guestProfile->address = $request->guest_address ?: $guestProfile->address;
                $guestProfile->save();
            }

            // Update Booking details
            $booking->room_id = $room->id;
            $booking->guest_name = trim($request->guest_name);
            $booking->guest_contact = $request->guest_contact;
            $booking->guest_id_type = $request->guest_id_type;
            $booking->guest_id_number = $request->guest_id_number;
            if ($idImagePath) {
                $booking->guest_id_image_path = $idImagePath;
            }
            $booking->num_guests = $request->num_guests;
            $booking->booking_type = $request->booking_type;
            $booking->short_time_hours = $request->booking_type !== 'overnight' ? $request->short_time_hours : null;
            $booking->check_in = $checkInTime->format('Y-m-d H:i:s');
            $booking->expected_check_out = $pricing['expected_check_out'];
            
            // Re-calculate pricing fields
            $booking->base_amount = $pricing['base_amount'];
            $booking->peak_surcharge = $pricing['peak_surcharge'];
            $booking->discount_type = $reqDiscountType ?: null;
            $booking->discount_amount = $pricing['discount_amount'];
            
            // Adjust paid totals if different
            $oldTotal = $booking->total_amount;
            $newTotal = $pricing['total_amount'];
            $booking->total_amount = $newTotal;

            $oldPaid = $booking->amount_paid;
            $oldCash = $booking->cash_amount;
            $oldGcash = $booking->gcash_amount;

            $paymentRatio = $request->input('payment_ratio', 'full');
            $collectedAmount = ($paymentRatio === 'half') ? round($newTotal / 2, 2) : $newTotal;
            $booking->amount_paid = $collectedAmount;
            $booking->payment_status = ($collectedAmount >= $newTotal) ? 'paid' : 'partial';

            // Adjust guest total spent if amount_paid changed
            if ($guestProfile && $collectedAmount != $oldPaid) {
                $guestProfile->total_spent += ($collectedAmount - $oldPaid);
                $guestProfile->save();
            }

            $paymentMethod = $request->payment_method;
            $cashAmount = 0.00;
            $gcashAmount = 0.00;
            $refNum = $request->gcash_ref ?: $request->reference_number ?: null;

            if ($paymentMethod === 'cash') {
                $cashAmount = $collectedAmount;
            } elseif ($paymentMethod === 'gcash') {
                $gcashAmount = $collectedAmount;
            } elseif ($paymentMethod === 'card' || $paymentMethod === 'bank_transfer') {
                // Card / Bank
            } else { // split
                $cashAmount = (float)($request->cash_amount ?: 0);
                $gcashAmount = (float)($request->gcash_amount ?: 0);
                if (abs(($cashAmount + $gcashAmount) - $collectedAmount) > 0.01) {
                    throw new \Exception("Split amounts must equal the collected deposit amount ₱{$collectedAmount}.");
                }
            }

            $booking->payment_method = $paymentMethod;
            $booking->cash_amount = $cashAmount;
            $booking->gcash_amount = $gcashAmount;
            $booking->gcash_ref = $refNum;
            $booking->is_peak = $pricing['is_peak'];
            
            if ($request->notes) {
                $booking->notes = $request->notes;
            }
            $booking->save();

            // Transaction update or adjustment log
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Booking details edited/updated. Ref: {$booking->booking_ref}. Old Total: ₱{$oldTotal} -> New Total: ₱{$newTotal}",
                'amount' => round($collectedAmount - $oldPaid, 2),
                'payment_method' => $paymentMethod,
                'cash_amount' => round($cashAmount - $oldCash, 2),
                'gcash_amount' => round($gcashAmount - $oldGcash, 2),
                'gcash_ref' => $refNum,
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_UPDATE',
                'bookings',
                $booking->id,
                $oldTotal,
                $newTotal,
                "Updated booking details for {$booking->guest_name} (Ref: {$booking->booking_ref})."
            );

            $redirectRoute = $booking->status === 'reserved' ? 'reservations.index' : 'checkin.index';
            return redirect()->route($redirectRoute)->with('success', "Booking {$booking->booking_ref} updated successfully!");
        });
    }
}
