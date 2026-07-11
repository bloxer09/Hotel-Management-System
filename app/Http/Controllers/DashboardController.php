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
        // Optimized to use SQL aggregation instead of loading all models
        $today = Carbon::today();
        $sevenDaysAgo = Carbon::today()->subDays(6);
        $startOfMonth = Carbon::today()->startOfMonth();
        $startOfYear = Carbon::today()->startOfYear();

        $periods = [
            'today' => $today,
            '7d' => $sevenDaysAgo,
            'month' => $startOfMonth,
            'year' => $startOfYear,
        ];

        $results = [];
        foreach ($periods as $key => $startDate) {
            $totals = Transaction::where('created_at', '>=', $startDate)
                ->selectRaw('
                    SUM(cash_amount) as cash, 
                    SUM(gcash_amount) as gcash, 
                    SUM(CASE WHEN payment_method = "card" THEN amount ELSE 0 END) as card,
                    SUM(CASE WHEN payment_method = "bank_transfer" THEN amount ELSE 0 END) as bank
                ')->first();

            $cash = round($totals->cash ?? 0, 2);
            $gcash = round($totals->gcash ?? 0, 2);
            $card = round($totals->card ?? 0, 2);
            $bank = round($totals->bank ?? 0, 2);
            $sales_total = round($cash + $gcash + $card + $bank, 2);

            $product = round((float) InventoryUsage::where('created_at', '>=', $startDate)->sum('total_price'), 2);
            $room = round(max(0.00, $sales_total - $product), 2);
            $income = round((float) \App\Models\Income::where('income_date', '>=', $startDate->format('Y-m-d'))->sum('amount'), 2);
            $total = round($sales_total + $income, 2);
            $expense = round((float) \App\Models\Expense::where('expense_date', '>=', $startDate->format('Y-m-d'))->sum('amount'), 2);
            $net = round($total - $expense, 2);

            $results[$key] = compact('cash', 'gcash', 'card', 'bank', 'total', 'product', 'room', 'income', 'expense', 'net');
        }

        extract([
            'cashToday' => $results['today']['cash'], 'gcashToday' => $results['today']['gcash'], 'cardToday' => $results['today']['card'], 'bankToday' => $results['today']['bank'], 'totalToday' => $results['today']['total'], 'productToday' => $results['today']['product'], 'roomToday' => $results['today']['room'], 'expenseToday' => $results['today']['expense'], 'netToday' => $results['today']['net'],
            'cash7d' => $results['7d']['cash'], 'gcash7d' => $results['7d']['gcash'], 'card7d' => $results['7d']['card'], 'bank7d' => $results['7d']['bank'], 'total7d' => $results['7d']['total'], 'product7d' => $results['7d']['product'], 'room7d' => $results['7d']['room'], 'expense7d' => $results['7d']['expense'], 'net7d' => $results['7d']['net'],
            'cashMonth' => $results['month']['cash'], 'gcashMonth' => $results['month']['gcash'], 'cardMonth' => $results['month']['card'], 'bankMonth' => $results['month']['bank'], 'totalMonth' => $results['month']['total'], 'productMonth' => $results['month']['product'], 'roomMonth' => $results['month']['room'], 'expenseMonth' => $results['month']['expense'], 'netMonth' => $results['month']['net'],
            'cashYear' => $results['year']['cash'], 'gcashYear' => $results['year']['gcash'], 'cardYear' => $results['year']['card'], 'bankYear' => $results['year']['bank'], 'totalYear' => $results['year']['total'], 'productYear' => $results['year']['product'], 'roomYear' => $results['year']['room'], 'expenseYear' => $results['year']['expense'], 'netYear' => $results['year']['net'],
        ]);

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

        // Pre-calculate InventoryUsage for 30 days grouped by date to avoid N+1 queries
        $inventoryUsages30d = InventoryUsage::where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, SUM(total_price) as total_price')
            ->groupBy('date')
            ->pluck('total_price', 'date');

        // Pre-calculate daily expenses for 30 days
        $expenses30d = \App\Models\Expense::where('expense_date', '>=', $startDate->format('Y-m-d'))
            ->selectRaw('expense_date, SUM(amount) as total_amount')
            ->groupBy('expense_date')
            ->pluck('total_amount', 'expense_date');

        // Pre-calculate daily incomes/cash injections for 30 days
        $incomes30d = \App\Models\Income::where('income_date', '>=', $startDate->format('Y-m-d'))
            ->selectRaw('income_date, SUM(amount) as total_amount')
            ->groupBy('income_date')
            ->pluck('total_amount', 'income_date');

        $dailyOccupancy = [];
        $dailyRevenue = [];
        $totalRevenue30d = 0;

        for ($i = 29; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $dayStr = $day->format('Y-m-d');
            $dayLabel = $day->format('M d');

            // Daily Revenue from transactions on this day
            $dayTransactions = $transactions30d->filter(fn($t) => Carbon::parse($t->created_at)->isSameDay($day));
            
            $cash = round((float)$dayTransactions->whereIn('payment_method', ['cash', 'split'])->sum('cash_amount'), 2);
            $gcash = round((float)$dayTransactions->whereIn('payment_method', ['gcash', 'split'])->sum('gcash_amount'), 2);
            $card = round((float)$dayTransactions->where('payment_method', 'card')->sum('amount'), 2);
            $bank = round((float)$dayTransactions->where('payment_method', 'bank_transfer')->sum('amount'), 2);
            $sales_total = round($cash + $gcash + $card + $bank, 2);

            // Separate daily Room related income and Product/Inventory usage income
            $product = round((float) ($inventoryUsages30d[$dayStr] ?? 0), 2);
            $room = round(max(0.00, $sales_total - $product), 2);

            $dayIncomes = round((float) ($incomes30d[$dayStr] ?? 0), 2);
            $total = round($sales_total + $dayIncomes, 2); // Gross income includes cash injections
            $dayExpenses = round((float) ($expenses30d[$dayStr] ?? 0), 2);

            $totalRevenue30d += $total;

            $dailyRevenue[] = [
                'date' => $dayLabel,
                'cash' => $cash,
                'gcash' => $gcash,
                'card' => $card,
                'bank_transfer' => $bank,
                'income' => $dayIncomes,
                'total' => $total,
                'room' => $room,
                'product' => $product,
                'expenses' => $dayExpenses,
                'net_income' => round($total - $dayExpenses, 2),
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

        // Room Type Revenue Breakdown
        $roomTypeRevenue = [];
        $roomTypes = RoomType::all();
        foreach ($roomTypes as $type) {
            $typeRevenue = round((float)$transactions30d->filter(function($t) use ($type) {
                return $t->booking && $t->booking->room && $t->booking->room->room_type_id === $type->id;
            })->sum('amount'), 2);

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

        $upcomingCheckInsList = Booking::with(['room', 'room.type'])
            ->where('status', 'reserved')
            ->whereBetween('check_in', [now(), now()->addDay()])
            ->orderBy('check_in', 'asc')
            ->get();

        $upcomingCheckOutsList = Booking::with(['room', 'room.type'])
            ->where('status', 'active')
            ->whereBetween('expected_check_out', [now(), now()->addDay()])
            ->orderBy('expected_check_out', 'asc')
            ->get();

        $recentExpenses = \App\Models\Expense::with('user')
            ->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

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
                        'expenses' => $expenseToday,
                        'net_income' => $netToday,
                        'label' => "Today's Revenue"
                    ],
                    'last_7_days' => [
                        'total' => $total7d,
                        'room' => $room7d,
                        'product' => $product7d,
                        'expenses' => $expense7d,
                        'net_income' => $net7d,
                        'label' => "Last 7 Days"
                    ],
                    'this_month' => [
                        'total' => $totalMonth,
                        'room' => $roomMonth,
                        'product' => $productMonth,
                        'expenses' => $expenseMonth,
                        'net_income' => $netMonth,
                        'label' => "This Month"
                    ],
                    'this_year' => [
                        'total' => $totalYear,
                        'room' => $roomYear,
                        'product' => $productYear,
                        'expenses' => $expenseYear,
                        'net_income' => $netYear,
                        'label' => "This Year"
                    ],
                ],
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
            'upcomingCheckins' => $upcomingCheckInsList,
            'upcomingCheckouts' => $upcomingCheckOutsList,
            'recentExpenses' => $recentExpenses,
        ]);
    }
}

