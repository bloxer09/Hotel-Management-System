<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\InventoryUsage;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\InventoryUsageSettlementService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryMovementFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_front_desk_pos_sale_stamps_usage_and_movement_to_the_active_register(): void
    {
        $desk = $this->frontDesk();
        $register = ShiftService::activeRegister();
        $item = $this->item(['current_stock' => 10, 'selling_price' => 30]);

        $this->actingAs($desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertSame(9, (int) $item->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_POS_SALE)->count());

        $usage = InventoryUsage::firstOrFail();
        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame($register->id, (int) $usage->shift_id);
        $this->assertSame($register->id, (int) $movement->shift_session_id);
        $this->assertSame($desk->id, (int) $usage->recorded_by);
        $this->assertSame($desk->id, (int) $movement->performed_by);
    }

    public function test_b_admin_pos_while_front_desk_register_is_active_uses_that_register(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $register = ShiftService::activeRegister();
        $this->assertSame($desk->id, (int) $register->user_id);

        $item = $this->item(['current_stock' => 10, 'selling_price' => 30]);
        $this->actingAs($admin)->post(route('pos.checkout'), [
            'consumer_name' => 'Admin Sale',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 30,
        ])->assertSessionHasNoErrors();

        $usage = InventoryUsage::firstOrFail();
        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame($admin->id, (int) $usage->recorded_by);
        $this->assertSame($admin->id, (int) $movement->performed_by);
        $this->assertSame($register->id, (int) $usage->shift_id);
        $this->assertSame($register->id, (int) $movement->shift_session_id);
    }

    public function test_c_booking_add_item_uses_the_same_active_register_on_usage_and_movement(): void
    {
        $desk = $this->frontDesk();
        $register = ShiftService::activeRegister();
        $booking = $this->activeStay($desk);
        $item = $this->item(['current_stock' => 10, 'selling_price' => 20]);

        $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 1,
        ])->assertRedirect();

        $usage = InventoryUsage::firstOrFail();
        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame($register->id, (int) $usage->shift_id);
        $this->assertSame($register->id, (int) $movement->shift_session_id);
        $this->assertSame($desk->id, (int) $usage->recorded_by);
        $this->assertSame($desk->id, (int) $movement->performed_by);
        $this->assertSame(9, (int) $item->fresh()->current_stock);
    }

    public function test_d_front_desk_stock_adjust_without_active_register_is_rejected(): void
    {
        $desk = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Viewer Desk']);
        $item = $this->item(['current_stock' => 10]);

        $response = $this->actingAs($desk)->from(route('inventory.index'))->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 5,
            'reason' => 'No register restock',
        ]);

        $this->assertTrue($response->isRedirection() || $response->status() === 403);
        $this->assertSame(10, (int) $item->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::count());
        $this->assertSame(0, InventoryChangeRequest::count());
    }

    public function test_e_front_desk_stock_adjust_with_active_register_is_stamped_on_approve(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $register = ShiftService::activeRegister();
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 4,
            'reason' => 'Delivery',
        ])->assertRedirect();
        $this->assertSame(10, (int) $item->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::count());

        $request = InventoryChangeRequest::firstOrFail();
        $this->actingAs($admin)->post(route('inventory.requests.approve', $request))->assertRedirect();

        $this->assertSame(14, (int) $item->fresh()->current_stock);
        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame(InventoryStockMovement::TYPE_RESTOCK, $movement->movement_type);
        $this->assertSame($register->id, (int) $movement->shift_session_id);
        $this->assertSame($admin->id, (int) $movement->performed_by);
    }

    public function test_pending_restock_is_applied_to_the_register_active_at_approval_not_submit(): void
    {
        $deskA = $this->frontDesk('desk_shift40');
        $shift40 = ShiftService::activeRegister();
        $this->assertSame($deskA->id, (int) $shift40->user_id);

        $admin = $this->admin();
        $water = $this->item(['item_name' => 'Water', 'current_stock' => 10, 'selling_price' => 20]);

        $this->actingAs($deskA)->post(route('inventory.adjust', $water), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Delivery of 24 Water',
        ])->assertRedirect();

        $this->assertSame(10, (int) $water->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::count());
        $changeRequest = InventoryChangeRequest::firstOrFail();
        $this->assertSame(InventoryChangeRequest::STATUS_PENDING, $changeRequest->status);
        $this->assertSame($deskA->id, (int) $changeRequest->requested_by);

        $shift40->update(['ended_at' => now()]);
        $this->assertNull(ShiftService::activeRegister());

        $deskB = $this->frontDesk('desk_shift41');
        $shift41 = ShiftService::activeRegister();
        $this->assertNotSame($shift40->id, $shift41->id);
        $this->assertSame($deskB->id, (int) $shift41->user_id);

        $this->actingAs($admin)->post(route('inventory.requests.approve', $changeRequest))->assertRedirect();

        $this->assertSame(34, (int) $water->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::count());

        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame(InventoryStockMovement::TYPE_RESTOCK, $movement->movement_type);
        $this->assertSame(24, (int) $movement->quantity_change);
        $this->assertSame($deskA->id, (int) $changeRequest->fresh()->requested_by);
        $this->assertSame($admin->id, (int) $changeRequest->fresh()->reviewed_by);
        $this->assertSame($admin->id, (int) $movement->performed_by);
        $this->assertSame($shift41->id, (int) $movement->shift_session_id);
        $this->assertSame(0, InventoryStockMovement::where('shift_session_id', $shift40->id)->count());

        $this->actingAs($admin)->get(route('inventory.index', ['tab' => 'history']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 1)
                ->where('history.data.0.type_key', InventoryStockMovement::TYPE_RESTOCK)
                ->where('history.data.0.register_label', 'Shift #'.$shift41->id)
                ->where('history.data.0.requested_by_name', $deskA->full_name)
                ->where('history.data.0.actor_name', $admin->full_name)
                ->where('history.data.0.quantity_change', 24)
            );
    }

    public function test_f_admin_restock_with_active_register_keeps_admin_as_actor(): void
    {
        $desk = $this->frontDesk();
        $admin = $this->admin();
        $register = ShiftService::activeRegister();
        $this->assertSame($desk->id, (int) $register->user_id);
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 3,
            'reason' => 'Admin restock during FD shift',
        ])->assertRedirect();

        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame(InventoryStockMovement::TYPE_RESTOCK, $movement->movement_type);
        $this->assertSame($admin->id, (int) $movement->performed_by);
        $this->assertSame($register->id, (int) $movement->shift_session_id);
        $this->assertSame(13, (int) $item->fresh()->current_stock);
    }

    public function test_g_admin_restock_without_active_register_leaves_shift_null(): void
    {
        $admin = $this->admin();
        $this->assertNull(ShiftService::activeRegister());
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 2,
            'reason' => 'Admin restock, no register',
        ])->assertRedirect();

        $movement = InventoryStockMovement::firstOrFail();
        $this->assertSame(InventoryStockMovement::TYPE_RESTOCK, $movement->movement_type);
        $this->assertSame($admin->id, (int) $movement->performed_by);
        $this->assertNull($movement->shift_session_id);
        $this->assertSame(12, (int) $item->fresh()->current_stock);
    }

    public function test_h_new_add_stock_writes_restock_not_manual_add(): void
    {
        $admin = $this->admin();
        $item = $this->item(['current_stock' => 5]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 1,
            'reason' => 'Physical delivery',
        ]);

        $this->assertDatabaseHas('inventory_stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_RESTOCK,
        ]);
        $this->assertDatabaseMissing('inventory_stock_movements', [
            'movement_type' => InventoryStockMovement::TYPE_MANUAL_ADD,
        ]);
    }

    public function test_i_legacy_manual_add_rows_remain_unchanged(): void
    {
        $admin = $this->admin();
        $item = $this->item();
        $legacy = InventoryStockMovement::record([
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_MANUAL_ADD,
            'quantity_change' => 2,
            'stock_before' => 8,
            'stock_after' => 10,
            'source_type' => 'legacy',
            'source_id' => null,
            'performed_by' => $admin->id,
            'shift_session_id' => null,
            'notes' => 'Pre-phase-1 add',
        ]);

        $this->actingAs($admin)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 1,
            'reason' => 'New restock',
        ]);

        $this->assertSame(InventoryStockMovement::TYPE_MANUAL_ADD, $legacy->fresh()->movement_type);
        $this->assertNull($legacy->fresh()->shift_session_id);
        $this->assertSame(1, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_RESTOCK)->count());
        $this->assertSame(1, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_MANUAL_ADD)->count());
    }

    public function test_j_legacy_null_shift_history_displays_safely_with_manila_seconds(): void
    {
        $admin = $this->admin();
        $item = $this->item();
        InventoryStockMovement::record([
            'inventory_item_id' => $item->id,
            'movement_type' => InventoryStockMovement::TYPE_MANUAL_ADD,
            'quantity_change' => 1,
            'stock_before' => 9,
            'stock_after' => 10,
            'performed_by' => $admin->id,
            'shift_session_id' => null,
            'notes' => 'Unassigned legacy',
        ]);

        $this->actingAs($admin)->get(route('inventory.index', ['tab' => 'history']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.data.0.register_label', 'No register')
                ->where('history.data.0.type_key', InventoryStockMovement::TYPE_MANUAL_ADD)
                ->where('history.data.0.occurred_at_manila', fn ($value) => is_string($value) && preg_match('/\d+:\d{2}:\d{2} [AP]M$/', $value) === 1)
            );
    }

    public function test_k_existing_inventory_usage_shift_ids_are_not_rewritten(): void
    {
        $desk = $this->frontDesk();
        $oldShift = ShiftSession::create([
            'user_id' => $desk->id,
            'shift_code' => 'night',
            'started_at' => now()->subDay(),
            'ended_at' => now()->subHours(12),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);
        $item = $this->item();
        $usage = InventoryUsage::create([
            'booking_id' => $this->activeStay($desk)->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_price' => 20,
            'total_price' => 20,
            'recorded_by' => $desk->id,
            'shift_id' => $oldShift->id,
        ]);

        $this->assertSame($oldShift->id, (int) $usage->fresh()->shift_id);
        $this->assertNotEquals(ShiftService::activeRegisterId(), $oldShift->id);
        $this->assertSame($oldShift->id, (int) InventoryUsage::find($usage->id)->shift_id);
    }

    public function test_l_admin_can_configure_is_turnover_tracked(): void
    {
        $admin = $this->admin();
        $item = $this->item(['is_turnover_tracked' => false]);

        $this->actingAs($admin)->patch(route('inventory.update', $item), [
            'item_name' => $item->item_name,
            'category' => $item->category,
            'unit' => $item->unit,
            'minimum_stock' => $item->minimum_stock,
            'unit_cost' => $item->unit_cost,
            'selling_price' => $item->selling_price,
            'is_active' => true,
            'is_turnover_tracked' => true,
        ])->assertRedirect();

        $this->assertTrue($item->fresh()->is_turnover_tracked);

        $this->actingAs($admin)->post(route('inventory.store'), [
            'item_name' => 'Safeguard',
            'category' => 'toiletries',
            'unit' => 'pc',
            'current_stock' => 4,
            'minimum_stock' => 1,
            'unit_cost' => 8,
            'selling_price' => 20,
            'is_turnover_tracked' => true,
        ])->assertRedirect();

        $this->assertTrue(InventoryItem::where('item_name', 'Safeguard')->first()->is_turnover_tracked);
        $this->assertTrue($item->fresh()->is_turnover_tracked);
    }

    public function test_m_front_desk_cannot_change_turnover_tracking_catalog_flag(): void
    {
        $desk = $this->frontDesk();
        $item = $this->item(['is_turnover_tracked' => false]);

        $this->actingAs($desk)->patch(route('inventory.update', $item), [
            'item_name' => $item->item_name,
            'category' => $item->category,
            'unit' => $item->unit,
            'minimum_stock' => $item->minimum_stock,
            'unit_cost' => $item->unit_cost,
            'selling_price' => $item->selling_price,
            'is_active' => true,
            'is_turnover_tracked' => true,
        ])->assertForbidden();

        $this->assertFalse($item->fresh()->is_turnover_tracked);
    }

    public function test_n_pos_still_locks_and_refuses_combined_oversell(): void
    {
        $desk = $this->frontDesk();
        $item = $this->item(['current_stock' => 3, 'selling_price' => 10]);

        $this->actingAs($desk)->from(route('pos.index'))->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2],
                ['item_id' => $item->id, 'quantity' => 2],
            ],
            'payment_method' => 'cash',
            'cash_amount' => 40,
        ])->assertSessionHas('error');

        $this->assertSame(3, (int) $item->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::count());
    }

    public function test_o_booking_add_item_deducts_stock_exactly_once(): void
    {
        $desk = $this->frontDesk();
        $booking = $this->activeStay($desk);
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 2,
        ])->assertRedirect();

        $this->assertSame(8, (int) $item->fresh()->current_stock);
        $this->assertSame(1, InventoryStockMovement::count());
        $this->assertSame(1, InventoryUsage::count());
    }

    public function test_p_checkout_does_not_create_an_additional_stock_deduction(): void
    {
        $desk = $this->frontDesk();
        $booking = $this->activeStay($desk, 1000, 1000);
        $item = $this->item(['current_stock' => 10, 'selling_price' => 20]);

        $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 1,
        ])->assertRedirect();
        $movementsAfterAdd = InventoryStockMovement::count();
        $stockAfterAdd = (int) $item->fresh()->current_stock;

        $this->actingAs($desk)->post(route('bookings.checkout', $booking), [
            'payment_method' => 'cash',
            'cash_amount' => 20,
        ])->assertRedirect(route('rooms.index'));

        $this->assertSame($stockAfterAdd, (int) $item->fresh()->current_stock);
        $this->assertSame($movementsAfterAdd, InventoryStockMovement::count());
    }

    public function test_q_phase_zero_paid_pos_plus_unpaid_add_item_billing_still_holds(): void
    {
        $desk = $this->frontDesk();
        $booking = $this->activeStay($desk, 1000, 1000);
        $coke = $this->item(['item_name' => 'Coke', 'selling_price' => 30]);
        $water = $this->item(['item_name' => 'Water', 'selling_price' => 20]);
        $settlement = app(InventoryUsageSettlementService::class);

        $this->actingAs($desk)->post(route('pos.checkout'), [
            'booking_id' => $booking->id,
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 30,
        ])->assertSessionHasNoErrors();
        $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $water->id,
            'quantity' => 1,
        ])->assertRedirect();

        $this->assertSame(50.0, $settlement->chargesTotal($booking->id));
        $this->assertSame(30.0, $settlement->settledTotal($booking->id));
        $this->assertSame(20.0, $settlement->unsettledTotal($booking->id));
    }

    public function test_r_housekeeping_cannot_access_inventory_or_pos_stock_actions(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $item = $this->item();

        $this->actingAs($housekeeper)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('inventory.adjust', $item), [
            'adjustment_type' => 'add',
            'quantity' => 1,
            'reason' => 'No',
        ])->assertForbidden();
        $this->actingAs($housekeeper)->post(route('pos.checkout'), [
            'consumer_name' => 'Nope',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 30,
        ])->assertForbidden();
    }

    public function test_legacy_items_default_is_turnover_tracked_to_false(): void
    {
        $item = $this->item();
        $this->assertFalse($item->fresh()->is_turnover_tracked);
        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'is_turnover_tracked' => 0,
        ]);
    }

    public function test_booking_reversal_stamps_active_register_when_present(): void
    {
        $desk = $this->frontDesk();
        $register = ShiftService::activeRegister();
        $booking = $this->activeStay($desk);
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 2,
        ]);
        $this->actingAs($desk)->post(route('bookings.cancel', $booking), [
            'reason' => 'Guest left',
        ])->assertRedirect();

        $reversal = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_BOOKING_REVERSAL)->firstOrFail();
        $this->assertSame($register->id, (int) $reversal->shift_session_id);
        $this->assertSame($desk->id, (int) $reversal->performed_by);
        $this->assertSame(10, (int) $item->fresh()->current_stock);
    }

    public function test_admin_cancellation_without_register_leaves_reversal_shift_null(): void
    {
        $admin = $this->admin();
        $this->assertNull(ShiftService::activeRegister());
        $booking = $this->activeStay($admin);
        $item = $this->item(['current_stock' => 10]);

        $this->actingAs($admin)->post(route('bookings.items', $booking), [
            'item_id' => $item->id,
            'quantity' => 1,
        ]);
        $this->actingAs($admin)->post(route('bookings.cancel', $booking), [
            'reason' => 'Admin cancel',
        ])->assertRedirect();

        $reversal = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_BOOKING_REVERSAL)->firstOrFail();
        $this->assertNull($reversal->shift_session_id);
        $this->assertSame($admin->id, (int) $reversal->performed_by);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'full_name' => 'Phase1 Admin']);
    }

    private function frontDesk(string $username = 'phase1_desk'): User
    {
        $user = User::factory()->create([
            'role' => 'front_desk',
            'username' => $username,
            'full_name' => 'Phase1 Desk',
        ]);
        ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);

        return $user;
    }

    private function item(array $overrides = []): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'item_name' => 'Mineral Water',
            'category' => 'minibar',
            'unit' => 'bottle',
            'current_stock' => 10,
            'minimum_stock' => 2,
            'unit_cost' => 15,
            'selling_price' => 35,
            'is_active' => true,
        ], $overrides));
    }

    private function activeStay(User $user, float $total = 1000, float $paid = 1000): Booking
    {
        $type = RoomType::create([
            'type_name' => 'Phase1 Room '.uniqid(),
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'P1'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => 'occupied',
        ]);

        return Booking::create([
            'booking_ref' => 'BKG-'.$room->room_number,
            'room_id' => $room->id,
            'guest_name' => 'Phase1 Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'check_in' => now()->subHour(),
            'expected_check_out' => now()->addHours(10),
            'status' => 'active',
            'payment_status' => $paid >= $total ? 'paid' : 'partial',
            'base_amount' => $total,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'checked_in_by' => $user->id,
        ]);
    }
}
