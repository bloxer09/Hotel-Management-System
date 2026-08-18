<?php

namespace App\Services;

use App\Models\AdditionalCash;
use App\Models\Booking;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\InventoryUsage;
use App\Models\ShiftSession;
use App\Models\ShiftVarianceResolution;
use App\Models\Transaction;

class ShiftCashReconciliationService
{
    public const FORMULA_VERSION = 'shift_cash_v1';

    public const TOLERANCE = 0.01;

    public const STATUS_BALANCED = 'BALANCED';

    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';

    public const STATUS_PARTIALLY_RESOLVED = 'PARTIALLY_RESOLVED';

    public const STATUS_RESOLVED = 'RESOLVED';

    /**
     * Official per-drawer cash reconciliation for a shift.
     *
     * Phase 1 close-time formula (snapshotted and immutable):
     * expected = opening + cash collections + additional cash
     *          - expenses - cash transfers - withdrawals
     *
     * Live current-shift only: approved shortage-recovery cash that physically
     * entered this drawer is added once as variance_recovery_receipts. That
     * term is never written back onto a prior closed shift snapshot.
     *
     * variance = actual - expected (negative = SHORT, positive = OVER)
     *
     * Closed shifts with a stored snapshot keep that original expected/variance.
     *
     * @return array{
     *     formula_version: string,
     *     uses_snapshot: bool,
     *     rooms: array<string, mixed>,
     *     minibar: array<string, mixed>
     * }
     */
    public function forShift(ShiftSession $shift): array
    {
        $live = $this->liveForShift($shift);
        $usesSnapshot = $shift->ended_at !== null && $shift->expected_formula_version !== null;

        if ($usesSnapshot) {
            $live['rooms'] = $this->applySnapshot(
                $live['rooms'],
                (float) $shift->expected_cash_rooms,
                $shift->variance_rooms !== null ? (float) $shift->variance_rooms : null,
                $shift->ended_at ? (float) $shift->closing_cash : null
            );
            $live['minibar'] = $this->applySnapshot(
                $live['minibar'],
                (float) $shift->expected_cash_minibar,
                $shift->variance_minibar !== null ? (float) $shift->variance_minibar : null,
                $shift->ended_at ? (float) $shift->closing_cash_minibar : null
            );
        }

        return [
            'formula_version' => $usesSnapshot
                ? ($shift->expected_formula_version ?: self::FORMULA_VERSION)
                : self::FORMULA_VERSION,
            'uses_snapshot' => $usesSnapshot,
            'rooms' => $live['rooms'],
            'minibar' => $live['minibar'],
        ];
    }

    /**
     * Live (non-snapshot) totals used at close time and for open/legacy shifts.
     *
     * @return array{rooms: array<string, mixed>, minibar: array<string, mixed>}
     */
    public function liveForShift(ShiftSession $shift): array
    {
        $start = $shift->started_at;
        $end = $shift->ended_at ?: now();
        $userId = (int) $shift->user_id;

        $collections = $this->cashCollections($userId, $start, $end);
        $additional = $this->sumByDrawer(
            AdditionalCash::query()
                ->where('recorded_by', $userId)
                ->whereBetween('created_at', [$start, $end])
                ->get(['amount', 'cash_drawer'])
        );
        $expenses = $this->sumByDrawer(
            Expense::query()
                ->where('recorded_by', $userId)
                ->whereBetween('created_at', [$start, $end])
                ->get(['amount', 'cash_drawer'])
        );
        $movements = CashMovement::query()
            ->where('shift_session_id', $shift->id)
            ->get(['amount', 'cash_drawer', 'movement_type']);

        $transfers = $this->sumMovements($movements, 'cashier_transfer');
        $withdrawals = $this->sumMovements($movements, 'withdrawal');
        $recovery = $this->varianceRecoveryReceipts((int) $shift->id);

        $roomsActual = $shift->ended_at ? (float) $shift->closing_cash : null;
        $minibarActual = $shift->ended_at ? (float) $shift->closing_cash_minibar : null;

        return [
            'rooms' => $this->drawer(
                (float) $shift->opening_cash,
                $collections['rooms'],
                $additional['room'],
                $expenses['room'],
                $transfers['room'],
                $withdrawals['room'],
                $recovery['room'],
                $roomsActual
            ),
            'minibar' => $this->drawer(
                (float) $shift->opening_cash_minibar,
                $collections['minibar'],
                $additional['minibar'],
                $expenses['minibar'],
                $transfers['minibar'],
                $withdrawals['minibar'],
                $recovery['minibar'],
                $minibarActual
            ),
        ];
    }

