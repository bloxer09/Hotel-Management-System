<?php

namespace Tests\Unit;

use App\Models\InventoryUsage;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\InventoryUsageSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryUsageSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryUsageSettlementService $settlement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settlement = app(InventoryUsageSettlementService::class);
    }

    public function test_null_transaction_is_unsettled(): void
    {
        $usage = new InventoryUsage(['transaction_id' => null, 'total_price' => 20]);
        $usage->setRelation('transaction', null);

        $this->assertFalse($this->settlement->isSettled($usage));
    }

    public function test_pos_sale_is_settled_only_when_tender_covers_amount(): void
    {
        $paid = $this->posUsage(30, 30, 0, 0);
        $zero = $this->posUsage(30, 0, 0, 0);
        $short = $this->posUsage(30, 10, 0, 0);

        $this->assertTrue($this->settlement->isSettled($paid));
        $this->assertFalse($this->settlement->isSettled($zero));
        $this->assertFalse($this->settlement->isSettled($short));
    }

    public function test_pos_gcash_tender_on_the_transaction_is_collection_bearing(): void
    {
        $usage = $this->posUsage(35, 0, 35, 0);

        $this->assertTrue($this->settlement->isSettled($usage));
    }

    public function test_check_out_with_verified_payment_is_settled(): void
    {
        $usage = $this->checkoutUsage('verified');

        $this->assertTrue($this->settlement->isSettled($usage));
        $this->assertTrue($this->settlement->isModernCollectionBearingCheckout($usage->transaction));
    }

    public function test_check_out_with_pending_payment_is_unsettled(): void
    {
        $usage = $this->checkoutUsage('pending');

        $this->assertFalse($this->settlement->isSettled($usage));
    }

    public function test_modern_zero_amount_check_out_without_payment_is_not_settled(): void
    {
        $transaction = new Transaction([
            'transaction_type' => 'check_out',
            'amount' => 0,
            'payment_method' => 'na',
            'cash_amount' => 0,
        ]);
        $transaction->payment_id = null;
        $transaction->setRelation('payment', null);

        $usage = new InventoryUsage(['total_price' => 20]);
        $usage->setRelation('transaction', $transaction);

        $this->assertFalse($this->settlement->isSettled($usage));
        $this->assertFalse($this->settlement->isLegacyPreLedgerCheckoutCollection($transaction));
    }

    public function test_legacy_check_out_without_payment_id_remains_settled(): void
    {
        $transaction = new Transaction([
            'transaction_type' => 'check_out',
            'amount' => 20,
            'payment_method' => 'cash',
            'cash_amount' => 20,
        ]);
        $transaction->payment_id = null;
        $transaction->setRelation('payment', null);

        $usage = new InventoryUsage(['total_price' => 20]);
        $usage->setRelation('transaction', $transaction);

        $this->assertTrue($this->settlement->isLegacyPreLedgerCheckoutCollection($transaction));
        $this->assertTrue($this->settlement->isSettled($usage));
    }

    public function test_check_out_with_payment_id_but_missing_payment_row_is_unsettled(): void
    {
        $transaction = new Transaction([
            'transaction_type' => 'check_out',
            'amount' => 20,
            'payment_method' => 'cash',
            'cash_amount' => 20,
        ]);
        $transaction->payment_id = 99;
        $transaction->setRelation('payment', null);

        $usage = new InventoryUsage(['total_price' => 20]);
        $usage->setRelation('transaction', $transaction);

        $this->assertFalse($this->settlement->isModernCollectionBearingCheckout($transaction));
        $this->assertFalse($this->settlement->isLegacyPreLedgerCheckoutCollection($transaction));
        $this->assertFalse($this->settlement->isSettled($usage));
    }

    private function posUsage(float $amount, float $cash, float $gcash, float $bank): InventoryUsage
    {
        $transaction = new Transaction([
            'transaction_type' => 'pos_sale',
            'amount' => $amount,
            'cash_amount' => $cash,
            'gcash_amount' => $gcash,
            'bank_amount' => $bank,
        ]);
        $usage = new InventoryUsage(['total_price' => $amount]);
        $usage->setRelation('transaction', $transaction);

        return $usage;
    }

    private function checkoutUsage(string $paymentStatus): InventoryUsage
    {
        $payment = new Payment(['status' => $paymentStatus]);
        $transaction = new Transaction([
            'transaction_type' => 'check_out',
            'amount' => 20,
            'cash_amount' => 20,
        ]);
        $transaction->payment_id = 1;
        $transaction->setRelation('payment', $payment);

        $usage = new InventoryUsage(['total_price' => 20]);
        $usage->setRelation('transaction', $transaction);

        return $usage;
    }
}
