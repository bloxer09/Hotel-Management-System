<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\ShiftSession;
use App\Models\ShiftVarianceResolution;
use App\Models\User;
use App\Support\HotelDateTime;

class NotificationService
{
    /**
     * Build a role-specific notification payload.
     *
     * @return array{
     *     success: bool,
     *     generated_at: string,
     *     minutes_ahead: int,
     *     counts: array<string, int>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function forUser(User $user): array
    {
        $role = (string) $user->role;
        $housekeeping = UserRole::isHousekeeping($role);
        $operational = UserRole::allowsOperational($role);
        $minutesAhead = max(1, (int) config('hotel.checkout_warning_minutes', 60));
        $now = HotelDateTime::now();

        $checkout = $this->checkoutAlerts($now, $minutesAhead, $housekeeping);
        $cleaningRequired = $this->cleaningRequiredAlerts($housekeeping);
        $maintenance = $this->maintenanceAlerts();
        $inventory = $housekeeping ? [] : $this->inventoryAlerts();
        $inventoryRequestResult = $housekeeping
            ? ['items' => [], 'count' => 0]
            : $this->inventoryRequestAlerts($user);
        $inventoryRequests = $inventoryRequestResult['items'];
        $inventoryRequestCount = $inventoryRequestResult['count'];

        $overdue = array_values(array_filter($checkout['items'], fn (array $item) => $item['type'] === 'checkout_overdue'));
        $upcoming = array_values(array_filter($checkout['items'], fn (array $item) => $item['type'] === 'checkout_upcoming'));

        $cashVariance = $housekeeping ? [] : $this->cashVarianceAlerts($user);
        $expenseAlerts = $housekeeping ? [] : $this->expenseAlerts($user);

        // Room Ready / cleaning-finished is not a bell item. Vacant rooms
        // already appear on the Rooms board.
        if ($housekeeping) {
            $items = array_merge($overdue, $upcoming, $cleaningRequired, $maintenance);
        } else {
            $items = array_merge(
                $overdue,
                $upcoming,
                $cleaningRequired,
                $maintenance,
                $inventoryRequests,
                $inventory,
                $cashVariance,
                $expenseAlerts
            );
        }

        $roomsAttentionCount = $this->uniqueAttentionRoomCount($overdue, $upcoming, $cleaningRequired);

        $outOfStockCount = count(array_filter($inventory, fn (array $item) => $item['type'] === 'out_of_stock'));

        return [
            'success' => true,
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'minutes_ahead' => $minutesAhead,
            'counts' => [
                'total' => count($items),
                'checkout' => count($checkout['items']),
                'upcoming' => $checkout['upcoming'],
                'overdue' => $checkout['overdue'],
                'inventory' => count($inventory),
                'out_of_stock' => $outOfStockCount,
                'cleaning_required' => count($cleaningRequired),
                'cleaning_finished' => 0,
                'rooms_attention' => $roomsAttentionCount,
                'maintenance' => count($maintenance),
                'critical_maintenance' => count(array_filter($maintenance, fn (array $item) => ($item['priority'] ?? '') === 'critical')),
                'inventory_requests' => $inventoryRequestCount,
                'cash_variance' => count($cashVariance),
                'expense_approvals' => count($expenseAlerts),
            ],
            'items' => $items,
            'role' => $role,
            'operational' => $operational,
            'cash_variance_banner' => $this->cashVarianceBanner($user, $housekeeping),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, upcoming: int, overdue: int}
     */
    private function checkoutAlerts($now, int $minutesAhead, bool $housekeeping): array
    {
        $dueSoonCutoff = HotelDateTime::toDatabase($now->copy()->addMinutes($minutesAhead));
        $turnoverMinutes = max(0, (int) config('hotel.turnover_buffer_minutes', 20));

        $rows = Booking::with(['room'])
            ->where('status', 'active')
            ->whereNotNull('expected_check_out')
            ->where('expected_check_out', '<=', $dueSoonCutoff)
            ->orderBy('expected_check_out')
            ->get();

        $items = [];
        $upcoming = 0;
        $overdue = 0;

        foreach ($rows as $row) {
            $expectedOut = HotelDateTime::fromStay($row->expected_check_out);
            $diffSeconds = (int) $expectedOut->diffInSeconds($now, false);
            $roomNumber = $row->room->room_number ?? '?';
            $checkoutDisplay = $expectedOut->format('g:i A');
            $incoming = BookingService::nextReservedCheckIn(
                (int) $row->room_id,
                HotelDateTime::toDatabase($expectedOut),
                (int) $row->id
            );

            if ($diffSeconds >= 0) {
                $overdue++;
                $items[] = $housekeeping
                    ? $this->housekeepingOverdueItem($row, $roomNumber, $checkoutDisplay, $diffSeconds, $incoming, $turnoverMinutes)
                    : $this->deskOverdueItem($row, $roomNumber, $checkoutDisplay, $diffSeconds);
            } else {
                $upcoming++;
                $items[] = $housekeeping
                    ? $this->housekeepingUpcomingItem($row, $roomNumber, $checkoutDisplay, abs($diffSeconds), $incoming, $turnoverMinutes)
                    : $this->deskUpcomingItem($row, $roomNumber, $checkoutDisplay, abs($diffSeconds));
            }
        }

        return [
            'items' => $items,
            'upcoming' => $upcoming,
            'overdue' => $overdue,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deskUpcomingItem(Booking $row, string $roomNumber, string $checkoutDisplay, int $dueInSeconds): array
    {
        $guest = $row->guest_name ?: 'Guest';

        return [
            'type' => 'checkout_upcoming',
            'alert_key' => 'checkout-upcoming-'.$row->id,
            'booking_id' => (int) $row->id,
            'room_id' => (int) $row->room_id,
            'room_number' => $roomNumber,
            'guest_name' => $guest,
            'expected_check_out' => HotelDateTime::toDatabase($row->expected_check_out),
            'expected_check_out_display' => $checkoutDisplay,
            'due_in_seconds' => $dueInSeconds,
            'title' => 'Upcoming Checkout',
            'message' => "Room {$roomNumber} • {$guest}\nCheckout: {$checkoutDisplay}\nDue in ".$this->humanDuration($dueInSeconds),
            'action_label' => 'View Stay',
            'action_url' => route('bookings.show', $row->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deskOverdueItem(Booking $row, string $roomNumber, string $checkoutDisplay, int $overdueSeconds): array
    {
        $guest = $row->guest_name ?: 'Guest';

        return [
            'type' => 'checkout_overdue',
            'alert_key' => 'checkout-overdue-'.$row->id,
            'booking_id' => (int) $row->id,
            'room_id' => (int) $row->room_id,
            'room_number' => $roomNumber,
            'guest_name' => $guest,
            'expected_check_out' => HotelDateTime::toDatabase($row->expected_check_out),
            'expected_check_out_display' => $checkoutDisplay,
            'overdue_seconds' => $overdueSeconds,
            'title' => 'Overdue Checkout',
            'message' => "Room {$roomNumber} • {$guest}\nExpected checkout: {$checkoutDisplay}\nOverdue by ".$this->humanDuration($overdueSeconds),
            'action_label' => 'View Stay',
            'action_url' => route('bookings.show', $row->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function housekeepingUpcomingItem(
        Booking $row,
        string $roomNumber,
        string $checkoutDisplay,
        int $dueInSeconds,
        ?string $incoming,
        int $turnoverMinutes
    ): array {
        $lines = [
            "Room {$roomNumber}",
            "Expected checkout: {$checkoutDisplay}",
            'Prepare for room turnover.',
        ];

        if ($incoming) {
            $incomingDisplay = HotelDateTime::fromStay($incoming)->format('g:i A');
            $lines[] = "Incoming reservation: {$incomingDisplay}";
            if ($turnoverMinutes > 0) {
                $lines[] = "{$turnoverMinutes}-minute turnover window after checkout";
            }
        }

        return [
            'type' => 'checkout_upcoming',
            'alert_key' => 'checkout-upcoming-'.$row->id,
            'booking_id' => (int) $row->id,
            'room_id' => (int) $row->room_id,
            'room_number' => $roomNumber,
            'expected_check_out' => HotelDateTime::toDatabase($row->expected_check_out),
            'expected_check_out_display' => $checkoutDisplay,
            'due_in_seconds' => $dueInSeconds,
            'incoming_reservation' => $incoming,
            'title' => 'Upcoming Room Checkout',
            'message' => implode("\n", $lines),
            'action_label' => 'View Rooms',
            'action_url' => route('rooms.index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function housekeepingOverdueItem(
        Booking $row,
        string $roomNumber,
        string $checkoutDisplay,
        int $overdueSeconds,
        ?string $incoming,
        int $turnoverMinutes
    ): array {
        $lines = [
            "Room {$roomNumber}",
            "Expected checkout: {$checkoutDisplay}",
        ];

        if ($incoming) {
            $incomingDisplay = HotelDateTime::fromStay($incoming)->format('g:i A');
            $lines[] = "Incoming reservation: {$incomingDisplay}";
            if ($turnoverMinutes > 0) {
                $lines[] = "{$turnoverMinutes}-minute turnover window after checkout";
            }
        }

        return [
            'type' => 'checkout_overdue',
            'alert_key' => 'checkout-overdue-'.$row->id,
            'booking_id' => (int) $row->id,
            'room_id' => (int) $row->room_id,
            'room_number' => $roomNumber,
            'expected_check_out' => HotelDateTime::toDatabase($row->expected_check_out),
            'expected_check_out_display' => $checkoutDisplay,
            'overdue_seconds' => $overdueSeconds,
            'incoming_reservation' => $incoming,
            'title' => 'Room Checkout Overdue',
            'message' => implode("\n", $lines),
            'action_label' => 'View Rooms',
            'action_url' => route('rooms.index'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cleaningRequiredAlerts(bool $housekeeping): array
    {
        $rooms = Room::query()
            ->where('status', 'cleaning')
            ->orderBy('room_number')
            ->get(['id', 'room_number']);

        $items = [];
        foreach ($rooms as $room) {
            $items[] = [
                'type' => 'cleaning_required',
                'alert_key' => 'room-cleaning-'.$room->id,
                'room_id' => (int) $room->id,
                'room_number' => $room->room_number,
                'title' => 'Room Needs Cleaning',
                'message' => $housekeeping
                    ? "Room {$room->room_number}\nGuest checkout completed.\nRoom requires turnover cleaning."
                    : "Room {$room->room_number} requires turnover cleaning.",
                'action_label' => 'View Rooms',
                'action_url' => route('rooms.index'),
            ];
        }

        return $items;
    }

    /**
     * Unique rooms that currently need operational attention.
     * Cleaning, upcoming checkout, and overdue checkout each count a room once.
     * Maintenance is excluded (it has its own sidebar badge).
     *
     * @param  list<array<string, mixed>>  $overdue
     * @param  list<array<string, mixed>>  $upcoming
     * @param  list<array<string, mixed>>  $cleaningRequired
     */
    private function uniqueAttentionRoomCount(array $overdue, array $upcoming, array $cleaningRequired): int
    {
        $roomIds = [];
        foreach (array_merge($overdue, $upcoming, $cleaningRequired) as $item) {
            $roomId = (int) ($item['room_id'] ?? 0);
            if ($roomId > 0) {
                $roomIds[$roomId] = true;
            }
        }

        return count($roomIds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maintenanceAlerts(): array
    {
        $tickets = MaintenanceTicket::with('room')
            ->whereIn('status', ['open', 'in_progress', 'for_verification'])
            ->orderByDesc('priority')
            ->get();

        $items = [];
        foreach ($tickets as $ticket) {
            $statusLabel = match ($ticket->status) {
                'in_progress' => 'Repairing',
                'for_verification' => 'For Verification',
                default => 'Open',
            };
            $roomNumber = $ticket->room->room_number ?? '?';
            $items[] = [
                'type' => 'maintenance',
                'alert_key' => 'maintenance-'.$ticket->id.'-'.$ticket->status,
                'ticket_id' => (int) $ticket->id,
                'room_id' => $ticket->room_id ? (int) $ticket->room_id : null,
                'room_number' => $roomNumber,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'title' => $ticket->priority === 'critical' ? 'Critical Maintenance' : 'Maintenance',
                'message' => "Room {$roomNumber} — {$statusLabel}: ".$ticket->title,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'action_label' => 'View Maintenance',
                'action_url' => route('maintenance.index'),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventoryAlerts(): array
    {
        $items = [];
        foreach (InventoryItem::where('is_active', true)->get() as $inv) {
            if (! $inv->isLowStock()) {
                continue;
            }
            $stock = $inv->current_stock ?? $inv->quantity ?? 0;
            $min = $inv->minimum_stock ?? $inv->reorder_level ?? 0;
            $out = $stock <= 0;
            $items[] = [
                'type' => $out ? 'out_of_stock' : 'low_stock',
                'alert_key' => 'inventory-'.$inv->id.'-'.($out ? 'out_of_stock' : 'low_stock'),
                'item_id' => (int) $inv->id,
                'item_name' => $inv->item_name,
                'quantity' => (int) $stock,
                'reorder_level' => (int) $min,
                'unit' => $inv->unit,
                'title' => $out ? 'Out of Stock' : 'Low Stock',
                'message' => $out
                    ? "OUT OF STOCK: {$inv->item_name} (0 remaining)"
                    : "Low stock: {$inv->item_name} ({$stock} remaining)",
                'action_label' => 'View Inventory',
                'action_url' => route('inventory.index'),
            ];
        }

        return $items;
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    private function inventoryRequestAlerts(User $user): array
    {
        if (! in_array($user->role, [UserRole::Admin->value, UserRole::FrontDesk->value], true)) {
            return ['items' => [], 'count' => 0];
        }

        $query = InventoryChangeRequest::with(['requester:id,full_name', 'item:id,item_name'])
            ->where('status', InventoryChangeRequest::STATUS_PENDING)
            ->orderByDesc('created_at');

        if ($user->role !== UserRole::Admin->value) {
            $query->where('requested_by', $user->id);
        }

        $count = (clone $query)->count();
        $items = [];
        foreach ($query->limit(20)->get() as $changeRequest) {
            $label = $changeRequest->displayItemName();
            $requesterName = $changeRequest->requester?->full_name ?? 'Staff';
            $items[] = [
                'type' => 'inventory_request',
                'alert_key' => 'inventory-request-'.$changeRequest->id,
                'request_id' => (int) $changeRequest->id,
                'item_name' => $label,
                'title' => 'Inventory Request',
                'message' => $user->role === UserRole::Admin->value
                    ? "Pending inventory request from {$requesterName}: {$label}"
                    : "Your inventory request is pending review: {$label}",
                'action_label' => 'View Requests',
                'action_url' => route('inventory.index', ['tab' => 'pending']),
            ];
        }

        return ['items' => $items, 'count' => $count];
    }

    private function humanDuration(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds < 60) {
            return $seconds <= 1 ? '1 second' : "{$seconds} seconds";
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        $hours = intdiv($minutes, 60);
        $remain = $minutes % 60;
        $hourLabel = $hours === 1 ? '1 hour' : "{$hours} hours";
        if ($remain === 0) {
            return $hourLabel;
        }

        return $hourLabel.' '.($remain === 1 ? '1 minute' : "{$remain} minutes");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cashVarianceAlerts(User $user): array
    {
        $service = app(ShiftVarianceResolutionService::class);
        $isAdmin = $user->role === UserRole::Admin->value;
        $items = [];

        $pendingQuery = ShiftSession::with('user:id,full_name')
            ->whereNotNull('ended_at')
            ->whereNotNull('expected_formula_version')
            ->whereIn('variance_status', [
                ShiftVarianceResolutionService::STATUS_PENDING_REVIEW,
                ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED,
            ])
            ->orderByDesc('ended_at');

        if (! $isAdmin) {
            $pendingQuery->where('user_id', $user->id);
        }

        foreach ($pendingQuery->limit(20)->get() as $shift) {
            $rooms = $service->drawerReview($shift, ShiftVarianceResolution::DRAWER_ROOM);
            $minibar = $service->drawerReview($shift, ShiftVarianceResolution::DRAWER_MINIBAR);
            $lines = $this->varianceDrawerLines($rooms, $minibar);
            if ($lines === []) {
                continue;
            }

            if ($isAdmin) {
                $items[] = [
                    'type' => 'cash_variance_pending_review',
                    'alert_key' => 'cash-variance-admin-'.$shift->id,
                    'shift_id' => (int) $shift->id,
                    'title' => 'CASH VARIANCE PENDING REVIEW',
                    'message' => 'Shift #'.$shift->id.' • '.($shift->user?->full_name ?? 'Front Desk')."\n".implode("\n", $lines),
                    'action_label' => 'Review Variance',
                    'action_url' => route('shifts.report', $shift->id),
                ];
            } else {
                $items[] = [
                    'type' => 'cash_variance_pending',
                    'alert_key' => 'cash-variance-fd-'.$shift->id,
                    'shift_id' => (int) $shift->id,
                    'title' => 'SHIFT CASH VARIANCE',
                    'message' => 'Shift #'.$shift->id."\n".implode("\n", $lines)."\nPending Admin Review",
                    'action_label' => 'View Details',
                    'action_url' => route('shifts.report', $shift->id),
                ];
            }
        }

        if ($isAdmin) {
            return $items;
        }

        $reviews = ShiftVarianceResolution::query()
            ->with('shift:id,user_id')
            ->whereIn('status', [
                ShiftVarianceResolution::STATUS_APPROVED,
                ShiftVarianceResolution::STATUS_REJECTED,
            ])
            ->whereHas('shift', fn ($query) => $query->where('user_id', $user->id))
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subHours(48))
            ->orderByDesc('reviewed_at')
            ->limit(20)
            ->get();

        foreach ($reviews as $resolution) {
            $statusLabel = $resolution->status === ShiftVarianceResolution::STATUS_APPROVED
                ? 'Approved'
                : 'Rejected';
            $drawer = $resolution->drawer === ShiftVarianceResolution::DRAWER_MINIBAR ? 'Minibar' : 'Rooms';
            $items[] = [
                'type' => 'cash_variance_reviewed',
                'alert_key' => 'cash-variance-review-'.$resolution->id.'-'.$resolution->status,
                'shift_id' => (int) $resolution->shift_session_id,
                'resolution_id' => (int) $resolution->id,
                'title' => 'SHIFT CASH VARIANCE '.$statusLabel,
                'message' => 'Shift #'.$resolution->shift_session_id."\n{$drawer} "
                    .ucfirst($resolution->variance_type)
                    .' ₱'.number_format((float) $resolution->amount, 2)
                    ."\n{$statusLabel} by Admin",
                'action_label' => 'View Details',
                'action_url' => route('shifts.report', $resolution->shift_session_id),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expenseAlerts(User $user): array
    {
        if (! in_array($user->role, [UserRole::Admin->value, UserRole::FrontDesk->value], true)) {
            return [];
        }

        $items = [];
        $isAdmin = $user->role === UserRole::Admin->value;

        if ($isAdmin) {
            $pending = Expense::query()
                ->with(['user:id,full_name', 'category:id,name', 'originShift:id'])
                ->where('status', Expense::STATUS_PENDING_APPROVAL)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            foreach ($pending as $expense) {
                $items[] = [
                    'type' => 'expense_approval_required',
                    'alert_key' => 'expense-approval-'.$expense->id,
                    'expense_id' => (int) $expense->id,
                    'title' => 'EXPENSE APPROVAL REQUIRED',
                    'message' => 'Reference: '.$expense->reference
                        ."\nFront Desk: ".($expense->user?->full_name ?? 'Staff')
                        ."\nAmount: ₱".number_format((float) $expense->amount, 2)
                        ."\nCategory: ".($expense->category?->name ?? 'Uncategorized')
                        ."\nShift: #".($expense->shift_session_id ?? '—'),
                    'action_label' => 'Review Expense',
                    'action_url' => route('expenses.review', $expense),
                ];
            }

            return $items;
        }

        $pending = Expense::query()
            ->with(['category:id,name'])
            ->where('recorded_by', $user->id)
            ->where('status', Expense::STATUS_PENDING_APPROVAL)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        foreach ($pending as $expense) {
            $items[] = [
                'type' => 'expense_awaiting_approval',
                'alert_key' => 'expense-awaiting-'.$expense->id,
                'expense_id' => (int) $expense->id,
                'title' => 'EXPENSE AWAITING APPROVAL',
                'message' => $expense->reference.' • ₱'.number_format((float) $expense->amount, 2)
                    ."\nWaiting for Admin approval. Drawer cash is unchanged.",
                'action_label' => 'View Expense',
                'action_url' => route('expenses.index'),
            ];
        }

        $reviewed = Expense::query()
            ->with(['reviewer:id,full_name'])
            ->where('recorded_by', $user->id)
            ->whereIn('status', [Expense::STATUS_APPROVED, Expense::STATUS_REJECTED, Expense::STATUS_POSTED])
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subHours(48))
            ->orderByDesc('reviewed_at')
            ->limit(20)
            ->get();

        foreach ($reviewed as $expense) {
            if ($expense->status === Expense::STATUS_APPROVED) {
                $items[] = [
                    'type' => 'expense_approved',
                    'alert_key' => 'expense-approved-'.$expense->id,
                    'expense_id' => (int) $expense->id,
                    'title' => 'EXPENSE APPROVED',
                    'message' => $expense->reference.' • ₱'.number_format((float) $expense->amount, 2)
                        ."\nApproved by ".($expense->reviewer?->full_name ?? 'Admin')
                        ."\nStill needs MARK PAID / DISBURSED before drawer cash changes.",
                    'action_label' => 'Mark Paid',
                    'action_url' => route('expenses.index'),
                ];
            } elseif ($expense->status === Expense::STATUS_REJECTED) {
                $items[] = [
                    'type' => 'expense_rejected',
                    'alert_key' => 'expense-rejected-'.$expense->id,
                    'expense_id' => (int) $expense->id,
                    'title' => 'EXPENSE REJECTED',
                    'message' => $expense->reference.' • ₱'.number_format((float) $expense->amount, 2)
                        ."\nRejected by ".($expense->reviewer?->full_name ?? 'Admin')
                        .($expense->review_notes ? "\nReason: ".$expense->review_notes : ''),
                    'action_label' => 'View Expense',
                    'action_url' => route('expenses.index'),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $rooms
     * @param  array<string, mixed>  $minibar
     * @return list<string>
     */
    private function varianceDrawerLines(array $rooms, array $minibar): array
    {
        $lines = [];
        foreach ([$rooms, $minibar] as $drawer) {
            if (($drawer['remaining'] ?? 0) < ShiftCashReconciliationService::TOLERANCE) {
                continue;
            }
            $kind = ($drawer['variance_type'] ?? '') === ShiftVarianceResolution::VARIANCE_OVERAGE ? 'over' : 'short';
            $lines[] = ($drawer['label'] ?? 'Drawer').' '.$kind.' ₱'.number_format((float) $drawer['remaining'], 2);
        }

        return $lines;
    }

    /**
     * Front Desk persistent banner payload. Never included in bell items.
     *
     * @return array<string, mixed>|null
     */
    private function cashVarianceBanner(User $user, bool $housekeeping): ?array
    {
        if ($housekeeping) {
            return null;
        }

        return app(ShiftVarianceResolutionService::class)->bannerForUser($user);
    }
}
