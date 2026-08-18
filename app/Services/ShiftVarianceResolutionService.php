<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ShiftSession;
use App\Models\ShiftVarianceResolution;
use App\Models\User;
use App\Support\HotelDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftVarianceResolutionService
{
    public const STATUS_BALANCED = ShiftCashReconciliationService::STATUS_BALANCED;

    public const STATUS_PENDING_REVIEW = ShiftCashReconciliationService::STATUS_PENDING_REVIEW;

    public const STATUS_PARTIALLY_RESOLVED = 'PARTIALLY_RESOLVED';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const LEGACY_MESSAGE = 'Legacy shift — no immutable close-time variance snapshot available.';

    public function __construct(
        private readonly ShiftCashReconciliationService $reconciliation
    ) {}

    public function hasSnapshot(ShiftSession $shift): bool
    {
        return $shift->ended_at !== null && $shift->expected_formula_version !== null;
    }

    public function originalVariance(ShiftSession $shift, string $drawer): float
    {
        return $drawer === ShiftVarianceResolution::DRAWER_MINIBAR
            ? round((float) $shift->variance_minibar, 2)
            : round((float) $shift->variance_rooms, 2);
    }

    public function originalExpected(ShiftSession $shift, string $drawer): float
    {
        return $drawer === ShiftVarianceResolution::DRAWER_MINIBAR
            ? round((float) $shift->expected_cash_minibar, 2)
            : round((float) $shift->expected_cash_rooms, 2);
    }

    public function originalActual(ShiftSession $shift, string $drawer): float
    {
        return $drawer === ShiftVarianceResolution::DRAWER_MINIBAR
            ? round((float) $shift->closing_cash_minibar, 2)
            : round((float) $shift->closing_cash, 2);
    }

    public function varianceTypeFromOriginal(float $originalVariance): ?string
    {
        if (abs($originalVariance) < ShiftCashReconciliationService::TOLERANCE) {
            return null;
        }

        return $originalVariance < 0
            ? ShiftVarianceResolution::VARIANCE_SHORTAGE
            : ShiftVarianceResolution::VARIANCE_OVERAGE;
    }

    public function approvedResolvedAmount(int $shiftId, string $drawer, string $varianceType): float
    {
        return round((float) ShiftVarianceResolution::query()
            ->where('shift_session_id', $shiftId)
            ->where('drawer', $drawer)
            ->where('variance_type', $varianceType)
            ->where('status', ShiftVarianceResolution::STATUS_APPROVED)
            ->sum('amount'), 2);
    }

    public function remainingMagnitude(ShiftSession $shift, string $drawer): float
    {
        $original = $this->originalVariance($shift, $drawer);
        $type = $this->varianceTypeFromOriginal($original);
        if ($type === null) {
            return 0.00;
        }

        $approved = $this->approvedResolvedAmount((int) $shift->id, $drawer, $type);

        return max(round(abs($original) - $approved, 2), 0.00);
    }

    public function computeVarianceStatus(ShiftSession $shift): string
    {
        if (! $this->hasSnapshot($shift)) {
            return (string) ($shift->variance_status ?: '');
        }

        $roomsVariance = $this->originalVariance($shift, ShiftVarianceResolution::DRAWER_ROOM);
        $minibarVariance = $this->originalVariance($shift, ShiftVarianceResolution::DRAWER_MINIBAR);

        if (
            abs($roomsVariance) < ShiftCashReconciliationService::TOLERANCE
            && abs($minibarVariance) < ShiftCashReconciliationService::TOLERANCE
        ) {
            return self::STATUS_BALANCED;
        }

        $roomsRemaining = $this->remainingMagnitude($shift, ShiftVarianceResolution::DRAWER_ROOM);
        $minibarRemaining = $this->remainingMagnitude($shift, ShiftVarianceResolution::DRAWER_MINIBAR);

        if (
            $roomsRemaining < ShiftCashReconciliationService::TOLERANCE
            && $minibarRemaining < ShiftCashReconciliationService::TOLERANCE
        ) {
            return self::STATUS_RESOLVED;
        }

        $hasApproved = ShiftVarianceResolution::query()
            ->where('shift_session_id', $shift->id)
            ->where('status', ShiftVarianceResolution::STATUS_APPROVED)
            ->exists();

        return $hasApproved ? self::STATUS_PARTIALLY_RESOLVED : self::STATUS_PENDING_REVIEW;
    }

    public function refreshVarianceStatus(ShiftSession $shift): string
    {
        $status = $this->computeVarianceStatus($shift);
        if ($this->hasSnapshot($shift) && $shift->variance_status !== $status) {
            $shift->variance_status = $status;
            $shift->save();
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function drawerReview(ShiftSession $shift, string $drawer): array
    {
        $original = $this->originalVariance($shift, $drawer);
        $type = $this->varianceTypeFromOriginal($original);
        $resolved = $type
            ? $this->approvedResolvedAmount((int) $shift->id, $drawer, $type)
            : 0.00;
        $remaining = $this->remainingMagnitude($shift, $drawer);
        $label = $this->reconciliation->varianceLabel($original);

        $drawerStatus = self::STATUS_BALANCED;
        if ($type !== null) {
            if ($remaining < ShiftCashReconciliationService::TOLERANCE) {
                $drawerStatus = self::STATUS_RESOLVED;
            } elseif ($resolved >= ShiftCashReconciliationService::TOLERANCE) {
                $drawerStatus = self::STATUS_PARTIALLY_RESOLVED;
            } else {
                $drawerStatus = self::STATUS_PENDING_REVIEW;
            }
        }

        return [
            'drawer' => $drawer,
            'label' => $drawer === ShiftVarianceResolution::DRAWER_MINIBAR ? 'Minibar' : 'Rooms',
            'original_expected' => $this->originalExpected($shift, $drawer),
            'original_actual' => $this->originalActual($shift, $drawer),
            'original_variance' => $original,
            'original_label' => $label,
            'variance_type' => $type,
            'resolved_amount' => $resolved,
            'remaining' => $remaining,
            'remaining_label' => $type === null || $remaining < ShiftCashReconciliationService::TOLERANCE
                ? ($type === null ? self::STATUS_BALANCED : ($type === ShiftVarianceResolution::VARIANCE_SHORTAGE ? 'SHORT' : 'OVER'))
                : ($type === ShiftVarianceResolution::VARIANCE_SHORTAGE ? 'SHORT' : 'OVER'),
            'status' => $drawerStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewPayload(ShiftSession $shift, ?User $viewer = null): array
    {
        $isLegacy = $shift->ended_at !== null && $shift->expected_formula_version === null;
        $hasSnapshot = $this->hasSnapshot($shift);
        $rooms = $this->drawerReview($shift, ShiftVarianceResolution::DRAWER_ROOM);
        $minibar = $this->drawerReview($shift, ShiftVarianceResolution::DRAWER_MINIBAR);
        $overall = $hasSnapshot
            ? $this->computeVarianceStatus($shift)
            : (string) ($shift->variance_status ?: '');

        $isAdmin = $viewer && $viewer->role === UserRole::Admin->value;
        $isOwner = $viewer && (int) $shift->user_id === (int) $viewer->id;
        $unresolved = $hasSnapshot
            && ($rooms['remaining'] >= ShiftCashReconciliationService::TOLERANCE
                || $minibar['remaining'] >= ShiftCashReconciliationService::TOLERANCE);

        $resolutions = $shift->relationLoaded('varianceResolutions')
            ? $shift->varianceResolutions
            : $shift->varianceResolutions()
                ->with(['submitter:id,full_name', 'reviewer:id,full_name', 'cashReceivedIntoShift.user:id,full_name'])
                ->orderBy('id')
                ->get();

        return [
            'is_legacy' => $isLegacy,
            'has_snapshot' => $hasSnapshot,
            'legacy_message' => $isLegacy ? self::LEGACY_MESSAGE : null,
            'can_resolve' => $hasSnapshot && ! $isLegacy,
            'can_submit' => $hasSnapshot
                && $unresolved
                && $isOwner
                && $viewer
                && $viewer->role === UserRole::FrontDesk->value,
            'can_review' => (bool) $isAdmin && $hasSnapshot,
            'overall_status' => $overall,
            'rooms' => $rooms,
            'minibar' => $minibar,
            'active_register' => $this->serializeActiveRegister(),
            'active_register_shift_id' => ShiftService::activeRegister()?->id,
            'resolutions' => $resolutions->map(fn (ShiftVarianceResolution $row) => $this->serializeResolution($row))->values()->all(),
            'shortage_types' => ShiftVarianceResolution::SHORTAGE_TYPES,
            'overage_types' => ShiftVarianceResolution::OVERAGE_TYPES,
            'admin_only_types' => ShiftVarianceResolution::ADMIN_ONLY_TYPES,
            'types_without_physical_cash' => ShiftVarianceResolution::TYPES_WITHOUT_PHYSICAL_CASH,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeResolution(ShiftVarianceResolution $row): array
    {
        return [
            'id' => (int) $row->id,
            'shift_session_id' => (int) $row->shift_session_id,
            'drawer' => $row->drawer,
            'variance_type' => $row->variance_type,
            'resolution_type' => $row->resolution_type,
            'amount' => round((float) $row->amount, 2),
            'notes' => $row->notes,
            'submitted_by' => (int) $row->submitted_by,
            'submitted_by_name' => $row->submitter?->full_name,
            'status' => $row->status,
            'reviewed_by' => $row->reviewed_by ? (int) $row->reviewed_by : null,
            'reviewed_by_name' => $row->reviewer?->full_name,
            'reviewed_at' => HotelDateTime::utcIso($row->reviewed_at),
            'reviewed_at_display' => HotelDateTime::formatUtcForDisplay($row->reviewed_at),
            'review_notes' => $row->review_notes,
            'cash_received_into_shift_id' => $row->cash_received_into_shift_id
                ? (int) $row->cash_received_into_shift_id
                : null,
            'cash_received_into_shift_label' => $this->receivingShiftLabel($row),
            'created_at' => HotelDateTime::utcIso($row->created_at),
            'created_at_display' => HotelDateTime::formatUtcForDisplay($row->created_at),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeActiveRegister(): ?array
    {
        $active = ShiftService::activeRegister();
        if (! $active || $active->ended_at !== null) {
            return null;
        }

        $active->loadMissing('user:id,full_name,role');

        return [
            'id' => (int) $active->id,
            'shift_code' => $active->shift_code,
            'user_id' => (int) $active->user_id,
            'user_name' => $active->user?->full_name,
            'user_role' => $active->user?->role,
        ];
    }

    private function receivingShiftLabel(ShiftVarianceResolution $row): ?string
    {
        if (! $row->cash_received_into_shift_id) {
            return null;
        }

        $shift = $row->relationLoaded('cashReceivedIntoShift')
            ? $row->cashReceivedIntoShift
            : $row->cashReceivedIntoShift()->with('user:id,full_name')->first();

        if (! $shift) {
            return 'Shift #'.$row->cash_received_into_shift_id;
        }

        $name = $shift->user?->full_name ?: 'Front Desk';

        return $name.' • '.ucfirst((string) $shift->shift_code).' shift #'.$shift->id;
    }

    /**
     * @param  array{drawer: string, resolution_type: string, amount: float|int|string, notes?: ?string}  $input
     */
    public function submit(ShiftSession $shift, User $actor, array $input): ShiftVarianceResolution
    {
        $this->assertCanSubmit($shift, $actor);
        $prepared = $this->prepareEntry($shift, $actor, $input, immediateApprove: false);

        $resolution = ShiftVarianceResolution::create($prepared);

        $this->audit(
            $actor->id,
            'SHIFT_VARIANCE_SUBMITTED',
            $shift,
            $resolution,
            $this->drawerReview($shift, $resolution->drawer),
            $this->drawerReview($shift, $resolution->drawer),
            $resolution->notes
        );

        return $resolution;
    }

    /**
     * Admin records a resolution that is approved in the same transaction.
     *
     * @param  array{drawer: string, resolution_type: string, amount: float|int|string, notes?: ?string, review_notes?: ?string, receive_into_active_drawer?: bool}  $input
     */
    public function recordApproved(ShiftSession $shift, User $admin, array $input): ShiftVarianceResolution
    {
        $this->assertAdmin($admin);
        $this->assertResolvable($shift);

        return DB::transaction(function () use ($shift, $admin, $input) {
            $locked = ShiftSession::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $prepared = $this->prepareEntry($locked, $admin, $input, immediateApprove: true);
            $before = $this->drawerReview($locked, $prepared['drawer']);
            $this->assertApprovalFitsRemaining($before['remaining'], (float) $prepared['amount']);

            $receivingShift = $this->resolveReceivingShift($locked, $prepared['resolution_type'], $prepared['drawer'], $input);
            $prepared['cash_received_into_shift_id'] = $receivingShift?->id;
            $prepared['status'] = ShiftVarianceResolution::STATUS_APPROVED;
            $prepared['reviewed_by'] = $admin->id;
            $prepared['reviewed_at'] = now();
            $prepared['review_notes'] = $input['review_notes'] ?? $input['notes'] ?? null;

            $resolution = ShiftVarianceResolution::create($prepared);
            $this->refreshVarianceStatus($locked);
            $after = $this->drawerReview($locked->fresh(), $resolution->drawer);

            $this->audit(
                $admin->id,
                'SHIFT_VARIANCE_APPROVED',
                $locked,
                $resolution,
                $before,
                $after,
                $resolution->review_notes
            );

            if ($receivingShift) {
                $this->auditRecoveryReceived($admin, $locked, $resolution, $receivingShift, $before, $after);
            }

            return $resolution->fresh(['submitter', 'reviewer', 'cashReceivedIntoShift']);
        });
    }

    public function approve(
        ShiftVarianceResolution $resolution,
        User $admin,
        ?string $reviewNotes = null,
        bool $receiveIntoActiveDrawer = false,
        ?string $recoveryDestination = null
    ): ShiftVarianceResolution {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($resolution, $admin, $reviewNotes, $receiveIntoActiveDrawer, $recoveryDestination) {
            $lockedShift = ShiftSession::query()
                ->whereKey($resolution->shift_session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedResolution = ShiftVarianceResolution::query()
                ->whereKey($resolution->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertResolvable($lockedShift);

            if ($lockedResolution->status !== ShiftVarianceResolution::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted resolutions can be approved.',
                ]);
            }

            $before = $this->drawerReview($lockedShift, $lockedResolution->drawer);
            $this->assertApprovalFitsRemaining($before['remaining'], (float) $lockedResolution->amount);

            $receivingShift = $this->resolveReceivingShift(
                $lockedShift,
                $lockedResolution->resolution_type,
                $lockedResolution->drawer,
                [
                    'receive_into_active_drawer' => $receiveIntoActiveDrawer,
                    'recovery_destination' => $recoveryDestination,
                    'cash_received_into_shift_id' => null,
                ]
            );

            $lockedResolution->status = ShiftVarianceResolution::STATUS_APPROVED;
            $lockedResolution->reviewed_by = $admin->id;
            $lockedResolution->reviewed_at = now();
            $lockedResolution->review_notes = $reviewNotes;
            $lockedResolution->cash_received_into_shift_id = $receivingShift?->id;
            $lockedResolution->save();

            $this->refreshVarianceStatus($lockedShift);
            $after = $this->drawerReview($lockedShift->fresh(), $lockedResolution->drawer);

            $this->audit(
                $admin->id,
                'SHIFT_VARIANCE_APPROVED',
                $lockedShift,
                $lockedResolution,
                $before,
                $after,
                $reviewNotes
            );

            if ($receivingShift) {
                $this->auditRecoveryReceived($admin, $lockedShift, $lockedResolution, $receivingShift, $before, $after);
            }

            return $lockedResolution->fresh(['submitter', 'reviewer', 'cashReceivedIntoShift']);
        });
    }

    public function reject(ShiftVarianceResolution $resolution, User $admin, string $reviewNotes): ShiftVarianceResolution
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($resolution, $admin, $reviewNotes) {
            $lockedShift = ShiftSession::query()
                ->whereKey($resolution->shift_session_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedResolution = ShiftVarianceResolution::query()
                ->whereKey($resolution->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedResolution->status !== ShiftVarianceResolution::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted resolutions can be rejected.',
                ]);
            }

            $before = $this->drawerReview($lockedShift, $lockedResolution->drawer);

            $lockedResolution->status = ShiftVarianceResolution::STATUS_REJECTED;
            $lockedResolution->reviewed_by = $admin->id;
            $lockedResolution->reviewed_at = now();
            $lockedResolution->review_notes = $reviewNotes;
            $lockedResolution->save();

            $this->refreshVarianceStatus($lockedShift);
            $after = $this->drawerReview($lockedShift->fresh(), $lockedResolution->drawer);

            $this->audit(
                $admin->id,
                'SHIFT_VARIANCE_REJECTED',
                $lockedShift,
                $lockedResolution,
                $before,
                $after,
                $reviewNotes
            );

            return $lockedResolution->fresh(['submitter', 'reviewer']);
        });
    }

    /**
     * Unresolved snapshotted variances visible to the viewer (own shifts, or all for Admin on Index).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingSummariesForUser(User $user, int $limit = 8): array
    {
        $query = ShiftSession::with(['user:id,full_name', 'varianceResolutions.submitter', 'varianceResolutions.reviewer'])
            ->whereNotNull('ended_at')
            ->whereNotNull('expected_formula_version')
            ->whereIn('variance_status', [
                self::STATUS_PENDING_REVIEW,
                self::STATUS_PARTIALLY_RESOLVED,
            ])
            ->orderByDesc('ended_at');

        if ($user->role !== UserRole::Admin->value) {
            $query->where('user_id', $user->id);
        } else {
            $query->where('user_id', $user->id);
        }

        return $query->limit($limit)->get()->map(function (ShiftSession $shift) use ($user) {
            $payload = $this->reviewPayload($shift, $user);

            return [
                'shift_id' => (int) $shift->id,
                'shift_code' => $shift->shift_code,
                'closed_at' => HotelDateTime::utcIso($shift->ended_at),
                'closed_at_display' => HotelDateTime::formatUtcForDisplay($shift->ended_at),
                'overall_status' => $payload['overall_status'],
                'rooms' => $payload['rooms'],
                'minibar' => $payload['minibar'],
            ];
        })->all();
    }

    /**
     * Persistent Front Desk awareness banner. Own unresolved closed shifts only.
     *
     * @return array<string, mixed>|null
     */
    public function bannerForUser(?User $user): ?array
    {
        if (! $user || $user->role !== UserRole::FrontDesk->value) {
            return null;
        }

        $shifts = ShiftSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->whereNotNull('expected_formula_version')
            ->whereIn('variance_status', [
                self::STATUS_PENDING_REVIEW,
                self::STATUS_PARTIALLY_RESOLVED,
            ])
            ->orderByDesc('ended_at')
            ->limit(50)
            ->get();

        if ($shifts->isEmpty()) {
            return null;
        }

        $items = [];
        $totalShortage = 0.00;
        $totalOverage = 0.00;

        foreach ($shifts as $shift) {
            $drawers = [];
            foreach ([
                ShiftVarianceResolution::DRAWER_ROOM,
                ShiftVarianceResolution::DRAWER_MINIBAR,
            ] as $drawer) {
                $review = $this->drawerReview($shift, $drawer);
                if (($review['remaining'] ?? 0) < ShiftCashReconciliationService::TOLERANCE) {
                    continue;
                }

                if (($review['variance_type'] ?? null) === ShiftVarianceResolution::VARIANCE_OVERAGE) {
                    $totalOverage += (float) $review['remaining'];
                } else {
                    $totalShortage += (float) $review['remaining'];
                }

                $drawers[] = [
                    'drawer' => $review['drawer'],
                    'label' => $review['label'],
                    'kind' => ($review['variance_type'] ?? '') === ShiftVarianceResolution::VARIANCE_OVERAGE
                        ? 'Overage'
                        : 'Shortage',
                    'original_label' => $review['original_label'],
                    'original_variance' => $review['original_variance'],
                    'resolved_amount' => $review['resolved_amount'],
                    'remaining' => $review['remaining'],
                    'status' => $review['status'],
                ];
            }

            if ($drawers === []) {
                continue;
            }

            $awaitingReview = ShiftVarianceResolution::query()
                ->where('shift_session_id', $shift->id)
                ->where('status', ShiftVarianceResolution::STATUS_SUBMITTED)
                ->exists();

            $items[] = [
                'shift_id' => (int) $shift->id,
                'shift_code' => $shift->shift_code,
                'closed_at' => HotelDateTime::utcIso($shift->ended_at),
                'closed_at_display' => HotelDateTime::formatUtcForDisplay($shift->ended_at),
                'overall_status' => (string) $shift->variance_status,
                'awaiting_admin_review' => $awaitingReview,
                'drawers' => $drawers,
            ];
        }

        if ($items === []) {
            return null;
        }

        $count = count($items);
        $first = $items[0];

        return [
            'count' => $count,
            'total_remaining_shortage' => round($totalShortage, 2),
            'total_remaining_overage' => round($totalOverage, 2),
            'view_url' => $count === 1
                ? route('shifts.report', $first['shift_id'], false).'?tab=variance'
                : route('shifts.index', [], false),
            'view_label' => $count === 1 ? 'View Variance' : 'View My Variances',
            'shift' => $count === 1 ? $first : null,
        ];
    }

    public function assertCanView(ShiftSession $shift, User $user): void
    {
        if ($user->role === UserRole::Admin->value) {
            return;
        }

        if ($user->role === UserRole::FrontDesk->value && (int) $shift->user_id === (int) $user->id) {
            return;
        }

        abort(403, 'Unauthorized access.');
    }

    private function assertCanSubmit(ShiftSession $shift, User $actor): void
    {
        $this->assertResolvable($shift);

        if ($actor->role === UserRole::Admin->value) {
            return;
        }

        if ($actor->role === UserRole::FrontDesk->value && (int) $shift->user_id === (int) $actor->id) {
            return;
        }

        abort(403, 'Unauthorized access.');
    }

    private function assertAdmin(User $user): void
    {
        if ($user->role !== UserRole::Admin->value) {
            abort(403, 'Unauthorized access.');
        }
    }

    private function assertResolvable(ShiftSession $shift): void
    {
        if ($shift->ended_at === null) {
            throw ValidationException::withMessages([
                'shift' => 'Variance resolution is only available for closed shifts.',
            ]);
        }

        if (! $this->hasSnapshot($shift)) {
            throw ValidationException::withMessages([
                'shift' => self::LEGACY_MESSAGE,
            ]);
        }
    }

    /**
     * @param  array{drawer: string, resolution_type: string, amount: float|int|string, notes?: ?string}  $input
     * @return array<string, mixed>
     */
    private function prepareEntry(ShiftSession $shift, User $actor, array $input, bool $immediateApprove): array
    {
        $drawer = $input['drawer'] === ShiftVarianceResolution::DRAWER_MINIBAR
            ? ShiftVarianceResolution::DRAWER_MINIBAR
            : ShiftVarianceResolution::DRAWER_ROOM;
        $original = $this->originalVariance($shift, $drawer);
        $varianceType = $this->varianceTypeFromOriginal($original);

        if ($varianceType === null) {
            throw ValidationException::withMessages([
                'drawer' => 'This drawer has no original close-time variance to resolve.',
            ]);
        }

        $remaining = $this->remainingMagnitude($shift, $drawer);
        if ($remaining < ShiftCashReconciliationService::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'This drawer variance is already fully resolved.',
            ]);
        }

        $resolutionType = (string) $input['resolution_type'];
        $allowed = ShiftVarianceResolution::typesFor($varianceType);
        if (! in_array($resolutionType, $allowed, true)) {
            throw ValidationException::withMessages([
                'resolution_type' => 'This resolution type does not match the original variance.',
            ]);
        }

        $isAdmin = $actor->role === UserRole::Admin->value;
        if (! $isAdmin && in_array($resolutionType, ShiftVarianceResolution::ADMIN_ONLY_TYPES, true)) {
            throw ValidationException::withMessages([
                'resolution_type' => 'Only Admin can record this resolution type.',
            ]);
        }

        $amount = round((float) $input['amount'], 2);
        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be a positive value.',
            ]);
        }

        $notes = $this->justifiedNotes($resolutionType, $input);

        if ($immediateApprove) {
            $this->assertApprovalFitsRemaining($remaining, $amount);
        }

        return [
            'shift_session_id' => $shift->id,
            'drawer' => $drawer,
            'variance_type' => $varianceType,
            'resolution_type' => $resolutionType,
            'amount' => $amount,
            'notes' => $notes,
            'submitted_by' => $actor->id,
            'status' => ShiftVarianceResolution::STATUS_SUBMITTED,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'cash_received_into_shift_id' => null,
        ];
    }

    private function assertApprovalFitsRemaining(float $remaining, float $amount): void
    {
        if (round($amount, 2) - round($remaining, 2) > ShiftCashReconciliationService::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'Approved amount cannot exceed the remaining unresolved variance of '
                    .number_format($remaining, 2).'.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function justifiedNotes(string $resolutionType, array $input): ?string
    {
        $notes = trim((string) ($input['notes'] ?? ''));
        $reference = trim((string) ($input['transaction_reference'] ?? ''));

        if ($reference !== '') {
            $notes = $notes === ''
                ? 'Transaction reference: '.$reference
                : $notes.' (Transaction reference: '.$reference.')';
        }

        $needsJustification = in_array($resolutionType, ShiftVarianceResolution::TYPES_REQUIRING_JUSTIFICATION, true);
        $hasConcreteReference = $resolutionType === ShiftVarianceResolution::TYPE_TRANSACTION_CORRECTION
            && $reference !== '';

        if ($needsJustification && ! $hasConcreteReference && mb_strlen($notes) < 3) {
            throw ValidationException::withMessages([
                'notes' => $resolutionType === ShiftVarianceResolution::TYPE_TRANSACTION_CORRECTION
                    ? 'Transaction correction requires notes or a concrete transaction reference.'
                    : 'A justification note is required for this resolution type.',
            ]);
        }

        return $notes !== '' ? $notes : null;
    }

    /**
     * Physical cash may enter the current active register only for shortage recovery.
     * The receiving shift is always resolved server-side from the live register —
     * client-supplied shift IDs are ignored.
     *
     * @param  array<string, mixed>  $input
     */
    private function resolveReceivingShift(
        ShiftSession $sourceShift,
        string $resolutionType,
        string $drawer,
        array $input
    ): ?ShiftSession {
        unset($input['cash_received_into_shift_id']);

        $wantsDrawer = $this->wantsActiveDrawerDestination($input);
        if (! $wantsDrawer) {
            return null;
        }

        if (
            $resolutionType !== ShiftVarianceResolution::TYPE_SHORTAGE_RECOVERY
            || in_array($resolutionType, ShiftVarianceResolution::TYPES_WITHOUT_PHYSICAL_CASH, true)
        ) {
            throw ValidationException::withMessages([
                'recovery_destination' => 'Only shortage recovery can be received into a Front Desk drawer. Accounting resolutions do not move cash.',
            ]);
        }

        if (! in_array($drawer, [
            ShiftVarianceResolution::DRAWER_ROOM,
            ShiftVarianceResolution::DRAWER_MINIBAR,
        ], true)) {
            throw ValidationException::withMessages([
                'drawer' => 'Shortage recovery must target the Rooms or Minibar drawer that had the original variance.',
            ]);
        }

        $active = ShiftService::activeRegister();
        $this->assertValidReceivingShift($sourceShift, $active);

        return $active;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function wantsActiveDrawerDestination(array $input): bool
    {
        $destination = (string) ($input['recovery_destination'] ?? '');
        if ($destination === ShiftVarianceResolution::DESTINATION_ACTIVE_DRAWER) {
            return true;
        }
        if ($destination === ShiftVarianceResolution::DESTINATION_OFFICE_SAFE) {
            return false;
        }

        return (bool) ($input['receive_into_active_drawer'] ?? false);
    }

    private function assertValidReceivingShift(ShiftSession $sourceShift, ?ShiftSession $active): void
    {
        if (! $active) {
            throw ValidationException::withMessages([
                'recovery_destination' => 'No active Front Desk drawer is available to receive recovery cash.',
            ]);
        }

        if ($active->ended_at !== null) {
            throw ValidationException::withMessages([
                'recovery_destination' => 'Recovery cash cannot be assigned to a closed shift.',
            ]);
        }

        if ((int) $active->id === (int) $sourceShift->id) {
            throw ValidationException::withMessages([
                'recovery_destination' => 'Recovery cash cannot be posted back into the closed source shift.',
            ]);
        }

        $active->loadMissing('user:id,full_name,role');
        $role = $active->user?->role;
        if ($role === null || UserRole::isHousekeeping($role) || ! UserRole::allowsOperational($role)) {
            throw ValidationException::withMessages([
                'recovery_destination' => 'Recovery cash can only be received by an active Front Desk or Admin register.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        int $userId,
        string $action,
        ShiftSession $shift,
        ShiftVarianceResolution $resolution,
        array $before,
        array $after,
        ?string $reason
    ): void {
        BookingService::auditLog(
            $userId,
            $action,
            'shift_variance_resolutions',
            (int) $resolution->id,
            [
                'shift_session_id' => (int) $shift->id,
                'drawer' => $resolution->drawer,
                'original_variance' => $before['original_variance'],
                'remaining' => $before['remaining'],
            ],
            [
                'shift_session_id' => (int) $shift->id,
                'drawer' => $resolution->drawer,
                'variance_type' => $resolution->variance_type,
                'resolution_type' => $resolution->resolution_type,
                'amount' => round((float) $resolution->amount, 2),
                'status' => $resolution->status,
                'original_variance' => $after['original_variance'],
                'resolved_amount' => $after['resolved_amount'],
                'remaining' => $after['remaining'],
                'variance_status' => $shift->fresh()->variance_status,
                'submitted_by' => (int) $resolution->submitted_by,
                'reviewed_by' => $resolution->reviewed_by ? (int) $resolution->reviewed_by : null,
                'cash_received_into_shift_id' => $resolution->cash_received_into_shift_id
                    ? (int) $resolution->cash_received_into_shift_id
                    : null,
                'notes' => $resolution->notes,
                'review_notes' => $resolution->review_notes,
            ],
            $reason
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function auditRecoveryReceived(
        User $admin,
        ShiftSession $sourceShift,
        ShiftVarianceResolution $resolution,
        ShiftSession $receivingShift,
        array $before,
        array $after
    ): void {
        BookingService::auditLog(
            $admin->id,
            'SHIFT_VARIANCE_RECOVERY_RECEIVED',
            'shift_sessions',
            (int) $receivingShift->id,
            [
                'shift_session_id' => (int) $sourceShift->id,
                'drawer' => $resolution->drawer,
                'original_variance' => $before['original_variance'],
                'remaining' => $before['remaining'],
            ],
            [
                'shift_session_id' => (int) $sourceShift->id,
                'resolution_id' => (int) $resolution->id,
                'drawer' => $resolution->drawer,
                'amount' => round((float) $resolution->amount, 2),
                'original_variance' => $after['original_variance'],
                'remaining' => $after['remaining'],
                'cash_received_into_shift_id' => (int) $receivingShift->id,
                'submitted_by' => (int) $resolution->submitted_by,
                'reviewed_by' => (int) $admin->id,
            ],
            'Shortage recovery cash received into active shift #'.$receivingShift->id
        );
    }
}
