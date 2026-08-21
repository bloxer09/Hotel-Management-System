<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryShiftTurnover;
use App\Models\InventoryStockMovement;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\InventoryTurnoverService;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryTurnoverPhase4Test extends TestCase
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

    public function test_a_balanced_incoming_handover_allows_accept(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(InventoryShiftTurnover::STATUS_ACCEPTED, InventoryShiftTurnover::first()->status);
    }

    public function test_b_handover_mismatch_cannot_normal_accept(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 34]]),
        ])->assertSessionHas('error');
        $this->assertSame(InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::first()->status);
    }

    public function test_c_mismatch_may_be_marked_disputed_only_with_reason(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);
        $next = $this->frontDesk('incoming_desk');
        $stock = (int) $coke->fresh()->current_stock;
        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 34]]),
            'reason' => 'One Coke could not be located during incoming verification.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(InventoryShiftTurnover::STATUS_DISPUTED, InventoryShiftTurnover::first()->status);
        $this->assertSame($stock, (int) $coke->fresh()->current_stock);
        $this->assertTrue(AuditLog::where('action', 'INVENTORY_HANDOVER_DISPUTED')->exists());
    }

    public function test_d_empty_dispute_reason_rejected(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 34]]),
            'reason' => '',
        ])->assertSessionHasErrors('reason');
        $this->assertSame(InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::first()->status);
    }

    public function test_e_front_desk_cannot_admin_resolve_dispute(): void
    {
        $data = $this->disputedHandover();
        $this->actingAs($data['next'])->post(route('shifts.inventory_turnover.resolve', $data['turnover']), [
            'counts' => $this->countRows([[$data['coke'], 34]]),
            'reason' => 'Should be forbidden',
        ])->assertForbidden();
    }

    public function test_f_housekeeping_cannot_resolve_or_view_restricted_turnover_admin_pages(): void
    {
        $data = $this->disputedHandover();
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)->get(route('shifts.inventory_turnover.history'))->assertForbidden();
        $this->actingAs($housekeeper)->get(route('shifts.inventory_turnover.show_record', $data['turnover']))->assertForbidden();
        $this->actingAs($housekeeper)->get(route('shifts.inventory_turnover.print', $data['turnover']))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('shifts.inventory_turnover.resolve', $data['turnover']), [
            'counts' => $this->countRows([[$data['coke'], 34]]),
            'reason' => 'No',
        ])->assertForbidden();
    }

    public function test_g_admin_sees_outgoing_variance_separately_from_handover_difference(): void
    {
        $data = $this->manualCaseB();
        $this->actingAs($this->admin())
            ->get(route('shifts.inventory_turnover.show_record', $data['turnover']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/InventoryTurnoverShow')
                ->where('turnover.items.0.expected_closing_quantity', 34)
                ->where('turnover.items.0.outgoing_actual_quantity', 33)
                ->where('turnover.items.0.variance_quantity', -1)
                ->where('turnover.items.0.variance_label', 'SHORT 1')
                ->where('turnover.items.0.gap_net_quantity', 10)
                ->where('turnover.items.0.handover_expected_quantity', 43)
                ->where('turnover.items.0.incoming_verified_quantity', 42)
                ->where('turnover.items.0.handover_difference', -1)
                ->where('turnover.items.0.handover_difference_label', 'SHORT 1')
            );
    }

    public function test_h_through_n_admin_accepts_confirmed_incoming_physical_quantity(): void
    {
        $data = $this->manualCaseB();
        $turnover = $data['turnover'];
        $coke = $data['coke'];
        $expected = (int) $turnover->items()->first()->expected_closing_quantity;
        $actual = (int) $turnover->items()->first()->outgoing_actual_quantity;
        $variance = (int) $turnover->items()->first()->variance_quantity;
        $beforeStock = (int) $coke->fresh()->current_stock;

        $this->actingAs($this->admin())->post(route('shifts.inventory_turnover.resolve', $turnover), [
            'counts' => $this->countRows([[$coke, 42]]),
            'reason' => 'Confirmed physical stock really is 42.',
        ])->assertSessionHasNoErrors();

        $turnover->refresh();
        $line = $turnover->items()->first();
        $this->assertSame(InventoryShiftTurnover::STATUS_ACCEPTED, $turnover->status);
        $this->assertSame($expected, (int) $line->expected_closing_quantity);
        $this->assertSame($actual, (int) $line->outgoing_actual_quantity);
        $this->assertSame($variance, (int) $line->variance_quantity);
        $this->assertSame(42, (int) $line->incoming_verified_quantity);
        $this->assertSame(-1, (int) $line->handover_difference);
        $this->assertSame(42, (int) $coke->fresh()->current_stock);

        $movement = InventoryStockMovement::query()
            ->where('source_type', InventoryShiftTurnover::SOURCE_RESOLUTION)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($beforeStock, (int) $movement->stock_before);
        $this->assertSame(42, (int) $movement->stock_after);
        $this->assertSame($this->adminUser()->id, (int) $movement->performed_by);
        $this->assertNotNull($movement->created_at);
        $this->assertStringContainsString('Admin handover resolution', (string) $movement->notes);

        $nextOpening = InventoryShiftTurnover::where('shift_session_id', ShiftService::activeRegister()->id)->firstOrFail();
        $this->assertSame(42, (int) $nextOpening->items()->first()->opening_quantity);
        $this->assertTrue(AuditLog::where('action', 'INVENTORY_HANDOVER_DISPUTE_RESOLVED')->exists());
    }

    public function test_o_resolution_has_no_cash_or_minibar_effect(): void
    {
        $data = $this->manualCaseB();
        $closed = ShiftSession::whereNotNull('ended_at')->firstOrFail();
        $rooms = (float) $closed->variance_rooms;
        $minibar = (float) $closed->variance_minibar;
        $usages = \App\Models\InventoryUsage::count();
        $this->actingAs($this->admin())->post(route('shifts.inventory_turnover.resolve', $data['turnover']), [
            'counts' => $this->countRows([[$data['coke'], 42]]),
            'reason' => 'Confirmed 42',
        ]);
        $closed->refresh();
        $this->assertEqualsWithDelta($rooms, (float) $closed->variance_rooms, 0.01);
        $this->assertEqualsWithDelta($minibar, (float) $closed->variance_minibar, 0.01);
        $this->assertSame($usages, \App\Models\InventoryUsage::count());
        $this->assertEqualsWithDelta(0.0, (float) $closed->variance_minibar, 0.01);
    }

    public function test_p_recount_request_keeps_turnover_disputed_and_unaccepted(): void
    {
        $data = $this->disputedHandover();
        $stock = (int) $data['coke']->fresh()->current_stock;
        $this->actingAs($this->admin())->post(route('shifts.inventory_turnover.recount', $data['turnover']), [
            'reason' => 'Please recount Coke on the shelf.',
        ])->assertSessionHasNoErrors();
        $data['turnover']->refresh();
        $this->assertSame(InventoryShiftTurnover::STATUS_DISPUTED, $data['turnover']->status);
        $this->assertNull($data['turnover']->accepted_at);
        $this->assertSame(InventoryShiftTurnover::RESOLUTION_REQUIRE_RECOUNT, $data['turnover']->resolution_type);
        $this->assertSame($stock, (int) $data['coke']->fresh()->current_stock);
        $this->assertTrue(AuditLog::where('action', 'INVENTORY_HANDOVER_RECOUNT_REQUESTED')->exists());
    }

    public function test_q_gap_plus_24_displayed_separately_balanced_incoming_difference_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00'));
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 20]]),
        ]);
        $this->endCashShift($desk);
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:10:00'));
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 24,
            'reason' => 'Gap restock',
        ]);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->get(route('shifts.inventory_turnover.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending_handover.items.0.gap_net_quantity', 24)
                ->where('pending_handover.items.0.handover_expected_quantity', 44)
            );
        $this->actingAs($next)->post(route('shifts.inventory_turnover.accept'), [
            'counts' => $this->countRows([[$coke, 44]]),
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) InventoryShiftTurnover::first()->items()->first()->handover_difference);
    }

    public function test_r_historical_turnover_detail_uses_frozen_values(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $turnover = InventoryShiftTurnover::firstOrFail();
        InventoryStockMovement::record([
            'inventory_item_id' => $coke->id,
            'movement_type' => InventoryStockMovement::TYPE_POS_SALE,
            'quantity_change' => -4,
            'stock_before' => 33,
            'stock_after' => 29,
            'performed_by' => $desk->id,
            'shift_session_id' => $turnover->shift_session_id,
            'notes' => 'Late row must not rewrite frozen expected',
        ]);
        $this->actingAs($desk)->get(route('shifts.inventory_turnover.show_record', $turnover))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('turnover.items.0.expected_closing_quantity', 34)
                ->where('turnover.items.0.sold_quantity', 1)
                ->where('turnover.items.0.outgoing_actual_quantity', 33)
            );
    }

    public function test_s_submitted_and_accepted_turnover_cannot_be_hard_deleted(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $turnover = InventoryShiftTurnover::firstOrFail();
        $this->actingAs($this->admin())->delete(route('shifts.inventory_turnover.destroy', $turnover))->assertForbidden();
        $this->assertFalse($turnover->delete());
        $this->assertTrue(InventoryShiftTurnover::whereKey($turnover->id)->exists());
    }

    public function test_t_turnover_history_filters_and_status_work(): void
    {
        $this->test_a_balanced_incoming_handover_allows_accept();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('shifts.inventory_turnover.history', ['status' => 'accepted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/InventoryTurnoverHistory')
                ->where('turnovers.data.0.status', 'accepted')
                ->where('filters.status', 'accepted')
            );
        $this->actingAs($admin)->get(route('shifts.inventory_turnover.history', ['status' => 'disputed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('turnovers.data', []));
    }

    public function test_u_admin_override_reason_appears_in_detail_and_report(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $admin = $this->admin();
        ShiftSession::query()->update(['user_id' => $admin->id]);
        $this->actingAs($admin)->post(route('shifts.end'), [
            'closing_cash' => 1000,
            'closing_cash_minibar' => 500,
            'inventory_override_reason' => 'Register must close; count tomorrow',
        ])->assertSessionHasNoErrors();
        $turnover = InventoryShiftTurnover::firstOrFail();
        $this->actingAs($admin)->get(route('shifts.inventory_turnover.show_record', $turnover))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('turnover.admin_override_reason', 'Register must close; count tomorrow')
            );
        $this->actingAs($admin)->get(route('shifts.report', $turnover->shift_session_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.inventory_accountability.admin_override_reason', 'Register must close; count tomorrow')
            );
    }

    public function test_v_manual_set_warning_appears_where_appropriate(): void
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'set',
            'quantity' => 40,
            'reason' => 'Physical recount SET',
        ]);
        $this->startCount($desk);
        $this->actingAs($desk)->get(route('shifts.inventory_turnover.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current_turnover.has_manual_set', true)
                ->has('current_turnover.manual_set_movements', 1)
            );
    }

    public function test_w_x_printable_report_renders_with_inventory_wording(): void
    {
        $data = $this->manualCaseB();
        $this->actingAs($this->admin())
            ->get(route('shifts.inventory_turnover.print', $data['turnover']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/InventoryTurnoverPrint')
                ->where('title', 'HOTEL INVENTORY TURNOVER REPORT')
                ->where('turnover.items.0.variance_label', 'SHORT 1')
                ->where('turnover.items.0.handover_difference_label', 'SHORT 1')
            );
        $this->assertTrue(AuditLog::where('action', 'INVENTORY_TURNOVER_REPORT_EXPORTED')->exists());
        $payload = $this->turnovers->printPayload($data['turnover']->fresh(['items']));
        $json = json_encode($payload);
        $this->assertStringNotContainsString('Cash Shortage', $json);
        $this->assertStringNotContainsString('Amount Due', $json);
        $this->assertStringNotContainsString('Employee Liability', $json);
        $this->assertSame('SHORT 1', $payload['turnover']['items'][0]['variance_label']);
        $this->assertGreaterThan(0, $payload['turnover']['items'][0]['reference_retail_value']);
    }

    public function test_y_reference_retail_value_is_informational_only(): void
    {
        $data = $this->manualCaseB();
        $this->actingAs($this->admin())->get(route('shifts.inventory_turnover.show_record', $data['turnover']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('turnover.items.0.reference_retail_value', 25)
                ->where('turnover.items.0.handover_reference_retail_value', 25)
            );
    }

    public function test_z_front_desk_access_remains_limited(): void
    {
        $data = $this->disputedHandover();
        $stranger = $this->frontDesk('other_fd');
        $response = $this->actingAs($stranger)->post(route('shifts.inventory_turnover.resolve', $data['turnover']), [
            'counts' => $this->countRows([[$data['coke'], 34]]),
            'reason' => 'No',
        ]);
        $this->assertTrue(in_array($response->status(), [403, 302], true));
        $data['turnover']->refresh();
        $this->assertSame(InventoryShiftTurnover::STATUS_DISPUTED, $data['turnover']->status);
        $this->actingAs($stranger)->get(route('shifts.inventory_turnover.history'))->assertOk();
    }

    private function disputedHandover(): array
    {
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 35]]),
        ]);
        $this->endCashShift($desk);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 34]]),
            'reason' => 'One Coke could not be located during incoming verification.',
        ]);

        return [
            'desk' => $desk,
            'coke' => $coke,
            'next' => $next,
            'turnover' => InventoryShiftTurnover::firstOrFail()->load('items.item'),
        ];
    }

    private function manualCaseB(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 18:00:00'));
        [$desk, $coke] = $this->readyTrackedDesk();
        $this->sell($desk, $coke, 1, 25);
        $this->startCount($desk);
        $this->actingAs($desk)->post(route('shifts.inventory_turnover.submit'), [
            'counts' => $this->countRows([[$coke, 33]]),
        ]);
        $this->endCashShift($desk, 1000, 525);
        Carbon::setTestNow(Carbon::parse('2026-08-20 18:20:00'));
        $this->actingAs($this->admin())->post(route('inventory.adjust', $coke), [
            'adjustment_type' => 'add',
            'quantity' => 10,
            'reason' => 'Between-shift restock',
        ]);
        $next = $this->frontDesk('incoming_desk');
        $this->actingAs($next)->post(route('shifts.inventory_turnover.dispute'), [
            'counts' => $this->countRows([[$coke, 42]]),
            'reason' => 'Incoming count is 42 versus expected 43.',
        ]);

        return [
            'desk' => $desk,
            'coke' => $coke,
            'next' => $next,
            'turnover' => InventoryShiftTurnover::where('status', InventoryShiftTurnover::STATUS_DISPUTED)->firstOrFail()->load('items'),
        ];
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

    private function admin(): User
    {
        return $this->adminUser();
    }

    private function adminUser(): User
    {
        return User::query()->where('role', 'admin')->first()
            ?? User::factory()->create(['role' => 'admin', 'full_name' => 'Phase4 Admin']);
    }

    private function frontDesk(string $username = 'phase4_desk'): User
    {
        $existing = User::query()->where('username', $username)->first();
        if ($existing) {
            if (! ShiftService::activeRegister() || (int) ShiftService::activeRegister()->user_id !== (int) $existing->id) {
                ShiftSession::create([
                    'user_id' => $existing->id,
                    'shift_code' => 'morning',
                    'started_at' => now()->subHour(),
                    'opening_cash' => 1000,
                    'opening_cash_minibar' => 500,
                ]);
            }

            return $existing;
        }
        $user = User::factory()->create([
            'role' => 'front_desk',
            'username' => $username,
            'full_name' => 'Phase4 Desk '.$username,
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
}
