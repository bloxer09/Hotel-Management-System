<?php

namespace Tests\Feature;

use App\Models\AdditionalCash;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\ShiftCashReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_threshold_999_99_and_1000_post_immediately_but_1000_01_is_pending(): void
    {
        [$desk, $shift] = $this->openFrontDesk();

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(999.99))->assertRedirect();
        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1000.00))->assertRedirect();
        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1000.01))->assertRedirect();

        $this->assertSame(Expense::STATUS_POSTED, Expense::where('amount', '999.99')->first()->status);
        $this->assertSame(Expense::STATUS_POSTED, Expense::where('amount', '1000.00')->first()->status);
        $this->assertSame(Expense::STATUS_PENDING_APPROVAL, Expense::where('amount', '1000.01')->first()->status);

        $recon = app(ShiftCashReconciliationService::class)->forShift($shift->fresh());
        $this->assertEquals(3000.01, $recon['rooms']['expected_cash']);
        $this->assertEquals(1999.99, $recon['rooms']['expenses']);
    }

    public function test_five_hundred_expense_posts_and_decreases_expected_once(): void
    {
        [$desk, $shift] = $this->openFrontDesk(1000);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(500))->assertRedirect();

        $expense = Expense::first();
        $this->assertSame(Expense::STATUS_POSTED, $expense->status);
        $this->assertSame($shift->id, $expense->shift_session_id);
        $this->assertSame($shift->id, $expense->posted_shift_session_id);
        $this->assertMatchesRegularExpression('/^EXP-\d{8}-\d{4}$/', $expense->reference);

        $recon = app(ShiftCashReconciliationService::class)->forShift($shift->fresh());
        $this->assertEquals(500.00, $recon['rooms']['expected_cash']);
        $this->assertEquals(500.00, $recon['rooms']['expenses']);
    }

    public function test_large_expense_approve_does_not_change_expected_until_marked_paid(): void
    {
        [$desk, $shift] = $this->openFrontDesk(2000);
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'System Administrator']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500, 'Emergency Plumbing'))->assertRedirect();
        $expense = Expense::first();
        $this->assertSame(Expense::STATUS_PENDING_APPROVAL, $expense->status);

        $before = app(ShiftCashReconciliationService::class)->forShift($shift->fresh());
        $this->assertEquals(2000.00, $before['rooms']['expected_cash']);

        $this->actingAs($admin)->post(route('expenses.approve', $expense))->assertRedirect();
        $expense->refresh();
        $this->assertSame(Expense::STATUS_APPROVED, $expense->status);
        $this->assertNull($expense->posted_shift_session_id);
        $this->assertEquals(2000.00, app(ShiftCashReconciliationService::class)->forShift($shift->fresh())['rooms']['expected_cash']);

        $this->actingAs($desk)->post(route('expenses.pay', $expense))->assertRedirect();
        $expense->refresh();
        $this->assertSame(Expense::STATUS_POSTED, $expense->status);
        $this->assertSame($shift->id, $expense->posted_shift_session_id);
        $this->assertEquals(500.00, app(ShiftCashReconciliationService::class)->forShift($shift->fresh())['rooms']['expected_cash']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'EXPENSE_SUBMITTED', 'record_id' => $expense->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXPENSE_APPROVED', 'record_id' => $expense->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXPENSE_POSTED', 'record_id' => $expense->id]);
    }

    public function test_rejected_large_expense_never_affects_expected(): void
    {
        [$desk, $shift] = $this->openFrontDesk(2000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = Expense::first();
        $this->actingAs($admin)->post(route('expenses.reject', $expense), [
            'review_notes' => 'insufficient documentation',
        ])->assertRedirect();

        $this->assertSame(Expense::STATUS_REJECTED, $expense->fresh()->status);
        $this->assertEquals(2000.00, app(ShiftCashReconciliationService::class)->forShift($shift->fresh())['rooms']['expected_cash']);
    }

    public function test_front_desk_cannot_approve_and_housekeeping_is_forbidden(): void
    {
        [$desk] = $this->openFrontDesk();
        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = Expense::first();

        $this->actingAs($desk)->post(route('expenses.approve', $expense))->assertForbidden();

        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)->get(route('expenses.index'))->assertForbidden();
        $this->actingAs($housekeeper)->get(route('additional-cash.index'))->assertForbidden();
        $this->actingAs($housekeeper)->get(route('expenses.approvals'))->assertForbidden();
        $this->actingAs($housekeeper)->get(route('cash-activity.index'))->assertForbidden();
    }

    public function test_additional_cash_posts_immediately_and_increases_expected_once(): void
    {
        [$desk, $shift] = $this->openFrontDesk(1000);

        $this->actingAs($desk)->post(route('additional-cash.store'), [
            'income_date' => now()->toDateString(),
            'amount' => 2000,
            'cash_drawer' => 'room',
            'notes' => 'Admin drawer replenishment',
        ])->assertRedirect();

        $row = AdditionalCash::first();
        $this->assertSame(AdditionalCash::STATUS_POSTED, $row->status);
        $this->assertSame($shift->id, $row->shift_session_id);
        $this->assertMatchesRegularExpression('/^ADC-\d{8}-\d{4}$/', $row->reference);
        $this->assertEquals(3000.00, app(ShiftCashReconciliationService::class)->forShift($shift->fresh())['rooms']['expected_cash']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADDITIONAL_CASH_RECORDED', 'record_id' => $row->id]);
    }

    public function test_additional_cash_requires_source_and_cannot_be_hard_deleted(): void
    {
        [$desk] = $this->openFrontDesk();
        $this->actingAs($desk)->post(route('additional-cash.store'), [
            'income_date' => now()->toDateString(),
            'amount' => 100,
            'cash_drawer' => 'room',
            'notes' => '',
        ])->assertSessionHasErrors('notes');

        $this->actingAs($desk)->post(route('additional-cash.store'), [
            'income_date' => now()->toDateString(),
            'amount' => 100,
            'cash_drawer' => 'room',
            'notes' => 'Change fund added',
        ])->assertRedirect();

        $row = AdditionalCash::first();
        $this->actingAs($desk)->delete(route('additional-cash.destroy', $row))->assertForbidden();
        $this->assertDatabaseHas('additional_cash', ['id' => $row->id, 'status' => AdditionalCash::STATUS_POSTED]);
    }

    public function test_admin_records_against_active_front_desk_register(): void
    {
        [$desk, $shift] = $this->openFrontDesk(1000);
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'System Administrator']);

        $this->actingAs($admin)->post(route('expenses.store'), $this->expensePayload(200))->assertRedirect();
        $expense = Expense::first();
        $this->assertSame($admin->id, $expense->recorded_by);
        $this->assertSame($shift->id, $expense->shift_session_id);
        $this->assertSame($shift->id, $expense->posted_shift_session_id);
        $this->assertEquals(800.00, app(ShiftCashReconciliationService::class)->forShift($shift->fresh())['rooms']['expected_cash']);
    }

    public function test_origin_shift_can_close_with_pending_expense_and_later_payment_attaches_to_new_shift(): void
    {
        [$desk, $first] = $this->openFrontDesk(5000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = Expense::first();

        $this->actingAs($desk)->post(route('shifts.end'), [
            'closing_cash' => 5000,
            'closing_denominations' => ['1000' => 5],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
        ])->assertRedirect();

        $first->refresh();
        $this->assertEquals(5000.00, (float) $first->expected_cash_rooms);
        $this->assertTrue((bool) $first->expected_formula_version);

        $this->actingAs($admin)->post(route('expenses.approve', $expense))->assertRedirect();

        $second = $this->openShift($desk, 5000);
        $this->actingAs($desk)->post(route('expenses.pay', $expense))->assertRedirect();
        $expense->refresh();

        $this->assertSame($first->id, $expense->shift_session_id);
        $this->assertSame($second->id, $expense->posted_shift_session_id);
        $this->assertEquals(5000.00, (float) $first->fresh()->expected_cash_rooms);
        $this->assertEquals(3500.00, app(ShiftCashReconciliationService::class)->forShift($second->fresh())['rooms']['expected_cash']);
    }

    public function test_posted_expense_cannot_be_hard_deleted_and_void_preserves_row(): void
    {
        [$desk, $shift] = $this->openFrontDesk(1000);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(200))->assertRedirect();
        $expense = Expense::first();

        $this->actingAs($desk)->delete(route('expenses.destroy', $expense))->assertForbidden();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => Expense::STATUS_POSTED]);

        $this->actingAs($admin)->post(route('expenses.void', $expense), [
            'reason' => 'Duplicate entry; cash was never paid',
            'confirm_no_physical_movement' => true,
        ])->assertRedirect();

        $this->assertSame(Expense::STATUS_VOIDED, $expense->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXPENSE_VOIDED', 'record_id' => $expense->id]);
        $this->assertEquals(1000.00, app(ShiftCashReconciliationService::class)->forShift($shift->fresh())['rooms']['expected_cash']);
    }

    public function test_admin_history_and_notifications_are_role_aware(): void
    {
        [$desk] = $this->openFrontDesk();
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'System Administrator']);
        $otherDesk = User::factory()->create(['role' => 'front_desk', 'full_name' => 'Other Clerk']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500, 'Emergency Plumbing'))->assertRedirect();
        $expense = Expense::first();

        $this->actingAs($admin)
            ->get(route('cash-activity.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CashActivity/Index')
                ->has('activities.data', 1)
                ->where('activities.data.0.reference', $expense->reference)
            );

        $this->actingAs($otherDesk)
            ->get(route('expenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Expenses/Index')
                ->has('expenses.data', 0)
            );

        $this->actingAs($admin)
            ->get(route('api.notifications'))
            ->assertOk()
            ->assertJsonFragment(['type' => 'expense_approval_required', 'alert_key' => 'expense-approval-'.$expense->id]);

        $this->actingAs($desk)
            ->get(route('api.notifications'))
            ->assertOk()
            ->assertJsonFragment(['type' => 'expense_awaiting_approval']);

        $housekeeper = User::factory()->create(['role' => 'housekeeping']);
        $this->actingAs($housekeeper)
            ->get(route('api.notifications'))
            ->assertOk()
            ->assertJsonMissing(['type' => 'expense_approval_required']);
    }

    public function test_blank_expense_reason_is_rejected(): void
    {
        [$desk] = $this->openFrontDesk();
        $this->actingAs($desk)->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(),
            'amount' => 50,
            'cash_drawer' => 'room',
            'category' => 'Supplies',
            'notes' => '   ',
        ])->assertSessionHasErrors('notes');
    }

    /**
     * @return array{0: User, 1: ShiftSession}
     */
    private function openFrontDesk(float $opening = 5000): array
    {
        $desk = User::factory()->create([
            'role' => 'front_desk',
            'full_name' => 'Loren Gay Yuson',
            'username' => 'loren_'.substr(uniqid(), -6),
        ]);

        return [$desk, $this->openShift($desk, $opening)];
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

    private function expensePayload(float $amount, string $notes = 'Operating supplies'): array
    {
        ExpenseCategory::findOrCreateFromName('Supplies');

        return [
            'expense_date' => now()->toDateString(),
            'amount' => $amount,
            'cash_drawer' => 'room',
            'category' => 'Supplies',
            'notes' => $notes,
        ];
    }
}
