<?php

namespace App\Services;

use App\Models\AdditionalCash;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\HotelDateTime;
use App\Support\PostedCashVoidPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdditionalCashLedgerService
{
    public function record(User $actor, array $payload, ?string $receiptPath = null): AdditionalCash
    {
        $register = $this->requireActiveRegister();

        return DB::transaction(function () use ($actor, $payload, $receiptPath, $register) {
            $row = AdditionalCash::create([
                'income_date' => $payload['income_date'],
                'amount' => round((float) $payload['amount'], 2),
                'cash_drawer' => $payload['cash_drawer'],
                'notes' => $payload['notes'],
                'receipt_path' => $receiptPath,
                'recorded_by' => $actor->id,
                'status' => AdditionalCash::STATUS_POSTED,
                'shift_session_id' => $register->id,
            ]);

            $this->audit($actor, 'ADDITIONAL_CASH_RECORDED', $row, null, AdditionalCash::STATUS_POSTED, $payload['notes']);

            return $row->fresh(['user', 'originShift']);
        });
    }

    public function voidPosted(AdditionalCash $row, User $actor, string $reason, bool $confirmedNoPhysicalMovement): AdditionalCash
    {
        if (! $row->isPosted()) {
            throw new InvalidArgumentException('Only posted additional cash can be voided.');
        }

        PostedCashVoidPolicy::assertConfirmed($confirmedNoPhysicalMovement);
        $openShift = PostedCashVoidPolicy::assertOpenAccountingShift($row->accountingShift());
        PostedCashVoidPolicy::assertActorMayVoid($actor, $openShift);

        return DB::transaction(function () use ($row, $actor, $reason) {
            $before = $row->status;
            $row->update([
                'status' => AdditionalCash::STATUS_VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);
            $this->audit(
                $actor,
                'ADDITIONAL_CASH_VOIDED',
                $row->fresh(),
                $before,
                AdditionalCash::STATUS_VOIDED,
                $reason,
                ['physical_cash_did_not_occur' => true]
            );

            return $row->fresh();
        });
    }

    public function annotateClosedPosted(AdditionalCash $row, User $actor, string $reason): AdditionalCash
    {
        if (! $row->isPosted()) {
            throw new InvalidArgumentException('Only posted additional cash can receive a closed-shift audit note.');
        }

        PostedCashVoidPolicy::assertAdmin($actor);
        PostedCashVoidPolicy::assertClosedOrHistorical($row->accountingShift());

        return DB::transaction(function () use ($row, $actor, $reason) {
            $this->audit(
                $actor,
                'ADDITIONAL_CASH_CLOSED_SHIFT_NOTE',
                $row->fresh(),
                AdditionalCash::STATUS_POSTED,
                AdditionalCash::STATUS_POSTED,
                $reason,
                ['financially_posted' => true]
            );

            return $row->fresh();
        });
    }

    private function requireActiveRegister(): ShiftSession
    {
        $register = ShiftService::activeRegister();
        if (! $register) {
            throw new InvalidArgumentException('An active front-desk register is required to record additional cash.');
        }

        return $register;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $actor, string $action, AdditionalCash $row, ?string $before, string $after, ?string $reason, array $extra = []): void
    {
        AuditService::log(
            $actor->id,
            $action,
            'additional_cash',
            (int) $row->id,
            ['status' => $before],
            array_merge([
                'reference' => $row->reference,
                'amount' => (float) $row->amount,
                'drawer' => $row->cash_drawer,
                'origin_shift_id' => $row->shift_session_id,
                'posted_shift_id' => $row->shift_session_id,
                'actor_id' => $actor->id,
                'actor_name' => $actor->full_name,
                'status' => $after,
                'occurred_at_utc' => HotelDateTime::utcIso(now()),
            ], $extra),
            $reason
        );
    }
}
