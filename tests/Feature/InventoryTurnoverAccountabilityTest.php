<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\InventoryAmenityIssue;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\InventoryShiftCountItem;
use App\Models\InventoryShiftTurnover;
use App\Models\InventoryStockMovement;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\StayAmenityPolicy;
use App\Models\User;
use App\Services\InventoryTurnoverService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryTurnoverAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private InventoryTurnoverService $turnovers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->turnovers = app(InventoryTurnoverService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_first_go_live_physical_opening_establishes_baseline(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 40);
        $shift = ShiftService::activeRegister();
        $turnover = $this->turnovers->ensureForShift($shift);

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.opening'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ])->assertRedirect();

        $turnover->refresh();
        $this->assertTrue($turnover->is_bootstrap);
        $this->assertNotNull($turnover->opening_established_at);
        $this->assertSame(35, (int) $turnover->items()->first()->opening_quantity);
        $this->assertSame(35, (int) $coke->fresh()->current_stock);
        $this->assertTrue(AuditLog::where('action', 'INVENTORY_TURNOVER_OPENING_ACCEPTED')->exists());
    }

    public function test_b_only_turnover_tracked_items_require_count(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 35);
        $laundry = $this->untracked('Laundry Service', 10);
        $this->bootstrapOpening($desk, [[$coke, 35]]);

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.start_counting'))->assertRedirect();
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_shift_count_items', ['inventory_item_id' => $coke->id]);
        $this->assertDatabaseMissing('inventory_shift_count_items', ['inventory_item_id' => $laundry->id]);
    }

    public function test_c_opening_35_sold_1_expected_34(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $line = $this->lineFor($coke);
        $this->assertSame(35, (int) $line->opening_quantity);
        $this->assertSame(1, (int) $line->sold_quantity);
        $this->assertSame(34, (int) $line->expected_closing_quantity);
    }

    public function test_d_opening_20_sold_2_complimentary_1_expected_17(): void
    {
        $desk = $this->frontDesk();
        $soap = $this->tracked('Safeguard', 20, 'toiletries', 0);
        $this->configureAmenity($soap);
        $this->bootstrapOpening($desk, [[$soap, 20]]);
        $booking = $this->activeStay($desk);
        $this->sell($desk, $soap, 2, 0);
        $this->actingAs($desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => [['inventory_item_id' => $soap->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->startCount($desk);
        $line = $this->lineFor($soap);
        $this->assertSame(2, (int) $line->sold_quantity);
        $this->assertSame(1, (int) $line->complimentary_quantity);
        $this->assertSame(17, (int) $line->expected_closing_quantity);
    }

    public function test_e_restock_plus_10_is_included_in_expected(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 10,
            'reason' => 'Delivery',
        ])->assertRedirect();

        $this->startCount($desk);
        $line = $this->lineFor($coke);
        $this->assertSame(10, (int) $line->restock_quantity);
        $this->assertSame(45, (int) $line->expected_closing_quantity);
    }

    public function test_f_physical_equals_expected_is_balanced(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ])->assertSessionHasNoErrors();

        $line = $this->lineFor($coke);
        $this->assertSame(0, (int) $line->variance_quantity);
        $this->assertSame('BALANCED', $this->turnovers->varianceLabel((int) $line->variance_quantity));
    }

    public function test_g_expected_34_physical_33_is_outgoing_short_1(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ])->assertSessionHasNoErrors();

        $line = $this->lineFor($coke);
        $this->assertSame(34, (int) $line->expected_closing_quantity);
        $this->assertSame(33, (int) $line->outgoing_actual_quantity);
        $this->assertSame(-1, (int) $line->variance_quantity);
        $this->assertSame('SHORT 1', $this->turnovers->varianceLabel(-1));
    }

    public function test_h_snapshot_freezes_expected_34_before_inventory_variance(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ])->assertSessionHasNoErrors();

        $line = $this->lineFor($coke);
        $variance = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)->firstOrFail();
        $this->assertTrue($line->updated_at->lte($variance->created_at) || $line->expected_closing_quantity === 34);
        $this->assertSame(34, (int) $line->expected_closing_quantity);
        $this->assertSame(-1, (int) $variance->quantity_change);
    }

    public function test_i_inventory_variance_aligns_current_stock_from_34_to_33(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->assertSame(34, (int) $coke->fresh()->current_stock);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);

        $this->assertSame(33, (int) $coke->fresh()->current_stock);
        $movement = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)->firstOrFail();
        $this->assertSame(34, (int) $movement->stock_before);
        $this->assertSame(33, (int) $movement->stock_after);
        $this->assertSame(ShiftService::activeRegister()->id, (int) $movement->shift_session_id);
        $this->assertStringContainsString('Physical count reconciliation', (string) $movement->notes);
    }

    public function test_j_frozen_report_still_shows_expected_34_actual_33_short_1(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);

        $this->actingAs($desk)
            ->get(route('shifts.report', ShiftService::activeRegister()->id).'?tab=inventory-accountability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.inventory_accountability.is_frozen', true)
                ->where('report.inventory_accountability.items.0.expected_closing_quantity', 34)
                ->where('report.inventory_accountability.items.0.outgoing_actual_quantity', 33)
                ->where('report.inventory_accountability.items.0.variance_quantity', -1)
                ->where('report.inventory_accountability.items.0.variance_label', 'SHORT 1')
            );
    }

    public function test_k_inventory_variance_is_excluded_from_expected_formula(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        InventoryStockMovement::record([
            'inventory_item_id' => $coke->id,
            'movement_type' => InventoryStockMovement::TYPE_INVENTORY_VARIANCE,
            'quantity_change' => -2,
            'stock_before' => 35,
            'stock_after' => 33,
            'performed_by' => $desk->id,
            'shift_session_id' => ShiftService::activeRegisterId(),
            'notes' => 'Should not enter expected',
        ]);
        $this->startCount($desk);
        $this->assertSame(35, (int) $this->lineFor($coke)->expected_closing_quantity);
    }

    public function test_l_over_count_creates_over_and_reconciliation_movement(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 37]]),
        ])->assertSessionHasNoErrors();

        $line = $this->lineFor($coke);
        $this->assertSame(2, (int) $line->variance_quantity);
        $this->assertSame('OVER 2', $this->turnovers->varianceLabel(2));
        $this->assertSame(37, (int) $coke->fresh()->current_stock);
        $movement = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)->firstOrFail();
        $this->assertSame(2, (int) $movement->quantity_change);
    }

    public function test_m_counting_blocks_tracked_pos(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 25,
        ])->assertForbidden();
        $this->assertSame(35, (int) $coke->fresh()->current_stock);
    }

    public function test_n_counting_blocks_tracked_booking_add_item(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $booking = $this->activeStay($desk);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $coke->id,
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_o_counting_blocks_tracked_complimentary_issue(): void
    {
        $desk = $this->frontDesk();
        $soap = $this->tracked('Safeguard', 20, 'toiletries', 0);
        $this->configureAmenity($soap);
        $this->bootstrapOpening($desk, [[$soap, 20]]);
        $booking = $this->activeStay($desk);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => [['inventory_item_id' => $soap->id, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_p_untracked_item_remains_usable_during_count(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $laundry = $this->untracked('Laundry Service', 8);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $laundry->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 50,
        ])->assertSessionHasNoErrors();
        $this->assertSame(7, (int) $laundry->fresh()->current_stock);
        $this->assertSame(35, (int) $coke->fresh()->current_stock);
    }

    public function test_q_incoming_tracked_use_blocked_until_accepted(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('pos.checkout'), [
            'consumer_name' => 'Blocked',
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 25,
        ])->assertForbidden();
    }

    public function test_r_outgoing_33_incoming_33_accept_sets_next_opening_33(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $this->endCashShift($desk, 1000, 525);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ])->assertSessionHasNoErrors();

        $nextTurnover = InventoryShiftTurnover::where('shift_session_id', ShiftService::activeRegister()->id)->firstOrFail();
        $this->assertSame(33, (int) $nextTurnover->items()->first()->opening_quantity);
        $this->assertSame(InventoryShiftTurnover::STATUS_ACCEPTED, InventoryShiftTurnover::where('shift_session_id', '!=', $nextTurnover->shift_session_id)->first()->status);
    }

    public function test_s_outgoing_33_incoming_32_is_disputed_not_silently_accepted(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $this->endCashShift($desk, 1000, 525);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 32]]),
        ])->assertSessionHas('error');

        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 32]]),
            'reason' => 'Missing one bottle at handover',
        ])->assertSessionHasNoErrors();

        $previous = InventoryShiftTurnover::where('status', InventoryShiftTurnover::STATUS_DISPUTED)->firstOrFail();
        $this->assertSame(34, (int) $previous->items()->first()->expected_closing_quantity);
        $this->assertSame(33, (int) $previous->items()->first()->outgoing_actual_quantity);
        $this->assertSame(33, (int) $previous->items()->first()->handover_expected_quantity);
        $this->assertSame(0, (int) $previous->items()->first()->gap_net_quantity);
        $this->assertSame(-1, (int) $previous->items()->first()->handover_difference);
        $this->assertTrue(AuditLog::where('action', 'INVENTORY_HANDOVER_DISPUTED')->exists());
    }

    public function test_t_admin_resolves_discrepancy_without_rewriting_outgoing_snapshot(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $this->endCashShift($desk, 1000, 525);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 32]]),
            'reason' => 'Handover short',
        ]);

        $turnover = InventoryShiftTurnover::where('status', InventoryShiftTurnover::STATUS_DISPUTED)->firstOrFail();
        $this->actingAs($this->admin())->post(route('shifts.inventory_turnover.resolve', $turnover), [
            'counts' => $this->countRows([[$coke, 32]]),
            'reason' => 'Confirmed physical 32',
        ])->assertSessionHasNoErrors();

        $turnover->refresh();
        $line = $turnover->items()->first();
        $this->assertSame(InventoryShiftTurnover::STATUS_ACCEPTED, $turnover->status);
        $this->assertSame(34, (int) $line->expected_closing_quantity);
        $this->assertSame(33, (int) $line->outgoing_actual_quantity);
        $this->assertSame(-1, (int) $line->variance_quantity);
        $this->assertSame(32, (int) $line->incoming_verified_quantity);
        $this->assertSame(32, (int) $coke->fresh()->current_stock);
    }

    public function test_u_between_shift_admin_plus_24_changes_live_stock_with_null_shift(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ]);
        $this->endCashShift($desk);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Between-shift restock',
        ])->assertRedirect();

        $this->assertSame(44, (int) $coke->fresh()->current_stock);
        $movement = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_RESTOCK)->latest('id')->firstOrFail();
        $this->assertNull($movement->shift_session_id);
        $this->assertSame($admin->id, (int) $movement->performed_by);
    }

    public function test_v_incoming_physically_accepts_44_as_opening_44(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ]);
        $this->endCashShift($desk);
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Gap restock',
        ]);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 44]]),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $nextTurnover = InventoryShiftTurnover::where('shift_session_id', ShiftService::activeRegister()->id)->firstOrFail();
        $this->assertSame(44, (int) $nextTurnover->items()->first()->opening_quantity);
        $outgoing = InventoryShiftTurnover::where('shift_session_id', '!=', $nextTurnover->shift_session_id)->firstOrFail();
        $line = $outgoing->items()->first();
        $this->assertSame(20, (int) $line->outgoing_actual_quantity);
        $this->assertSame(24, (int) $line->gap_net_quantity);
        $this->assertSame(44, (int) $line->handover_expected_quantity);
        $this->assertSame(44, (int) $line->incoming_verified_quantity);
        $this->assertSame(0, (int) $line->handover_difference);
    }

    public function test_w_gap_plus_24_is_not_next_shift_restock(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ]);
        $this->endCashShift($desk);
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Gap restock',
        ]);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 44]]),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->startCount($next);
        $this->assertSame(0, (int) $this->lineFor($coke)->restock_quantity);
        $this->assertSame(44, (int) $this->lineFor($coke)->expected_closing_quantity);
    }

    public function test_x_same_operator_still_performs_acceptance_checkpoint(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);

        ShiftSession::create([
            'user_id' => $desk->id,
            'shift_code' => 'evening',
            'started_at' => now(),
            'opening_cash' => 1000,
            'opening_cash_minibar' => 500,
        ]);

        $this->actingAs($desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Same operator',
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 25,
        ])->assertForbidden();

        $this->actingAs($desk)
            ->get(route('shifts.inventory_turnover.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('same_operator_checkpoint', true));

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ])->assertSessionHasNoErrors();
    }

    public function test_y_no_incoming_staff_outgoing_may_submit_and_close_while_gate_waits(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);
        $this->assertNull(ShiftService::activeRegister());
        $this->assertSame(InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::first()->status);

        $next = $this->frontDesk('later_desk');
        $this->actingAs($next)->post(route('pos.checkout'), [
            'consumer_name' => 'Waiting',
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 25,
        ])->assertForbidden();
    }

    public function test_z_inventory_short_does_not_affect_cash_variance(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $this->endCashShift($desk, 1000, 525);

        $shift = ShiftSession::whereNotNull('ended_at')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $shift->variance_minibar, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $shift->variance_rooms, 0.01);
        $this->assertSame(-1, (int) InventoryShiftCountItem::first()->variance_quantity);
    }

    public function test_aa_complimentary_does_not_affect_minibar_cash(): void
    {
        $desk = $this->frontDesk();
        $soap = $this->tracked('Safeguard', 20, 'toiletries', 0);
        $this->configureAmenity($soap);
        $this->bootstrapOpening($desk, [[$soap, 20]]);
        $booking = $this->activeStay($desk);
        $this->actingAs($desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => [['inventory_item_id' => $soap->id, 'quantity' => 1]],
        ])->assertRedirect();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$soap, 19]]),
        ]);
        $this->endCashShift($desk);
        $shift = ShiftSession::whereNotNull('ended_at')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $shift->variance_minibar, 0.01);
        $this->assertSame(0, \App\Models\InventoryUsage::count());
    }

    public function test_ab_pending_front_desk_restock_does_not_enter_bucket_before_approval(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->actingAs($desk)->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 10,
            'reason' => 'Pending delivery',
        ])->assertRedirect();
        $this->assertSame(0, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_RESTOCK)->count());
        $this->startCount($desk);
        $this->assertSame(0, (int) $this->lineFor($coke)->restock_quantity);
        $this->assertSame(InventoryChangeRequest::STATUS_PENDING, InventoryChangeRequest::first()->status);
    }

    public function test_ac_approved_restock_enters_active_shift_bucket(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->actingAs($desk)->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 10,
            'reason' => 'Delivery',
        ]);
        $request = InventoryChangeRequest::firstOrFail();
        $this->actingAs($this->admin())->post(route('inventory.requests.approve', $request))->assertRedirect();
        $this->startCount($desk);
        $this->assertSame(10, (int) $this->lineFor($coke)->restock_quantity);
        $movement = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_RESTOCK)->firstOrFail();
        $this->assertSame(ShiftService::activeRegister()->id, (int) $movement->shift_session_id);
    }

    public function test_ad_housekeeping_is_forbidden(): void
    {
        $this->frontDesk();
        $this->tracked('Coke', 35);
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)->get(route('shifts.inventory_turnover.show'))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('shifts.inventory_turnover.start_counting'))->assertForbidden();
    }

    public function test_ae_admin_override_requires_reason_and_audit(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->actingAs($desk)->post(route('shifts.end'), [
            'closing_cash' => 1000,
            'closing_cash_minibar' => 500,
        ])->assertSessionHasErrors('inventory_turnover');

        $admin = $this->admin();
        ShiftSession::query()->update(['user_id' => $admin->id]);
        $this->actingAs($admin)->post(route('shifts.end'), [
            'closing_cash' => 1000,
            'closing_cash_minibar' => 500,
            'inventory_override_reason' => '',
        ])->assertSessionHasErrors('inventory_override_reason');

        $this->actingAs($admin)->post(route('shifts.end'), [
            'closing_cash' => 1000,
            'closing_cash_minibar' => 500,
            'inventory_override_reason' => 'Register must close; count tomorrow',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(AuditLog::where('action', 'INVENTORY_TURNOVER_ADMIN_OVERRIDE')->exists());
        $this->assertNotNull(InventoryShiftTurnover::first()->admin_override_reason);
        $this->assertSame(InventoryShiftTurnover::STATUS_OPEN, InventoryShiftTurnover::first()->status);
        $this->assertNotNull(ShiftSession::first()->ended_at);
    }

    public function test_af_historical_turnover_uses_frozen_snapshot_not_live_resum(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $shiftId = ShiftService::activeRegister()->id;
        InventoryStockMovement::record([
            'inventory_item_id' => $coke->id,
            'movement_type' => InventoryStockMovement::TYPE_POS_SALE,
            'quantity_change' => -5,
            'stock_before' => 33,
            'stock_after' => 28,
            'performed_by' => $desk->id,
            'shift_session_id' => $shiftId,
            'notes' => 'Late row must not rewrite frozen expected',
        ]);

        $this->actingAs($desk)
            ->get(route('shifts.report', $shiftId))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.inventory_accountability.items.0.expected_closing_quantity', 34)
                ->where('report.inventory_accountability.items.0.sold_quantity', 1)
            );
    }

    public function test_blank_physical_count_is_not_treated_as_zero(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => [['inventory_item_id' => $coke->id, 'quantity' => '']],
        ])->assertSessionHasErrors('counts');
    }

    public function test_manual_scenario_coke_safeguard_shampoo_and_gap_restock(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 35);
        $soap = $this->tracked('Safeguard', 20, 'toiletries', 0);
        $shampoo = $this->tracked('Shampoo', 20, 'toiletries', 0);
        $this->configureAmenity($soap);
        $this->configureAmenity($shampoo);
        $this->bootstrapOpening($desk, [[$coke, 35], [$soap, 20], [$shampoo, 20]]);

        $this->sell($desk, $coke, 1, 25);
        $this->sell($desk, $soap, 2, 0);
        $booking = $this->activeStay($desk);
        $this->actingAs($desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => [
                ['inventory_item_id' => $soap->id, 'quantity' => 1],
                ['inventory_item_id' => $shampoo->id, 'quantity' => 1],
            ],
        ])->assertRedirect();

        $this->startCount($desk);
        $this->assertSame(34, (int) $this->lineFor($coke)->expected_closing_quantity);
        $this->assertSame(17, (int) $this->lineFor($soap)->expected_closing_quantity);
        $this->assertSame(19, (int) $this->lineFor($shampoo)->expected_closing_quantity);

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33], [$soap, 17], [$shampoo, 19]]),
        ])->assertSessionHasNoErrors();

        $this->assertSame(-1, (int) $this->lineFor($coke)->variance_quantity);
        $this->assertSame(0, (int) $this->lineFor($soap)->variance_quantity);
        $this->assertSame(33, (int) $coke->fresh()->current_stock);
        $this->assertSame(17, (int) $soap->fresh()->current_stock);
        $this->assertSame(19, (int) $shampoo->fresh()->current_stock);

        $this->endCashShift($desk, 1000, 525);
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 10,
            'reason' => 'Gap coke restock',
        ]);
        $this->assertSame(43, (int) $coke->fresh()->current_stock);
        $this->assertNull(InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_RESTOCK)->latest('id')->first()->shift_session_id);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 43], [$soap, 17], [$shampoo, 19]]),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->startCount($next);
        $this->assertSame(43, (int) $this->lineFor($coke)->opening_quantity);
        $this->assertSame(17, (int) $this->lineFor($soap)->opening_quantity);
        $this->assertSame(19, (int) $this->lineFor($shampoo)->opening_quantity);
        $this->assertSame(0, (int) $this->lineFor($coke)->restock_quantity);
    }

    public function test_zero_tracked_products_do_not_create_a_fake_turnover_requirement(): void
    {
        $desk = $this->frontDesk();
        $this->untracked('Laundry Service', 5);
        $this->assertNull($this->turnovers->ensureForShift(ShiftService::activeRegister()));
        $this->endCashShift($desk);
        $this->assertNotNull(ShiftSession::first()->ended_at);
        $this->assertSame(0, InventoryShiftTurnover::count());
    }

    public function test_history_keeps_no_register_payload_for_gap_movements(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ]);
        $this->endCashShift($desk);
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Gap restock',
        ]);

        $this->actingAs($this->admin())
            ->get(route('inventory.index', ['tab' => 'history', 'history_type' => 'inventory_variance']))
            ->assertOk();

        $this->actingAs($this->admin())
            ->get(route('inventory.index', ['tab' => 'history', 'history_type' => 'restock']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('history.data.0.register_label', 'No register'));
    }

    public function test_hardening_a_pos_blocked_until_opening_without_visiting_turnover_ui(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 40);
        $this->assertSame(0, InventoryShiftTurnover::count());

        $blocked = $this->actingAs($desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $coke->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'cash_amount' => 25,
        ]);
        $this->assertBootstrapBlocked($blocked);
        $this->assertSame(40, (int) $coke->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_POS_SALE)->count());

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.opening'), [
            'counts' => $this->countRows([[$coke, 40]]),
        ])->assertSessionHasNoErrors();

        $this->sell($desk, $coke, 1, 25);
        $this->assertSame(39, (int) $coke->fresh()->current_stock);
    }

    public function test_hardening_b_booking_add_item_blocked_until_opening_without_visiting_turnover_ui(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 40);
        $booking = $this->activeStay($desk);

        $blocked = $this->actingAs($desk)->post(route('bookings.items', $booking), [
            'item_id' => $coke->id,
            'quantity' => 1,
        ]);
        $this->assertBootstrapBlocked($blocked);
        $this->assertSame(40, (int) $coke->fresh()->current_stock);
    }

    public function test_hardening_c_complimentary_issue_blocked_until_opening_without_visiting_turnover_ui(): void
    {
        $desk = $this->frontDesk();
        $soap = $this->tracked('Safeguard', 20, 'toiletries', 0);
        $this->configureAmenity($soap);
        $booking = $this->activeStay($desk);

        $blocked = $this->actingAs($desk)->post(route('bookings.amenities.issue', $booking), [
            'issue_context' => 'initial',
            'items' => [['inventory_item_id' => $soap->id, 'quantity' => 1]],
        ]);
        $this->assertBootstrapBlocked($blocked);
        $this->assertSame(20, (int) $soap->fresh()->current_stock);
        $this->assertSame(0, InventoryAmenityIssue::count());
    }

    public function test_hardening_d_front_desk_tracked_adjust_blocked_until_opening_without_visiting_turnover_ui(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 40);

        $blocked = $this->actingAs($desk)->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 6,
            'reason' => 'Delivery before opening',
        ]);
        $this->assertBootstrapBlocked($blocked);
        $this->assertSame(40, (int) $coke->fresh()->current_stock);
        $this->assertSame(0, InventoryChangeRequest::count());
    }

    public function test_hardening_e_authorized_gap_restock_is_balanced_handover_not_next_restock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00'));
        [$desk, $coke] = $this->readyTrackedDesk();
        $outgoingShiftId = ShiftService::activeRegister()->id;
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ])->assertSessionHasNoErrors();
        $this->endCashShift($desk);

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:10:00'));
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Authorized gap restock',
        ])->assertRedirect();
        $this->assertSame(44, (int) $coke->fresh()->current_stock);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)
            ->get(route('shifts.inventory_turnover.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending_handover.items.0.outgoing_actual_quantity', 20)
                ->where('pending_handover.items.0.gap_net_quantity', 24)
                ->where('pending_handover.items.0.handover_expected_quantity', 44)
            );

        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 44]]),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $outgoingLine = InventoryShiftCountItem::where('inventory_item_id', $coke->id)
            ->whereHas('turnover', fn ($q) => $q->where('shift_session_id', $outgoingShiftId))
            ->firstOrFail();
        $this->assertSame(20, (int) $outgoingLine->outgoing_actual_quantity);
        $this->assertSame(24, (int) $outgoingLine->gap_net_quantity);
        $this->assertSame(44, (int) $outgoingLine->handover_expected_quantity);
        $this->assertSame(44, (int) $outgoingLine->incoming_verified_quantity);
        $this->assertSame(0, (int) $outgoingLine->handover_difference);

        $nextTurnover = InventoryShiftTurnover::where('shift_session_id', ShiftService::activeRegister()->id)->firstOrFail();
        $this->assertSame(44, (int) $nextTurnover->items()->first()->opening_quantity);

        $this->startCount($next);
        $this->assertSame(0, (int) $this->lineFor($coke)->restock_quantity);
        $this->assertSame(44, (int) $this->lineFor($coke)->expected_closing_quantity);

        $this->actingAs($desk)
            ->get(route('shifts.report', $outgoingShiftId).'?tab=inventory-accountability')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.inventory_accountability.items.0.outgoing_actual_quantity', 20)
                ->where('report.inventory_accountability.items.0.gap_net_quantity', 24)
                ->where('report.inventory_accountability.items.0.handover_expected_quantity', 44)
                ->where('report.inventory_accountability.items.0.incoming_verified_quantity', 44)
                ->where('report.inventory_accountability.items.0.handover_difference', 0)
                ->where('report.inventory_accountability.items.0.handover_difference_label', 'BALANCED')
            );
    }

    public function test_hardening_f_gap_then_incoming_short_disputes_against_handover_expected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 11:00:00'));
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ]);
        $this->endCashShift($desk);
        Carbon::setTestNow(Carbon::parse('2026-08-20 11:10:00'));
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Authorized gap restock',
        ]);

        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 43]]),
        ])->assertSessionHas('error');
        $this->assertSame(InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::first()->status);

        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 43]]),
            'reason' => 'One bottle missing versus expected at handover',
        ])->assertSessionHasNoErrors();

        $line = InventoryShiftTurnover::where('status', InventoryShiftTurnover::STATUS_DISPUTED)->firstOrFail()->items()->first();
        $this->assertSame(20, (int) $line->outgoing_actual_quantity);
        $this->assertSame(24, (int) $line->gap_net_quantity);
        $this->assertSame(44, (int) $line->handover_expected_quantity);
        $this->assertSame(43, (int) $line->incoming_verified_quantity);
        $this->assertSame(-1, (int) $line->handover_difference);
        $this->assertSame('SHORT 1', $this->turnovers->varianceLabel((int) $line->handover_difference));
    }

    public function test_hardening_g_bootstrap_physical_short_writes_immutable_reconciliation_movement(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 25);

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.opening'), [
            'counts' => $this->countRows([[$coke, 23]]),
        ])->assertSessionHasNoErrors();

        $this->assertSame(23, (int) $coke->fresh()->current_stock);
        $line = $this->lineFor($coke);
        $this->assertSame(23, (int) $line->opening_quantity);

        $movement = InventoryStockMovement::where('movement_type', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)->firstOrFail();
        $this->assertSame(25, (int) $movement->stock_before);
        $this->assertSame(23, (int) $movement->stock_after);
        $this->assertSame(-2, (int) $movement->quantity_change);
        $this->assertSame($desk->id, (int) $movement->performed_by);
        $this->assertSame(InventoryShiftTurnover::SOURCE_OPENING, $movement->source_type);
        $this->assertNotNull($movement->created_at);
        $this->assertStringContainsString('Bootstrap physical opening reconciliation', (string) $movement->notes);
        $this->assertSame(InventoryStockMovement::TYPE_INVENTORY_VARIANCE, $movement->movement_type);

        $this->startCount($desk);
        $frozen = $this->lineFor($coke);
        $this->assertSame(23, (int) $frozen->opening_quantity);
        $this->assertSame(23, (int) $frozen->expected_closing_quantity);
        $this->assertSame(0, (int) $frozen->restock_quantity);
        $this->assertSame(0, (int) $frozen->other_out_quantity);
        $this->assertSame(0, (int) $frozen->sold_quantity);
    }

    public function test_hardening_h_bootstrap_matching_live_stock_does_not_write_zero_movement(): void
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 23);

        $this->actingAs($desk)->post(route('shifts.inventory_turnover.opening'), [
            'counts' => $this->countRows([[$coke, 23]]),
        ])->assertSessionHasNoErrors();

        $this->assertSame(23, (int) $this->lineFor($coke)->opening_quantity);
        $this->assertSame(23, (int) $coke->fresh()->current_stock);
        $this->assertSame(0, InventoryStockMovement::count());
    }

    private function assertBootstrapBlocked($response): void
    {
        $response->assertForbidden();
        $this->assertSame(
            InventoryTurnoverService::BOOTSTRAP_MESSAGE,
            $response->exception?->getMessage()
        );
    }

    private function readyTrackedDesk(): array
    {
        $desk = $this->frontDesk();
        $coke = $this->tracked('Coke', 35);
        $this->bootstrapOpening($desk, [[$coke, 35]]);

        return [$desk, $coke];
    }

    private function bootstrapOpening(User $desk, array $pairs): void
    {
        $this->turnovers->ensureForShift(ShiftService::activeRegister());
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.opening'), [
            'counts' => $this->countRows($pairs),
        ])->assertSessionHasNoErrors();
    }

    private function startCount(User $desk): void
    {
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.start_counting'))->assertSessionHasNoErrors();
    }

    private function sell(User $desk, InventoryItem $item, int $qty, float $price): void
    {
        $this->actingAs($desk)->post(route('pos.checkout'), [
            'consumer_name' => 'Walk In',
            'items' => [['item_id' => $item->id, 'quantity' => $qty]],
            'payment_method' => 'cash',
            'cash_amount' => $price * $qty,
        ])->assertSessionHasNoErrors();
    }

    private function endCashShift(User $user, float $rooms = 1000, float $minibar = 500): void
    {
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => $rooms,
            'closing_cash_minibar' => $minibar,
        ])->assertSessionHasNoErrors();
    }

    private function countRows(array $pairs): array
    {
        return collect($pairs)->map(fn ($pair) => [
            'inventory_item_id' => $pair[0]->id,
            'quantity' => $pair[1],
        ])->all();
    }

    private function lineFor(InventoryItem $item): InventoryShiftCountItem
    {
        return InventoryShiftCountItem::where('inventory_item_id', $item->id)->latest('id')->firstOrFail();
    }

    private function tracked(string $name, int $stock, string $category = 'minibar', float $price = 25): InventoryItem
    {
        return InventoryItem::create([
            'item_name' => $name,
            'category' => $category,
            'unit' => 'piece',
            'current_stock' => $stock,
            'minimum_stock' => 2,
            'unit_cost' => 10,
            'selling_price' => $price,
            'is_active' => true,
            'is_turnover_tracked' => true,
        ]);
    }

    private function untracked(string $name, int $stock): InventoryItem
    {
        return InventoryItem::create([
            'item_name' => $name,
            'category' => 'laundry',
            'unit' => 'service',
            'current_stock' => $stock,
            'minimum_stock' => 1,
            'unit_cost' => 20,
            'selling_price' => 50,
            'is_active' => true,
            'is_turnover_tracked' => false,
        ]);
    }

    private function configureAmenity(InventoryItem $item): void
    {
        StayAmenityPolicy::create([
            'stay_key' => StayAmenityPolicy::STAY_OVERNIGHT,
            'inventory_item_id' => $item->id,
            'default_quantity' => 1,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'full_name' => 'Phase3 Admin']);
    }

    private function frontDesk(string $username = 'phase3_desk'): User
    {
        $user = User::factory()->create([
            'role' => 'front_desk',
            'username' => $username,
            'full_name' => 'Phase3 Desk '.$username,
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

    private function activeStay(User $user): Booking
    {
        $type = RoomType::create([
            'type_name' => 'Phase3 Room '.uniqid(),
            'base_rate' => 1000,
            'hourly_rate' => 200,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'P3'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => 'occupied',
        ]);

        return Booking::create([
            'booking_ref' => 'BKG-'.$room->room_number,
            'room_id' => $room->id,
            'guest_name' => 'Phase3 Guest',
            'num_guests' => 1,
            'booking_type' => 'overnight',
            'check_in' => now()->subHour(),
            'expected_check_out' => now()->addHours(10),
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'amount_paid' => 1000,
            'checked_in_by' => $user->id,
        ]);
    }
}
