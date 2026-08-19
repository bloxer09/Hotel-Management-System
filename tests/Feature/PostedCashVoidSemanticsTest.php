<?php

namespace Tests\Feature;

use App\Models\AdditionalCash;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\ShiftCashReconciliationService;
use App\Support\PostedCashVoidPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostedCashVoidSemanticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_shift_erroneous_expense_void_restores_expected(): void
    {
        [$desk, $shift] = $this->openFrontDesk(10000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = $this->approveAndPayPostedExpense($admin, $desk);
        $this->assertEquals(8500.00, $this->expectedRooms($shift));

        $this->actingAs($admin)->post(route('expenses.void', $expense), $this->voidPayload(
            'Duplicate expense entry; cash was never paid'
        ))->assertRedirect();

        $expense->refresh();
        $this->assertSame(Expense::STATUS_VOIDED, $expense->status);
        $this->assertSame($admin->id, $expense->voided_by);
        $this->assertNotNull($expense->voided_at);
        $this->assertSame('Duplicate expense entry; cash was never paid', $expense->void_reason);
        $this->assertEquals(10000.00, $this->expectedRooms($shift->fresh()));
    }

    public function test_posted_expense_cannot_be_silently_voided_when_cash_physically_moved(): void
    {
        [$desk, $shift] = $this->openFrontDesk(10000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = $this->approveAndPayPostedExpense($admin, $desk);

        $this->actingAs($admin)->post(route('expenses.void', $expense), [
            'reason' => 'Supplier later returned the cash',
        ])->assertSessionHasErrors('confirm_no_physical_movement');

        $this->actingAs($admin)->post(route('expenses.void', $expense), [
            'reason' => 'Supplier later returned the cash',
            'confirm_no_physical_movement' => false,
        ])->assertSessionHasErrors('confirm_no_physical_movement');

        $this->assertSame(Expense::STATUS_POSTED, $expense->fresh()->status);
        $this->assertEquals(8500.00, $this->expectedRooms($shift->fresh()));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'EXPENSE_VOIDED', 'record_id' => $expense->id]);
    }

    public function test_closed_shift_posted_expense_cannot_rewrite_snapshot_via_ordinary_void(): void
    {
        [$desk, $shift] = $this->openFrontDesk(10000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = $this->approveAndPayPostedExpense($admin, $desk);
        $this->closeShift($desk, 8500);
        $shift->refresh();
        $frozenExpected = (float) $shift->expected_cash_rooms;
        $this->assertEquals(8500.00, $frozenExpected);

        $this->openShift($desk, 5000);

        $this->actingAs($admin)->from(route('expenses.index'))->post(route('expenses.void', $expense), $this->voidPayload(
            'Trying to reverse a closed-shift disbursement'
        ))->assertRedirect()->assertSessionHas('error', PostedCashVoidPolicy::CLOSED_SHIFT_MESSAGE);

        $this->assertSame(Expense::STATUS_POSTED, $expense->fresh()->status);
        $this->assertEquals($frozenExpected, (float) $shift->fresh()->expected_cash_rooms);
        $this->assertEquals(8500.00, $this->expectedRooms($shift->fresh()));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'EXPENSE_VOIDED', 'record_id' => $expense->id]);
    }

    public function test_closed_shift_physical_correction_leaves_original_snapshot_unchanged(): void
    {
        [$desk, $shift] = $this->openFrontDesk(10000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = $this->approveAndPayPostedExpense($admin, $desk);
        $this->closeShift($desk, 8500);
        $shift->refresh();

        $this->actingAs($admin)->post(route('expenses.audit-note', $expense), [
            'reason' => 'Supplier later returned 1,500. Record the cash-in on the current shift.',
        ])->assertRedirect();

        $this->assertSame(Expense::STATUS_POSTED, $expense->fresh()->status);
        $this->assertEquals(8500.00, (float) $shift->fresh()->expected_cash_rooms);

        $second = $this->openShift($desk, 5000);
        $this->actingAs($desk)->post(route('additional-cash.store'), [
            'income_date' => now()->toDateString(),
            'amount' => 1500,
            'cash_drawer' => 'room',
            'notes' => 'Supplier refund of yesterday posted expense; cash returned to drawer.',
        ])->assertRedirect();

        $this->assertSame(Expense::STATUS_POSTED, $expense->fresh()->status);
        $this->assertEquals(8500.00, (float) $shift->fresh()->expected_cash_rooms);
        $this->assertEquals(8500.00, $this->expectedRooms($shift->fresh()));
        $this->assertEquals(6500.00, $this->expectedRooms($second->fresh()));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EXPENSE_CLOSED_SHIFT_NOTE',
            'record_id' => $expense->id,
            'reason' => 'Supplier later returned 1,500. Record the cash-in on the current shift.',
        ]);
    }

    public function test_open_shift_erroneous_additional_cash_void_restores_expected(): void
    {
        [$desk, $shift] = $this->openFrontDesk(5000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('additional-cash.store'), $this->additionalCashPayload(2000))->assertRedirect();
        $row = AdditionalCash::first();
        $this->assertEquals(7000.00, $this->expectedRooms($shift));

        $this->actingAs($admin)->post(route('additional-cash.void', $row), $this->voidPayload(
            'Incorrect additional cash; no money entered the drawer'
        ))->assertRedirect();

        $this->assertSame(AdditionalCash::STATUS_VOIDED, $row->fresh()->status);
        $this->assertEquals(5000.00, $this->expectedRooms($shift->fresh()));
    }

    public function test_real_additional_cash_cannot_be_silently_voided_as_if_money_disappeared(): void
    {
        [$desk, $shift] = $this->openFrontDesk(5000);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($desk)->post(route('additional-cash.store'), $this->additionalCashPayload(2000))->assertRedirect();
        $row = AdditionalCash::first();

        $this->actingAs($admin)->post(route('additional-cash.void', $row), [
            'reason' => 'Need to take the 2,000 back out',
        ])->assertSessionHasErrors('confirm_no_physical_movement');

        $this->assertSame(AdditionalCash::STATUS_POSTED, $row->fresh()->status);
        $this->assertEquals(7000.00, $this->expectedRooms($shift->fresh()));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ADDITIONAL_CASH_VOIDED', 'record_id' => $row->id]);
    }

    public function test_void_audit_retains_reason_actor_and_timestamp(): void
    {
        [$desk] = $this->openFrontDesk(10000);
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Audit Admin']);

        $this->actingAs($desk)->post(route('expenses.store'), $this->expensePayload(1500))->assertRedirect();
        $expense = $this->approveAndPayPostedExpense($admin, $desk);

        $this->actingAs($admin)->post(route('expenses.void', $expense), $this->voidPayload(
            'Accidental posting; cashier never disbursed cash'
        ))->assertRedirect();

        $log = AuditLog::query()->where('action', 'EXPENSE_VOIDED')->where('record_id', $expense->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Accidental posting; cashier never disbursed cash', $log->reason);
        $this->assertNotNull($log->created_at);

        $payload = json_decode((string) $log->new_value, true);
        $this->assertTrue($payload['physical_cash_did_not_occur']);
        $this->assertSame($admin->id, $payload['actor_id']);
        $this->assertSame('Audit Admin', $payload['actor_name']);
        $this->assertNotEmpty($payload['occurred_at_utc']);
        $this->assertSame(Expense::STATUS_VOIDED, $payload['status']);
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

    private function closeShift(User $user, float $closingCash): void
    {
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => $closingCash,
            'closing_denominations' => $this->denoms($closingCash),
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
        ])->assertRedirect();
    }

    /**
     * @return array<string, int>
     */
    private function denoms(float $amount): array
    {
        $remaining = (int) round($amount);
        $map = [];
        foreach ([1000, 500, 200, 100, 50, 20, 10, 5, 1] as $note) {
            $qty = intdiv($remaining, $note);
            if ($qty > 0) {
                $map[(string) $note] = $qty;
                $remaining -= $qty * $note;
            }
        }

        return $map;
    }

    private function approveAndPayPostedExpense(User $admin, User $desk): Expense
    {
        $expense = Expense::first();
        $this->assertSame(Expense::STATUS_PENDING_APPROVAL, $expense->status);

        $this->actingAs($admin)->post(route('expenses.approve', $expense))->assertRedirect();
        $this->actingAs($desk)->post(route('expenses.pay', $expense))->assertRedirect();

        $expense->refresh();
        $this->assertSame(Expense::STATUS_POSTED, $expense->status);

        return $expense;
    }

    private function expectedRooms(ShiftSession $shift): float
    {
        return (float) app(ShiftCashReconciliationService::class)->forShift($shift)['rooms']['expected_cash'];
    }

    /**
     * @return array{reason: string, confirm_no_physical_movement: bool}
     */
    private function voidPayload(string $reason): array
    {
        return [
            'reason' => $reason,
            'confirm_no_physical_movement' => true,
        ];
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

    /**
     * @return array{income_date: string, amount: float, cash_drawer: string, notes: string}
     */
    private function additionalCashPayload(float $amount): array
    {
        return [
            'income_date' => now()->toDateString(),
            'amount' => $amount,
            'cash_drawer' => 'room',
            'notes' => 'Drawer top-up from office safe',
        ];
    }
}
