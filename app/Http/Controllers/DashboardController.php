<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\InventoryItem;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryUsage;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1. Occupancy statistics (Today)
        $roomsCount = Room::count();
        $vacantCount = Room::where('status', 'vacant')->count();
        $occupiedCount = Room::where('status', 'occupied')->count();
        $cleaningCount = Room::where('status', 'cleaning')->count();
        $oooCount = Room::where('status', 'out_of_order')->count();

        // 2. Revenue Periods Calculations
        // 2.1 Today's Revenue
        $today = Carbon::today();
        $todayTransactions = Transaction::whereDate('created_at', $today)->get();
        $cashToday = $todayTransactions->sum('cash_amount');
        $gcashToday = $todayTransactions->sum('gcash_amount');
        $cardToday = $todayTransactions->where('payment_method', 'card')->sum('amount');
        $bankToday = $todayTransactions->where('payment_method', 'bank_transfer')->sum('amount');
        $totalToday = $cashToday + $gcashToday + $cardToday + $bankToday;
        $productToday = (float) InventoryUsage::whereDate('created_at', $today)->sum('total_price');
        $roomToday = max(0.00, $totalToday - $productToday);

        // 2.2 Last 7 Days Revenue
        $sevenDaysAgo = Carbon::today()->subDays(6);
        $sevenDaysTransactions = Transaction::where('created_at', '>=', $sevenDaysAgo)->get();
        $cash7d = $sevenDaysTransactions->sum('cash_amount');
        $gcash7d = $sevenDaysTransactions->sum('gcash_amount');
        $card7d = $sevenDaysTransactions->where('payment_method', 'card')->sum('amount');
        $bank7d = $sevenDaysTransactions->where('payment_method', 'bank_transfer')->sum('amount');
        $total7d = $cash7d + $gcash7d + $card7d + $bank7d;
        $product7d = (float) InventoryUsage::where('created_at', '>=', $sevenDaysAgo)->sum('total_price');
        $room7d = max(0.00, $total7d - $product7d);

        // 2.3 This Month Revenue
        $startOfMonth = Carbon::today()->startOfMonth();
        $monthTransactions = Transaction::where('created_at', '>=', $startOfMonth)->get();
        $cashMonth = $monthTransactions->sum('cash_amount');
        $gcashMonth = $monthTransactions->sum('gcash_amount');
        $cardMonth = $monthTransactions->where('payment_method', 'card')->sum('amount');
        $bankMonth = $monthTransactions->where('payment_method', 'bank_transfer')->sum('amount');
        $totalMonth = $cashMonth + $gcashMonth + $cardMonth + $bankMonth;
        $productMonth = (float) InventoryUsage::where('created_at', '>=', $startOfMonth)->sum('total_price');
        $roomMonth = max(0.00, $totalMonth - $productMonth);

        // 2.4 This Year Revenue
        $startOfYear = Carbon::today()->startOfYear();
        $yearTransactions = Transaction::where('created_at', '>=', $startOfYear)->get();
        $cashYear = $yearTransactions->sum('cash_amount');
        $gcashYear = $yearTransactions->sum('gcash_amount');
        $cardYear = $yearTransactions->where('payment_method', 'card')->sum('amount');
        $bankYear = $yearTransactions->where('payment_method', 'bank_transfer')->sum('amount');
        $totalYear = $cashYear + $gcashYear + $cardYear + $bankYear;
        $productYear = (float) InventoryUsage::where('created_at', '>=', $startOfYear)->sum('total_price');
        $roomYear = max(0.00, $totalYear - $productYear);

        // 3. Recent Bookings (limit 5)
        $recentBookings = Booking::with(['room', 'room.type'])
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        // 4. Low stock inventory items
        $lowStockItems = InventoryItem::where('is_active', true)
            ->where('minimum_stock', '>', 0)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->get();

        // Check if there is an active shift for desk staff
        $activeShift = null;
        if (in_array($request->user()->role, ['front_desk', 'cashier'])) {
            $activeShift = \App\Models\ShiftSession::where('user_id', $request->user()->id)
                ->whereNull('ended_at')
                ->first();
        }

        // 5. Rich Analytics for Recharts (Past 30 Days)
        $startDate = Carbon::today()->subDays(29);
        $endDate = Carbon::today();

        // Gather all transactions in 30 days
        $transactions30d = Transaction::with('booking.room.type')
            ->where('created_at', '>=', $startDate)
            ->get();

        // Gather all bookings active in 30 days
        $bookings30d = Booking::where('status', '!=', 'cancelled')
            ->where('check_in', '<=', now())
            ->where(function($q) use ($startDate) {
                $q->whereNull('check_out')
                  ->orWhere('check_out', '>=', $startDate);
            })
            ->get();

        // Generate 30 days list and compute daily stats
        $dailyOccupancy = [];
        $dailyRevenue = [];
        $totalRevenue30d = 0;

        for ($i = 29; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $dayStr = $day->format('Y-m-d');
            $dayLabel = $day->format('M d');

            // Daily Revenue from transactions on this day
            $dayTransactions = $transactions30d->filter(fn($t) => Carbon::parse($t->created_at)->isSameDay($day));
            
            $cash = (float)$dayTransactions->whereIn('payment_method', ['cash', 'split'])->sum('cash_amount');
            $gcash = (float)$dayTransactions->whereIn('payment_method', ['gcash', 'split'])->sum('gcash_amount');
            $card = (float)$dayTransactions->where('payment_method', 'card')->sum('amount');
            $bank = (float)$dayTransactions->where('payment_method', 'bank_transfer')->sum('amount');
            $total = $cash + $gcash + $card + $bank;

            // Separate daily Room related income and Product/Inventory usage income
            $product = (float) InventoryUsage::whereDate('created_at', $day)->sum('total_price');
            $room = max(0.00, $total - $product);

            $totalRevenue30d += $total;

            $dailyRevenue[] = [
                'date' => $dayLabel,
                'cash' => $cash,
                'gcash' => $gcash,
                'card' => $card,
                'bank_transfer' => $bank,
                'total' => $total,
                'room' => $room,
                'product' => $product,
            ];

            // Daily Occupancy
            // A room is occupied if there was a booking covering this day
            $occupiedOnDay = 0;
            $dayStart = $day->copy()->startOfDay();
            $dayEnd = $day->copy()->endOfDay();

            foreach ($bookings30d as $booking) {
                $checkIn = Carbon::parse($booking->check_in);
                $checkOut = $booking->check_out ? Carbon::parse($booking->check_out) : null;
                $expectedCheckOut = $booking->expected_check_out ? Carbon::parse($booking->expected_check_out) : null;

                $effectiveCheckOut = $checkOut ?: $expectedCheckOut ?: now();

                if ($checkIn->lt($dayEnd) && $effectiveCheckOut->gt($dayStart)) {
                    $occupiedOnDay++;
                }
            }

            // Cap occupied rooms at total rooms
            $occupiedOnDay = min($occupiedOnDay, $roomsCount);
            $occRate = $roomsCount > 0 ? round(($occupiedOnDay / $roomsCount) * 100, 1) : 0;

            $dailyOccupancy[] = [
                'date' => $dayLabel,
                'occupied_rooms' => $occupiedOnDay,
                'occupancy_rate' => $occRate,
            ];
        }

        // RevPAR KPI: total revenue in 30 days / (total rooms * 30)
        $availableRoomNights = max(1, $roomsCount) * 30;
        $revpar30d = round($totalRevenue30d / $availableRoomNights, 2);

        // Room Type Revenue Breakdown
        $roomTypeRevenue = [];
        $roomTypes = RoomType::all();
        foreach ($roomTypes as $type) {
            $typeRevenue = (float)$transactions30d->filter(function($t) use ($type) {
                return $t->booking && $t->booking->room && $t->booking->room->room_type_id === $type->id;
            })->sum('amount');

            $roomTypeRevenue[] = [
                'name' => $type->type_name,
                'value' => $typeRevenue,
            ];
        }

        // 6. Live updates/alerts for dashboard
        $now = Carbon::now();
        $startOfToday = Carbon::today();
        $endOfToday = Carbon::today()->endOfDay();
        $endOfTomorrow = Carbon::tomorrow()->endOfDay();

        // 6.1 Upcoming check-ins (reserved bookings starting today or tomorrow)
        $upcomingCheckIns = Booking::with(['room'])
            ->where('status', 'reserved')
            ->whereBetween('check_in', [$startOfToday, $endOfTomorrow])
            ->orderBy('check_in', 'asc')
            ->get()
            ->map(fn($b) => [
                'type' => 'check_in',
                'id' => $b->id,
                'title' => "Expected Check-in: Room " . ($b->room ? $b->room->room_number : 'N/A'),
                'description' => "Guest {$b->guest_name} at " . Carbon::parse($b->check_in)->format('h:i A') . " (Ref: {$b->booking_ref})",
                'time' => $b->check_in,
                'status' => 'pending',
                'link' => route('reservations.index') . '?status=reserved',
            ]);

        // 6.2 Overdue and Impending Check-outs (active stays)
        $activeStays = Booking::with(['room'])
            ->where('status', 'active')
            ->get();

        $checkoutAlerts = collect();
        foreach ($activeStays as $stay) {
            $expectedOut = Carbon::parse($stay->expected_check_out);
            $roomNum = $stay->room ? $stay->room->room_number : 'N/A';
            if ($expectedOut->isPast()) {
                // Overdue checkout!
                $diffMins = $expectedOut->diffInMinutes($now);
                $readableTime = '';
                if ($diffMins < 60) {
                    $readableTime = round($diffMins) . " mins";
                } else {
                    $hours = floor($diffMins / 60);
                    $remMins = round($diffMins % 60);
                    if ($hours < 24) {
                        $readableTime = "{$hours}h {$remMins}m";
                    } else {
                        $days = floor($hours / 24);
                        $remHours = $hours % 24;
                        $readableTime = "{$days}d {$remHours}h {$remMins}m";
                    }
                }

                $checkoutAlerts->push([
                    'type' => 'overdue_checkout',
                    'id' => $stay->id,
                    'title' => "OVERDUE Departure: Room {$roomNum}",
                    'description' => "Guest {$stay->guest_name} was expected at " . $expectedOut->format('h:i A') . " ({$readableTime} ago)",
                    'time' => $stay->expected_check_out,
                    'status' => 'critical',
                    'link' => route('reservations.index') . '?status=active',
                ]);
            } elseif ($expectedOut->between($startOfToday, $endOfToday)) {
                // Upcoming checkout today
                $checkoutAlerts->push([
                    'type' => 'checkout',
                    'id' => $stay->id,
                    'title' => "Impending Checkout: Room {$roomNum}",
                    'description' => "Guest {$stay->guest_name} departing at " . $expectedOut->format('h:i A'),
                    'time' => $stay->expected_check_out,
                    'status' => 'warning',
                    'link' => route('reservations.index') . '?status=active',
                ]);
            }
        }

        // 6.3 Active Cleanings
        $cleaningRooms = Room::where('status', 'cleaning')
            ->get()
            ->map(fn($r) => [
                'type' => 'cleaning',
                'id' => $r->id,
                'title' => "Room {$r->room_number} in Cleaning",
                'description' => "Assigned housekeeper: " . ($r->assigned_housekeeper ?: 'None') . " - Active cleaning in progress.",
                'time' => $r->updated_at,
                'status' => 'info',
                'link' => route('rooms.index'),
            ]);

        // 6.4 Open Maintenance Tickets
        $maintenanceTickets = \App\Models\MaintenanceTicket::whereIn('status', ['open', 'in_progress'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn($t) => [
                'type' => 'maintenance',
                'id' => $t->id,
                'title' => "Maintenance Alert: Room {$t->room_number}",
                'description' => "Issue: {$t->title} ({$t->priority} priority) - currently {$t->status}",
                'time' => $t->created_at,
                'status' => $t->priority === 'critical' ? 'critical' : ($t->priority === 'high' ? 'warning' : 'info'),
                'link' => route('maintenance.index'),
            ]);

        // Combine all and sort by priority / urgency, then by time
        $liveUpdates = collect()
            ->concat($checkoutAlerts)
            ->concat($upcomingCheckIns)
            ->concat($cleaningRooms)
            ->concat($maintenanceTickets)
            ->sortBy(function($item) {
                if ($item['status'] === 'critical') return 1;
                if ($item['status'] === 'warning') return 2;
                if ($item['status'] === 'pending') return 3;
                return 4;
            })
            ->values();

        return Inertia::render('Dashboard', [
            'stats' => [
                'rooms' => [
                    'total' => $roomsCount,
                    'vacant' => $vacantCount,
                    'occupied' => $occupiedCount,
                    'cleaning' => $cleaningCount,
                    'out_of_order' => $oooCount,
                ],
                'revenue' => [
                    'cash' => $cashToday,
                    'gcash' => $gcashToday,
                    'card' => $cardToday,
                    'bank' => $bankToday,
                    'total' => $totalToday,
                    'room' => $roomToday,
                    'product' => $productToday,
                ],
                'revenue_periods' => [
                    'today' => [
                        'total' => $totalToday,
                        'room' => $roomToday,
                        'product' => $productToday,
                        'label' => "Today's Income"
                    ],
                    'last_7_days' => [
                        'total' => $total7d,
                        'room' => $room7d,
                        'product' => $product7d,
                        'label' => "Last 7 Days"
                    ],
                    'this_month' => [
                        'total' => $totalMonth,
                        'room' => $roomMonth,
                        'product' => $productMonth,
                        'label' => "This Month"
                    ],
                    'this_year' => [
                        'total' => $totalYear,
                        'room' => $roomYear,
                        'product' => $productYear,
                        'label' => "This Year"
                    ],
                ],
                'revpar' => $revpar30d,
                'revenue_30d' => $totalRevenue30d,
            ],
            'charts' => [
                'dailyOccupancy' => $dailyOccupancy,
                'dailyRevenue' => $dailyRevenue,
                'roomTypeRevenue' => $roomTypeRevenue,
            ],
            'recentBookings' => $recentBookings,
            'lowStockItems' => $lowStockItems,
            'activeShift' => $activeShift,
            'liveUpdates' => $liveUpdates,
        ]);
    }
}

