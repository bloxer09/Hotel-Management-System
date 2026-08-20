<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutBookingRequest;
use App\Http\Requests\ExtendBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\InventoryUsage;
use App\Models\PromoCode;
use App\Models\Room;
use App\Models\Setting;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Services\BookingService;
use App\Services\InventoryChangeRequestService;
use App\Services\InventoryUsageSettlementService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Support\HotelDateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BookingController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly InventoryUsageSettlementService $inventorySettlement
    ) {}

    public function show(Booking $booking, Request $request)
    {
        $booking->load([
            'room',
            'room.type',
            'guestProfile',
            'transactions.processedBy',
            'payments.components',
            'payments.recorder',
            'payments.verifier',
        ]);

        $inventoryUsages = $this->inventorySettlement->usagesForBooking($booking->id)
            ->each(fn (InventoryUsage $usage) => $this->inventorySettlement->decorateStayUsage($usage));

        $inventoryItems = InventoryItem::where('is_active', true)
            ->where('current_stock', '>', 0)
            ->get();

        // Calculate live checkout figures on the hotel stay clock
        $now = HotelDateTime::now();
        $lateHours = BookingService::calculateLateCheckoutHours($booking->expected_check_out, $now);
        $lateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out, $now);

        $inventoryCharges = $this->inventorySettlement->chargesTotal($booking->id);
        $settledInventorySum = $this->inventorySettlement->settledTotal($booking->id);
        $unpaidInventorySum = $booking->status === 'active'
            ? $this->inventorySettlement->unsettledTotal($booking->id)
            : 0.0;

        $additionalDue = $lateFee + $unpaidInventorySum;
        $totalEstimatedBill = $booking->total_amount + $additionalDue;
        $pendingPaymentAmount = round((float) $booking->payments
            ->where('status', 'pending')
            ->sum(fn ($payment) => (float) ($payment->pivot->allocated_amount ?? $payment->amount)), 2);
        $booking->setAttribute('pending_payment_amount', $pendingPaymentAmount);
        $booking->setAttribute(
            'outstanding_verified_balance',
            round(max(0, (float) $booking->total_amount - (float) $booking->amount_paid), 2)
        );

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
                'inventory_charges' => $inventoryCharges,
                'settled_inventory' => $settledInventorySum,
                'unpaid_inventory' => $unpaidInventorySum,
                'additional_due' => $additionalDue,
                'total_estimated' => $totalEstimatedBill,
            ],
        ];

        if ($request->query('json') || (($request->wantsJson() || $request->ajax()) && ! $request->hasHeader('X-Inertia'))) {
            return response()->json($data);
        }

        return Inertia::render('Bookings/Show', $data);
    }

    public function receipt(Booking $booking)
    {
        $booking->load(['room', 'room.type', 'guestProfile', 'transactions.processedBy', 'checkinStaff', 'checkoutStaff', 'inventoryUsages.item']);

        $settings = [
            'vat_enabled' => Setting::getValue('vat_enabled', '0') === '1',
            'vat_percent' => (float) Setting::getValue('vat_percent', '12'),
        ];

        return Inertia::render('Bookings/Receipt', [
            'booking' => $booking,
            'transactions' => $booking->transactions()->orderBy('created_at', 'asc')->orderBy('id', 'asc')->get(),
            'settings' => $settings,
        ]);
    }

    public function extend(Booking $booking, ExtendBookingRequest $request)
    {
        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (! $activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to extend a booking.');
        }

        if ($booking->status !== 'active') {
            return back()->with('error', 'Only active bookings can be extended.');
        }

        try {
            return DB::transaction(function () use ($booking, $request, $user) {
                $room = $booking->room;
                $roomType = $room->type;

                $cost = 0.00;
                $desc = '';
                $expectedOut = HotelDateTime::fromStay($booking->getRawOriginal('expected_check_out'));

                if ($request->hours) {
                    $hours = (int) $request->hours;
                    $cost = round((float) $roomType->hourly_rate * $hours, 2);
                    $expectedOut = $expectedOut->copy()->addHours($hours);
                    $desc = "Extended by {$hours} hour(s) @ ₱{$roomType->hourly_rate}/hr";
                } else {
                    $days = (int) $request->days;
                    $cost = round((float) $roomType->base_rate * $days, 2);
                    $expectedOut = $expectedOut->copy()->addDays($days);
                    $desc = "Extended by {$days} night(s) @ ₱{$roomType->base_rate}/night";
                }

                $newExpectedCheckOut = $expectedOut->format('Y-m-d H:i:s');
                BookingService::rejectOverlappingExtension($booking, $newExpectedCheckOut);

                // Verify payment
                $paymentMethod = $request->payment_method;
                $resolved = $this->payments->resolveComponents(
                    $paymentMethod,
                    $cost,
                    $request->only(['gcash_ref', 'reference_number', 'cash_amount', 'gcash_amount'])
                );
                $refNum = $resolved['ref_number'];
                $cashAmount = $resolved['cash_amount'];
                $gcashAmount = $resolved['gcash_amount'];

                // Update stay charges first; payment is recorded separately below.
                $booking->expected_check_out = $newExpectedCheckOut;
                $booking->extension_fee += $cost;
                $booking->total_amount += $cost;
                $booking->save();

                $paymentStatus = $paymentMethod === 'cash' ? 'verified' : 'pending';
                $payment = $this->payments->record([
                    'payer_name' => $booking->booker_name ?: $booking->guest_name,
                    'payer_contact' => $booking->booker_contact ?: $booking->guest_contact,
                    'payment_method_code' => $paymentMethod,
                    'reference_number' => $refNum,
                    'amount' => $cost,
                    'payment_type' => 'partial',
                    'status' => $paymentStatus,
                    'recorded_by' => $user->id,
                    'remarks' => $request->transaction_notes,
                ], [$booking->id => $cost], $resolved['components'], [
                    'transaction_type' => 'extension',
                    'description' => "Extension fee for Ref: {$booking->booking_ref}. {$desc}",
                ]);

                // Update Guest Profile spending
                if ($paymentStatus === 'verified' && $booking->guestProfile) {
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
                    "Extended Booking {$booking->booking_ref}. Fee: ₱{$cost}. New expected checkout: ".$booking->expected_check_out
                );

                return redirect()->route('bookings.show', $booking->id)->with(
                    'success',
                    "Booking extended. Payment {$payment->receipt_number} recorded as {$payment->status}."
                );
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function addItems(Booking $booking, Request $request)
    {
        $request->validate([
            'item_id' => 'required|exists:inventory_items,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        ShiftService::assertCanChangeTrackedInventory($user);

        if ($booking->status !== 'active') {
            return back()->with('error', 'Can only add inventory items to active bookings.');
        }

        try {
            return DB::transaction(function () use ($booking, $request, $user) {
                $item = InventoryItem::query()
                    ->whereKey($request->item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($item->current_stock < $request->quantity) {
                    throw new \Exception("Insufficient stock for {$item->item_name}. Current stock: {$item->current_stock}");
                }

                // Deduct stock
                $oldStock = (int) $item->current_stock;
                $item->current_stock = $oldStock - (int) $request->quantity;
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

                app(InventoryChangeRequestService::class)->recordExternalMovement(
                    $item,
                    InventoryStockMovement::TYPE_BOOKING_USAGE,
                    -1 * (int) $request->quantity,
                    (int) $oldStock,
                    (int) $item->current_stock,
                    $user->id,
                    'booking',
                    $booking->id,
                    "Deducted {$request->quantity} {$item->unit}(s) of {$item->item_name} for Booking Ref: {$booking->booking_ref}."
                );

                return redirect()->route('bookings.show', $booking->id)->with('success', "Added {$request->quantity} x {$item->item_name} to bill (₱{$totalPrice}).");
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkout(Booking $booking, CheckoutBookingRequest $request)
    {
        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (! $activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to process checkout.');
        }

        if ($booking->status !== 'active') {
            return back()->with('error', 'Booking is already checked out or inactive.');
        }

        try {
            return DB::transaction(function () use ($booking, $request, $user) {
                $now = now();
                $stayNow = HotelDateTime::now();

                $waiveLateFee = (bool) ($request->waive_late_fee ?? false);

                // Calculate late fees
                $lateHours = BookingService::calculateLateCheckoutHours($booking->expected_check_out, $stayNow);
                $originalLateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out, $stayNow);
                $lateFee = $waiveLateFee ? 0.00 : $originalLateFee;

                $inventorySum = $this->inventorySettlement->unsettledTotal($booking->id);

                $extraCharge = (float) ($request->extra_charge_amount ?? 0);
                $separateExtraChargePayment = $extraCharge > 0
                    && (bool) $request->boolean('extra_charge_separate_payment');
                $extraChargePaymentMethod = $request->extra_charge_payment_method;
                $extraChargePaymentReference = $request->extra_charge_payment_reference;

                // Total addition due at checkout
                $additionalDue = $lateFee + $inventorySum + $extraCharge;
                $mainSettlementDue = $lateFee + $inventorySum + ($separateExtraChargePayment ? 0 : $extraCharge);

                // Validate payments
                $paymentMethod = $request->payment_method;
                $resolved = $this->payments->resolveComponents(
                    $paymentMethod,
                    $mainSettlementDue,
                    $request->only(['gcash_ref', 'reference_number', 'cash_amount', 'gcash_amount'])
                );
                $refNum = $resolved['ref_number'];
                $cashAmount = $resolved['cash_amount'];
                $gcashAmount = $resolved['gcash_amount'];

                if ($separateExtraChargePayment && blank($extraChargePaymentMethod)) {
                    throw new \InvalidArgumentException('Select a payment method for the separate extra charge.');
                }
                if ($separateExtraChargePayment && $extraChargePaymentMethod !== 'cash' && blank($extraChargePaymentReference)) {
                    throw new \InvalidArgumentException('A payment reference is required for a non-cash extra charge.');
                }

                // Update booking details
                $booking->check_out = HotelDateTime::toDatabase();
                $booking->late_hours = $lateHours;
                $booking->late_checkout_fee = $lateFee;
                $booking->total_amount += $additionalDue;
                $booking->status = 'checked_out';
                $booking->checked_out_by = $user->id;

                $notes = [];
                if ($request->notes) {
                    $notes[] = trim($request->notes);
                }
                if ($waiveLateFee && $originalLateFee > 0) {
                    $notes[] = 'Late check-out fee of ₱'.number_format($originalLateFee, 2).' waived by '.$user->name.'.';
                }
                if (! empty($notes)) {
                    $booking->notes = trim(($booking->notes ? $booking->notes."\n" : '').implode("\n", $notes));
                }
                $booking->save();

                // Create check out transaction (only if there was additional collection)
                if ($mainSettlementDue > 0) {
                    $descParts = [];
                    if ($lateHours > 0) {
                        $descParts[] = $waiveLateFee ? "Late hours: {$lateHours}h = ₱0.00 [WAIVED]" : "Late hours: {$lateHours}h = ₱{$lateFee}";
                    }
                    if ($inventorySum > 0) {
                        $descParts[] = "Minibar items = ₱{$inventorySum}";
                    }
                    if ($extraCharge > 0 && ! $separateExtraChargePayment) {
                        $descParts[] = "Extra Charges ({$request->extra_charge_description}) = ₱{$extraCharge}";
                    }
                    $descStr = implode(', ', $descParts);
                    $desc = "Checkout settlements ({$descStr}) for Ref: {$booking->booking_ref}";

                    // Checkout is completed only after the front-desk staff has
                    // accepted the settlement, so the receipt is verified here.
                    $payment = $this->payments->record([
                        'payer_name' => $booking->booker_name ?: $booking->guest_name,
                        'payer_contact' => $booking->booker_contact ?: $booking->guest_contact,
                        'payment_method_code' => $paymentMethod,
                        'reference_number' => $refNum,
                        'amount' => $mainSettlementDue,
                        'payment_type' => 'final',
                        'status' => 'verified',
                        'recorded_by' => $user->id,
                        'verified_by' => $user->id,
                        'verified_at' => $now,
                        'remarks' => $request->transaction_notes ?: $request->notes,
                    ], [$booking->id => $mainSettlementDue], $resolved['components'], [
                        'transaction_type' => 'check_out',
                        'description' => $desc,
                    ]);

                    // Link inventory usages to this check_out transaction
                    $checkoutTx = Transaction::where('payment_id', $payment->id)
                        ->where('booking_id', $booking->id)
                        ->first();
                    if ($checkoutTx) {
                        $this->inventorySettlement->attachUnsettledToCheckoutTransaction(
                            $booking->id,
                            (int) $checkoutTx->id
                        );
                    }

                    // Update guest profile total spent
                    if ($booking->guestProfile) {
                        $booking->guestProfile->total_spent += $mainSettlementDue;
                        $booking->guestProfile->save();
                    }
                } else {
                    // Operational checkout trace only — not a collection.
                    // payment_id stays null, amount is 0, method is na.
                    Transaction::create([
                        'booking_id' => $booking->id,
                        'transaction_type' => 'check_out',
                        'description' => "Checkout completed with no outstanding balances. Ref: {$booking->booking_ref}",
                        'amount' => 0.00,
                        'payment_method' => 'na',
                        'cash_amount' => 0.00,
                        'gcash_amount' => 0.00,
                        'processed_by' => $user->id,
                        'notes' => $request->transaction_notes ?: $request->notes,
                    ]);
                }

                if ($separateExtraChargePayment) {
                    $extraChargeDescription = trim((string) $request->extra_charge_description) ?: 'Unspecified extra charge';
                    $extraCashAmount = $extraChargePaymentMethod === 'cash' ? $extraCharge : 0.00;
                    $extraGcashAmount = $extraChargePaymentMethod === 'gcash' ? $extraCharge : 0.00;

                    app(PaymentService::class)->record([
                        'payer_name' => $booking->booker_name ?: $booking->guest_name,
                        'payer_contact' => $booking->booker_contact ?: $booking->guest_contact,
                        'payment_method_code' => $extraChargePaymentMethod,
                        'reference_number' => $extraChargePaymentReference,
                        'amount' => $extraCharge,
                        'payment_type' => 'final',
                        'status' => 'verified',
                        'recorded_by' => $user->id,
                        'verified_by' => $user->id,
                        'verified_at' => $now,
                        'remarks' => "Separate payment for {$extraChargeDescription}",
                    ], [$booking->id => $extraCharge], $this->paymentComponents(
                        $extraChargePaymentMethod,
                        $extraCharge,
                        $extraCashAmount,
                        $extraGcashAmount,
                        $extraChargePaymentReference
                    ), [
                        'transaction_type' => 'adjustment',
                        'description' => "Separate checkout charge (Extra Charges ({$extraChargeDescription}) = P {$extraCharge}) for Ref: {$booking->booking_ref}",
                    ]);

                    if ($booking->guestProfile) {
                        $booking->guestProfile->total_spent += $extraCharge;
                        $booking->guestProfile->save();
                    }
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
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function paymentComponents(
        string $method,
        float $amount,
        float $cashAmount,
        float $gcashAmount,
        ?string $reference
    ): array {
        if ($method === 'split') {
            return [
                ['payment_method_code' => 'cash', 'amount' => $cashAmount],
                ['payment_method_code' => 'gcash', 'amount' => $gcashAmount, 'reference_number' => $reference],
            ];
        }

        return [[
            'payment_method_code' => $method,
            'amount' => $amount,
            'reference_number' => $method === 'cash' ? null : $reference,
        ]];
    }

    public function cancel(Booking $booking, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $user = $request->user();
        ShiftService::assertCanChangeTrackedInventory($user);

        try {
            return DB::transaction(function () use ($booking, $request, $user) {
                $lockedBooking = Booking::query()
                    ->whereKey($booking->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedBooking || $lockedBooking->status !== 'active') {
                    throw new \Exception('Only active bookings can be cancelled.');
                }

                $lockedBooking->status = 'cancelled';
                $lockedBooking->notes = trim($lockedBooking->notes."\nCancellation Reason: ".$request->reason);
                $lockedBooking->save();

                // Revert room status to vacant
                $room = $lockedBooking->room;
                $room->status = 'vacant';
                $room->save();

                // Inventory reversal! We should return items to stock if cancelled
                $usages = InventoryUsage::where('booking_id', $lockedBooking->id)->get();
                $itemIds = $usages->pluck('item_id')->filter()->unique()->sort()->values()->all();
                $lockedItems = $itemIds === []
                    ? collect()
                    : InventoryItem::query()
                        ->whereIn('id', $itemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                foreach ($usages as $usage) {
                    $item = $lockedItems->get($usage->item_id);
                    if (! $item) {
                        continue;
                    }
                    $oldStock = (int) $item->current_stock;
                    $item->current_stock = $oldStock + (int) $usage->quantity;
                    $item->save();

                    BookingService::auditLog(
                        $user->id,
                        'STOCK_INCREASE',
                        'inventory_items',
                        $item->id,
                        $oldStock,
                        $item->current_stock,
                        "Reverted {$usage->quantity} x {$item->item_name} back to stock due to booking cancellation (Ref: {$lockedBooking->booking_ref})."
                    );

                    app(InventoryChangeRequestService::class)->recordExternalMovement(
                        $item,
                        InventoryStockMovement::TYPE_BOOKING_REVERSAL,
                        (int) $usage->quantity,
                        (int) $oldStock,
                        (int) $item->current_stock,
                        $user->id,
                        'booking',
                        $lockedBooking->id,
                        "Reverted {$usage->quantity} x {$item->item_name} due to booking cancellation (Ref: {$lockedBooking->booking_ref})."
                    );
                }

                // Transaction log
                Transaction::create([
                    'booking_id' => $lockedBooking->id,
                    'transaction_type' => 'adjustment',
                    'description' => "Booking cancelled. Ref: {$lockedBooking->booking_ref}. Reason: {$request->reason}",
                    'amount' => 0.00,
                    'payment_method' => 'na',
                    'processed_by' => $user->id,
                ]);

                BookingService::auditLog(
                    $user->id,
                    'BOOKING_CANCEL',
                    'bookings',
                    $lockedBooking->id,
                    'active',
                    'cancelled',
                    "Cancelled Booking {$lockedBooking->booking_ref} for Room {$room->room_number}. Reason: {$request->reason}"
                );

                return redirect()->route('rooms.index')->with('success', "Booking {$lockedBooking->booking_ref} cancelled successfully.");
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function move(Booking $booking, Request $request)
    {
        $request->validate([
            'new_room_id' => 'required|exists:rooms,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (! $activeShift && $user->role !== 'admin') {
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

        try {
            return DB::transaction(function () use ($booking, $newRoomId, $reason, $user) {
                // Load and lock the old room
                $oldRoom = Room::lockForUpdate()->findOrFail($booking->room_id);

                // Load and lock the new room, and verify it is vacant
                $newRoom = Room::lockForUpdate()->findOrFail($newRoomId);
                if ($newRoom->status !== 'vacant') {
                    throw new \Exception('Selected new room is not available.');
                }

                $transferRoomNote = "Moved guest to Room {$newRoom->room_number}".($reason ? " | $reason" : '');
                $transferBookingNote = "[Room Transfer] {$oldRoom->room_number} → {$newRoom->room_number}".($reason ? " | {$reason}" : '');
                $transferTxDesc = "Room change: {$booking->guest_name} from {$oldRoom->room_number} to {$newRoom->room_number}".($reason ? " | {$reason}" : '');

                // Update booking details
                $booking->room_id = $newRoomId;
                $booking->notes = trim($booking->notes."\n".$transferBookingNote);
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
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
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
        if (! $room) {
            return response()->json(['error' => 'Room not found.'], 404);
        }
        $roomType = $room->type;
        if (! $roomType) {
            return response()->json(['error' => 'Room type not found.'], 404);
        }

        $cost = 0.00;
        $expectedOut = HotelDateTime::fromStay($booking->getRawOriginal('expected_check_out'));

        if ($request->hours) {
            $hours = (int) $request->hours;
            if (in_array($hours, [3, 6, 12, 24])) {
                $cost = BookingService::getShortTimeRate($roomType, $hours);
            } else {
                $cost = round((float) $roomType->hourly_rate * $hours, 2);
            }
            $expectedOut = $expectedOut->copy()->addHours($hours);
        } else {
            $days = (int) $request->days;
            $cost = round((float) $roomType->base_rate * $days, 2);
            $expectedOut = $expectedOut->copy()->addDays($days);
        }

        $newExpectedCheckOut = $expectedOut->format('Y-m-d H:i:s');
        BookingService::rejectOverlappingExtension($booking, $newExpectedCheckOut);

        $peakDate = BookingService::isPeakDate($booking->getRawOriginal('expected_check_out'));
        $peakSurcharge = BookingService::calculateSurcharge($peakDate, $cost);
        $totalAmount = round($cost + $peakSurcharge, 2);

        return response()->json([
            'base_amount' => $cost,
            'peak_surcharge' => $peakSurcharge,
            'total_amount' => $totalAmount,
            'new_expected_check_out' => $newExpectedCheckOut,
            'new_expected_check_out_label' => $expectedOut->format('M d, Y h:i A'),
            'is_peak' => $peakDate ? true : false,
            'peak_label' => $peakDate ? $peakDate->label : null,
        ]);
    }

    public function update(Booking $booking, UpdateBookingRequest $request)
    {
        $user = $request->user();
        $room = Room::with('type')->findOrFail($request->room_id);

        $idImagePath = null;
        if ($request->hasFile('id_image')) {
            $idImagePath = $request->file('id_image')->store('id_images', 'public');
        }

        try {
            return DB::transaction(function () use ($booking, $request, $room, $user, $idImagePath) {
                $oldRoomId = $booking->room_id;
                $newRoomId = $room->id;

                $checkInTime = $request->has('check_in')
                    ? HotelDateTime::parseLocal($request->check_in)
                    : HotelDateTime::parseLocal(optional($booking->check_in)->format('Y-m-d H:i:s'));

                $reqDiscountType = $request->discount_type ?: '';
                $reqDiscountAmount = (float) ($request->discount_amount ?: 0);

                if ($request->filled('promo_code')) {
                    $promoCodeModel = PromoCode::where('code', $request->promo_code)->first();
                    if ($promoCodeModel && $promoCodeModel->isValid()) {
                        $reqDiscountType = 'promo';
                        $t = BookingService::calculateBookingAmounts($room, $request->booking_type, $checkInTime->format('Y-m-d H:i:s'), $request->num_nights ?: 1, $request->short_time_hours ?: 3, '', 0, $request->num_guests ?: 1);
                        $roomSubtotal = $t['base_amount'] + $t['peak_surcharge'];
                        if ($promoCodeModel->discount_type === 'percent') {
                            $reqDiscountAmount = round($roomSubtotal * ($promoCodeModel->discount_value / 100), 2);
                        } else {
                            $reqDiscountAmount = min($roomSubtotal, (float) $promoCodeModel->discount_value);
                        }
                    } else {
                        throw new \Exception('The promo code is invalid or expired.');
                    }
                }

                $pricing = BookingService::calculateBookingAmounts(
                    $room,
                    $request->booking_type,
                    $checkInTime->format('Y-m-d H:i:s'),
                    $request->num_nights ?: 1,
                    $request->short_time_hours ?: 3,
                    $reqDiscountType,
                    $reqDiscountAmount,
                    $request->num_guests ?: 1
                );

                // Overlap check if room has changed or time has changed
                if ($newRoomId != $oldRoomId || $checkInTime->format('Y-m-d H:i:s') !== HotelDateTime::toDatabase($booking->check_in)) {
                    $overlap = Booking::where('room_id', $newRoomId)
                        ->where('id', '!=', $booking->id)
                        ->whereIn('status', ['active', 'reserved'])
                        ->where('check_in', '<', $pricing['expected_check_out'])
                        ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                        ->first();

                    if ($overlap) {
                        throw new \Exception("Conflict: Room {$room->room_number} is already booked from {$overlap->check_in} to {$overlap->expected_check_out}.");
                    }
                }

                // If room changes, handle room status swaps
                if ($newRoomId != $oldRoomId) {
                    $oldRoom = Room::findOrFail($oldRoomId);
                    if ($booking->status === 'active') {
                        $oldRoom->status = 'cleaning';
                        $oldRoom->notes = "Room reassigned via Booking Edit. Guest moved to {$room->room_number}";
                        $oldRoom->save();

                        $room->status = 'occupied';
                        $room->save();
                    }
                }

                $oldTotal = $booking->total_amount;
                $newTotal = $pricing['total_amount'];

                // Update booking details
                $booking->room_id = $newRoomId;
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
                $booking->num_nights = $request->booking_type === 'overnight' ? $request->num_nights : null;
                $booking->check_in = HotelDateTime::toDatabase($checkInTime);
                $booking->expected_check_out = $pricing['expected_check_out'];

                $booking->base_amount = $pricing['base_amount'];
                $booking->peak_surcharge = $pricing['peak_surcharge'];
                $booking->extra_pax_charges = $pricing['extra_pax_charges'];
                $booking->discount_type = $reqDiscountType ?: null;
                $booking->discount_amount = $pricing['discount_amount'];
                $booking->total_amount = $newTotal;

                $booking->is_peak = $pricing['is_peak'];

                if ($request->notes) {
                    $booking->notes = $request->notes;
                }
                $booking->save();

                // Booking edits never create, rewrite, or reverse payments. If this
                // booking already uses the ledger, refresh only its compatibility cache.
                if ($booking->paymentAllocations()->exists()) {
                    app(PaymentService::class)->syncBooking($booking);
                } else {
                    // Legacy bookings may only have the compatibility amount cache.
                    // Keep that paid amount intact, but refresh its status against
                    // the newly recalculated booking total.
                    $paid = round(max(0, (float) $booking->amount_paid), 2);
                    $booking->payment_status = $paid <= 0
                        ? 'unpaid'
                        : ($paid + 0.01 >= (float) $booking->total_amount ? 'paid' : 'partial');
                    $booking->save();
                }

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
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
