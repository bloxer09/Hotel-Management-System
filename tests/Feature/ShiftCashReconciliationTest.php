<?php

namespace Tests\Feature;

use App\Models\AdditionalCash;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShiftCashReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftCashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_formula_produces_short_variance(): void
    {
        $user = $this->frontDesk();
        $shift = $this->openShift($user, 500);

        Transaction::create([
            'transaction_type' => 'check_in',
            'description' => 'Cash room sale',
            'amount' => 300,
            'payment_method' => 'cash',
            'cash_amount' => 300,
            'processed_by' => $user->id,
        ]);
        AdditionalCash::create([
            'income_date' => now()->toDateString(),
            'amount' => 100,
            'cash_drawer' => 'room',
            'notes' => 'Other cash receipt',
            'recorded_by' => $user->id,
        ]);
        Expense::create([
            'expense_date' => now()->toDateString(),
            'amount' => 50,
            'cash_drawer' => 'room',
            'notes' => 'Room expense',
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'cashier_transfer',
            'cash_drawer' => 'room',
            'amount' => 100,
            'description' => 'Cash transfer to office',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'withdrawal',
            'cash_drawer' => 'room',
            'amount' => 25,
            'description' => 'Approved withdrawal',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 700,
            'closing_denominations' => ['500' => 1, '200' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Short 25 after official count.',
        ])->assertRedirect(route('shifts.report', $shift->id));

        $shift->refresh();
        $this->assertSame(725.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(-25.0, (float) $shift->variance_rooms);
        $this->assertSame(ShiftCashReconciliationService::STATUS_PENDING_REVIEW, $shift->variance_status);
        $this->assertSame(ShiftCashReconciliationService::FORMULA_VERSION, $shift->expected_formula_version);

        $this->actingAs($user)->get(route('shifts.report', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.expectedDrawerCash', 725)
                ->where('report.cashVariance', -25)
                ->where('report.daily_cash_report.room_sales_cash', 300)
                ->where('report.daily_cash_report.additional_cash', 100)
                ->where('report.daily_cash_report.room_expenses', 50)
                ->where('report.daily_cash_report.cashier_transfers', 100)
                ->where('report.daily_cash_report.withdrawals', 25)
                ->where('report.daily_cash_report.expected_cash', 725)
                ->where('report.daily_cash_report.actual_cash', 700)
                ->where('report.daily_cash_report.variance', -25)
                ->where('report.daily_cash_report.variance_label', 'SHORT')
                ->where('report.cash_reconciliation.uses_snapshot', true)
            );

        $this->actingAs($user)->get(route('shifts.ledger-print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_tally.room_sales_cash', 300)
                ->where('cash_tally.other_cash_receipts', 100)
                ->where('cash_tally.total_expenses', 50)
                ->where('cash_tally.total_movements', 125)
                ->where('cash_tally.expected_cash', 725)
                ->where('cash_tally.actual_cash', 700)
                ->where('cash_tally.variance', -25)
                ->where('cash_tally.variance_label', 'SHORT')
            );
    }

    public function test_overage_and_balanced_variances_use_actual_minus_expected(): void
    {
        $overUser = $this->frontDesk('over_fd');
        $overShift = $this->openShift($overUser, 1000);
        $this->actingAs($overUser)->post(route('shifts.end'), [
            'closing_cash' => 1200,
            'closing_denominations' => ['1000' => 1, '200' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Counted over by 200.',
        ])->assertRedirect();
        $overShift->refresh();
        $this->assertSame(200.0, (float) $overShift->variance_rooms);
        $this->assertSame('PENDING_REVIEW', $overShift->variance_status);

        $this->actingAs($overUser)->get(route('shifts.report', $overShift->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.daily_cash_report.expected_cash', 1000)
                ->where('report.daily_cash_report.actual_cash', 1200)
                ->where('report.daily_cash_report.variance', 200)
                ->where('report.daily_cash_report.variance_label', 'OVER')
            );

        $balUser = $this->frontDesk('bal_fd');
        $balShift = $this->openShift($balUser, 1000);
        $this->actingAs($balUser)->post(route('shifts.end'), [
            'closing_cash' => 1000,
            'closing_denominations' => ['1000' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
        ])->assertRedirect();
        $balShift->refresh();
        $this->assertSame(0.0, (float) $balShift->variance_rooms);
        $this->assertSame('BALANCED', $balShift->variance_status);
    }

    public function test_additional_cash_and_expense_are_applied_once_and_transfers_lower_expected(): void
    {
        $user = $this->frontDesk();
        $shift = $this->openShift($user, 1000);
        AdditionalCash::create([
            'income_date' => now()->toDateString(),
            'amount' => 100,
            'cash_drawer' => 'room',
            'notes' => 'Once',
            'recorded_by' => $user->id,
        ]);
        Expense::create([
            'expense_date' => now()->toDateString(),
            'amount' => 40,
            'cash_drawer' => 'room',
            'notes' => 'Once',
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'cashier_transfer',
            'cash_drawer' => 'room',
            'amount' => 60,
            'description' => 'Office remittance',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);
        CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => 'withdrawal',
            'cash_drawer' => 'room',
            'amount' => 10,
            'description' => 'Withdrawal',
            'moved_at' => now(),
            'recorded_by' => $user->id,
        ]);

        $live = app(ShiftCashReconciliationService::class)->forShift($shift);
        $this->assertSame(990.0, $live['rooms']['expected_cash']);
        $this->assertSame(100.0, $live['rooms']['additional_cash']);
        $this->assertSame(40.0, $live['rooms']['expenses']);
        $this->assertSame(60.0, $live['rooms']['cash_transfers']);
        $this->assertSame(10.0, $live['rooms']['withdrawals']);
    }

    public function test_closed_shift_keeps_snapshot_when_later_expense_changes(): void
    {
        $user = $this->frontDesk();
        $shift = $this->openShift($user, 500);
        $expense = Expense::create([
            'expense_date' => now()->toDateString(),
            'amount' => 50,
            'cash_drawer' => 'room',
            'notes' => 'Original expense',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 450,
            'closing_denominations' => ['200' => 2, '50' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(450.0, (float) $shift->expected_cash_rooms);
        $this->assertSame(0.0, (float) $shift->variance_rooms);

        $expense->update(['amount' => 5]);

        $this->actingAs($user)->get(route('shifts.report', $shift->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.expectedDrawerCash', 450)
                ->where('report.cashVariance', 0)
                ->where('report.cash_reconciliation.uses_snapshot', true)
            );
    }

    public function test_closing_with_variance_requires_server_side_explanation(): void
    {
        $user = $this->frontDesk();
        $shift = $this->openShift($user, 1000);

        $this->actingAs($user)
            ->from(route('shifts.index'))
            ->post(route('shifts.end'), [
                'closing_cash' => 900,
                'closing_denominations' => ['500' => 1, '200' => 2],
                'closing_cash_minibar' => 0,
                'closing_denominations_minibar' => [],
            ])
            ->assertRedirect(route('shifts.index'))
            ->assertSessionHasErrors('notes');

        $this->assertNull($shift->fresh()->ended_at);

        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 900,
            'closing_denominations' => ['500' => 1, '200' => 2],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Missing 100 after recount.',
        ])->assertRedirect(route('shifts.report', $shift->id));

        $this->assertNotNull($shift->fresh()->ended_at);
        $this->assertSame(-100.0, (float) $shift->fresh()->variance_rooms);
    }

    public function test_next_shift_suggested_opening_uses_previous_actual_and_matching_handover_succeeds(): void
    {
        $fd1 = $this->frontDesk('fd1');
        $fd1Shift = $this->openShift($fd1, 15000);
        $this->actingAs($fd1)->post(route('shifts.end'), [
            'closing_cash' => 14500,
            'closing_denominations' => ['1000' => 14, '500' => 1],
            'closing_cash_minibar' => 500,
            'closing_denominations_minibar' => ['500' => 1],
            'notes' => 'Short 500 on rooms drawer.',
        ])->assertRedirect();

        $fd2 = $this->frontDesk('fd2');
        $this->actingAs($fd2)->get(route('shifts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestedOpeningCash', 14500)
                ->where('suggestedOpeningCashMinibar', 500)
                ->where('previousClosedShift.closing_cash', 14500)
            );

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 14500,
            'opening_denominations' => [],
            'opening_cash_minibar' => 500,
            'opening_denominations_minibar' => [],
        ])->assertRedirect(route('shifts.index'));

        $fd2Shift = ShiftSession::active()->firstOrFail();
        $this->assertSame($fd1Shift->id, $fd2Shift->handover_from_shift_id);
        $this->assertSame(14500.0, (float) $fd2Shift->opening_cash);
        $this->assertSame(500.0, (float) $fd2Shift->opening_cash_minibar);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SHIFT_HANDOVER',
            'record_id' => $fd2Shift->id,
        ]);
    }

    public function test_handover_count_difference_requires_explanation_and_is_audited(): void
    {
        $fd1 = $this->frontDesk('fd1b');
        $this->openShift($fd1, 14500);
        $this->actingAs($fd1)->post(route('shifts.end'), [
            'closing_cash' => 14500,
            'closing_denominations' => ['1000' => 14, '500' => 1],
            'closing_cash_minibar' => 500,
            'closing_denominations_minibar' => ['500' => 1],
            'notes' => 'Closed at physical count.',
        ]);

        $fd2 = $this->frontDesk('fd2b');
        $this->actingAs($fd2)
            ->from(route('shifts.index'))
            ->post(route('shifts.start'), [
                'shift_code' => 'evening',
                'opening_cash' => 14400,
                'opening_denominations' => [],
                'opening_cash_minibar' => 500,
                'opening_denominations_minibar' => [],
            ])
            ->assertRedirect(route('shifts.index'))
            ->assertSessionHasErrors('handover_notes');

        $this->actingAs($fd2)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 14400,
            'opening_denominations' => [],
            'opening_cash_minibar' => 500,
            'opening_denominations_minibar' => [],
            'handover_notes' => 'One hundred missing from envelope during count.',
        ])->assertRedirect(route('shifts.index'));

        $newShift = ShiftSession::active()->firstOrFail();
        $this->assertSame(14400.0, (float) $newShift->opening_cash);
        $this->assertSame('One hundred missing from envelope during count.', $newShift->handover_notes);
        $this->assertNotNull($newShift->handover_from_shift_id);
        $this->assertStringContainsString('-100', AuditLog::where('action', 'SHIFT_HANDOVER')->latest('id')->first()->reason);
    }

    public function test_same_user_consecutive_shifts_still_record_handover_relationship(): void
    {
        $user = $this->frontDesk('same_fd');
        $first = $this->openShift($user, 1000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 1000,
            'closing_denominations' => ['1000' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
        ]);

        $this->actingAs($user)->post(route('shifts.start'), [
            'shift_code' => 'evening',
            'opening_cash' => 1000,
            'opening_denominations' => [],
            'opening_cash_minibar' => 0,
            'opening_denominations_minibar' => [],
        ])->assertRedirect();

        $second = ShiftSession::active()->firstOrFail();
        $this->assertSame($first->id, $second->handover_from_shift_id);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'SHIFT_HANDOVER',
            'record_id' => $second->id,
        ]);
    }

    public function test_housekeeping_cannot_access_shift_cash_pages(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $operator = $this->frontDesk();
        $shift = $this->openShift($operator, 1000);

        $this->actingAs($housekeeper)->get(route('shifts.index'))->assertForbidden();
        $this->actingAs($housekeeper)->get(route('shifts.report', $shift->id))->assertForbidden();
        $this->actingAs($housekeeper)->post(route('shifts.start'), [
            'shift_code' => 'morning',
            'opening_cash' => 0,
            'opening_cash_minibar' => 0,
        ])->assertForbidden();
    }

    public function test_shift_end_audit_contains_structured_reconciliation(): void
    {
        $user = $this->frontDesk();
        $shift = $this->openShift($user, 1000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 900,
            'closing_denominations' => ['500' => 1, '200' => 2],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Short one hundred.',
        ]);

        $log = AuditLog::where('action', 'SHIFT_END')->where('record_id', $shift->id)->firstOrFail();
        $payload = json_decode($log->new_value, true);
        $this->assertSame(ShiftCashReconciliationService::FORMULA_VERSION, $payload['formula_version']);
        $this->assertEquals(1000, $payload['rooms']['opening']);
        $this->assertEquals(1000, $payload['rooms']['expected']);
        $this->assertEquals(900, $payload['rooms']['actual']);
        $this->assertEquals(-100, $payload['rooms']['variance']);
    }

    private function frontDesk(string $suffix = ''): User
    {
        return User::factory()->create([
            'role' => 'front_desk',
            'username' => 'recon_'.($suffix !== '' ? $suffix.'_' : '').substr(uniqid(), -6),
        ]);
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
}
