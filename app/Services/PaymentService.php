<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentComponent;
use App\Models\ShiftSession;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentService
{
    public const METHODS = [
        'cash',
        'gcash',
        'bank_transfer',
        'card',
        'maya',
        'other_ewallet',
        'other',
        'split',
    ];

    public const TYPES = ['deposit', 'partial', 'full', 'final', 'refund', 'reversal'];

    public const STATUSES = ['pending', 'verified', 'rejected', 'voided', 'refunded'];

    public const OUTFLOW_TYPES = ['refund', 'reversal'];

    public function record(
        array $paymentData,
        array $allocations,
        array $components = [],
        array $legacy = []
    ): Payment {
        return DB::transaction(function () use ($paymentData, $allocations, $components, $legacy) {
            $method = (string) ($paymentData['payment_method_code'] ?? '');
            $type = (string) ($paymentData['payment_type'] ?? 'partial');
            $amount = round((float) ($paymentData['amount'] ?? 0), 2);
            $status = (string) ($paymentData['status'] ?? ($method === 'cash' ? 'verified' : 'pending'));

            $this->assertValidPayment($method, $type, $status, $amount, $allocations, $components);
            $this->assertReferenceIsNotDuplicated($method, $paymentData['reference_number'] ?? null);
            foreach ($components as $component) {
                $this->assertComponentReferenceIsNotDuplicated(
                    (string) ($component['payment_method_code'] ?? ''),
                    $component['reference_number'] ?? null
                );
            }

            $recordedBy = $paymentData['recorded_by'] ?? auth()->id();
            $receivedAt = Carbon::parse($paymentData['received_at'] ?? now());
            $shiftId = $paymentData['shift_id'] ?? $this->activeShiftId($recordedBy, $receivedAt);

            if ($status === 'verified') {
                $paymentData['verified_by'] = $paymentData['verified_by'] ?? $recordedBy;
                $paymentData['verified_at'] = $paymentData['verified_at'] ?? $receivedAt;
            }

            $payment = Payment::create(array_merge($paymentData, [
                'receipt_number' => $paymentData['receipt_number'] ?? $this->nextReceiptNumber(),
                'received_at' => $receivedAt,
                'payment_method_code' => $method,
                'payment_type' => $type,
                'amount' => $amount,
                'status' => $status,
                'recorded_by' => $recordedBy,
                'shift_id' => $shiftId,
            ]));

            foreach ($allocations as $bookingId => $allocatedAmount) {
                $payment->allocations()->create([
                    'booking_id' => (int) $bookingId,
                    'allocated_amount' => round((float) $allocatedAmount, 2),
                ]);
            }

            if (empty($components)) {
                $components = [[
                    'payment_method_code' => $method,
                    'amount' => $amount,
                    'reference_number' => $paymentData['reference_number'] ?? null,
                ]];
            }

            foreach ($components as $component) {
                $payment->components()->create([
                    'payment_method_code' => $component['payment_method_code'],
                    'amount' => round((float) $component['amount'], 2),
                    'reference_number' => $component['reference_number'] ?? null,
                ]);
            }

            if ($status === 'verified' && ! ($legacy['skip'] ?? false)) {
                $this->createLegacyTransactions($payment, $legacy);
            }
            if ($status === 'verified' && ! ($legacy['skip_sync'] ?? false)) {
                $this->syncAllocatedBookings($payment);
            }

            BookingService::auditLog(
                $recordedBy,
                'PAYMENT_RECORDED',
                'payments',
                $payment->id,
                null,
                ['receipt_number' => $payment->receipt_number, 'amount' => $amount, 'status' => $status],
                $payment->remarks
            );

            return $payment->load(['allocations.booking', 'components', 'recorder', 'verifier']);
        });
    }

    public function verify(Payment $payment, int $verifiedBy, ?string $reference = null): Payment
    {
        return DB::transaction(function () use ($payment, $verifiedBy, $reference) {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException('Only pending payments can be verified.');
            }
            $reference = trim((string) ($reference ?: $locked->reference_number));
            if ($locked->payment_method_code !== 'cash' && $reference === '') {
                throw new InvalidArgumentException('A payment reference is required before verification.');
            }
            if ($reference !== (string) $locked->reference_number) {
                $this->assertReferenceIsNotDuplicated($locked->payment_method_code, $reference);
                foreach ($locked->components()->where('payment_method_code', '!=', 'cash')->get() as $component) {
                    $this->assertComponentReferenceIsNotDuplicated($component->payment_method_code, $reference);
                }
                $locked->reference_number = $reference;
                $locked->components()
                    ->where('payment_method_code', '!=', 'cash')
                    ->whereNull('reference_number')
                    ->update(['reference_number' => $reference]);
            }

            $locked->fill([
                'status' => 'verified',
                'verified_by' => $verifiedBy,
                'verified_at' => now(),
            ]);
            $locked->save();

            $this->createLegacyTransactions($locked, [
                'transaction_type' => $this->legacyTypeFor($locked),
                'description' => "Verified payment {$locked->receipt_number}",
            ]);
            $this->syncAllocatedBookings($locked);

            BookingService::auditLog(
                $verifiedBy,
                'PAYMENT_VERIFIED',
                'payments',
                $locked->id,
                'pending',
                'verified',
                "Verified payment {$locked->receipt_number}."
            );

            return $locked->fresh(['allocations.booking', 'components', 'recorder', 'verifier']);
        });
    }

    public function reject(Payment $payment, int $rejectedBy, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $rejectedBy, $reason) {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException('Only pending payments can be rejected.');
            }

            $locked->update([
                'status' => 'rejected',
                'verified_by' => $rejectedBy,
                'verified_at' => now(),
                'remarks' => trim(($locked->remarks ? $locked->remarks."\n" : '')."Rejected: {$reason}"),
            ]);
            $this->syncAllocatedBookings($locked);

            BookingService::auditLog(
                $rejectedBy,
                'PAYMENT_REJECTED',
                'payments',
                $locked->id,
                'pending',
                'rejected',
                $reason
            );

            return $locked->fresh(['allocations.booking', 'components', 'recorder', 'verifier']);
        });
    }

    public function refund(
        Payment $original,
        float $amount,
        int $recordedBy,
        string $reason,
        ?string $method = null,
        ?string $reference = null,
        ?int $bookingId = null
    ): Payment {
        $original->loadMissing(['allocations', 'components']);
        if ($original->status !== 'verified' || in_array($original->payment_type, self::OUTFLOW_TYPES, true)) {
            throw new InvalidArgumentException('Only verified incoming payments can be refunded.');
        }

        if ($bookingId) {
            $originalAllocation = $original->allocations->firstWhere('booking_id', $bookingId);
            if (! $originalAllocation) {
                throw new InvalidArgumentException('The selected payment is not allocated to this booking.');
            }
            $alreadyRefunded = (float) DB::table('payments as p')
                ->join('payment_allocations as pa', 'pa.payment_id', '=', 'p.id')
                ->where('p.original_payment_id', $original->id)
                ->where('p.status', 'verified')
                ->whereIn('p.payment_type', self::OUTFLOW_TYPES)
                ->where('pa.booking_id', $bookingId)
                ->sum('pa.allocated_amount');
            $refundable = round((float) $originalAllocation->allocated_amount - $alreadyRefunded, 2);
        } else {
            $alreadyRefunded = (float) Payment::where('original_payment_id', $original->id)
                ->where('status', 'verified')
                ->whereIn('payment_type', self::OUTFLOW_TYPES)
                ->sum('amount');
            $refundable = round((float) $original->amount - $alreadyRefunded, 2);
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $refundable) {
            throw new InvalidArgumentException("Refund must be greater than zero and no more than {$refundable}.");
        }

        $allocations = $bookingId ? [$bookingId => $amount] : [];
        if (! $bookingId) {
            $remaining = $amount;
            $rows = $original->allocations->values();
            foreach ($rows as $index => $allocation) {
                $allocated = $index === $rows->count() - 1
                    ? $remaining
                    : round($amount * ((float) $allocation->allocated_amount / (float) $original->amount), 2);
                $allocations[$allocation->booking_id] = $allocated;
                $remaining = round($remaining - $allocated, 2);
            }
        }

        $refundMethod = $method ?: $original->payment_method_code;
        $status = $refundMethod === 'cash' ? 'verified' : 'pending';
        $components = [];
        if ($refundMethod === 'split') {
            $remaining = $amount;
            $originalComponents = $original->components->values();
            foreach ($originalComponents as $index => $component) {
                $componentAmount = $index === $originalComponents->count() - 1
                    ? $remaining
                    : round($amount * ((float) $component->amount / (float) $original->amount), 2);
                if ($componentAmount > 0) {
                    $components[] = [
                        'payment_method_code' => $component->payment_method_code,
                        'amount' => $componentAmount,
                        'reference_number' => $reference,
                    ];
                }
                $remaining = round($remaining - $componentAmount, 2);
            }
        }

        return $this->record([
            'payer_name' => $original->payer_name,
            'payer_contact' => $original->payer_contact,
            'payment_method_code' => $refundMethod,
            'reference_number' => $reference,
            'amount' => $amount,
            'payment_type' => 'refund',
            'status' => $status,
            'recorded_by' => $recordedBy,
            'original_payment_id' => $original->id,
            'remarks' => "Refund of {$original->receipt_number}: {$reason}",
        ], $allocations, $components, [
            'transaction_type' => 'adjustment',
            'description' => "Refund for {$original->receipt_number}: {$reason}",
        ]);
    }

    public function syncBooking(Booking|int $booking): Booking
    {
        $booking = $booking instanceof Booking ? $booking : Booking::findOrFail($booking);

        $inflows = (float) DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.booking_id', $booking->id)
            ->where('p.status', 'verified')
            ->whereNotIn('p.payment_type', self::OUTFLOW_TYPES)
            ->sum('pa.allocated_amount');

        $outflows = (float) DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.booking_id', $booking->id)
            ->where('p.status', 'verified')
            ->whereIn('p.payment_type', self::OUTFLOW_TYPES)
            ->sum('pa.allocated_amount');

        // During a phased rollout, older transactions may not yet be backfilled.
        // Keep those unlinked receipts in the compatibility cache so recording a
        // new ledger payment can never erase an older paid balance.
        $legacyUnlinked = (float) $booking->transactions()
            ->whereNull('payment_id')
            ->where('amount', '>', 0)
            ->whereNotIn('payment_method', ['na', ''])
            ->sum('amount');

        $netPaid = round(max(0, $legacyUnlinked + $inflows - $outflows), 2);
        $booking->amount_paid = $netPaid;
        $booking->payment_status = $netPaid <= 0
            ? 'unpaid'
            : ($netPaid + 0.01 >= (float) $booking->total_amount ? 'paid' : 'partial');

        $channelTotals = DB::table('payment_components as pc')
            ->join('payments as p', 'p.id', '=', 'pc.payment_id')
            ->join('payment_allocations as pa', 'pa.payment_id', '=', 'p.id')
            ->where('pa.booking_id', $booking->id)
            ->where('p.status', 'verified')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN pc.payment_method_code='cash' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount * (pa.allocated_amount / p.amount) ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN pc.payment_method_code='cash' AND p.payment_type IN ('refund','reversal') THEN pc.amount * (pa.allocated_amount / p.amount) ELSE 0 END),0) AS cash_total,
                COALESCE(SUM(CASE WHEN pc.payment_method_code='gcash' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount * (pa.allocated_amount / p.amount) ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN pc.payment_method_code='gcash' AND p.payment_type IN ('refund','reversal') THEN pc.amount * (pa.allocated_amount / p.amount) ELSE 0 END),0) AS gcash_total
            ")
            ->first();

        $legacyCash = (float) $booking->transactions()
            ->whereNull('payment_id')
            ->where('amount', '>', 0)
            ->sum('cash_amount');
        $legacyGcash = (float) $booking->transactions()
            ->whereNull('payment_id')
            ->where('amount', '>', 0)
            ->sum('gcash_amount');

        $booking->cash_amount = round(max(0, $legacyCash + (float) ($channelTotals->cash_total ?? 0)), 2);
        $booking->gcash_amount = round(max(0, $legacyGcash + (float) ($channelTotals->gcash_total ?? 0)), 2);

        $latest = Payment::whereHas('allocations', fn ($q) => $q->where('booking_id', $booking->id))
            ->where('status', 'verified')
            ->whereNotIn('payment_type', self::OUTFLOW_TYPES)
            ->latest('received_at')
            ->first();
        if ($latest) {
            $booking->payment_method = in_array($latest->payment_method_code, ['cash', 'gcash', 'card', 'bank_transfer', 'split'], true)
                ? $latest->payment_method_code
                : 'bank_transfer';
            $booking->gcash_ref = $latest->payment_method_code === 'gcash' ? $latest->reference_number : null;
        }

        $booking->save();

        return $booking;
    }

    public function syncAllocatedBookings(Payment $payment): void
    {
        $payment->loadMissing('allocations');
        foreach ($payment->allocations->pluck('booking_id')->unique() as $bookingId) {
            $this->syncBooking((int) $bookingId);
        }
    }

    private function assertValidPayment(
        string $method,
        string $type,
        string $status,
        float $amount,
        array $allocations,
        array $components
    ): void {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Unsupported payment method.');
        }
        if (! in_array($type, self::TYPES, true) || ! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported payment type or status.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }
        if (empty($allocations) || abs(array_sum($allocations) - $amount) > 0.01) {
            throw new InvalidArgumentException('Payment allocations must equal the payment amount.');
        }
        if ($method === 'split') {
            if (count($components) < 2 || abs(array_sum(array_column($components, 'amount')) - $amount) > 0.01) {
                throw new InvalidArgumentException('Split payment components must equal the payment amount.');
            }
        }
    }

    private function assertReferenceIsNotDuplicated(string $method, ?string $reference): void
    {
        $reference = trim((string) $reference);
        if ($method === 'cash' || $reference === '') {
            return;
        }

        if (Payment::whereNotIn('status', ['rejected', 'voided'])
            ->where(function ($q) use ($method, $reference) {
                $q->where(function ($direct) use ($method, $reference) {
                    $direct->where('payment_method_code', $method)
                        ->where('reference_number', $reference);
                })->orWhereHas('components', fn ($component) => $component
                    ->where('payment_method_code', $method)
                    ->where('reference_number', $reference));
            })
            ->exists()) {
            throw new InvalidArgumentException('This payment reference has already been recorded.');
        }
    }

    private function assertComponentReferenceIsNotDuplicated(string $method, ?string $reference): void
    {
        $reference = trim((string) $reference);
        if ($method === 'cash' || $reference === '') {
            return;
        }

        if (PaymentComponent::where('payment_method_code', $method)
            ->where('reference_number', $reference)
            ->whereHas('payment', fn ($q) => $q->whereNotIn('status', ['rejected', 'voided']))
            ->exists()) {
            throw new InvalidArgumentException('This payment component reference has already been recorded.');
        }
    }

    private function activeShiftId(?int $userId, Carbon $receivedAt): ?int
    {
        if (! $userId) {
            return null;
        }

        return ShiftSession::where('user_id', $userId)
            ->where('started_at', '<=', $receivedAt)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $receivedAt))
            ->value('id');
    }

    private function nextReceiptNumber(): string
    {
        do {
            $receipt = 'PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Payment::where('receipt_number', $receipt)->exists());

        return $receipt;
    }

    private function createLegacyTransactions(Payment $payment, array $legacy): void
    {
        if (Transaction::where('payment_id', $payment->id)->exists()) {
            return;
        }

        $payment->loadMissing(['allocations', 'components']);
        $direction = in_array($payment->payment_type, self::OUTFLOW_TYPES, true) ? -1 : 1;
        foreach ($payment->allocations as $allocation) {
            $ratio = (float) $allocation->allocated_amount / (float) $payment->amount;
            $cash = $this->componentAmount($payment, 'cash') * $ratio * $direction;
            $gcash = $this->componentAmount($payment, 'gcash') * $ratio * $direction;
            $bank = (
                $this->componentAmount($payment, 'bank_transfer')
                + $this->componentAmount($payment, 'card')
                + $this->componentAmount($payment, 'maya')
                + $this->componentAmount($payment, 'other_ewallet')
                + $this->componentAmount($payment, 'other')
            ) * $ratio * $direction;

            $legacyMethod = in_array($payment->payment_method_code, ['cash', 'gcash', 'card', 'bank_transfer', 'split'], true)
                ? $payment->payment_method_code
                : 'na';

            Transaction::create([
                'payment_id' => $payment->id,
                'booking_id' => $allocation->booking_id,
                'transaction_type' => $legacy['transaction_type'] ?? $this->legacyTypeFor($payment),
                'description' => $legacy['description'] ?? "Payment {$payment->receipt_number}",
                'amount' => round((float) $allocation->allocated_amount * $direction, 2),
                'payment_method' => $legacyMethod,
                'cash_amount' => round($cash, 2),
                'gcash_amount' => round($gcash, 2),
                'bank_amount' => round($bank, 2),
                'gcash_ref' => $payment->payment_method_code === 'gcash' ? $payment->reference_number : null,
                'bank_ref' => in_array($payment->payment_method_code, ['bank_transfer', 'card'], true) ? $payment->reference_number : null,
                'processed_by' => $payment->recorded_by,
                'notes' => $payment->remarks,
            ]);
        }
    }

    private function componentAmount(Payment $payment, string $method): float
    {
        return (float) $payment->components
            ->where('payment_method_code', $method)
            ->sum('amount');
    }

    private function legacyTypeFor(Payment $payment): string
    {
        if (in_array($payment->payment_type, self::OUTFLOW_TYPES, true)) {
            return 'adjustment';
        }

        return $payment->payment_type === 'deposit' ? 'check_in' : 'adjustment';
    }
}