    /**
     * @return array{
     *     expected_cash_rooms: float,
     *     expected_cash_minibar: float,
     *     variance_rooms: float,
     *     variance_minibar: float,
     *     expected_formula_version: string,
     *     variance_status: string,
     *     rooms: array<string, mixed>,
     *     minibar: array<string, mixed>
     * }
     */
    public function snapshotAtClose(ShiftSession $shift, float $actualRooms, float $actualMinibar): array
    {
        $live = $this->liveForShift($shift);
        $roomsExpected = (float) $live['rooms']['expected_cash'];
        $minibarExpected = (float) $live['minibar']['expected_cash'];
        $roomsVariance = $this->variance($actualRooms, $roomsExpected);
        $minibarVariance = $this->variance($actualMinibar, $minibarExpected);

        return [
            'expected_cash_rooms' => $roomsExpected,
            'expected_cash_minibar' => $minibarExpected,
            'variance_rooms' => $roomsVariance,
            'variance_minibar' => $minibarVariance,
            'expected_formula_version' => self::FORMULA_VERSION,
            'variance_status' => $this->hasVariance($roomsVariance, $minibarVariance)
                ? self::STATUS_PENDING_REVIEW
                : self::STATUS_BALANCED,
            'rooms' => array_merge($live['rooms'], [
                'actual_cash' => $actualRooms,
                'variance' => $roomsVariance,
                'variance_label' => $this->varianceLabel($roomsVariance),
            ]),
            'minibar' => array_merge($live['minibar'], [
                'actual_cash' => $actualMinibar,
                'variance' => $minibarVariance,
                'variance_label' => $this->varianceLabel($minibarVariance),
            ]),
        ];
    }

    public function hasVariance(?float $roomsVariance, ?float $minibarVariance): bool
    {
        return abs((float) $roomsVariance) >= self::TOLERANCE
            || abs((float) $minibarVariance) >= self::TOLERANCE;
    }

    public function variance(float $actual, float $expected): float
    {
        return round($actual - $expected, 2);
    }

    public function varianceLabel(?float $variance): ?string
    {
        if ($variance === null) {
            return null;
        }

        if (abs($variance) < self::TOLERANCE) {
            return self::STATUS_BALANCED;
        }

        return $variance < 0 ? 'SHORT' : 'OVER';
    }

    /**
     * Cash physically received this shift from transactions (same source as the Shift PDF).
     *
     * @return array{rooms: float, minibar: float}
     */
    public function cashCollections(int $userId, mixed $start, mixed $end): array
    {
        $detail = $this->cashCollectionsDetail($userId, $start, $end, []);

        return [
            'rooms' => $detail['rooms'],
            'minibar' => $detail['minibar'],
        ];
    }

    /**
     * Display split of the official rooms cash_collections total.
     *
     * Stay cash = rooms-drawer cash whose booking is on the Room Sales logbook.
     * Reservation cash = rooms-drawer cash for a not-yet-in-house reservation.
     * Other room-related cash = remaining rooms-drawer cash (extensions,
     * adjustments, or collections on an already-active stay that is not on Page 1).
     * stay_rooms + reservation_rooms + other_rooms always equals rooms.
     *
     * @param  list<int>  $stayBookingIds
     * @return array{rooms: float, minibar: float, stay_rooms: float, reservation_rooms: float, other_rooms: float}
     */
    public function cashCollectionsDetail(int $userId, mixed $start, mixed $end, array $stayBookingIds): array
    {
        $stayLookup = array_fill_keys(array_map('intval', $stayBookingIds), true);
        $transactions = Transaction::query()
            ->with(['booking:id,status,checked_in_by,booking_ref'])
            ->where('processed_by', $userId)
            ->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment', 'pos_sale'])
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $roomsCash = 0.00;
        $minibarCash = 0.00;
        $stayRooms = 0.00;
        $reservationRooms = 0.00;
        $otherRooms = 0.00;

        foreach ($transactions as $transaction) {
            $roomsPart = 0.00;
            $minibarPart = 0.00;

            if ($transaction->transaction_type === 'pos_sale') {
                $minibarPart = (float) $transaction->cash_amount;
            } elseif ($transaction->transaction_type === 'check_out') {
                $minibarTotal = (float) InventoryUsage::where('transaction_id', $transaction->id)->sum('total_price');
                if ($transaction->amount > 0) {
                    $ratio = min(1.0, $minibarTotal / $transaction->amount);
                    $minibarPart = (float) $transaction->cash_amount * $ratio;
                    $roomsPart = (float) $transaction->cash_amount - $minibarPart;
                }
            } else {
                $roomsPart = (float) $transaction->cash_amount;
            }

            $minibarCash += $minibarPart;
            $roomsCash += $roomsPart;

            if ($roomsPart == 0.0) {
                continue;
            }

            match ($this->classifyRoomCash($transaction, $stayLookup)) {
                'stay' => $stayRooms += $roomsPart,
                'reservation' => $reservationRooms += $roomsPart,
                default => $otherRooms += $roomsPart,
            };
        }

        return [
            'rooms' => round($roomsCash, 2),
            'minibar' => round($minibarCash, 2),
            'stay_rooms' => round($stayRooms, 2),
            'reservation_rooms' => round($reservationRooms, 2),
            'other_rooms' => round($otherRooms, 2),
        ];
    }

