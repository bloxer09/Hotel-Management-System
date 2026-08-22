<?php

namespace App\Services;

use App\Exceptions\InventoryTurnoverException;
use App\Models\InventoryItem;
use App\Models\InventoryShiftCountItem;
use App\Models\InventoryShiftTurnover;
use App\Models\InventoryStockMovement;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\HotelDateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTurnoverService
{
    public const FREEZE_MESSAGE = 'Inventory turnover count is in progress. Tracked inventory is temporarily locked.';

    public const HANDOVER_MESSAGE = 'Inventory handover must be verified before using tracked inventory.';

    public const BOOTSTRAP_MESSAGE = 'A physical opening count is required for tracked inventory before it can be used.';

    public const CONTEXT_SALE = 'sale';

    public const CONTEXT_ADMIN_ADJUST = 'admin_adjust';

    public function trackedItems(): Collection
    {
        return InventoryItem::query()
            ->where('is_turnover_tracked', true)
            ->where('is_active', true)
            ->orderBy('item_name')
            ->get();
    }

    public function hasTrackedItems(): bool
    {
        return InventoryItem::query()->where('is_turnover_tracked', true)->where('is_active', true)->exists();
    }

    public function countingFreezeActive(): bool
    {
        return InventoryShiftTurnover::query()
            ->where('status', InventoryShiftTurnover::STATUS_COUNTING)
            ->exists();
    }

    public function pendingHandover(): ?InventoryShiftTurnover
    {
        return InventoryShiftTurnover::query()
            ->with(['items', 'shiftSession.user'])
            ->whereIn('status', [InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::STATUS_DISPUTED])
            ->orderByDesc('id')
            ->first();
    }

    public function isGapWindow(): bool
    {
        return $this->pendingHandover() !== null;
    }

    public function latestAccepted(): ?InventoryShiftTurnover
    {
        return InventoryShiftTurnover::query()
            ->with('items')
            ->where('status', InventoryShiftTurnover::STATUS_ACCEPTED)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->first();
    }

    public function bootstrapPending(): bool
    {
        if (! $this->hasTrackedItems()) {
            return false;
        }

        if ($this->pendingHandover()) {
            return false;
        }

        if ($this->latestAccepted()) {
            return false;
        }

        $current = $this->turnoverForActiveRegister();
        if ($current) {
            return ! $current->openingEstablished();
        }

        return true;
    }

    /**
     * Authoritative tracked-stock custody check. Does not depend on visiting
     * Shift Register or the turnover screen. Lazily creates the register's
     * turnover snapshot when a Front Desk register is active, then blocks
     * until physical opening or incoming acceptance is complete.
     */
    public function assertTrackedInventoryReadyForRegister(User $user, array $itemIds, string $context = self::CONTEXT_SALE): void
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return;
        }

        $trackedIds = InventoryItem::query()
            ->whereIn('id', $itemIds)
            ->where('is_turnover_tracked', true)
            ->pluck('id');

        if ($trackedIds->isEmpty()) {
            return;
        }

        if ($this->countingFreezeActive()) {
            abort(403, self::FREEZE_MESSAGE);
        }

        if ($this->isGapWindow()) {
            if ($context === self::CONTEXT_ADMIN_ADJUST && $user->role === 'admin') {
                return;
            }

            abort(403, self::HANDOVER_MESSAGE);
        }

        $register = ShiftService::activeRegister();
        if ($register) {
            $this->ensureForShift($register);
        }

        if ($this->bootstrapPending()) {
            abort(403, self::BOOTSTRAP_MESSAGE);
        }
    }

    public function assertItemsMutable(User $user, array $itemIds, string $context = self::CONTEXT_SALE): void
    {
        $this->assertTrackedInventoryReadyForRegister($user, $itemIds, $context);
    }

    public function ensureForShift(ShiftSession $shift): ?InventoryShiftTurnover
    {
        if (! $this->hasTrackedItems()) {
            return null;
        }

        $existing = InventoryShiftTurnover::query()
            ->where('shift_session_id', $shift->id)
            ->first();
        if ($existing) {
            $this->syncTrackedLines($existing);

            return $existing->fresh(['items']);
        }

        return DB::transaction(function () use ($shift) {
            $pending = $this->pendingHandover();
            $previous = $this->latestAccepted();
            $isBootstrap = $previous === null && $pending === null;
            $openingEstablishedAt = null;
            $openings = [];

            if ($previous) {
                foreach ($previous->items as $line) {
                    $openings[$line->inventory_item_id] = (int) $line->incoming_verified_quantity;
                }
                $openingEstablishedAt = now();
            }

            $turnover = InventoryShiftTurnover::create([
                'shift_session_id' => $shift->id,
                'status' => InventoryShiftTurnover::STATUS_OPEN,
                'formula_version' => InventoryShiftTurnover::FORMULA_VERSION,
                'is_bootstrap' => $isBootstrap,
                'opening_established_at' => $openingEstablishedAt,
            ]);

            foreach ($this->trackedItems() as $item) {
                InventoryShiftCountItem::create([
                    'inventory_shift_turnover_id' => $turnover->id,
                    'inventory_item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'unit' => $item->unit,
                    'selling_price' => $item->selling_price,
                    'opening_quantity' => $isBootstrap ? null : ($openings[$item->id] ?? 0),
                ]);
            }

            return $turnover->fresh(['items']);
        });
    }

    public function acceptOpening(User $user, InventoryShiftTurnover $turnover, array $counts): InventoryShiftTurnover
    {
        $this->assertCanOperate($user, $turnover);

        if ($turnover->status !== InventoryShiftTurnover::STATUS_OPEN) {
            throw new InventoryTurnoverException('Opening counts can only be recorded while the turnover is open.');
        }

        if ($this->pendingHandover()) {
            throw new InventoryTurnoverException('Verify the previous shift handover before recording a new opening count.');
        }

        if ($turnover->openingEstablished() && ! $turnover->is_bootstrap) {
            throw new InventoryTurnoverException('Opening stock is already established from the previous accepted handover.');
        }

        $normalized = $this->requireAllTrackedCounts($counts);

        return DB::transaction(function () use ($user, $turnover, $normalized) {
            $locked = InventoryShiftTurnover::query()->whereKey($turnover->id)->lockForUpdate()->firstOrFail();
            $this->syncTrackedLines($locked);

            foreach ($locked->items()->orderBy('inventory_item_id')->get() as $line) {
                $line->opening_quantity = $normalized[$line->inventory_item_id];
                $line->save();
            }

            $itemIds = $locked->items()->orderBy('inventory_item_id')->pluck('inventory_item_id')->all();
            $stockItems = InventoryItem::query()
                ->whereIn('id', $itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($locked->items()->orderBy('inventory_item_id')->get() as $line) {
                $item = $stockItems->get($line->inventory_item_id);
                if (! $item) {
                    continue;
                }
                $before = (int) $item->current_stock;
                $after = (int) $line->opening_quantity;
                if ($before === $after) {
                    continue;
                }
                $item->current_stock = $after;
                $item->save();
                InventoryStockMovement::record([
                    'inventory_item_id' => $item->id,
                    'movement_type' => InventoryStockMovement::TYPE_INVENTORY_VARIANCE,
                    'quantity_change' => $after - $before,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'source_type' => InventoryShiftTurnover::SOURCE_OPENING,
                    'source_id' => $locked->id,
                    'performed_by' => $user->id,
                    'shift_session_id' => $locked->shift_session_id,
                    'notes' => 'Bootstrap physical opening reconciliation. Live stock aligned to counted opening '.$after.' from '.$before.'.',
                ]);
            }

            $locked->is_bootstrap = true;
            $locked->opening_established_at = now();
            $locked->counted_by = $user->id;
            $locked->save();

            BookingService::auditLog(
                $user->id,
                'INVENTORY_TURNOVER_OPENING_ACCEPTED',
                'inventory_shift_turnovers',
                $locked->id,
                null,
                $locked->shift_session_id,
                'Physical opening count established for tracked inventory.'
            );

            return $locked->fresh(['items']);
        });
    }

    public function startCounting(User $user, InventoryShiftTurnover $turnover): InventoryShiftTurnover
    {
        $this->assertCanOperate($user, $turnover);

        if (! $this->hasTrackedItems()) {
            throw new InventoryTurnoverException('No products are configured for inventory turnover.');
        }

        if ($turnover->isFrozenSnapshot()) {
            throw new InventoryTurnoverException('This inventory turnover is already frozen.');
        }

        if (! $turnover->openingEstablished()) {
            throw new InventoryTurnoverException('Record the physical opening count before starting the outgoing count.');
        }

        if ($turnover->status === InventoryShiftTurnover::STATUS_COUNTING) {
            return $turnover->fresh(['items']);
        }

        return DB::transaction(function () use ($user, $turnover) {
            $locked = InventoryShiftTurnover::query()->whereKey($turnover->id)->lockForUpdate()->firstOrFail();
            $this->syncTrackedLines($locked);
            $freezeAt = now();
            $buckets = $this->movementBuckets((int) $locked->shift_session_id, $freezeAt);
            $hasManualSet = false;

            foreach ($locked->items()->get() as $line) {
                $bucket = $buckets[$line->inventory_item_id] ?? $this->emptyBucket();
                $opening = (int) $line->opening_quantity;
                $expected = $this->expectedClosing($opening, $bucket);
                $hasManualSet = $hasManualSet || $bucket['manual_set'] !== 0;

                $line->fill([
                    'restock_quantity' => $bucket['restock'],
                    'return_quantity' => $bucket['returns'],
                    'sold_quantity' => $bucket['sold'],
                    'complimentary_quantity' => $bucket['complimentary'],
                    'other_out_quantity' => $bucket['other_out'],
                    'manual_set_quantity' => $bucket['manual_set'],
                    'expected_closing_quantity' => $expected,
                    'outgoing_actual_quantity' => null,
                    'variance_quantity' => null,
                ])->save();
            }

            $locked->status = InventoryShiftTurnover::STATUS_COUNTING;
            $locked->freeze_started_at = $freezeAt;
            $locked->counted_by = $user->id;
            $locked->has_manual_set = $hasManualSet;
            $locked->save();

            BookingService::auditLog(
                $user->id,
                'INVENTORY_TURNOVER_COUNT_STARTED',
                'inventory_shift_turnovers',
                $locked->id,
                InventoryShiftTurnover::STATUS_OPEN,
                InventoryShiftTurnover::STATUS_COUNTING,
                'Tracked inventory posting frozen for outgoing physical count. Shift #'.$locked->shift_session_id
            );

            return $locked->fresh(['items']);
        });
    }

    public function cancelCounting(User $user, InventoryShiftTurnover $turnover): InventoryShiftTurnover
    {
        $this->assertCanOperate($user, $turnover);

        if ($turnover->status !== InventoryShiftTurnover::STATUS_COUNTING) {
            throw new InventoryTurnoverException('Only an in-progress count can be cancelled.');
        }

        $turnover->status = InventoryShiftTurnover::STATUS_OPEN;
        $turnover->freeze_started_at = null;
        $turnover->save();

        return $turnover->fresh(['items']);
    }

    public function submit(User $user, InventoryShiftTurnover $turnover, array $counts, ?string $notes = null): InventoryShiftTurnover
    {
        $this->assertCanOperate($user, $turnover);

        if ($turnover->status !== InventoryShiftTurnover::STATUS_COUNTING) {
            throw new InventoryTurnoverException('Start the outgoing count before submitting physical actuals.');
        }

        $normalized = $this->requireAllTrackedCounts($counts);

        return DB::transaction(function () use ($user, $turnover, $normalized, $notes) {
            $locked = InventoryShiftTurnover::query()->whereKey($turnover->id)->lockForUpdate()->firstOrFail();
            $itemIds = $locked->items()->orderBy('inventory_item_id')->pluck('inventory_item_id')->all();
            $stockItems = InventoryItem::query()
                ->whereIn('id', $itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $varianceSummary = [];
            foreach ($locked->items()->orderBy('inventory_item_id')->get() as $line) {
                $actual = $normalized[$line->inventory_item_id];
                $expected = (int) $line->expected_closing_quantity;
                $variance = $actual - $expected;
                $line->outgoing_actual_quantity = $actual;
                $line->variance_quantity = $variance;
                $line->save();
                $varianceSummary[] = $line->item_name.': '.$this->varianceLabel($variance);
            }

            $locked->status = InventoryShiftTurnover::STATUS_SUBMITTED;
            $locked->submitted_at = now();
            $locked->counted_by = $user->id;
            $locked->notes = $notes;
            $locked->save();

            foreach ($locked->items()->orderBy('inventory_item_id')->get() as $line) {
                $item = $stockItems->get($line->inventory_item_id);
                if (! $item) {
                    continue;
                }
                $before = (int) $item->current_stock;
                $after = (int) $line->outgoing_actual_quantity;
                $delta = $after - $before;
                if ($delta === 0) {
                    continue;
                }

                $item->current_stock = $after;
                $item->save();

                InventoryStockMovement::record([
                    'inventory_item_id' => $item->id,
                    'movement_type' => InventoryStockMovement::TYPE_INVENTORY_VARIANCE,
                    'quantity_change' => $delta,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'source_type' => InventoryShiftTurnover::SOURCE_TYPE,
                    'source_id' => $locked->id,
                    'performed_by' => $user->id,
                    'shift_session_id' => $locked->shift_session_id,
                    'notes' => 'Physical count reconciliation. Shift #'.$locked->shift_session_id,
                ]);
            }

            BookingService::auditLog(
                $user->id,
                'INVENTORY_TURNOVER_SUBMITTED',
                'inventory_shift_turnovers',
                $locked->id,
                InventoryShiftTurnover::STATUS_COUNTING,
                InventoryShiftTurnover::STATUS_SUBMITTED,
                'Outgoing inventory turnover submitted. '.implode('; ', $varianceSummary)
            );

            return $locked->fresh(['items']);
        });
    }

    public function acceptHandover(User $user, InventoryShiftTurnover $turnover, array $counts, ?string $notes = null): InventoryShiftTurnover
    {
        $this->assertCanVerify($user, $turnover);

        if ($turnover->status === InventoryShiftTurnover::STATUS_DISPUTED) {
            throw new InventoryTurnoverException('A disputed handover cannot be accepted normally. Admin must resolve the discrepancy or request a recount.');
        }

        if ($turnover->status !== InventoryShiftTurnover::STATUS_SUBMITTED) {
            throw new InventoryTurnoverException('Only a submitted turnover can be accepted.');
        }

        $normalized = $this->requireAllTrackedCounts($counts);
        $gaps = $this->gapNetByItem($turnover);
        $mismatches = [];
        foreach ($turnover->items as $line) {
            $verified = $normalized[$line->inventory_item_id];
            $expected = $this->handoverExpectedQuantity($line, $gaps);
            if ($verified !== $expected) {
                $mismatches[] = $line->item_name;
            }
        }

        if ($mismatches !== []) {
            throw new InventoryTurnoverException(
                'Incoming count does not match expected physical stock at handover (outgoing actual + authorized between-shift movements) for: '.implode(', ', $mismatches).'. Recount or mark the handover as disputed.'
            );
        }

        return $this->finalizeAcceptance($user, $turnover, $normalized, $notes);
    }

    public function disputeHandover(User $user, InventoryShiftTurnover $turnover, array $counts, string $reason): InventoryShiftTurnover
    {
        $this->assertCanVerify($user, $turnover);

        if ($turnover->status !== InventoryShiftTurnover::STATUS_SUBMITTED && $turnover->status !== InventoryShiftTurnover::STATUS_DISPUTED) {
            throw new InventoryTurnoverException('Only a submitted turnover can be disputed.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when the incoming count differs from expected physical stock at handover.',
            ]);
        }

        $normalized = $this->requireAllTrackedCounts($counts);

        return DB::transaction(function () use ($user, $turnover, $normalized, $reason) {
            $locked = InventoryShiftTurnover::query()->whereKey($turnover->id)->lockForUpdate()->firstOrFail();
            $locked->load('items');
            $gaps = $this->gapNetByItem($locked);
            $liveBefore = InventoryItem::query()
                ->whereIn('id', $locked->items->pluck('inventory_item_id')->all())
                ->pluck('current_stock', 'id');

            foreach ($locked->items as $line) {
                $verified = $normalized[$line->inventory_item_id];
                $this->writeHandoverFigures($line, $verified, $gaps);
                $line->save();
            }

            $locked->status = InventoryShiftTurnover::STATUS_DISPUTED;
            $locked->disputed_reason = $reason;
            $locked->disputed_at = now();
            $locked->disputed_by = $user->id;
            $locked->save();

            foreach ($locked->items as $line) {
                $itemId = (int) $line->inventory_item_id;
                $stockNow = (int) InventoryItem::query()->whereKey($itemId)->value('current_stock');
                if ($stockNow !== (int) ($liveBefore[$itemId] ?? $stockNow)) {
                    throw new InventoryTurnoverException('Marking a handover disputed must not change live stock.');
                }
            }

            BookingService::auditLog(
                $user->id,
                'INVENTORY_HANDOVER_DISPUTED',
                'inventory_shift_turnovers',
                $locked->id,
                InventoryShiftTurnover::STATUS_SUBMITTED,
                InventoryShiftTurnover::STATUS_DISPUTED,
                $reason
            );

            return $locked->fresh(['items']);
        });
    }

    public function resolveDispute(User $admin, InventoryShiftTurnover $turnover, array $counts, string $reason): InventoryShiftTurnover
    {
        if ($admin->role !== 'admin') {
            abort(403, 'Only administrators can resolve inventory handover disputes.');
        }

        if ($turnover->status !== InventoryShiftTurnover::STATUS_DISPUTED) {
            throw new InventoryTurnoverException('Only a disputed handover can be resolved.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'An admin resolution reason is required.',
            ]);
        }

        $normalized = $this->requireAllTrackedCounts($counts);

        return $this->finalizeAcceptance($admin, $turnover, $normalized, $reason, true);
    }

    public function requestRecount(User $user, InventoryShiftTurnover $turnover, string $reason): InventoryShiftTurnover
    {
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can request a handover recount.');
        }

        if ($turnover->status !== InventoryShiftTurnover::STATUS_DISPUTED && $turnover->status !== InventoryShiftTurnover::STATUS_SUBMITTED) {
            throw new InventoryTurnoverException('A recount can only be requested for a submitted or disputed handover.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to request a recount.',
            ]);
        }

        $before = $turnover->status;
        $turnover->status = InventoryShiftTurnover::STATUS_DISPUTED;
        $turnover->resolution_type = InventoryShiftTurnover::RESOLUTION_REQUIRE_RECOUNT;
        $turnover->recount_requested_at = now();
        $turnover->recount_requested_by = $user->id;
        if ($turnover->disputed_reason === null || $turnover->disputed_reason === '') {
            $turnover->disputed_reason = $reason;
            $turnover->disputed_at = $turnover->disputed_at ?? now();
            $turnover->disputed_by = $turnover->disputed_by ?? $user->id;
        }
        $turnover->save();

        BookingService::auditLog(
            $user->id,
            'INVENTORY_HANDOVER_RECOUNT_REQUESTED',
            'inventory_shift_turnovers',
            $turnover->id,
            $before,
            InventoryShiftTurnover::STATUS_DISPUTED,
            $reason
        );

        return $turnover->fresh(['items']);
    }

    public function assertCanView(User $user, InventoryShiftTurnover $turnover): void
    {
        $turnover->loadMissing('shiftSession');
        if ($user->role === 'housekeeping') {
            abort(403, 'Housekeeping cannot access inventory turnover records.');
        }

        if ($user->role === 'admin') {
            return;
        }

        if ($user->role !== 'front_desk') {
            abort(403, 'Unauthorized inventory turnover access.');
        }

        $shiftUserId = (int) ($turnover->shiftSession?->user_id ?? 0);
        $visible = $shiftUserId === (int) $user->id
            || (int) $turnover->counted_by === (int) $user->id
            || (int) $turnover->accepted_by === (int) $user->id
            || (int) $turnover->disputed_by === (int) $user->id
            || in_array($turnover->status, [InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::STATUS_DISPUTED], true);

        if (! $visible) {
            abort(403, 'Front Desk can only view current handover work and their own shift turnovers.');
        }
    }

    public function recordCloseOverride(User $admin, InventoryShiftTurnover $turnover, ?string $reason): void
    {
        if ($admin->role !== 'admin') {
            abort(403, 'Only administrators can override the inventory count requirement.');
        }

        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'inventory_override_reason' => 'A reason is required to end the shift without a completed inventory turnover.',
            ]);
        }

        if ($turnover->status === InventoryShiftTurnover::STATUS_COUNTING) {
            $turnover->status = InventoryShiftTurnover::STATUS_OPEN;
            $turnover->freeze_started_at = null;
        }

        $turnover->admin_override_reason = $reason;
        $turnover->admin_override_by = $admin->id;
        $turnover->admin_override_at = now();
        $turnover->save();

        BookingService::auditLog(
            $admin->id,
            'INVENTORY_TURNOVER_ADMIN_OVERRIDE',
            'inventory_shift_turnovers',
            $turnover->id,
            null,
            $reason,
            'End Shift allowed without completed inventory turnover. Shift #'.$turnover->shift_session_id
        );
    }

    public function assertReadyToEndShift(User $user, ShiftSession $shift, ?string $overrideReason): void
    {
        if (! $this->hasTrackedItems()) {
            return;
        }

        $turnover = $this->ensureForShift($shift);
        if (! $turnover) {
            return;
        }

        if (in_array($turnover->status, [InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::STATUS_ACCEPTED, InventoryShiftTurnover::STATUS_DISPUTED], true)) {
            return;
        }

        if ($user->role === 'admin') {
            $this->recordCloseOverride($user, $turnover, $overrideReason);

            return;
        }

        throw ValidationException::withMessages([
            'inventory_turnover' => 'Complete the tracked inventory physical count and submit the turnover before ending the shift.',
        ]);
    }

    public function movementShiftIdForNewRow(): ?int
    {
        if ($this->isGapWindow()) {
            return null;
        }

        return ShiftService::activeRegisterId();
    }

    public function reportPayload(?InventoryShiftTurnover $turnover): ?array
    {
        if (! $turnover) {
            return null;
        }

        $turnover->loadMissing([
            'items',
            'shiftSession.user',
            'countedBy',
            'acceptedBy',
            'disputedBy',
            'resolvedBy',
            'recountRequestedBy',
            'adminOverrideBy',
        ]);
        $liveGaps = $turnover->accepted_at ? null : $this->gapNetByItem($turnover);
        $items = $turnover->items->map(fn (InventoryShiftCountItem $line) => $this->linePayload($line, $turnover, $liveGaps))->values()->all();
        $shortItems = collect($items)->filter(fn (array $row) => ($row['variance_quantity'] ?? 0) < 0)->values()->all();
        $overItems = collect($items)->filter(fn (array $row) => ($row['variance_quantity'] ?? 0) > 0)->values()->all();
        $handoverIssues = collect($items)->filter(fn (array $row) => $row['handover_difference'] !== null && (int) $row['handover_difference'] !== 0)->values()->all();

        return [
            'id' => $turnover->id,
            'shift_session_id' => $turnover->shift_session_id,
            'shift_code' => $turnover->shiftSession?->shift_code,
            'business_date_manila' => $turnover->shiftSession?->started_at
                ? HotelDateTime::formatUtcForDisplay($turnover->shiftSession->started_at)
                : null,
            'status' => $turnover->status,
            'status_label' => $turnover->statusLabel(),
            'status_description' => $turnover->statusDescription(),
            'formula_version' => $turnover->formula_version,
            'is_bootstrap' => (bool) $turnover->is_bootstrap,
            'has_manual_set' => (bool) $turnover->has_manual_set,
            'is_frozen' => $turnover->isFrozenSnapshot(),
            'freeze_started_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->freeze_started_at),
            'submitted_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->submitted_at),
            'accepted_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->accepted_at),
            'disputed_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->disputed_at),
            'resolved_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->resolved_at),
            'recount_requested_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->recount_requested_at),
            'counted_by_name' => $turnover->countedBy?->full_name,
            'accepted_by_name' => $turnover->acceptedBy?->full_name,
            'disputed_by_name' => $turnover->disputedBy?->full_name,
            'resolved_by_name' => $turnover->resolvedBy?->full_name,
            'recount_requested_by_name' => $turnover->recountRequestedBy?->full_name,
            'outgoing_operator_name' => $turnover->shiftSession?->user?->full_name,
            'incoming_operator_name' => $turnover->acceptedBy?->full_name,
            'notes' => $turnover->notes,
            'disputed_reason' => $turnover->disputed_reason,
            'resolution_type' => $turnover->resolution_type,
            'resolution_notes' => $turnover->resolution_notes,
            'admin_override_reason' => $turnover->admin_override_reason,
            'admin_override_by_name' => $turnover->adminOverrideBy?->full_name,
            'admin_override_at_manila' => HotelDateTime::formatUtcForDisplay($turnover->admin_override_at),
            'short_item_count' => count($shortItems),
            'over_item_count' => count($overItems),
            'handover_issue_count' => count($handoverIssues),
            'handover_status' => $this->handoverStatusLabel($turnover, $handoverIssues),
            'outgoing_shorts' => $shortItems,
            'outgoing_overs' => $overItems,
            'handover_issues' => $handoverIssues,
            'gap_movements' => $this->gapMovementDetails($turnover),
            'manual_set_movements' => $this->manualSetDetails($turnover),
            'items' => $items,
        ];
    }

    public function screenPayload(?ShiftSession $shift, User $user): array
    {
        $tracked = $this->trackedItems();
        $pending = $this->pendingHandover();
        $current = $shift ? $this->ensureForShift($shift) : null;
        $pendingShiftUserId = $pending?->shiftSession?->user_id;

        return [
            'tracked_count' => $tracked->count(),
            'has_tracked_items' => $tracked->isNotEmpty(),
            'bootstrap_pending' => $this->bootstrapPending(),
            'counting_freeze' => $this->countingFreezeActive(),
            'pending_handover' => $pending ? $this->reportPayload($pending) : null,
            'current_turnover' => $current ? $this->reportPayload($current) : null,
            'tracked_items' => $tracked->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'current_stock' => (int) $item->current_stock,
            ])->values()->all(),
            'can_admin_resolve' => $user->role === 'admin',
            'disputed_count' => $this->disputedCount(),
            'same_operator_checkpoint' => $pending !== null
                && $pendingShiftUserId !== null
                && (int) $pendingShiftUserId === (int) $user->id,
        ];
    }

    public function indexSummary(?ShiftSession $shift, User $user): array
    {
        $screen = $this->screenPayload($shift, $user);
        $current = $screen['current_turnover'] ?? [];

        return [
            'has_tracked_items' => $screen['has_tracked_items'],
            'tracked_count' => $screen['tracked_count'],
            'bootstrap_pending' => $screen['bootstrap_pending'],
            'counting_freeze' => $screen['counting_freeze'],
            'pending_handover_status' => $screen['pending_handover']['status'] ?? null,
            'current_status' => $current['status'] ?? null,
            'current_is_frozen' => (bool) ($current['is_frozen'] ?? false),
            'requires_count_before_end' => $screen['has_tracked_items'] && ! (bool) ($current['is_frozen'] ?? false),
            'admin_override_reason' => $current['admin_override_reason'] ?? null,
            'same_operator_checkpoint' => $screen['same_operator_checkpoint'],
            'has_manual_set' => (bool) ($current['has_manual_set'] ?? false),
            'disputed_count' => $screen['disputed_count'] ?? 0,
            'pending_handover_status_label' => $screen['pending_handover']['status_label'] ?? null,
            'current_status_label' => $current['status_label'] ?? null,
            'current_status_description' => $current['status_description'] ?? null,
        ];
    }

    public function bannerForUser(?User $user): ?array
    {
        if (! $user || ! in_array($user->role, ['admin', 'front_desk'], true)) {
            return null;
        }

        if ($this->countingFreezeActive()) {
            return [
                'tone' => 'warning',
                'title' => 'Inventory count in progress',
                'message' => self::FREEZE_MESSAGE,
                'href' => route('shifts.inventory_turnover.show'),
            ];
        }

        $pending = $this->pendingHandover();
        if ($pending && $pending->status === InventoryShiftTurnover::STATUS_DISPUTED) {
            $count = $this->disputedCount();

            return [
                'tone' => 'warning',
                'title' => $user->role === 'admin'
                    ? ($count > 1 ? $count.' inventory handovers require Admin review' : 'Inventory handover has a discrepancy and requires Admin review.')
                    : 'Inventory handover has a discrepancy and requires Admin review.',
                'message' => InventoryShiftTurnover::STATUS_DESCRIPTIONS[InventoryShiftTurnover::STATUS_DISPUTED],
                'href' => $user->role === 'admin'
                    ? route('shifts.inventory_turnover.show_record', $pending)
                    : route('shifts.inventory_turnover.show'),
                'disputed_count' => $count,
            ];
        }

        if ($pending) {
            return [
                'tone' => 'warning',
                'title' => 'Inventory handover requires verification.',
                'message' => self::HANDOVER_MESSAGE,
                'href' => route('shifts.inventory_turnover.show'),
            ];
        }

        if ($this->bootstrapPending()) {
            return [
                'tone' => 'warning',
                'title' => 'Tracked inventory opening count required',
                'message' => self::BOOTSTRAP_MESSAGE,
                'href' => route('shifts.inventory_turnover.show'),
            ];
        }

        $incomplete = InventoryShiftTurnover::query()
            ->whereNotNull('admin_override_reason')
            ->whereIn('status', [InventoryShiftTurnover::STATUS_OPEN, InventoryShiftTurnover::STATUS_COUNTING])
            ->latest('id')
            ->first();

        if ($incomplete) {
            return [
                'tone' => 'warning',
                'title' => 'Incomplete inventory turnover',
                'message' => 'A previous shift was closed without a completed inventory count. Review the outstanding turnover before using tracked stock as counted.',
                'href' => route('shifts.inventory_turnover.show'),
            ];
        }

        return null;
    }

    public function turnoverForShift(int $shiftId): ?InventoryShiftTurnover
    {
        return InventoryShiftTurnover::query()
            ->with(['items', 'countedBy', 'acceptedBy', 'shiftSession.user'])
            ->where('shift_session_id', $shiftId)
            ->first();
    }

    public function varianceLabel(int $variance): string
    {
        if ($variance === 0) {
            return 'BALANCED';
        }

        return $variance < 0 ? 'SHORT '.abs($variance) : 'OVER '.$variance;
    }

    public function disputedCount(): int
    {
        return InventoryShiftTurnover::query()
            ->where('status', InventoryShiftTurnover::STATUS_DISPUTED)
            ->count();
    }

    public function history(User $user, array $filters = [])
    {
        $this->assertDeskRole($user);

        $query = InventoryShiftTurnover::query()
            ->with(['items', 'shiftSession.user', 'countedBy', 'acceptedBy', 'adminOverrideBy'])
            ->orderByDesc('id');

        if ($user->role === 'front_desk') {
            $query->where(function ($inner) use ($user) {
                $inner->where('counted_by', $user->id)
                    ->orWhere('accepted_by', $user->id)
                    ->orWhere('disputed_by', $user->id)
                    ->orWhereHas('shiftSession', fn ($shift) => $shift->where('user_id', $user->id))
                    ->orWhereIn('status', [InventoryShiftTurnover::STATUS_SUBMITTED, InventoryShiftTurnover::STATUS_DISPUTED]);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['shift_session_id'])) {
            $query->where('shift_session_id', (int) $filters['shift_session_id']);
        }
        if (! empty($filters['employee_id'])) {
            $employeeId = (int) $filters['employee_id'];
            $query->where(function ($inner) use ($employeeId) {
                $inner->where('counted_by', $employeeId)
                    ->orWhere('accepted_by', $employeeId)
                    ->orWhereHas('shiftSession', fn ($shift) => $shift->where('user_id', $employeeId));
            });
        }
        if (! empty($filters['date'])) {
            $from = HotelDateTime::startOfDay($filters['date'])->copy()->utc();
            $to = HotelDateTime::endOfDay($filters['date'])->copy()->utc();
            $query->whereHas('shiftSession', function ($shift) use ($from, $to) {
                $shift->whereBetween('started_at', [$from, $to]);
            });
        }

        return $query->paginate(20)->withQueryString()->through(fn (InventoryShiftTurnover $turnover) => $this->historyRow($turnover));
    }

    public function printPayload(InventoryShiftTurnover $turnover): array
    {
        return [
            'hotel_name' => config('app.name', 'Hotel'),
            'title' => 'HOTEL INVENTORY TURNOVER REPORT',
            'printed_at_manila' => HotelDateTime::formatUtcForDisplay(now()),
            'turnover' => $this->reportPayload($turnover),
        ];
    }

    public function recordReportExported(User $user, InventoryShiftTurnover $turnover): void
    {
        BookingService::auditLog(
            $user->id,
            'INVENTORY_TURNOVER_REPORT_EXPORTED',
            'inventory_shift_turnovers',
            $turnover->id,
            null,
            $turnover->status,
            'Inventory turnover report exported for Shift #'.$turnover->shift_session_id
        );
    }

    private function assertDeskRole(User $user): void
    {
        if ($user->role === 'housekeeping') {
            abort(403, 'Housekeeping cannot access inventory turnover records.');
        }
        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'Unauthorized inventory turnover access.');
        }
    }

    private function historyRow(InventoryShiftTurnover $turnover): array
    {
        $payload = $this->reportPayload($turnover);

        return [
            'id' => $payload['id'],
            'shift_session_id' => $payload['shift_session_id'],
            'shift_code' => $payload['shift_code'],
            'business_date_manila' => $payload['business_date_manila'],
            'outgoing_operator_name' => $payload['outgoing_operator_name'],
            'status' => $payload['status'],
            'status_label' => $payload['status_label'],
            'status_description' => $payload['status_description'],
            'submitted_at_manila' => $payload['submitted_at_manila'],
            'incoming_operator_name' => $payload['incoming_operator_name'],
            'accepted_at_manila' => $payload['accepted_at_manila'],
            'short_item_count' => $payload['short_item_count'],
            'over_item_count' => $payload['over_item_count'],
            ...$this->historyHandoverDisplay($payload['items'] ?? []),
            'handover_status' => $payload['handover_status'],
            'has_admin_override' => (bool) $payload['admin_override_reason'],
        ];
    }

    /**
     * History-list display only. Does not write handover_difference or change
     * expected closing, variance, dispute, stock, or cash.
     */
    private function historyHandoverDisplay(array $items): array
    {
        $values = collect($items)
            ->pluck('handover_difference')
            ->filter(fn ($value) => $value !== null);

        if ($values->isEmpty()) {
            return [
                'handover_short_quantity' => null,
                'handover_over_quantity' => null,
                'net_handover_difference' => null,
            ];
        }

        return [
            'handover_short_quantity' => (int) $values->sum(fn ($value) => ((int) $value) < 0 ? abs((int) $value) : 0),
            'handover_over_quantity' => (int) $values->sum(fn ($value) => ((int) $value) > 0 ? (int) $value : 0),
            'net_handover_difference' => (int) $values->sum(),
        ];
    }

    private function handoverStatusLabel(InventoryShiftTurnover $turnover, array $handoverIssues): string
    {
        if ($turnover->status === InventoryShiftTurnover::STATUS_DISPUTED) {
            return 'DISPUTED';
        }
        if ($turnover->accepted_at === null) {
            return $turnover->status === InventoryShiftTurnover::STATUS_SUBMITTED ? 'AWAITING VERIFICATION' : 'NOT STARTED';
        }

        return $handoverIssues === [] ? 'BALANCED' : 'HANDOVER DIFFERENCE';
    }

    private function gapMovementDetails(InventoryShiftTurnover $turnover): array
    {
        $start = $turnover->submitted_at ?? $turnover->freeze_started_at;
        if (! $start) {
            return [];
        }

        $end = $turnover->accepted_at ?? now();
        $itemIds = $turnover->items->pluck('inventory_item_id')->all();
        if ($itemIds === []) {
            return [];
        }

        return InventoryStockMovement::query()
            ->with('performer')
            ->whereNull('shift_session_id')
            ->where('movement_type', '!=', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)
            ->whereIn('inventory_item_id', $itemIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->orderBy('id')
            ->get()
            ->map(function (InventoryStockMovement $row) {
                $change = (int) $row->quantity_change;

                return [
                    'inventory_item_id' => (int) $row->inventory_item_id,
                    'movement_type' => $row->movement_type,
                    'quantity_change' => $change,
                    'quantity_label' => $change > 0 ? '+'.$change : (string) $change,
                    'performed_by_name' => $row->performer?->full_name,
                    'created_at_manila' => HotelDateTime::formatUtcForDisplay($row->created_at),
                    'notes' => $row->notes,
                ];
            })
            ->values()
            ->all();
    }

    private function manualSetDetails(InventoryShiftTurnover $turnover): array
    {
        if (! $turnover->shift_session_id) {
            return [];
        }

        $until = $turnover->freeze_started_at ?? now();

        return InventoryStockMovement::query()
            ->with(['performer', 'item'])
            ->where('shift_session_id', $turnover->shift_session_id)
            ->where('movement_type', InventoryStockMovement::TYPE_MANUAL_SET)
            ->where('created_at', '<=', $until)
            ->orderBy('id')
            ->get()
            ->map(fn (InventoryStockMovement $row) => [
                'inventory_item_id' => (int) $row->inventory_item_id,
                'item_name' => $row->item?->item_name,
                'quantity_change' => (int) $row->quantity_change,
                'performed_by_name' => $row->performer?->full_name,
                'created_at_manila' => HotelDateTime::formatUtcForDisplay($row->created_at),
                'notes' => $row->notes,
            ])
            ->values()
            ->all();
    }

    private function finalizeAcceptance(
        User $user,
        InventoryShiftTurnover $turnover,
        array $verifiedCounts,
        ?string $notes,
        bool $adminResolve = false
    ): InventoryShiftTurnover {
        return DB::transaction(function () use ($user, $turnover, $verifiedCounts, $notes, $adminResolve) {
            $locked = InventoryShiftTurnover::query()->whereKey($turnover->id)->lockForUpdate()->firstOrFail();
            $outgoingExpected = [];
            $outgoingActual = [];
            $gaps = $this->gapNetByItem($locked);

            foreach ($locked->items as $line) {
                $outgoingExpected[$line->inventory_item_id] = $line->expected_closing_quantity;
                $outgoingActual[$line->inventory_item_id] = $line->outgoing_actual_quantity;
                $verified = $verifiedCounts[$line->inventory_item_id];
                $this->writeHandoverFigures($line, $verified, $gaps);
                $line->save();
            }

            $locked->status = InventoryShiftTurnover::STATUS_ACCEPTED;
            $locked->accepted_at = now();
            $locked->accepted_by = $user->id;
            if ($notes) {
                $locked->notes = trim((string) $locked->notes."\n".$notes);
            }
            if ($adminResolve) {
                $locked->resolution_type = InventoryShiftTurnover::RESOLUTION_ACCEPT_INCOMING;
                $locked->resolution_notes = $notes;
                $locked->resolved_at = now();
                $locked->resolved_by = $user->id;
            }
            $locked->save();

            foreach ($locked->items as $line) {
                $line->refresh();
                if ((int) $line->expected_closing_quantity !== (int) $outgoingExpected[$line->inventory_item_id]
                    || (int) $line->outgoing_actual_quantity !== (int) $outgoingActual[$line->inventory_item_id]) {
                    throw new InventoryTurnoverException('Outgoing snapshot cannot be rewritten during handover acceptance.');
                }
            }

            $alignIds = $locked->items->pluck('inventory_item_id')->sort()->values()->all();
            $alignItems = InventoryItem::query()
                ->whereIn('id', $alignIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($locked->items as $line) {
                $item = $alignItems->get($line->inventory_item_id);
                if (! $item) {
                    continue;
                }
                $before = (int) $item->current_stock;
                $after = (int) $line->incoming_verified_quantity;
                if ($before === $after) {
                    continue;
                }
                $item->current_stock = $after;
                $item->save();
                InventoryStockMovement::record([
                    'inventory_item_id' => $item->id,
                    'movement_type' => InventoryStockMovement::TYPE_INVENTORY_VARIANCE,
                    'quantity_change' => $after - $before,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'source_type' => $adminResolve
                        ? InventoryShiftTurnover::SOURCE_RESOLUTION
                        : InventoryShiftTurnover::SOURCE_TYPE,
                    'source_id' => $locked->id,
                    'performed_by' => $user->id,
                    'shift_session_id' => null,
                    'notes' => $adminResolve
                        ? 'Admin handover resolution. Live stock aligned to confirmed incoming physical count '.$after.' from '.$before.'. Outgoing snapshot unchanged. Reason: '.$notes
                        : 'Incoming handover physical alignment. Outgoing snapshot unchanged.',
                ]);
            }

            $active = ShiftService::activeRegister();
            if ($active && (int) $active->id !== (int) $locked->shift_session_id) {
                $next = $this->ensureForShift($active);
                if ($next && $next->status === InventoryShiftTurnover::STATUS_OPEN) {
                    foreach ($next->items as $line) {
                        $source = $locked->items->firstWhere('inventory_item_id', $line->inventory_item_id);
                        $line->opening_quantity = $source ? (int) $source->incoming_verified_quantity : 0;
                        $line->save();
                    }
                    $next->opening_established_at = now();
                    $next->is_bootstrap = false;
                    $next->save();
                }
            }

            BookingService::auditLog(
                $user->id,
                $adminResolve ? 'INVENTORY_HANDOVER_DISPUTE_RESOLVED' : 'INVENTORY_HANDOVER_ACCEPTED',
                'inventory_shift_turnovers',
                $locked->id,
                $adminResolve ? InventoryShiftTurnover::STATUS_DISPUTED : InventoryShiftTurnover::STATUS_SUBMITTED,
                InventoryShiftTurnover::STATUS_ACCEPTED,
                $adminResolve
                    ? 'Admin accepted incoming physical quantities for Shift #'.$locked->shift_session_id.'. '.$notes
                    : 'Incoming physical inventory accepted for Shift #'.$locked->shift_session_id
            );

            return $locked->fresh(['items']);
        });
    }

    /**
     * @return array<int, array{restock: int, returns: int, sold: int, complimentary: int, other_out: int, manual_set: int}>
     */
    private function movementBuckets(int $shiftSessionId, $freezeAt): array
    {
        $rows = InventoryStockMovement::query()
            ->where('shift_session_id', $shiftSessionId)
            ->where('movement_type', '!=', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)
            ->where('created_at', '<=', $freezeAt)
            ->orderBy('id')
            ->get();

        $buckets = [];
        foreach ($rows as $movement) {
            $itemId = (int) $movement->inventory_item_id;
            $buckets[$itemId] ??= $this->emptyBucket();
            $change = (int) $movement->quantity_change;
            $abs = abs($change);

            match ($movement->movement_type) {
                InventoryStockMovement::TYPE_POS_SALE,
                InventoryStockMovement::TYPE_BOOKING_USAGE => $buckets[$itemId]['sold'] += $abs,
                InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY => $buckets[$itemId]['complimentary'] += $abs,
                InventoryStockMovement::TYPE_RESTOCK,
                InventoryStockMovement::TYPE_MANUAL_ADD => $buckets[$itemId]['restock'] += max(0, $change),
                InventoryStockMovement::TYPE_BOOKING_REVERSAL => $buckets[$itemId]['returns'] += max(0, $change),
                InventoryStockMovement::TYPE_MANUAL_SUBTRACT,
                InventoryStockMovement::TYPE_DAMAGED,
                InventoryStockMovement::TYPE_EXPIRED,
                InventoryStockMovement::TYPE_INTERNAL_USE,
                InventoryStockMovement::TYPE_OTHER_AUTHORIZED_OUT => $buckets[$itemId]['other_out'] += $abs,
                InventoryStockMovement::TYPE_MANUAL_SET => $buckets[$itemId]['manual_set'] += $change,
                default => null,
            };
        }

        return $buckets;
    }

    private function emptyBucket(): array
    {
        return [
            'restock' => 0,
            'returns' => 0,
            'sold' => 0,
            'complimentary' => 0,
            'other_out' => 0,
            'manual_set' => 0,
        ];
    }

    private function expectedClosing(int $opening, array $bucket): int
    {
        return $opening
            + $bucket['restock']
            + $bucket['returns']
            - $bucket['sold']
            - $bucket['complimentary']
            - $bucket['other_out']
            + $bucket['manual_set'];
    }

    /**
     * @return array<int, int>
     */
    private function requireAllTrackedCounts(array $counts): array
    {
        $tracked = $this->trackedItems();
        if ($tracked->isEmpty()) {
            throw new InventoryTurnoverException('No products are configured for inventory turnover.');
        }

        $byId = [];
        foreach ($counts as $row) {
            $itemId = (int) ($row['inventory_item_id'] ?? 0);
            if ($itemId < 1 || ! array_key_exists('quantity', $row) || $row['quantity'] === '' || $row['quantity'] === null) {
                continue;
            }
            if (! is_numeric($row['quantity']) || (int) $row['quantity'] != $row['quantity'] || (int) $row['quantity'] < 0) {
                throw ValidationException::withMessages([
                    'counts' => 'Physical counts must be whole numbers 0 or greater. Blank values are not treated as zero.',
                ]);
            }
            $byId[$itemId] = (int) $row['quantity'];
        }

        $missing = [];
        foreach ($tracked as $item) {
            if (! array_key_exists($item->id, $byId)) {
                $missing[] = $item->item_name;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'counts' => 'Enter a physical count for every tracked product. Missing: '.implode(', ', $missing),
            ]);
        }

        return $byId;
    }

    private function syncTrackedLines(InventoryShiftTurnover $turnover): void
    {
        if ($turnover->isFrozenSnapshot()) {
            return;
        }

        $existing = $turnover->items()->pluck('inventory_item_id')->all();
        foreach ($this->trackedItems() as $item) {
            if (in_array($item->id, $existing, true)) {
                continue;
            }
            InventoryShiftCountItem::create([
                'inventory_shift_turnover_id' => $turnover->id,
                'inventory_item_id' => $item->id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'selling_price' => $item->selling_price,
                'opening_quantity' => $turnover->openingEstablished() && ! $turnover->is_bootstrap ? 0 : null,
            ]);
        }
    }

    private function turnoverForActiveRegister(): ?InventoryShiftTurnover
    {
        $register = ShiftService::activeRegister();
        if (! $register) {
            return null;
        }

        return InventoryShiftTurnover::query()->where('shift_session_id', $register->id)->first();
    }

    private function assertCanOperate(User $user, InventoryShiftTurnover $turnover): void
    {
        if ($user->role === 'housekeeping') {
            abort(403, 'Housekeeping cannot perform inventory turnover actions.');
        }

        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'Unauthorized inventory turnover action.');
        }

        $shift = $turnover->shiftSession;
        if ($user->role === 'front_desk' && $shift && (int) $shift->user_id !== (int) $user->id) {
            abort(403, 'Front Desk can only count the assigned register shift.');
        }
    }

    private function assertCanVerify(User $user, InventoryShiftTurnover $turnover): void
    {
        if ($user->role === 'housekeeping') {
            abort(403, 'Housekeeping cannot perform inventory turnover actions.');
        }

        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'Unauthorized inventory turnover action.');
        }

        $register = ShiftService::activeRegister();
        if ($user->role === 'front_desk') {
            if (! $register) {
                abort(403, self::HANDOVER_MESSAGE);
            }
            if ((int) $register->id === (int) $turnover->shift_session_id) {
                throw new InventoryTurnoverException('The outgoing operator must hand over to the next register before accepting these counts as a new opening.');
            }
        }
    }

    /**
     * Authorized between-shift stock changes after outgoing freeze and before
     * incoming acceptance. Null-shift movements only; inventory_variance is
     * excluded so outgoing reconciliation and incoming alignment never enter
     * handover expected.
     *
     * @return array<int, int>
     */
    private function gapNetByItem(InventoryShiftTurnover $turnover, $until = null): array
    {
        $start = $turnover->submitted_at ?? $turnover->freeze_started_at;
        if (! $start) {
            return [];
        }

        $end = $until ?? $turnover->accepted_at ?? now();
        $itemIds = $turnover->items()->pluck('inventory_item_id')->all();
        if ($itemIds === []) {
            return [];
        }

        $rows = InventoryStockMovement::query()
            ->whereNull('shift_session_id')
            ->where('movement_type', '!=', InventoryStockMovement::TYPE_INVENTORY_VARIANCE)
            ->whereIn('inventory_item_id', $itemIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->get(['inventory_item_id', 'quantity_change']);

        $nets = [];
        foreach ($rows as $row) {
            $itemId = (int) $row->inventory_item_id;
            $nets[$itemId] = ($nets[$itemId] ?? 0) + (int) $row->quantity_change;
        }

        return $nets;
    }

    private function handoverExpectedQuantity(InventoryShiftCountItem $line, array $gaps): int
    {
        $gap = (int) ($gaps[$line->inventory_item_id] ?? 0);

        return (int) $line->outgoing_actual_quantity + $gap;
    }

    private function writeHandoverFigures(InventoryShiftCountItem $line, int $verified, array $gaps): void
    {
        $gap = (int) ($gaps[$line->inventory_item_id] ?? 0);
        $expected = (int) $line->outgoing_actual_quantity + $gap;
        $line->gap_net_quantity = $gap;
        $line->handover_expected_quantity = $expected;
        $line->incoming_verified_quantity = $verified;
        $line->handover_difference = $verified - $expected;
    }

    private function linePayload(InventoryShiftCountItem $line, ?InventoryShiftTurnover $turnover = null, ?array $liveGaps = null): array
    {
        $turnover ??= $line->turnover;
        $variance = $line->variance_quantity;
        $shortQty = $variance !== null && $variance < 0 ? abs((int) $variance) : 0;

        $gap = (int) $line->gap_net_quantity;
        $handoverExpected = $line->handover_expected_quantity;
        $handover = $line->handover_difference;

        if ($turnover && $turnover->accepted_at === null && $line->outgoing_actual_quantity !== null) {
            $gaps = $liveGaps ?? $this->gapNetByItem($turnover);
            $gap = (int) ($gaps[$line->inventory_item_id] ?? 0);
            $handoverExpected = (int) $line->outgoing_actual_quantity + $gap;
            if ($line->incoming_verified_quantity !== null) {
                $handover = (int) $line->incoming_verified_quantity - $handoverExpected;
            }
        }

        $handoverShortQty = $handover !== null && $handover < 0 ? abs((int) $handover) : 0;

        return [
            'inventory_item_id' => $line->inventory_item_id,
            'item_name' => $line->item_name,
            'unit' => $line->unit,
            'selling_price' => (float) $line->selling_price,
            'opening_quantity' => $line->opening_quantity,
            'restock_quantity' => (int) $line->restock_quantity,
            'return_quantity' => (int) $line->return_quantity,
            'sold_quantity' => (int) $line->sold_quantity,
            'complimentary_quantity' => (int) $line->complimentary_quantity,
            'other_out_quantity' => (int) $line->other_out_quantity,
            'manual_set_quantity' => (int) $line->manual_set_quantity,
            'expected_closing_quantity' => $line->expected_closing_quantity,
            'outgoing_actual_quantity' => $line->outgoing_actual_quantity,
            'variance_quantity' => $variance,
            'variance_label' => $variance === null ? null : $this->varianceLabel((int) $variance),
            'gap_net_quantity' => $gap,
            'gap_net_label' => $gap > 0 ? '+'.$gap : (string) $gap,
            'handover_expected_quantity' => $handoverExpected,
            'incoming_verified_quantity' => $line->incoming_verified_quantity,
            'handover_difference' => $handover,
            'handover_difference_label' => $handover === null ? null : $this->varianceLabel((int) $handover),
            'reference_retail_value' => round($shortQty * (float) $line->selling_price, 2),
            'handover_reference_retail_value' => round($handoverShortQty * (float) $line->selling_price, 2),
        ];
    }
}
