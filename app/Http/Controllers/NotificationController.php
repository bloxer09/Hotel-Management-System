<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\MaintenanceTicket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * Return real-time checkout and inventory alerts as JSON.
     * Replicates the legacy includes/notifications.php behavior.
     * Accessible to operational roles, including housekeeping.
     */
    public function getNotifications(Request $request)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'front_desk', 'cashier', 'housekeeping'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        $cacheKey = $user->role === 'admin'
            ? 'notifications.role_admin'
            : 'notifications.user_'.$user->id;
        $data = Cache::remember($cacheKey, 15, function () use ($user) {
            $minutesAhead = 60;
            $now = Carbon::now();

            // ─── 1. Checkout Alerts ────────────────────────────────────────────────
            $checkoutRows = Booking::with(['room', 'room.type'])
                ->where('status', 'active')
                ->whereNotNull('expected_check_out')
                ->where('expected_check_out', '<=', $now->copy()->addMinutes($minutesAhead))
                ->orderBy('expected_check_out', 'asc')
                ->get();

            $checkoutItems = [];
            $upcomingCount = 0;
            $overdueCount = 0;

            foreach ($checkoutRows as $row) {
                $expectedOut = Carbon::parse($row->expected_check_out);
                $diffSeconds = $expectedOut->diffInSeconds($now, false);

                if ($diffSeconds >= 0) {
                    $overdueCount++;
                    $checkoutItems[] = [
                        'type' => 'checkout_overdue',
                        'alert_key' => 'checkout-overdue-'.$row->id,
                        'booking_id' => (int) $row->id,
                        'booking_ref' => $row->booking_ref,
                        'guest_name' => $row->guest_name,
                        'room_number' => $row->room->room_number ?? '?',
                        'expected_check_out' => $expectedOut->toIso8601String(),
                        'overdue_seconds' => $diffSeconds,
                        'message' => 'Overdue check-out: Room '.($row->room->room_number ?? '?')." ({$row->guest_name})",
                    ];
                } else {
                    $upcomingCount++;
                    $checkoutItems[] = [
                        'type' => 'checkout_upcoming',
                        'alert_key' => 'checkout-upcoming-'.$row->id,
                        'booking_id' => (int) $row->id,
                        'booking_ref' => $row->booking_ref,
                        'guest_name' => $row->guest_name,
                        'room_number' => $row->room->room_number ?? '?',
                        'expected_check_out' => $expectedOut->toIso8601String(),
                        'due_in_seconds' => abs($diffSeconds),
                        'message' => 'Check-out due soon: Room '.($row->room->room_number ?? '?')." ({$row->guest_name})",
                    ];
                }
            }

            // ─── 2. Inventory Alerts ───────────────────────────────────────────────
            $inventoryRows = InventoryItem::where('is_active', true)->get();
            $inventoryItems = [];
            $outOfStockCount = 0;

            foreach ($inventoryRows as $inv) {
                if ($inv->isLowStock()) {
                    $stock = $inv->current_stock ?? $inv->quantity ?? 0;
                    $min = $inv->minimum_stock ?? $inv->reorder_level ?? 0;
                    if ($stock <= 0) {
                        $outOfStockCount++;
                        $type = 'out_of_stock';
                        $msg = "OUT OF STOCK: {$inv->item_name} (0 remaining)";
                    } else {
                        $type = 'low_stock';
                        $msg = "Low stock: {$inv->item_name} ({$stock} remaining)";
                    }
                    $inventoryItems[] = [
                        'type' => $type,
                        'alert_key' => 'inventory-'.$inv->id.'-'.$type,
                        'item_id' => (int) $inv->id,
                        'item_name' => $inv->item_name,
                        'quantity' => (int) $stock,
                        'reorder_level' => (int) $min,
                        'unit' => $inv->unit,
                        'message' => $msg,
                    ];
                }
            }

            // ─── 3. Cleaning Finished Alerts ──────────────────────────────────────
            $yesterday = $now->copy()->subHours(24);
            $cleaningLogs = DB::table('audit_logs')
                ->where('action', 'ROOM_STATUS_CHANGE')
                ->where('created_at', '>=', $yesterday)
                ->where('new_value', 'LIKE', '%vacant%')
                ->orderBy('id', 'desc')
                ->limit(10)
                ->get();

            $cleaningItems = [];
            foreach ($cleaningLogs as $log) {
                $cleaningItems[] = [
                    'type' => 'cleaning_finished',
                    'alert_key' => 'cleaning-'.$log->id,
                    'log_id' => (int) $log->id,
                    'message' => $log->reason ?: 'Room cleaned and set to vacant.',
                    'cleaned_at' => $log->created_at,
                ];
            }

            // ─── 4. Maintenance Alerts ─────────────────────────────────────────────
            $maintenanceTickets = MaintenanceTicket::with('room')
                ->whereIn('status', ['open', 'in_progress', 'for_verification'])
                ->orderBy('priority', 'desc')
                ->get();

            $maintenanceItems = [];
            $criticalMaintenanceCount = 0;

            foreach ($maintenanceTickets as $ticket) {
                if ($ticket->priority === 'critical') {
                    $criticalMaintenanceCount++;
                }
                $statusLabel = match ($ticket->status) {
                    'in_progress' => 'Repairing',
                    'for_verification' => 'For Verification',
                    default => 'Open',
                };
                $maintenanceItems[] = [
                    'type' => 'maintenance',
                    'alert_key' => 'maintenance-'.$ticket->id.'-'.$ticket->status,
                    'ticket_id' => (int) $ticket->id,
                    'room_number' => $ticket->room->room_number ?? '?',
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                    'message' => 'Room '.($ticket->room->room_number ?? '?')." — {$statusLabel}: ".$ticket->title,
                    'created_at' => $ticket->created_at->toIso8601String(),
                ];
            }

            // ─── 5. Pending inventory requests ─────────────────────────────────────
            $requestQuery = InventoryChangeRequest::with(['requester:id,full_name', 'item:id,item_name'])
                ->where('status', InventoryChangeRequest::STATUS_PENDING)
                ->orderByDesc('created_at');

            if ($user->role !== 'admin') {
                $requestQuery->where('requested_by', $user->id);
            }

            $inventoryRequestItems = [];
            $pendingRequestCount = 0;
            if (in_array($user->role, ['admin', 'front_desk'], true)) {
                $pendingRequestCount = (clone $requestQuery)->count();
                foreach ($requestQuery->limit(20)->get() as $changeRequest) {
                    $label = $changeRequest->displayItemName();
                    $requesterName = $changeRequest->requester?->full_name ?? 'Staff';
                    $inventoryRequestItems[] = [
                        'type' => 'inventory_request',
                        'alert_key' => 'inventory-request-'.$changeRequest->id,
                        'request_id' => (int) $changeRequest->id,
                        'item_name' => $label,
                        'message' => $user->role === 'admin'
                            ? "Pending inventory request from {$requesterName}: {$label}"
                            : "Your inventory request is pending review: {$label}",
                    ];
                }
            }

            // ─── 6. Merge & Respond ────────────────────────────────────────────────
            $allItems = array_merge($inventoryItems, $checkoutItems, $cleaningItems, $maintenanceItems, $inventoryRequestItems);

            return [
                'success' => true,
                'generated_at' => $now->format('Y-m-d H:i:s'),
                'minutes_ahead' => $minutesAhead,
                'counts' => [
                    'total' => count($inventoryItems) + count($checkoutItems) + count($cleaningItems) + count($maintenanceItems) + $pendingRequestCount,
                    'checkout' => count($checkoutItems),
                    'upcoming' => $upcomingCount,
                    'overdue' => $overdueCount,
                    'inventory' => count($inventoryItems),
                    'out_of_stock' => $outOfStockCount,
                    'cleaning_finished' => count($cleaningItems),
                    'maintenance' => count($maintenanceItems),
                    'critical_maintenance' => $criticalMaintenanceCount,
                    'inventory_requests' => $pendingRequestCount,
                ],
                'items' => $allItems,
            ];
        });

        return response()->json($data);
    }
}
