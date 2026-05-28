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
        $minutesAhead = 5;
        $now = Carbon::now();

        // ─── 1. Checkout Alerts ────────────────────────────────────────────────
        // Active bookings whose expected check-out is within the next 5 minutes
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

        // ─── 3. Merge & Respond ────────────────────────────────────────────────
        // Inventory alerts first (matching legacy ordering), then checkout alerts
        $allItems = array_merge($inventoryItems, $checkoutItems);

        return response()->json([
            'success'       => true,
            'generated_at'  => $now->format('Y-m-d H:i:s'),
            'minutes_ahead' => $minutesAhead,
            'counts'        => [
                'total'         => count($allItems),
                'checkout'      => count($checkoutItems),
                'upcoming'      => $upcomingCount,
                'overdue'       => $overdueCount,
                'inventory'     => count($inventoryItems),
                'out_of_stock'  => $outOfStockCount,
            ],
            'items'         => $allItems,
        ]);
    }
}
