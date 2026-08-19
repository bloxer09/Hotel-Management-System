<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\HotelDateTime;
use App\Support\PostedCashVoidPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExpenseLedgerService
{
    public function submit(User $actor, array $payload, ?string $receiptPath = null): Expense
    {
        $register = $this->requireActiveRegister();
        $amount = round((float) $payload['amount'], 2);
        $requiresApproval = Expense::requiresApproval($amount);
        $now = now();

        $expense = DB::transaction(function () use ($actor, $payload, $receiptPath, $register, $amount, $requiresApproval, $now) {
            $category = ExpenseCategory::findOrCreateFromName((string) $payload['category']);
            $status = $requiresApproval ? Expense::STATUS_PENDING_APPROVAL : Expense::STATUS_POSTED;

            $expense = Expense::create([
                'expense_date' => $payload['expense_date'],
                'amount' => $amount,
                'cash_drawer' => $payload['cash_drawer'],
                'notes' => $payload['notes'],
                'expense_category_id' => $category->id,
                'receipt_path' => $receiptPath,
                'recorded_by' => $actor->id,
                'status' => $status,
                'shift_session_id' => $register->id,
                'posted_shift_session_id' => $requiresApproval ? null : $register->id,
                'posted_by' => $requiresApproval ? null : $actor->id,
                'posted_at' => $requiresApproval ? null : $now,
            ]);

            $this->audit($actor, $requiresApproval ? 'EXPENSE_SUBMITTED' : 'EXPENSE_POSTED', $expense, null, $expense->status, $payload['notes'] ?? null);

            return $expense;
        });

        return $expense->fresh(['user', 'category', 'originShift', 'postedShift', 'reviewer', 'poster']);
    }

    public function updatePending(Expense $expense, User $actor, array $payload, ?string $receiptPath = null): Expense
    {
        $this->assertCanEditPending($expense, $actor);

        return DB::transaction(function () use ($expense, $actor, $payload, $receiptPath) {
            $before = $expense->status;
            $amount = round((float) $payload['amount'], 2);
            $requiresApproval = Expense::requiresApproval($amount);
            $category = ExpenseCategory::findOrCreateFromName((string) $payload['category']);

            $updates = [
                'expense_date' => $payload['expense_date'],
                'amount' => $amount,
                'cash_drawer' => $payload['cash_drawer'],
                'notes' => $payload['notes'],
                'expense_category_id' => $category->id,
            ];
            if ($receiptPath !== null) {
                $updates['receipt_path'] = $receiptPath;
            }

            if (! $requiresApproval) {
                $register = $this->requireActiveRegister();
                $updates['status'] = Expense::STATUS_POSTED;
                $updates['posted_shift_session_id'] = $register->id;
                $updates['posted_by'] = $actor->id;
                $updates['posted_at'] = now();
            }

            $expense->update($updates);
            $this->audit(
                $actor,
                $expense->status === Expense::STATUS_POSTED ? 'EXPENSE_POSTED' : 'EXPENSE_SUBMITTED',
                $expense->fresh(),
                $before,
                $expense->status,
                'Pending expense updated.'
            );

            return $expense->fresh(['user', 'category', 'originShift', 'postedShift']);
        });
    }

    public function cancelPending(Expense $expense, User $actor, string $reason): Expense
    {
        $this->assertCanEditPending($expense, $actor);

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $before = $expense->status;
            $expense->update([
                'status' => Expense::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);
            $this->audit($actor, 'EXPENSE_REJECTED', $expense->fresh(), $before, Expense::STATUS_REJECTED, $reason);

            return $expense->fresh();
        });
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        $this->assertAdmin($actor);
        if (! $expense->isPending()) {
            throw new InvalidArgumentException('Only pending expenses can be approved.');
        }

        return DB::transaction(function () use ($expense, $actor) {
            $before = $expense->status;
            $expense->update([
                'status' => Expense::STATUS_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->audit($actor, 'EXPENSE_APPROVED', $expense->fresh(), $before, Expense::STATUS_APPROVED, null);

            return $expense->fresh(['user', 'category', 'originShift', 'reviewer']);
        });
    }

    public function reject(Expense $expense, User $actor, string $reason): Expense
    {
        $this->assertAdmin($actor);
        if (! $expense->isPending()) {
            throw new InvalidArgumentException('Only pending expenses can be rejected.');
        }

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $before = $expense->status;
            $expense->update([
                'status' => Expense::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);
            $this->audit($actor, 'EXPENSE_REJECTED', $expense->fresh(), $before, Expense::STATUS_REJECTED, $reason);

            return $expense->fresh(['user', 'category', 'originShift', 'reviewer']);
        });
    }

    public function markPaid(Expense $expense, User $actor): Expense
    {
        if (! $expense->isApproved()) {
            throw new InvalidArgumentException('Only approved expenses can be marked paid.');
        }

        $register = $this->requireActiveRegister();
        $this->assertCanOperateRegister($actor, $register);

        return DB::transaction(function () use ($expense, $actor, $register) {
            $before = $expense->status;
            $expense->update([
                'status' => Expense::STATUS_POSTED,
                'posted_shift_session_id' => $register->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);
            $this->audit($actor, 'EXPENSE_POSTED', $expense->fresh(), $before, Expense::STATUS_POSTED, 'Marked paid / disbursed.');

            return $expense->fresh(['user', 'category', 'originShift', 'postedShift', 'poster', 'reviewer']);
        });
    }

    public function voidPosted(Expense $expense, User $actor, string $reason, bool $confirmedNoPhysicalMovement): Expense
    {
        if (! $expense->isPosted()) {
            throw new InvalidArgumentException('Only posted expenses can be voided.');
        }

        PostedCashVoidPolicy::assertConfirmed($confirmedNoPhysicalMovement);
        $openShift = PostedCashVoidPolicy::assertOpenAccountingShift($expense->accountingShift());
        PostedCashVoidPolicy::assertActorMayVoid($actor, $openShift);

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $before = $expense->status;
            $expense->update([
                'status' => Expense::STATUS_VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);
            $this->audit(
                $actor,
                'EXPENSE_VOIDED',
                $expense->fresh(),
                $before,
                Expense::STATUS_VOIDED,
                $reason,
                ['physical_cash_did_not_occur' => true]
            );

            return $expense->fresh();
        });
    }

    public function annotateClosedPosted(Expense $expense, User $actor, string $reason): Expense
    {
        if (! $expense->isPosted()) {
            throw new InvalidArgumentException('Only posted expenses can receive a closed-shift audit note.');
        }

        PostedCashVoidPolicy::assertAdmin($actor);
        PostedCashVoidPolicy::assertClosedOrHistorical($expense->accountingShift());

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $this->audit(
                $actor,
                'EXPENSE_CLOSED_SHIFT_NOTE',
                $expense->fresh(),
                Expense::STATUS_POSTED,
                Expense::STATUS_POSTED,
                $reason,
                ['financially_posted' => true]
            );

            return $expense->fresh();
        });
    }

    public function unresolvedForOriginShift(ShiftSession $shift): int
    {
        return Expense::query()
            ->where('shift_session_id', $shift->id)
            ->whereIn('status', [Expense::STATUS_PENDING_APPROVAL, Expense::STATUS_APPROVED])
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(Expense $expense): array
    {
        return app(CashActivityHistoryService::class)->timeline('expenses', (int) $expense->id);
    }

    private function requireActiveRegister(): ShiftSession
    {
        $register = ShiftService::activeRegister();
        if (! $register) {
            throw new InvalidArgumentException('An active front-desk register is required to record or pay an expense.');
        }

        return $register;
    }

    private function assertCanOperateRegister(User $actor, ShiftSession $register): void
    {
        if ($actor->role === UserRole::Admin->value) {
            return;
        }
        if ($register->user_id === $actor->id) {
            return;
        }

        throw new InvalidArgumentException('Only the assigned register operator or an administrator may mark an expense paid.');
    }

    private function assertCanEditPending(Expense $expense, User $actor): void
    {
        if (! $expense->isPending()) {
            throw new InvalidArgumentException('Only pending expenses can be edited or cancelled.');
        }
        if ($actor->role === UserRole::Admin->value) {
            return;
        }
        if ((int) $expense->recorded_by === (int) $actor->id) {
            return;
        }

        throw new InvalidArgumentException('Only the submitter or an administrator may change a pending expense.');
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->role !== UserRole::Admin->value) {
            abort(403, 'Only an administrator may approve or reject large expenses.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $actor, string $action, Expense $expense, ?string $before, string $after, ?string $reason, array $extra = []): void
    {
        AuditService::log(
            $actor->id,
            $action,
            'expenses',
            (int) $expense->id,
            [
                'status' => $before,
            ],
            array_merge([
                'reference' => $expense->reference,
                'amount' => (float) $expense->amount,
                'drawer' => $expense->cash_drawer,
                'origin_shift_id' => $expense->shift_session_id,
                'posted_shift_id' => $expense->posted_shift_session_id,
                'actor_id' => $actor->id,
                'actor_name' => $actor->full_name,
                'status' => $after,
                'occurred_at_utc' => HotelDateTime::utcIso(now()),
            ], $extra),
            $reason
        );
    }
}
