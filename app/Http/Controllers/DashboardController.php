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

        // Today's revenue (from transactions)
        $today = Carbon::today();
        $todayTransactions = Transaction::whereDate('created_at', $today)->get();
        
        $cashRevenueToday = $todayTransactions->sum('cash_amount');
        $gcashRevenueToday = $todayTransactions->sum('gcash_amount');
        // Other methods (card, bank transfer)
        $cardRevenueToday = $todayTransactions->where('payment_method', 'card')->sum('amount');
        $bankRevenueToday = $todayTransactions->where('payment_method', 'bank_transfer')->sum('amount');
        
        $totalRevenueToday = $cashRevenueToday + $gcashRevenueToday + $cardRevenueToday + $bankRevenueToday;

        // Separate Room related income and Product/Inventory usage income today
        $productRevenueToday = (float) InventoryUsage::whereDate('created_at', $today)->sum('total_price');
        $roomRevenueToday = max(0.00, (float)$totalRevenueToday - $productRevenueToday);

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
                    'cash' => $cashRevenueToday,
                    'gcash' => $gcashRevenueToday,
                    'card' => $cardRevenueToday,
                    'bank' => $bankRevenueToday,
                    'total' => $totalRevenueToday,
                    'room' => $roomRevenueToday,
                    'product' => $productRevenueToday,
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
        ]);
    }
}

