<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Carbon\Carbon;
use DB;

class NotificationController extends Controller
{
    /**
     * Return real-time checkout and inventory alerts as JSON.
     * Replicates the legacy includes/notifications.php behavior.
     * Accessible to admin, front_desk, and cashier roles.
     */
    public function getNotifications(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $minutesAhead = 60;
        $now = Carbon::now();

        // ─── 1. Checkout Alerts ────────────────────────────────────────────────
        // Active bookings whose expected check-out is within the next 60 minutes
        // OR is already past (overdue).
        $checkoutRows = Booking::with(['room', 'room.type'])
            ->where('status', 'active')
            ->whereNotNull('expected_check_out')
            ->where('expected_check_out', '<=', $now->copy()->addMinutes($minutesAhead))
            ->orderBy('expected_check_out', 'asc')
            ->get();

        $checkoutItems = [];
        $upcomingCount = 0;
        $overdueCount  = 0;

        foreach ($checkoutRows as $row) {
            $expectedOut  = Carbon::parse($row->expected_check_out);
            $diffSeconds  = $expectedOut->diffInSeconds($now, false); // positive = past

            if ($diffSeconds >= 0) {
                // OVERDUE
                $overdueCount++;
                $minutesValue = (int) max(1, ceil($diffSeconds / 60));
                $state   = 'overdue';
                $message = sprintf(
                    'Room %s (%s) is overdue for checkout by %d minute%s.',
                    $row->room->room_number ?? '?',
                    $row->guest_name,
                    $minutesValue,
                    $minutesValue === 1 ? '' : 's'
                );
            } else {
                // UPCOMING
                $upcomingCount++;
                $minutesValue = (int) max(1, ceil(abs($diffSeconds) / 60));
                $state   = 'upcoming';
                $message = sprintf(
                    'Room %s (%s) will check out in %d minute%s.',
                    $row->room->room_number ?? '?',
                    $row->guest_name,
                    $minutesValue,
                    $minutesValue === 1 ? '' : 's'
                );
            }

            $checkoutItems[] = [
                'type'                    => 'checkout',
                'alert_key'               => 'checkout-' . $row->id . '-' . $state,
                'booking_id'              => (int) $row->id,
                'booking_ref'             => $row->booking_ref,
                'room_number'             => $row->room->room_number ?? '?',
                'room_type'               => $row->room->type->type_name ?? '?',
                'guest_name'              => $row->guest_name,
                'expected_check_out'      => $row->expected_check_out,
                'expected_check_out_label'=> $expectedOut->format('M d, Y h:i A'),
                'state'                   => $state,
                'minutes_value'           => $minutesValue,
                'message'                 => $message,
            ];
        }

        // ─── 2. Inventory Alerts ───────────────────────────────────────────────
        // Active items where current_stock <= minimum_stock
        $lowStockRows = InventoryItem::where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->orderBy('current_stock', 'asc')
            ->get();

        $inventoryItems = [];
        $outOfStockCount = 0;

        foreach ($lowStockRows as $item) {
            $currentStock = (int) $item->current_stock;
            $minimumStock = (int) $item->minimum_stock;
            $isOutOfStock = $currentStock <= 0;

            if ($isOutOfStock) {
                $outOfStockCount++;
                $state   = 'out_of_stock';
                $message = sprintf('%s is out of stock.', $item->item_name);
            } else {
                $state   = 'low_stock';
                $message = sprintf(
                    '%s only has %d %s left (minimum %d).',
                    $item->item_name,
                    $currentStock,
                    $item->unit,
                    $minimumStock
                );
            }

            $inventoryItems[] = [
                'type'          => 'inventory',
                'alert_key'     => 'inventory-' . $item->id . '-' . $currentStock . '-' . $minimumStock,
                'item_id'       => (int) $item->id,
                'item_name'     => $item->item_name,
                'category'      => $item->category,
                'unit'          => $item->unit,
                'current_stock' => $currentStock,
                'minimum_stock' => $minimumStock,
                'state'         => $state,
                'message'       => $message,
            ];
        }

        // ─── 3. Room Finished Cleaning Alerts ──────────────────────────────────
        // Room status changes from cleaning to vacant in the last 60 minutes
        $cleaningLogs = \App\Models\AuditLog::where('action', 'ROOM_STATUS_CHANGE')
            ->where('old_value', 'cleaning')
            ->where('new_value', 'vacant')
            ->where('created_at', '>=', $now->copy()->subMinutes(60))
            ->orderBy('created_at', 'desc')
            ->get();

        $cleaningItems = [];
        foreach ($cleaningLogs as $log) {
            $room = \App\Models\Room::find($log->record_id);
            if (!$room) continue;

            $cleaningItems[] = [
                'type'        => 'cleaning_finished',
                'alert_key'   => 'cleaning-' . $room->id . '-' . $log->created_at->timestamp,
                'room_id'     => (int) $room->id,
                'room_number' => $room->room_number,
                'message'     => "Room {$room->room_number} cleaning finished. Ready for check-in.",
                'created_at'  => $log->created_at->toIso8601String(),
            ];
        }

        // ─── 4. Open Maintenance Ticket Alerts ─────────────────────────────────
        // Open tickets with priority 'high' or 'critical'
        $maintenanceTickets = \App\Models\MaintenanceTicket::with('room')
            ->where('status', 'open')
            ->whereIn('priority', ['high', 'critical'])
            ->orderBy('created_at', 'desc')
            ->get();

        $maintenanceItems = [];
        $criticalMaintenanceCount = 0;
        foreach ($maintenanceTickets as $ticket) {
            if ($ticket->priority === 'critical') {
                $criticalMaintenanceCount++;
            }
            $maintenanceItems[] = [
                'type'        => 'maintenance',
                'alert_key'   => 'maintenance-' . $ticket->id . '-' . $ticket->priority,
                'ticket_id'   => (int) $ticket->id,
                'room_number' => $ticket->room->room_number ?? '?',
                'priority'    => $ticket->priority,
                'message'     => "Room " . ($ticket->room->room_number ?? '?') . " has an open " . $ticket->priority . " maintenance issue: " . $ticket->title,
                'created_at'  => $ticket->created_at->toIso8601String(),
            ];
        }

        // ─── 5. Merge & Respond ────────────────────────────────────────────────
        $allItems = array_merge($inventoryItems, $checkoutItems, $cleaningItems, $maintenanceItems);

        return response()->json([
            'success'       => true,
            'generated_at'  => $now->format('Y-m-d H:i:s'),
            'minutes_ahead' => $minutesAhead,
            'counts'        => [
                'total'               => count($allItems),
                'checkout'            => count($checkoutItems),
                'upcoming'            => $upcomingCount,
                'overdue'             => $overdueCount,
                'inventory'           => count($inventoryItems),
                'out_of_stock'        => $outOfStockCount,
                'cleaning_finished'   => count($cleaningItems),
                'maintenance'         => count($maintenanceItems),
                'critical_maintenance'=> $criticalMaintenanceCount,
            ],
            'items'         => $allItems,
        ]);
    }
}
