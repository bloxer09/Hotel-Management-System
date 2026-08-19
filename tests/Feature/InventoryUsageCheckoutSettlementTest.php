<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\InventoryUsage;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryUsageSettlementService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryUsageCheckoutSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private InventoryUsageSettlementService $settlement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = User::factory()->create([
            'role' => 'front_desk',
            'full_name' => 'Maria Santos',
        ]);
        ShiftSession::create([
            'user_id' => $this->desk->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);
        $this->settlement = app(InventoryUsageSettlementService::class);
    }

    public function test_a_paid_walk_in_pos_sale_never_appears_on_a_stay_checkout(): void
    {
        $booking = $this->activeStay();
        $coke = $this->item('Coke', 30);

        $this->actingAs($this->desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame(0, InventoryUsage::where('booking_id', $booking->id)->count());
        $this->assertSame(30.0, (float) InventoryUsage::whereNull('booking_id')->sum('total_price'));

        $show = $this->stayJson($booking);
        $this->assertEquals(0, $show['calculations']['unpaid_inventory']);
        $this->assertEquals(0, $show['calculations']['inventory_charges']);
    }

    public function test_room_linked_pos_with_full_tender_is_not_billed_again_at_checkout(): void
    {
        $booking = $this->activeStay();
        $coke = $this->item('Coke', 30);
        $stockBefore = (int) $coke->current_stock;
        $movementsBefore = InventoryStockMovement::count();

        $this->roomPos($booking, $coke, 1, 30)->assertSessionHasNoErrors();

        $usage = InventoryUsage::where('booking_id', $booking->id)->firstOrFail();
        $posId = (int) $usage->transaction_id;
        $this->assertTrue($usage->isSettled());
        $this->assertSame($posId, (int) $usage->origin_transaction_id);
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));

        $this->checkoutStay($booking, 0)->assertRedirect(route('rooms.index'));

        $usage->refresh()->load(['transaction.payment', 'originTransaction.processedBy']);
        $this->assertSame($stockBefore - 1, (int) $coke->fresh()->current_stock);
        $this->assertSame($movementsBefore + 1, InventoryStockMovement::count());
        $this->assertSame($posId, (int) $usage->transaction_id);
        $this->assertSame($posId, (int) $usage->origin_transaction_id);
        $this->assertSame('pos_sale', $usage->transaction->transaction_type);
        $this->assertSame('pos_sale', $usage->originTransaction->transaction_type);
        $this->assertSame($this->desk->id, (int) $usage->originTransaction->processed_by);
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));

        $line = collect($this->stayJson($booking->fresh())['inventoryUsages'])->first();
        $this->assertTrue($line['is_settled']);
        $this->assertSame($posId, (int) $line['origin_transaction_id']);
        $this->assertSame('pos_sale', $line['origin_transaction_type']);
        $this->assertSame('Maria Santos', $line['origin_processed_by']);
        $this->assertNotEmpty($line['origin_or_number']);
    }

    public function test_room_linked_pos_with_zero_tender_is_billed_once_at_checkout(): void
    {
        $booking = $this->activeStay();
        $coke = $this->item('Coke', 30);
        $stockAfterPos = null;

        $this->roomPos($booking, $coke, 1, 0)->assertSessionHasNoErrors();
        $stockAfterPos = (int) $coke->fresh()->current_stock;
        $movementsAfterPos = InventoryStockMovement::count();

        $usage = InventoryUsage::where('booking_id', $booking->id)->firstOrFail();
        $posId = (int) $usage->transaction_id;
        $posCreatedAt = $usage->transaction->created_at->toDateTimeString();
        $this->assertFalse($usage->isSettled());
        $this->assertSame($posId, (int) $usage->origin_transaction_id);
        $this->assertSame('pos_sale', $usage->transaction->transaction_type);
        $this->assertSame(30.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertEquals(30, $this->stayJson($booking)['calculations']['unpaid_inventory']);

        $this->checkoutStay($booking, 30)->assertRedirect(route('rooms.index'));

        $usage->refresh()->load(['transaction.payment', 'originTransaction.processedBy']);
        $this->assertTrue($this->settlement->isSettled($usage));
        $this->assertSame('check_out', $usage->transaction->transaction_type);
        $this->assertNotEquals($posId, (int) $usage->transaction_id);
        $this->assertSame($posId, (int) $usage->origin_transaction_id);
        $this->assertSame('pos_sale', $usage->originTransaction->transaction_type);
        $this->assertSame($this->desk->id, (int) $usage->originTransaction->processed_by);
        $this->assertSame($posCreatedAt, $usage->originTransaction->created_at->toDateTimeString());
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame($stockAfterPos, (int) $coke->fresh()->current_stock);
        $this->assertSame($movementsAfterPos, InventoryStockMovement::count());
        $this->assertSame(1, InventoryUsage::where('booking_id', $booking->id)->count());

        $line = collect($this->stayJson($booking->fresh())['inventoryUsages'])->first();
        $this->assertTrue($line['is_settled']);
        $this->assertSame($posId, (int) $line['origin_transaction_id']);
        $this->assertSame('pos_sale', $line['origin_transaction_type']);
        $this->assertSame('Maria Santos', $line['origin_processed_by']);
        $this->assertNotEmpty($line['origin_or_number']);
        $this->assertNotEmpty($line['origin_recorded_at']);
    }

    public function test_room_linked_partial_pos_tender_is_rejected_and_does_not_hide_a_remainder(): void
    {
        $booking = $this->activeStay();
        $coke = $this->item('Coke', 30);

        $this->actingAs($this->desk)->from(route('pos.index'))->post(route('pos.checkout'), [
            'booking_id' => $booking->id,
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 10,
        ])->assertSessionHas('error');

        $this->assertSame(10, (int) $coke->fresh()->current_stock);
        $this->assertSame(0, InventoryUsage::count());
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
    }

    public function test_booking_add_item_is_due_then_settled_once_at_checkout(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);
        $stockBefore = (int) $water->current_stock;

        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $usage = InventoryUsage::where('booking_id', $booking->id)->firstOrFail();
        $this->assertNull($usage->transaction_id);
        $this->assertSame(20.0, $this->settlement->unsettledTotal($booking->id));

        $movementsBeforeCheckout = InventoryStockMovement::count();
        $this->checkoutStay($booking, 20)->assertRedirect(route('rooms.index'));

        $usage->refresh();
        $this->assertNotNull($usage->transaction_id);
        $this->assertNull($usage->origin_transaction_id);
        $this->assertSame('check_out', $usage->transaction->transaction_type);
        $this->assertNotNull($usage->transaction->payment_id);
        $this->assertSame('verified', $usage->transaction->payment->status);
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame($stockBefore - 1, (int) $water->fresh()->current_stock);
        $this->assertSame($movementsBeforeCheckout, InventoryStockMovement::count());
    }

    public function test_mixed_paid_pos_and_unpaid_add_item_bills_only_the_add_item(): void
    {
        $booking = $this->activeStay();
        $coke = $this->item('Coke', 30);
        $water = $this->item('Water', 20);
        $cokeStock = (int) $coke->current_stock;
        $waterStock = (int) $water->current_stock;

        $this->roomPos($booking, $coke, 1, 30)->assertSessionHasNoErrors();
        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $show = $this->stayJson($booking);
        $this->assertEquals(50, $show['calculations']['inventory_charges']);
        $this->assertEquals(30, $show['calculations']['settled_inventory']);
        $this->assertEquals(20, $show['calculations']['unpaid_inventory']);
        $this->assertEquals(20, $show['calculations']['additional_due']);

        $movementsBeforeCheckout = InventoryStockMovement::count();
        $this->checkoutStay($booking, 20)->assertRedirect(route('rooms.index'));

        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame($cokeStock - 1, (int) $coke->fresh()->current_stock);
        $this->assertSame($waterStock - 1, (int) $water->fresh()->current_stock);
        $this->assertSame($movementsBeforeCheckout, InventoryStockMovement::count());

        $cokeUsage = InventoryUsage::where('item_id', $coke->id)->firstOrFail()->load(['transaction', 'originTransaction']);
        $waterUsage = InventoryUsage::where('item_id', $water->id)->firstOrFail()->load(['transaction', 'originTransaction']);
        $this->assertSame('pos_sale', $cokeUsage->transaction->transaction_type);
        $this->assertSame((int) $cokeUsage->transaction_id, (int) $cokeUsage->origin_transaction_id);
        $this->assertSame('check_out', $waterUsage->transaction->transaction_type);
        $this->assertNull($waterUsage->origin_transaction_id);
    }

    public function test_multiple_usages_on_one_paid_pos_transaction_are_all_settled(): void
    {
        $booking = $this->activeStay();
        $coke = $this->item('Coke', 30);
        $water = $this->item('Water', 20);

        $this->actingAs($this->desk)->post(route('pos.checkout'), [
            'booking_id' => $booking->id,
            'items' => [
                ['item_id' => $coke->id, 'quantity' => 1],
                ['item_id' => $water->id, 'quantity' => 1],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 50,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame(1, Transaction::where('transaction_type', 'pos_sale')->count());
        $this->assertSame(2, InventoryUsage::where('booking_id', $booking->id)->count());
        InventoryUsage::where('booking_id', $booking->id)->each(function (InventoryUsage $usage) {
            $this->assertTrue($this->settlement->isSettled($usage->load('transaction.payment')));
        });
    }

    public function test_group_checkout_preview_matches_settlement_and_ignores_paid_pos(): void
    {
        $type = $this->roomType();
        $paidStay = $this->activeStay('G1', $type, 1500, 1500);
        $unpaidStay = $this->activeStay('G2', $type, 1500, 1500);
        $emptyStay = $this->activeStay('G3', $type, 1500, 1500);
        $groupRef = 'GRP-INV-SETTLE';
        foreach ([$paidStay, $unpaidStay, $emptyStay] as $stay) {
            $stay->update(['group_ref' => $groupRef]);
        }

        $coke = $this->item('Coke', 30);
        $water = $this->item('Water', 20);
        $this->roomPos($paidStay, $coke, 1, 30)->assertSessionHasNoErrors();
        $this->actingAs($this->desk)->post(route('bookings.items', $unpaidStay), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $preview = $this->actingAs($this->desk)->get(route('reservations.group_checkout_preview', $groupRef));
        $preview->assertOk();
        $this->assertEquals(20.0, $preview->json('totals.minibar'));
        $this->assertEquals(20.0, $preview->json('totals.balance'));

        $this->actingAs($this->desk)->post(route('reservations.group_checkout_settle', $groupRef), [
            'payment_method' => 'cash',
            'cash_amount' => 20,
        ])->assertRedirect(route('rooms.index'));

        $this->assertSame(0.0, $this->settlement->unsettledTotal($paidStay->id));
        $this->assertSame(0.0, $this->settlement->unsettledTotal($unpaidStay->id));
        $this->assertSame('checked_out', $emptyStay->fresh()->status);
        $this->assertSame('check_out', InventoryUsage::where('item_id', $water->id)->first()->transaction->transaction_type);
        $this->assertSame('pos_sale', InventoryUsage::where('item_id', $coke->id)->first()->transaction->transaction_type);
    }

    public function test_cancelled_booking_cannot_be_checked_out_and_shows_no_inventory_due(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);

        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->actingAs($this->desk)->post(route('bookings.cancel', $booking), [
            'reason' => 'Guest left',
        ])->assertRedirect();

        $this->assertSame(10, (int) $water->fresh()->current_stock);

        $this->actingAs($this->desk)->from(route('bookings.show', $booking))->post(route('bookings.checkout', $booking), [
            'payment_method' => 'cash',
            'cash_amount' => 20,
        ])->assertSessionHas('error');

        $show = $this->stayJson($booking->fresh());
        $this->assertEquals(0, $show['calculations']['unpaid_inventory']);
        $this->assertEquals(0, $show['calculations']['additional_due']);
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_lodging_partial_amount_paid_does_not_settle_inventory_usages(): void
    {
        $booking = $this->activeStay('R05', $this->roomType(), 1000, 500);
        $water = $this->item('Water', 20);

        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->assertSame(20.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertEquals(20, $this->stayJson($booking)['calculations']['unpaid_inventory']);
        $this->assertEquals(500, $booking->fresh()->amount_paid);
    }

    public function test_pos_digital_tender_is_immediate_on_the_transaction_and_pending_lodging_payments_do_not_settle_inventory(): void
    {
        $booking = $this->activeStay('DG-1', $this->roomType(), 1000, 0);
        $coke = $this->item('Coke', 30);
        $water = $this->item('Water', 20);

        $this->actingAs($this->desk)->post(route('pos.checkout'), [
            'booking_id' => $booking->id,
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'gcash',
            'gcash_amount' => 30,
            'gcash_ref' => 'POS-GCASH-1',
        ])->assertSessionHasNoErrors();

        $posTx = Transaction::where('transaction_type', 'pos_sale')->firstOrFail();
        $this->assertNull($posTx->payment_id);
        $this->assertEquals(30.0, (float) $posTx->gcash_amount);
        $this->assertTrue($this->settlement->isSettled(
            InventoryUsage::where('item_id', $coke->id)->first()->load('transaction.payment')
        ));

        app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => 'LODGE-PENDING',
            'amount' => 400,
            'payment_type' => 'partial',
            'status' => 'pending',
            'recorded_by' => $this->desk->id,
        ], [$booking->id => 400]);

        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->assertSame(20.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame(0.0, (float) $booking->fresh()->amount_paid);
    }

    public function test_completed_checkout_cannot_charge_inventory_again(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);

        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->checkoutStay($booking, 20)->assertRedirect(route('rooms.index'));
        $this->actingAs($this->desk)->from(route('rooms.index'))->post(route('bookings.checkout', $booking), [
            'payment_method' => 'cash',
            'cash_amount' => 20,
        ])->assertSessionHas('error');

        $this->assertSame(1, Transaction::where('transaction_type', 'check_out')->where('booking_id', $booking->id)->count());
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
        $this->assertSame(9, (int) $water->fresh()->current_stock);
    }

    public function test_modern_checkout_without_verified_payment_does_not_settle_or_accept_attach(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);
        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $trace = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_out',
            'description' => 'Modern zero-due checkout trace',
            'amount' => 0,
            'payment_method' => 'na',
            'cash_amount' => 0,
            'processed_by' => $this->desk->id,
        ]);

        $usage = InventoryUsage::where('booking_id', $booking->id)->firstOrFail();
        $usage->update(['transaction_id' => $trace->id]);
        $usage->refresh()->load('transaction.payment');

        $this->assertFalse($this->settlement->isModernCollectionBearingCheckout($trace));
        $this->assertFalse($this->settlement->isLegacyPreLedgerCheckoutCollection($trace));
        $this->assertFalse($this->settlement->isSettled($usage));

        $this->expectException(\InvalidArgumentException::class);
        $this->settlement->attachUnsettledToCheckoutTransaction($booking->id, $trace->id);
    }

    public function test_pending_checkout_payment_does_not_settle_inventory(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);
        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $payment = app(PaymentService::class)->record([
            'payer_name' => $booking->guest_name,
            'payment_method_code' => 'gcash',
            'reference_number' => 'PENDING-CHECKOUT',
            'amount' => 20,
            'payment_type' => 'final',
            'status' => 'pending',
            'recorded_by' => $this->desk->id,
        ], [$booking->id => 20]);

        $checkout = Transaction::create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'transaction_type' => 'check_out',
            'description' => 'Checkout linked to pending payment',
            'amount' => 20,
            'payment_method' => 'gcash',
            'gcash_amount' => 20,
            'gcash_ref' => 'PENDING-CHECKOUT',
            'processed_by' => $this->desk->id,
        ]);
        $usage = InventoryUsage::where('booking_id', $booking->id)->firstOrFail();
        $usage->update(['transaction_id' => $checkout->id]);

        $this->assertSame('pending', $checkout->payment->status);
        $this->assertFalse($this->settlement->isModernCollectionBearingCheckout($checkout->load('payment')));
        $this->assertFalse($this->settlement->isSettled($usage->fresh()->load('transaction.payment')));
        $this->assertSame(20.0, $this->settlement->unsettledTotal($booking->id));

        $this->expectException(\InvalidArgumentException::class);
        $this->settlement->attachUnsettledToCheckoutTransaction($booking->id, $checkout->id);
    }

    public function test_legacy_check_out_without_payment_id_remains_settled(): void
    {
        $booking = $this->activeStay();
        $legacy = Transaction::create([
            'booking_id' => $booking->id,
            'transaction_type' => 'check_out',
            'description' => 'Pre-ledger checkout collection',
            'amount' => 20,
            'payment_method' => 'cash',
            'cash_amount' => 20,
            'processed_by' => $this->desk->id,
        ]);
        $this->assertNull($legacy->payment_id);

        $usage = InventoryUsage::create([
            'booking_id' => $booking->id,
            'transaction_id' => $legacy->id,
            'item_id' => $this->item('Water', 20)->id,
            'quantity' => 1,
            'unit_price' => 20,
            'total_price' => 20,
            'recorded_by' => $this->desk->id,
        ]);

        $legacy->refresh();
        $this->assertTrue($this->settlement->isLegacyPreLedgerCheckoutCollection($legacy));
        $this->assertTrue($this->settlement->isSettled($usage->load('transaction.payment')));
        $this->assertSame(0.0, $this->settlement->unsettledTotal($booking->id));
    }

    public function test_collecting_checkout_always_writes_a_verified_payment_id(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);
        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->checkoutStay($booking, 20)->assertRedirect(route('rooms.index'));

        $checkout = Transaction::where('transaction_type', 'check_out')
            ->where('booking_id', $booking->id)
            ->firstOrFail();
        $this->assertNotNull($checkout->payment_id);
        $this->assertSame('verified', $checkout->payment->status);
        $this->assertTrue($this->settlement->isModernCollectionBearingCheckout($checkout));
    }

    public function test_static_unsettled_for_checkout_helper_matches_the_service(): void
    {
        $booking = $this->activeStay();
        $water = $this->item('Water', 20);
        $this->actingAs($this->desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $viaModel = InventoryUsage::unsettledForCheckout($booking->id);
        $viaService = $this->settlement->unsettledForCheckout($booking->id);

        $this->assertEquals($viaService->pluck('id')->all(), $viaModel->pluck('id')->all());
        $this->assertCount(1, $viaModel);
    }

    private function stayJson(Booking $booking): array
    {
        return $this->actingAs($this->desk)
            ->get(route('bookings.show', $booking).'?json=1')
            ->assertOk()
            ->json();
    }

    private function checkoutStay(Booking $booking, float $cashAmount)
    {
        return $this->actingAs($this->desk)->post(route('bookings.checkout', $booking), [
            'payment_method' => 'cash',
            'cash_amount' => $cashAmount,
        ]);
    }

    private function roomPos(Booking $booking, InventoryItem $item, int $qty, float $cashAmount)
    {
        return $this->actingAs($this->desk)->post(route('pos.checkout'), [
            'booking_id' => $booking->id,
            'items' => [['item_id' => $item->id, 'quantity' => $qty]],
            'payment_method' => 'cash',
            'cash_amount' => $cashAmount,
        ]);
    }

    private function item(string $name, float $price): InventoryItem
    {
        return InventoryItem::create([
            'item_name' => $name,
            'category' => 'minibar',
            'unit' => 'pc',
            'current_stock' => 10,
            'minimum_stock' => 1,
            'unit_cost' => 10,
            'selling_price' => $price,
            'is_active' => true,
        ]);
    }

    private function roomType(): RoomType
    {
        return RoomType::create([
            'type_name' => 'Settlement Room '.uniqid(),
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);
    }

    private function activeStay(
        ?string $roomNumber = null,
        ?RoomType $type = null,
        float $total = 1000,
        float $paid = 1000
    ): Booking {
        $type ??= $this->roomType();
        $room = Room::create([
            'room_number' => $roomNumber ?: 'R'.substr(uniqid(), -5),
            'room_type_id' => $type->id,
            'status' => 'occupied',
        ]);

        return Booking::create([
            'booking_ref' => 'BKG-'.$room->room_number,
            'room_id' => $room->id,
            'guest_name' => 'Stay Guest '.$room->room_number,
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'check_in' => now()->subHour(),
            'expected_check_out' => now()->addHours(10),
            'status' => 'active',
            'payment_status' => $paid >= $total ? 'paid' : 'partial',
            'base_amount' => $total,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'checked_in_by' => $this->desk->id,
        ]);
    }
}
