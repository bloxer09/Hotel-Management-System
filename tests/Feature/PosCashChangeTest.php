<?php

namespace Tests\Feature;

use App\Http\Controllers\ShiftController;
use App\Models\InventoryItem;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCashChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_walk_in_cash_sale_records_net_cash_after_change(): void
    {
        $cashier = User::create([
            'username' => 'pos_cashier',
            'password' => bcrypt('password'),
            'full_name' => 'POS Cashier',
            'role' => 'front_desk',
            'is_active' => true,
        ]);
        $shift = ShiftSession::create([
            'user_id' => $cashier->id,
            'shift_code' => 'morning',
            'started_at' => now()->subMinute(),
            'opening_cash' => 500,
            'opening_cash_minibar' => 500,
        ]);
        $item = InventoryItem::create([
            'item_name' => 'Walk-in Combo',
            'category' => 'minibar',
            'unit' => 'set',
            'current_stock' => 10,
            'minimum_stock' => 1,
            'unit_cost' => 80,
            'selling_price' => 142,
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashier)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In Guest',
            'items' => [[
                'item_id' => $item->id,
                'quantity' => 1,
            ]],
            'payment_method' => 'cash',
            'cash_amount' => 150,
        ]);

        $response->assertSessionHasNoErrors();
        $transaction = Transaction::where('transaction_type', 'pos_sale')->firstOrFail();
        $this->assertEquals(142.0, $transaction->amount);
        $this->assertEquals(142.0, $transaction->cash_amount);
        $this->assertStringContainsString('Cash tendered: ₱150.00', $transaction->notes);
        $this->assertStringContainsString('Change given: ₱8.00', $transaction->notes);

        $sales = app(ShiftController::class)->getShiftSalesSummary(
            $cashier->id,
            $shift->started_at,
            now()
        );
        $this->assertEquals(142.0, $sales['minibar_cash']);
        $this->assertEquals(642.0, $shift->opening_cash_minibar + $sales['minibar_cash']);
    }
}
