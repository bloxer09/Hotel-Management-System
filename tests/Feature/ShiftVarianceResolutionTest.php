<?php

namespace Tests\Feature;

use App\Models\AdditionalCash;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\ShiftVarianceResolution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShiftCashReconciliationService;
use App\Services\ShiftVarianceResolutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftVarianceResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortage_close_snapshots_pending_review(): void
    {
        $fd = $this->frontDesk('a');
        $shift = $this->closeShortage($fd);

        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(14500.0, (float) $shift->closing_cash);
        $this->assertSame(-500.0, (float) $shift->variance_rooms);
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);
        $this->assertSame(ShiftCashReconciliationService::FORMULA_VERSION, $shift->expected_formula_version);
    }

    public function test_overage_close_snapshots_pending_review(): void
    {
        $fd = $this->frontDesk('b');
        $shift = $this->closeOverage($fd);

        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(15300.0, (float) $shift->closing_cash);
        $this->assertSame(300.0, (float) $shift->variance_rooms);
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);
    }

    public function test_front_desk_submit_does_not_change_original_variance(): void
    {
        $fd = $this->frontDesk('c');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Partial replenishment'))
            ->assertRedirect();

        $shift->refresh();
        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->assertSame(ShiftVarianceResolution::STATUS_SUBMITTED, $resolution->status);
        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(14500.0, (float) $shift->closing_cash);
        $this->assertSame(-500.0, (float) $shift->variance_rooms);
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);
        $this->assertSame(500.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift, 'room'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'SHIFT_VARIANCE_SUBMITTED']);
    }

    public function test_admin_approves_partial_then_final_recovery(): void
    {
        $fd = $this->frontDesk('d');
        $admin = $this->admin('d');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Partial replenishment'));
        $first = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $first), ['review_notes' => 'Partial accepted']);

        $shift->refresh();
        $this->assertSame(ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED, $shift->variance_status);
        $this->assertSame(200.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift, 'room'));
        $this->assertSame(-500.0, (float) $shift->variance_rooms);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(200, 'Final replenishment'));
        $second = ShiftVarianceResolution::query()->orderByDesc('id')->firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $second), ['review_notes' => 'Cleared']);

        $shift->refresh();
        $this->assertSame(ShiftVarianceResolutionService::STATUS_RESOLVED, $shift->variance_status);
        $this->assertSame(0.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift, 'room'));
        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(14500.0, (float) $shift->closing_cash);
        $this->assertSame(-500.0, (float) $shift->variance_rooms);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SHIFT_VARIANCE_APPROVED']);
    }

    public function test_rejected_resolution_does_not_reduce_remaining(): void
    {
        $fd = $this->frontDesk('e');
        $admin = $this->admin('e');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Please accept'));
        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.reject', $resolution), [
            'review_notes' => 'Need more evidence',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(ShiftVarianceResolution::STATUS_REJECTED, $resolution->fresh()->status);
        $this->assertSame(500.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift, 'room'));
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SHIFT_VARIANCE_REJECTED']);
    }

    public function test_front_desk_cannot_approve_own_resolution(): void
    {
        $fd = $this->frontDesk('f');
        $shift = $this->closeShortage($fd);
        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Own recovery'));
        $resolution = ShiftVarianceResolution::firstOrFail();

        $this->actingAs($fd)
            ->post(route('shifts.variances.approve', $resolution), ['review_notes' => 'Self approve'])
            ->assertForbidden();
        $this->assertSame(ShiftVarianceResolution::STATUS_SUBMITTED, $resolution->fresh()->status);
    }

    public function test_front_desk_cannot_submit_against_another_users_shift(): void
    {
        $fd1 = $this->frontDesk('g1');
        $fd2 = $this->frontDesk('g2');
        $shift = $this->closeShortage($fd1);

        $this->actingAs($fd2)
            ->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Not my shift'))
            ->assertForbidden();
        $this->assertDatabaseCount('shift_variance_resolutions', 0);
    }

    public function test_admin_can_review_all_variances(): void
    {
        $fd = $this->frontDesk('h');
        $admin = $this->admin('h');
        $shift = $this->closeShortage($fd);

        $this->actingAs($admin)
            ->get(route('shifts.variances.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/Variances')
                ->has('rows', 1)
                ->where('rows.0.shift_id', $shift->id)
                ->where('filter', 'pending')
                ->where('rows.0.review_url', fn ($url) => str_contains((string) $url, '/shifts/'.$shift->id.'/report')
                    && str_contains((string) $url, 'tab=variance'))
            );

        $this->actingAs($admin)
            ->get(route('shifts.report', $shift->id).'?tab=variance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/Report')
                ->where('shift.id', $shift->id)
                ->where('report.cash_variance_review.can_review', true)
                ->where('report.cash_variance_review.can_submit', false)
                ->where('report.cash_variance_review.can_resolve', true)
                ->where('report.cash_variance_review.overall_status', ShiftCashReconciliationService::STATUS_PENDING_REVIEW)
                ->where('report.cash_variance_review.resolutions', [])
                ->where('report.cash_variance_review.rooms.remaining', fn ($value) => (float) $value === 500.0)
            );
    }

    public function test_front_desk_cannot_access_admin_variance_queue(): void
    {
        $fd = $this->frontDesk('hfd');
        $this->closeShortage($fd);

        $this->actingAs($fd)
            ->get(route('shifts.variances.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_submitted_resolution_on_shift_report(): void
    {
        $fd = $this->frontDesk('hsub');
        $admin = $this->admin('hsub');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(500, 'Shortage recovered in office'));

        $this->actingAs($admin)
            ->get(route('shifts.report', $shift->id).'?tab=variance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/Report')
                ->has('report.cash_variance_review.resolutions', 1)
                ->where('report.cash_variance_review.resolutions.0.status', ShiftVarianceResolution::STATUS_SUBMITTED)
                ->where('report.cash_variance_review.resolutions.0.resolution_type', 'shortage_recovery')
                ->where('report.cash_variance_review.resolutions.0.amount', fn ($value) => (float) $value === 500.0)
                ->where('report.cash_variance_review.can_review', true)
                ->where('report.cash_variance_review.can_submit', false)
                ->where('report.cash_variance_review.rooms.remaining', fn ($value) => (float) $value === 500.0)
            );
    }

    public function test_front_desk_report_shows_submit_form_not_admin_record_controls(): void
    {
        $fd = $this->frontDesk('fdui');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)
            ->get(route('shifts.report', $shift->id).'?tab=variance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shifts/Report')
                ->where('report.cash_variance_review.can_submit', true)
                ->where('report.cash_variance_review.can_review', false)
                ->where('report.cash_variance_review.resolutions', [])
            );

        $this->actingAs($fd)
            ->post(route('shifts.variances.record', $shift), $this->submitPayload(500, 'Should not record'))
            ->assertForbidden();
    }

    public function test_queue_and_report_timestamps_display_asia_manila_from_utc_storage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 13:19:34', 'UTC'));

        try {
            $fd = $this->frontDesk('tzq');
            $admin = $this->admin('tzq');
            $shift = $this->closeShortage($fd);
            $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(100, 'Partial note'));

            $this->assertSame(
                '2026-08-18 13:19:34',
                $shift->fresh()->ended_at->copy()->utc()->format('Y-m-d H:i:s')
            );

            $this->actingAs($admin)
                ->get(route('shifts.variances.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('rows.0.closed_at', '2026-08-18T13:19:34Z')
                    ->where('rows.0.closed_at_display', '8/18/2026, 9:19:34 PM')
                );

            $this->actingAs($admin)
                ->get(route('shifts.report', $shift->id).'?tab=variance')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('report.cash_variance_review.resolutions.0.created_at', '2026-08-18T13:19:34Z')
                    ->where('report.cash_variance_review.resolutions.0.created_at_display', '8/18/2026, 9:19:34 PM')
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_eighty_five_peso_shortage_stays_open_until_admin_approval(): void
    {
        $fd = $this->frontDesk('85');
        $admin = $this->admin('85');
        $shift = $this->openShift($fd, 15000);
        $this->actingAs($fd)->post(route('shifts.end'), [
            'closing_cash' => 14915,
            'closing_denominations' => ['1000' => 14, '500' => 1, '100' => 4, '10' => 1, '5' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Short eighty five.',
        ])->assertRedirect();
        $shift = $shift->fresh();

        $this->assertSame(-85.0, (float) $shift->variance_rooms);
        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(14915.0, (float) $shift->closing_cash);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(85, 'Shortage recovered'));
        $shift->refresh();
        $this->assertSame(-85.0, (float) $shift->variance_rooms);
        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(14915.0, (float) $shift->closing_cash);
        $this->assertSame(85.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift, 'room'));
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);

        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $resolution), [
            'review_notes' => 'Cleared to office',
            'recovery_destination' => 'office_safe',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(ShiftVarianceResolutionService::STATUS_RESOLVED, $shift->variance_status);
        $this->assertSame(0.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift, 'room'));
        $this->assertSame(-85.0, (float) $shift->variance_rooms);
        $this->assertSame(15000.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(14915.0, (float) $shift->closing_cash);
        $this->assertNull($resolution->fresh()->cash_received_into_shift_id);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );
    }

    public function test_housekeeping_is_forbidden_from_variance_workflow(): void
    {
        $fd = $this->frontDesk('i');
        $hk = User::factory()->create(['role' => 'housekeeping', 'username' => 'hk_var_'.uniqid()]);
        $shift = $this->closeShortage($fd);
        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(100, 'Note for hk test'));
        $resolution = ShiftVarianceResolution::firstOrFail();

        $this->actingAs($hk)->get(route('shifts.variances.index'))->assertForbidden();
        $this->actingAs($hk)->post(route('shifts.variances.store', $shift), $this->submitPayload(100, 'Housekeeping'))->assertForbidden();
        $this->actingAs($hk)->post(route('shifts.variances.approve', $resolution))->assertForbidden();
        $this->actingAs($hk)->post(route('shifts.variances.reject', $resolution), ['review_notes' => 'No'])->assertForbidden();
        $this->actingAs($hk)->post(route('shifts.variances.record', $shift), $this->submitPayload(100, 'Housekeeping'))->assertForbidden();
    }

    public function test_cannot_approve_more_than_remaining_variance(): void
    {
        $fd = $this->frontDesk('j');
        $admin = $this->admin('j');
        $shift = $this->closeShortage($fd);

        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.record', $shift), [
                'drawer' => 'room',
                'resolution_type' => 'admin_adjustment',
                'amount' => 400,
                'notes' => 'First chunk',
            ])
            ->assertRedirect();

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(200, 'Too much'));
        $resolution = ShiftVarianceResolution::query()->where('status', 'submitted')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.approve', $resolution), ['review_notes' => 'Should fail'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(100.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift->fresh(), 'room'));
        $this->assertSame(ShiftVarianceResolution::STATUS_SUBMITTED, $resolution->fresh()->status);
    }

    public function test_two_approvals_cannot_over_resolve_remaining(): void
    {
        $fd = $this->frontDesk('k');
        $admin = $this->admin('k');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(400, 'First race'));
        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(400, 'Second race'));
        [$first, $second] = ShiftVarianceResolution::orderBy('id')->get()->all();

        $this->actingAs($admin)->post(route('shifts.variances.approve', $first), ['review_notes' => 'First wins']);
        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.approve', $second), ['review_notes' => 'Too late'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(ShiftVarianceResolution::STATUS_APPROVED, $first->fresh()->status);
        $this->assertSame(ShiftVarianceResolution::STATUS_SUBMITTED, $second->fresh()->status);
        $this->assertSame(100.0, app(ShiftVarianceResolutionService::class)->remainingMagnitude($shift->fresh(), 'room'));
        $this->assertSame(-500.0, (float) $shift->fresh()->variance_rooms);
    }

    public function test_original_snapshot_unchanged_after_every_resolution(): void
    {
        $fd = $this->frontDesk('l');
        $admin = $this->admin('l');
        $shift = $this->closeShortage($fd);
        $original = $shift->only([
            'expected_cash_rooms',
            'expected_cash_minibar',
            'closing_cash',
            'closing_cash_minibar',
            'variance_rooms',
            'variance_minibar',
            'expected_formula_version',
        ]);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Step 1'));
        $this->actingAs($admin)->post(route('shifts.variances.approve', ShiftVarianceResolution::first()), ['review_notes' => 'Ok']);
        $this->actingAs($admin)->post(route('shifts.variances.record', $shift), [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => 200,
            'notes' => 'Final admin record',
        ]);

        $this->assertEquals($original, $shift->fresh()->only(array_keys($original)));
    }

    public function test_rooms_and_minibar_are_resolved_separately_and_do_not_net(): void
    {
        $fd = $this->frontDesk('m');
        $admin = $this->admin('m');
        $shift = $this->closeOffsettingDrawers($fd);

        $this->assertSame(-500.0, (float) $shift->variance_rooms);
        $this->assertSame(500.0, (float) $shift->variance_minibar);
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);

        $this->actingAs($admin)->post(route('shifts.variances.record', $shift), [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => 500,
            'notes' => 'Rooms only',
        ]);

        $shift->refresh();
        $service = app(ShiftVarianceResolutionService::class);
        $this->assertSame(0.0, $service->remainingMagnitude($shift, 'room'));
        $this->assertSame(500.0, $service->remainingMagnitude($shift, 'minibar'));
        $this->assertSame(ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED, $shift->variance_status);
        $this->assertNotSame(ShiftCashReconciliationService::STATUS_BALANCED, $shift->variance_status);
    }

    public function test_overage_resolution_does_not_change_next_shift_opening(): void
    {
        $fd1 = $this->frontDesk('n1');
        $fd2 = $this->frontDesk('n2');
        $admin = $this->admin('n');
        $old = $this->closeOverage($fd1);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 15300,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();

        $next = ShiftSession::active()->firstOrFail();
        $this->assertSame(15300.0, (float) $next->opening_cash);

        $this->actingAs($admin)->post(route('shifts.variances.record', $old), [
            'drawer' => 'room',
            'resolution_type' => 'approved_unidentified_overage',
            'amount' => 300,
            'notes' => 'Accepted unidentified overage',
        ])->assertRedirect();

        $this->assertSame(15300.0, (float) $next->fresh()->opening_cash);
        $this->assertSame(300.0, (float) $old->fresh()->variance_rooms);
        $this->assertSame(15300.0, (float) $old->fresh()->closing_cash);
    }

    public function test_shortage_recovery_into_current_drawer_increases_expected_once_without_sales(): void
    {
        $fd1 = $this->frontDesk('o1');
        $fd2 = $this->frontDesk('o2');
        $admin = $this->admin('o');
        $old = $this->closeShortage($fd1);

        $booking = $this->makePaidBooking($fd2, 800);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 14500,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();
        $current = ShiftSession::active()->firstOrFail();

        Transaction::create([
            'transaction_type' => 'check_in',
            'description' => 'Room cash',
            'amount' => 200,
            'payment_method' => 'cash',
            'cash_amount' => 200,
            'processed_by' => $fd2->id,
        ]);

        $before = app(ShiftCashReconciliationService::class)->forShift($current);
        $this->assertSame(14700.0, $before['rooms']['expected_cash']);
        $this->assertSame(200.0, $before['rooms']['cash_collections']);
        $this->assertSame(0.0, $before['rooms']['variance_recovery_receipts']);

        $this->actingAs($admin)->post(route('shifts.variances.record', $old), [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => 500,
            'notes' => 'Cash handed to current register',
            'recovery_destination' => 'active_drawer',
        ])->assertRedirect();

        $after = app(ShiftCashReconciliationService::class)->forShift($current->fresh());
        $this->assertSame(15200.0, $after['rooms']['expected_cash']);
        $this->assertSame(500.0, $after['rooms']['variance_recovery_receipts']);
        $this->assertSame(0.0, $after['minibar']['variance_recovery_receipts']);
        $this->assertSame(200.0, $after['rooms']['cash_collections']);
        $this->assertSame(0.0, $after['rooms']['additional_cash']);
        $this->assertSame(800.0, (float) $booking->fresh()->amount_paid);
        $this->assertSame(14500.0, (float) $old->fresh()->closing_cash);
        $this->assertSame(-500.0, (float) $old->fresh()->variance_rooms);
        $this->assertSame($current->id, ShiftVarianceResolution::first()->cash_received_into_shift_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SHIFT_VARIANCE_RECOVERY_RECEIVED']);
        $this->assertDatabaseCount('additional_cash', 0);
    }

    public function test_office_safe_recovery_does_not_change_current_expected_cash(): void
    {
        $fd1 = $this->frontDesk('p1');
        $fd2 = $this->frontDesk('p2');
        $admin = $this->admin('p');
        $old = $this->closeShortage($fd1);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 14500,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();
        $current = ShiftSession::active()->firstOrFail();

        $this->actingAs($admin)->post(route('shifts.variances.record', $old), [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => 500,
            'notes' => 'Deposited to office safe',
            'recovery_destination' => 'office_safe',
        ])->assertRedirect();

        $live = app(ShiftCashReconciliationService::class)->forShift($current->fresh());
        $this->assertSame(14500.0, $live['rooms']['expected_cash']);
        $this->assertSame(0.0, $live['rooms']['variance_recovery_receipts']);
        $this->assertNull(ShiftVarianceResolution::first()->cash_received_into_shift_id);

        $this->actingAs($fd2)->get(route('shifts.report', $current->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.daily_cash_report.variance_recovery_receipts', 0)
                ->where('report.daily_cash_report.total_cash_received', 0)
            );

        $this->actingAs($fd2)->get(route('shifts.ledger-print', $current->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_tally.variance_recovery_receipts', 0)
                ->where('cash_tally.total_cash_received', 0)
            );
    }

    public function test_daily_cash_tally_shows_shortage_recovery_on_report_and_ledger(): void
    {
        $fd1 = $this->frontDesk('p1a');
        $fd2 = $this->frontDesk('p2a');
        $admin = $this->admin('pa');
        $old = $this->closeShortage($fd1);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 6900,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
            'handover_notes' => 'Verified opening count after prior shift close.',
        ])->assertRedirect();
        $current = ShiftSession::active()->firstOrFail();

        Transaction::create([
            'transaction_type' => 'check_in',
            'description' => 'Room cash',
            'amount' => 1700,
            'payment_method' => 'cash',
            'cash_amount' => 1700,
            'processed_by' => $fd2->id,
        ]);

        $this->actingAs($admin)->post(route('shifts.variances.record', $old), [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => 200,
            'notes' => 'Cash handed to current register',
            'recovery_destination' => 'active_drawer',
        ])->assertRedirect();

        $reconciliation = app(ShiftCashReconciliationService::class)->forShift($current->fresh());
        $this->assertSame(200.0, $reconciliation['rooms']['variance_recovery_receipts']);
        $this->assertSame(1700.0, $reconciliation['rooms']['cash_collections']);
        $this->assertSame(8800.0, $reconciliation['rooms']['total_cash_available']);
        $this->assertSame(8800.0, $reconciliation['rooms']['expected_cash']);

        $old->refresh();
        $this->assertSame(-500.0, (float) $old->variance_rooms);
        $this->assertSame(14500.0, (float) $old->closing_cash);
        $this->assertSame(15000.0, (float) $old->expected_cash_rooms);

        $this->actingAs($fd2)->get(route('shifts.report', $current->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.daily_cash_report.room_sales_cash', 1700)
                ->where('report.daily_cash_report.variance_recovery_receipts', 200)
                ->where('report.daily_cash_report.total_cash_received', 1900)
                ->where('report.daily_cash_report.total_cash_available', 8800)
                ->where('report.daily_cash_report.expected_cash', 8800)
            );

        $this->actingAs($fd2)->get(route('shifts.ledger-print', $current->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_tally.room_sales_cash', 1700)
                ->where('cash_tally.variance_recovery_receipts', 200)
                ->where('cash_tally.total_cash_received', 1900)
                ->where('cash_tally.total_cash_available', 8800)
                ->where('cash_tally.expected_cash', 8800)
            );
    }

    public function test_legacy_shift_cannot_be_formally_resolved(): void
    {
        $fd = $this->frontDesk('q');
        $admin = $this->admin('q');
        $legacy = ShiftSession::create([
            'user_id' => $fd->id,
            'shift_code' => 'morning',
            'started_at' => now()->subHours(8),
            'ended_at' => now()->subHour(),
            'opening_cash' => 15000,
            'closing_cash' => 14500,
            'opening_cash_minibar' => 0,
            'closing_cash_minibar' => 0,
            'expected_formula_version' => null,
            'variance_status' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('shifts.report', $legacy->id))
            ->post(route('shifts.variances.record', $legacy), [
                'drawer' => 'room',
                'resolution_type' => 'admin_adjustment',
                'amount' => 500,
                'notes' => 'Backfill fake snapshot',
            ])
            ->assertSessionHasErrors('shift');

        $this->actingAs($admin)
            ->get(route('shifts.report', $legacy->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.cash_variance_review.is_legacy', true)
                ->where('report.cash_variance_review.can_resolve', false)
                ->where('report.cash_variance_review.legacy_message', ShiftVarianceResolutionService::LEGACY_MESSAGE)
            );
    }

    public function test_notifications_go_to_admin_and_responsible_front_desk_not_housekeeping(): void
    {
        $fd = $this->frontDesk('r');
        $admin = $this->admin('r');
        $otherFd = $this->frontDesk('r2');
        $hk = User::factory()->create(['role' => 'housekeeping', 'username' => 'hk_var_n_'.uniqid()]);
        $shift = $this->closeShortage($fd);

        $adminItems = $this->actingAs($admin)->getJson(route('api.notifications'))->assertOk()->json('items');
        $fdItems = $this->actingAs($fd)->getJson(route('api.notifications'))->assertOk()->json('items');
        $otherItems = $this->actingAs($otherFd)->getJson(route('api.notifications'))->assertOk()->json('items');
        $hkItems = $this->actingAs($hk)->getJson(route('api.notifications'))->assertOk()->json('items');

        $this->assertNotNull($this->firstType($adminItems, 'cash_variance_pending_review'));
        $this->assertSame('cash-variance-admin-'.$shift->id, $this->firstType($adminItems, 'cash_variance_pending_review')['alert_key']);
        $this->assertNotNull($this->firstType($fdItems, 'cash_variance_pending'));
        $this->assertSame('cash-variance-fd-'.$shift->id, $this->firstType($fdItems, 'cash_variance_pending')['alert_key']);
        $this->assertNull($this->firstType($otherItems, 'cash_variance_pending'));
        $this->assertNull($this->firstType($hkItems, 'cash_variance_pending'));
        $this->assertNull($this->firstType($hkItems, 'cash_variance_pending_review'));

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Partial'));
        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $resolution), ['review_notes' => 'Partial ok']);

        $fdAfter = $this->actingAs($fd)->getJson(route('api.notifications'))->assertOk()->json('items');
        $this->assertNotNull($this->firstType($fdAfter, 'cash_variance_reviewed'));
        $this->assertSame(
            'cash-variance-review-'.$resolution->id.'-approved',
            $this->firstType($fdAfter, 'cash_variance_reviewed')['alert_key']
        );
        $hkAfter = $this->actingAs($hk)->getJson(route('api.notifications'))->assertOk()->json('items');
        $this->assertNull($this->firstType($hkAfter, 'cash_variance_reviewed'));
    }

    public function test_snapshot_stays_stable_after_later_expense_and_additional_cash_edits(): void
    {
        $fd = $this->frontDesk('s');
        $admin = $this->admin('s');
        $shift = $this->closeShortage($fd);
        $originalExpected = (float) $shift->expected_cash_rooms;
        $originalVariance = (float) $shift->variance_rooms;

        $extra = AdditionalCash::create([
            'income_date' => now()->toDateString(),
            'amount' => 250,
            'cash_drawer' => 'room',
            'notes' => 'Late additional cash',
            'recorded_by' => $fd->id,
        ]);
        $extra->created_at = $shift->started_at->copy()->addMinutes(10);
        $extra->save();

        $expense = Expense::create([
            'expense_date' => now()->toDateString(),
            'amount' => 80,
            'cash_drawer' => 'room',
            'category' => 'Supplies',
            'notes' => 'Late expense',
            'recorded_by' => $fd->id,
        ]);
        $expense->created_at = $shift->started_at->copy()->addMinutes(12);
        $expense->save();

        $this->actingAs($admin)->post(route('shifts.variances.record', $shift), [
            'drawer' => 'room',
            'resolution_type' => 'transaction_correction',
            'amount' => 500,
            'notes' => 'Accounting correction only',
        ]);

        $shift->refresh();
        $this->assertSame($originalExpected, (float) $shift->expected_cash_rooms);
        $this->assertSame($originalVariance, (float) $shift->variance_rooms);
        $this->assertSame(14500.0, (float) $shift->closing_cash);
        $recon = app(ShiftCashReconciliationService::class)->forShift($shift);
        $this->assertTrue($recon['uses_snapshot']);
        $this->assertSame($originalExpected, $recon['rooms']['expected_cash']);
        $this->assertSame($originalVariance, $recon['rooms']['variance']);
    }

    public function test_justification_notes_are_required_server_side_for_accounting_types(): void
    {
        $fd = $this->frontDesk('just');
        $admin = $this->admin('just');
        $shift = $this->closeShortage($fd);

        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.record', $shift), [
                'drawer' => 'room',
                'resolution_type' => 'admin_adjustment',
                'amount' => 100,
                'notes' => '   ',
            ])
            ->assertSessionHasErrors('notes');

        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.record', $shift), [
                'drawer' => 'room',
                'resolution_type' => 'other',
                'amount' => 100,
            ])
            ->assertSessionHasErrors('notes');

        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.record', $shift), [
                'drawer' => 'room',
                'resolution_type' => 'transaction_correction',
                'amount' => 100,
            ])
            ->assertSessionHasErrors('notes');

        $this->assertDatabaseCount('shift_variance_resolutions', 0);
    }

    public function test_transaction_correction_can_use_a_transaction_reference_instead_of_notes(): void
    {
        $fd = $this->frontDesk('txnref');
        $admin = $this->admin('txnref');
        $shift = $this->closeShortage($fd);

        $this->actingAs($admin)
            ->from(route('shifts.report', $shift->id))
            ->post(route('shifts.variances.record', $shift), [
                'drawer' => 'room',
                'resolution_type' => 'transaction_correction',
                'amount' => 500,
                'transaction_reference' => 'TXN-4411',
            ])
            ->assertRedirect();

        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->assertSame('approved', $resolution->status);
        $this->assertStringContainsString('TXN-4411', (string) $resolution->notes);
        $this->assertNull($resolution->cash_received_into_shift_id);
    }

    public function test_non_cash_resolution_types_never_change_current_drawer_expected_cash(): void
    {
        $fd1 = $this->frontDesk('nocash1');
        $fd2 = $this->frontDesk('nocash2');
        $admin = $this->admin('nocash');
        $old = $this->closeShortage($fd1);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 14500,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();
        $current = ShiftSession::active()->firstOrFail();
        $before = app(ShiftCashReconciliationService::class)->forShift($current)['rooms']['expected_cash'];

        $this->actingAs($admin)
            ->from(route('shifts.report', $old->id))
            ->post(route('shifts.variances.record', $old), [
                'drawer' => 'room',
                'resolution_type' => 'admin_adjustment',
                'amount' => 500,
                'notes' => 'Count error confirmed on CCTV.',
                'recovery_destination' => 'active_drawer',
                'cash_received_into_shift_id' => $current->id,
            ])
            ->assertSessionHasErrors('recovery_destination');

        $this->actingAs($admin)->post(route('shifts.variances.record', $old), [
            'drawer' => 'room',
            'resolution_type' => 'admin_adjustment',
            'amount' => 500,
            'notes' => 'Count error confirmed on CCTV.',
            'recovery_destination' => 'office_safe',
            'cash_received_into_shift_id' => $current->id,
            'receive_into_active_drawer' => true,
        ])->assertRedirect();

        $after = app(ShiftCashReconciliationService::class)->forShift($current->fresh());
        $this->assertSame($before, $after['rooms']['expected_cash']);
        $this->assertSame(0.0, $after['rooms']['variance_recovery_receipts']);
        $this->assertSame(0.0, $after['minibar']['variance_recovery_receipts']);
        $this->assertNull(ShiftVarianceResolution::first()->cash_received_into_shift_id);
    }

    public function test_overage_types_cannot_be_received_into_an_active_drawer(): void
    {
        $fd1 = $this->frontDesk('overdest1');
        $fd2 = $this->frontDesk('overdest2');
        $admin = $this->admin('overdest');
        $old = $this->closeOverage($fd1);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 15300,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();
        $current = ShiftSession::active()->firstOrFail();

        foreach (['identified_overage', 'approved_unidentified_overage', 'other'] as $type) {
            $this->actingAs($admin)
                ->from(route('shifts.report', $old->id))
                ->post(route('shifts.variances.record', $old), [
                    'drawer' => 'room',
                    'resolution_type' => $type,
                    'amount' => 50,
                    'notes' => 'Accounting explanation for '.$type,
                    'recovery_destination' => 'active_drawer',
                ])
                ->assertSessionHasErrors('recovery_destination');
        }

        $live = app(ShiftCashReconciliationService::class)->forShift($current->fresh());
        $this->assertSame(15300.0, $live['rooms']['expected_cash']);
        $this->assertSame(0.0, $live['rooms']['variance_recovery_receipts']);
    }

    public function test_active_drawer_recovery_requires_a_valid_open_front_desk_shift(): void
    {
        $fd = $this->frontDesk('nodrawer');
        $admin = $this->admin('nodrawer');
        $old = $this->closeShortage($fd);

        $this->actingAs($admin)
            ->from(route('shifts.report', $old->id))
            ->post(route('shifts.variances.record', $old), [
                'drawer' => 'room',
                'resolution_type' => 'shortage_recovery',
                'amount' => 500,
                'notes' => 'Trying to post into a missing drawer.',
                'recovery_destination' => 'active_drawer',
                'cash_received_into_shift_id' => $old->id,
            ])
            ->assertSessionHasErrors('recovery_destination');

        $this->assertDatabaseCount('shift_variance_resolutions', 0);
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $old->fresh()->variance_status);
    }

    public function test_recovery_cannot_use_a_closed_or_housekeeping_receiving_shift(): void
    {
        $fd1 = $this->frontDesk('badrecv1');
        $fd2 = $this->frontDesk('badrecv2');
        $hk = User::factory()->create(['role' => 'housekeeping', 'username' => 'hk_recv_'.uniqid()]);
        $admin = $this->admin('badrecv');
        $old = $this->closeShortage($fd1);

        $closedReceiver = ShiftSession::create([
            'user_id' => $fd2->id,
            'shift_code' => 'evening',
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHour(),
            'opening_cash' => 14500,
            'closing_cash' => 14500,
            'opening_cash_minibar' => 0,
            'closing_cash_minibar' => 0,
        ]);
        $hkShift = ShiftSession::create([
            'user_id' => $hk->id,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
            'shift_code' => 'night',
            'started_at' => now()->subMinutes(10),
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ]);

        $this->actingAs($admin)
            ->from(route('shifts.report', $old->id))
            ->post(route('shifts.variances.record', $old), [
                'drawer' => 'room',
                'resolution_type' => 'shortage_recovery',
                'amount' => 500,
                'notes' => 'Closed receiver should be rejected.',
                'recovery_destination' => 'active_drawer',
                'cash_received_into_shift_id' => $closedReceiver->id,
            ])
            ->assertSessionHasErrors('recovery_destination');

        $this->actingAs($admin)
            ->from(route('shifts.report', $old->id))
            ->post(route('shifts.variances.record', $old), [
                'drawer' => 'minibar',
                'resolution_type' => 'shortage_recovery',
                'amount' => 500,
                'notes' => 'Wrong drawer for a rooms shortage.',
                'recovery_destination' => 'office_safe',
            ])
            ->assertSessionHasErrors('drawer');

        $this->actingAs($admin)
            ->from(route('shifts.report', $old->id))
            ->post(route('shifts.variances.record', $old), [
                'drawer' => 'room',
                'resolution_type' => 'shortage_recovery',
                'amount' => 500,
                'notes' => 'Housekeeping cannot receive recovery cash.',
                'recovery_destination' => 'active_drawer',
                'cash_received_into_shift_id' => $hkShift->id,
            ])
            ->assertSessionHasErrors('recovery_destination');

        $this->assertDatabaseCount('shift_variance_resolutions', 0);
        $this->assertSame(0.0, app(ShiftCashReconciliationService::class)->liveForShift($hkShift)['rooms']['variance_recovery_receipts']);
    }

    public function test_explicit_office_safe_destination_does_not_use_client_supplied_shift_id(): void
    {
        $fd1 = $this->frontDesk('safe1');
        $fd2 = $this->frontDesk('safe2');
        $admin = $this->admin('safe');
        $old = $this->closeShortage($fd1);

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 14500,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();
        $current = ShiftSession::active()->firstOrFail();

        $this->actingAs($admin)->post(route('shifts.variances.record', $old), [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => 500,
            'notes' => 'Cash went to the office safe.',
            'recovery_destination' => 'office_safe',
            'cash_received_into_shift_id' => $current->id,
            'receive_into_active_drawer' => true,
        ])->assertRedirect();

        $live = app(ShiftCashReconciliationService::class)->forShift($current->fresh());
        $this->assertSame(14500.0, $live['rooms']['expected_cash']);
        $this->assertNull(ShiftVarianceResolution::first()->cash_received_into_shift_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitPayload(float $amount, string $notes): array
    {
        return [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => $amount,
            'notes' => $notes,
        ];
    }

    private function closeShortage(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 14500,
            'closing_denominations' => ['1000' => 14, '500' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Short five hundred.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function closeOverage(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 15300,
            'closing_denominations' => ['1000' => 15, '200' => 1, '100' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Over three hundred.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function closeOffsettingDrawers(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 14500,
            'closing_denominations' => ['1000' => 14, '500' => 1],
            'closing_cash_minibar' => 500,
            'closing_denominations_minibar' => ['500' => 1],
            'notes' => 'Rooms short, minibar over. Do not net.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function openShift(User $user, float $openingCash): ShiftSession
    {
        return ShiftSession::create([
            'user_id' => $user->id,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => $openingCash,
            'opening_cash_minibar' => 0,
        ]);
    }

    private function frontDesk(string $suffix): User
    {
        return User::factory()->create([
            'role' => 'front_desk',
            'username' => 'var_fd_'.$suffix.'_'.substr(uniqid(), -6),
            'full_name' => 'Maria Santos '.$suffix,
        ]);
    }

    private function admin(string $suffix): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'username' => 'var_admin_'.$suffix.'_'.substr(uniqid(), -6),
        ]);
    }

    private function makePaidBooking(User $user, float $paid): Booking
    {
        $type = RoomType::first() ?? RoomType::create([
            'type_name' => 'Variance Room Type',
            'base_rate' => 800,
            'hourly_rate' => 150,
            'max_occupancy' => 2,
        ]);
        $room = Room::create([
            'room_number' => 'V'.substr(uniqid(), -4),
            'room_type_id' => $type->id,
            'status' => 'occupied',
        ]);

        return Booking::create([
            'booking_ref' => 'VAR'.strtoupper(substr(uniqid(), -8)),
            'room_id' => $room->id,
            'guest_name' => 'Variance Guest',
            'num_guests' => 1,
            'booking_type' => 'short_time',
            'short_time_hours' => 3,
            'check_in' => now()->toDateTimeString(),
            'expected_check_out' => now()->addHours(3)->toDateTimeString(),
            'status' => 'active',
            'payment_status' => 'paid',
            'base_amount' => $paid,
            'total_amount' => $paid,
            'amount_paid' => $paid,
            'payment_method' => 'cash',
            'booked_by_user_id' => $user->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function firstType(array $items, string $type): ?array
    {
        foreach ($items as $item) {
            if (($item['type'] ?? null) === $type) {
                return $item;
            }
        }

        return null;
    }
}