    /**
     * @param  array<int, true>  $stayLookup
     */
    private function classifyRoomCash(Transaction $transaction, array $stayLookup): string
    {
        $bookingId = (int) ($transaction->booking_id ?? 0);
        if ($bookingId > 0 && isset($stayLookup[$bookingId])) {
            return 'stay';
        }

        return $this->isReservationDepositBooking($transaction->booking) ? 'reservation' : 'other';
    }

    private function isReservationDepositBooking(?Booking $booking): bool
    {
        if (! $booking) {
            return false;
        }

        $status = (string) $booking->status;
        if ($status === 'reserved') {
            return true;
        }

        return $booking->checked_in_by === null
            && in_array($status, ['cancelled', 'no_show'], true);
    }

    /**
     * @param  array<string, mixed>  $drawer
     * @return array<string, mixed>
     */
    private function applySnapshot(array $drawer, float $expected, ?float $variance, ?float $actual): array
    {
        $drawer['expected_cash'] = round($expected, 2);
        $drawer['actual_cash'] = $actual;
        $drawer['variance'] = $actual === null ? null : round((float) $variance, 2);
        $drawer['variance_label'] = $this->varianceLabel($drawer['variance']);
        $drawer['total_cash_available'] = round(
            (float) $drawer['opening_cash']
            + (float) $drawer['cash_collections']
            + (float) $drawer['additional_cash']
            + (float) ($drawer['variance_recovery_receipts'] ?? 0),
            2
        );

        return $drawer;
    }

    /**
     * @return array<string, mixed>
     */
    private function drawer(
        float $opening,
        float $collections,
        float $additional,
        float $expenses,
        float $transfers,
        float $withdrawals,
        float $recovery,
        ?float $actual
    ): array {
        $expected = round(
            $opening + $collections + $additional + $recovery - $expenses - $transfers - $withdrawals,
            2
        );
        $variance = $actual === null ? null : $this->variance($actual, $expected);

        return [
            'opening_cash' => round($opening, 2),
            'cash_collections' => round($collections, 2),
            'additional_cash' => round($additional, 2),
            'variance_recovery_receipts' => round($recovery, 2),
            'expenses' => round($expenses, 2),
            'cash_transfers' => round($transfers, 2),
            'withdrawals' => round($withdrawals, 2),
            'total_cash_available' => round($opening + $collections + $additional + $recovery, 2),
            'expected_cash' => $expected,
            'actual_cash' => $actual === null ? null : round($actual, 2),
            'variance' => $variance,
            'variance_label' => $this->varianceLabel($variance),
        ];
    }

    /**
     * Approved shortage recovery cash that physically entered this shift's drawer.
     * Linked via shift_variance_resolutions.cash_received_into_shift_id.
     *
     * @return array{room: float, minibar: float}
     */
    public function varianceRecoveryReceipts(int $shiftId): array
    {
        $rows = ShiftVarianceResolution::query()
            ->where('cash_received_into_shift_id', $shiftId)
            ->where('status', ShiftVarianceResolution::STATUS_APPROVED)
            ->where('resolution_type', ShiftVarianceResolution::TYPE_SHORTAGE_RECOVERY)
            ->get(['amount', 'drawer']);

        return $this->sumByDrawer($rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array{room: float, minibar: float}
     */
    private function sumByDrawer($rows): array
    {
        $sums = ['room' => 0.00, 'minibar' => 0.00];
        foreach ($rows as $row) {
            $raw = $row->cash_drawer ?? $row->drawer ?? 'room';
            $drawer = $raw === 'minibar' ? 'minibar' : 'room';
            $sums[$drawer] += (float) $row->amount;
        }

        return [
            'room' => round($sums['room'], 2),
            'minibar' => round($sums['minibar'], 2),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $movements
     * @return array{room: float, minibar: float}
     */
    private function sumMovements($movements, string $type): array
    {
        return $this->sumByDrawer($movements->where('movement_type', $type));
    }
}
