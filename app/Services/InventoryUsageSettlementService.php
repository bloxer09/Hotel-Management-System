<?php

namespace App\Services;

use App\Models\InventoryUsage;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class InventoryUsageSettlementService
{
    public const TENDER_TOLERANCE = 0.01;

    /**
     * Commercial usages on a stay that have not yet been financially settled
     * and therefore belong in checkout inventory due.
     *
     * @return Collection<int, InventoryUsage>
     */
    public function unsettledForCheckout(int $bookingId): Collection
    {
        return $this->usagesForBooking($bookingId)
            ->filter(fn (InventoryUsage $usage) => ! $this->isSettled($usage))
            ->values();
    }

    public function unsettledTotal(int $bookingId): float
    {
        return round((float) $this->unsettledForCheckout($bookingId)->sum('total_price'), 2);
    }

    public function settledTotal(int $bookingId): float
    {
        return round((float) $this->usagesForBooking($bookingId)
            ->filter(fn (InventoryUsage $usage) => $this->isSettled($usage))
            ->sum('total_price'), 2);
    }

    public function chargesTotal(int $bookingId): float
    {
        return round((float) $this->usagesForBooking($bookingId)->sum('total_price'), 2);
    }

    /**
     * @return Collection<int, InventoryUsage>
     */
    public function usagesForBooking(int $bookingId): Collection
    {
        return InventoryUsage::query()
            ->with(['transaction.payment', 'originTransaction.processedBy', 'item'])
            ->where('booking_id', $bookingId)
            ->orderBy('id')
            ->get();
    }

    public function isSettled(InventoryUsage $usage): bool
    {
        $transaction = $usage->relationLoaded('transaction')
            ? $usage->transaction
            : $usage->transaction()->with('payment')->first();

        if (! $transaction) {
            return false;
        }

        if ($transaction->transaction_type === 'pos_sale') {
            return $this->posSaleHasCollectionBearingTender($transaction);
        }

        if ($transaction->transaction_type === 'check_out') {
            return $this->checkOutIsSettled($transaction);
        }

        return false;
    }

    /**
     * Point currently unsettled stay usages at a collection-bearing checkout
     * settlement. Origin POS transaction_id is preserved separately.
     */
    public function attachUnsettledToCheckoutTransaction(int $bookingId, int $checkoutTransactionId): int
    {
        $checkout = Transaction::query()
            ->with('payment')
            ->whereKey($checkoutTransactionId)
            ->first();

        if (! $checkout || ! $this->isModernCollectionBearingCheckout($checkout)) {
            throw new InvalidArgumentException(
                'Inventory usages can only be attached to a check-out transaction with a verified payment.'
            );
        }

        $unsettled = $this->unsettledForCheckout($bookingId);
        $updated = 0;

        foreach ($unsettled as $usage) {
            $originId = $usage->origin_transaction_id;
            if (! $originId && $usage->transaction?->transaction_type === 'pos_sale') {
                $originId = $usage->transaction_id;
            }

            $usage->origin_transaction_id = $originId;
            $usage->transaction_id = $checkoutTransactionId;
            $usage->save();
            $updated++;
        }

        return $updated;
    }

    public function posSaleHasCollectionBearingTender(Transaction $transaction): bool
    {
        $collected = $this->collectedTender($transaction);

        return ($collected + self::TENDER_TOLERANCE) >= round((float) $transaction->amount, 2);
    }

    public function collectedTender(Transaction $transaction): float
    {
        return round(
            (float) $transaction->cash_amount
            + (float) $transaction->gcash_amount
            + (float) $transaction->bank_amount,
            2
        );
    }

    public function decorateStayUsage(InventoryUsage $usage): InventoryUsage
    {
        $usage->setAttribute('is_settled', $this->isSettled($usage));
        $origin = $usage->originTransaction ?: (
            $usage->transaction?->transaction_type === 'pos_sale' ? $usage->transaction : null
        );
        $usage->setAttribute('origin_transaction_id', $origin?->id ?? $usage->origin_transaction_id);
        $usage->setAttribute('origin_transaction_type', $origin?->transaction_type);
        $usage->setAttribute('origin_or_number', $origin?->formatted_or_number);
        $usage->setAttribute('origin_recorded_at', $origin?->created_at);
        $usage->setAttribute('origin_processed_by', $origin?->processedBy?->full_name);

        return $usage;
    }

    /**
     * Modern collecting check-outs are created only through PaymentService and
     * always carry a verified payment_id. Amount-0 / method-na rows are
     * operational traces, not collections.
     */
    public function isModernCollectionBearingCheckout(Transaction $transaction): bool
    {
        if ($transaction->transaction_type !== 'check_out' || ! $transaction->payment_id) {
            return false;
        }

        $payment = $transaction->relationLoaded('payment')
            ? $transaction->payment
            : $transaction->payment()->first();

        return $payment && $payment->status === 'verified';
    }

    /**
     * Pre-ledger check_out rows that still represent a real collection.
     * Matches payments:backfill-ledger: amount > 0, method not na, no payment_id.
     */
    public function isLegacyPreLedgerCheckoutCollection(Transaction $transaction): bool
    {
        if ($transaction->transaction_type !== 'check_out' || $transaction->payment_id) {
            return false;
        }

        $method = strtolower(trim((string) $transaction->payment_method));

        return round((float) $transaction->amount, 2) > self::TENDER_TOLERANCE
            && $method !== ''
            && $method !== 'na';
    }

    private function checkOutIsSettled(Transaction $transaction): bool
    {
        if ($this->isModernCollectionBearingCheckout($transaction)) {
            return true;
        }

        return $this->isLegacyPreLedgerCheckoutCollection($transaction);
    }
}
