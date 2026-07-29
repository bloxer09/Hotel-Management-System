<?php

namespace App\Http\Controllers;

use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\InventoryUsage;
use App\Models\InventoryItem;
use App\Models\CashMovement;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use DB;

class ShiftController extends Controller
{
    private const DENOMINATION_VALUES = [
        '0.01' => 0.01,
        '0.05' => 0.05,
        '0.25' => 0.25,
        '1' => 1.00,
        '5' => 5.00,
        '10' => 10.00,
        '20' => 20.00,
        '50' => 50.00,
        '100' => 100.00,
        '200' => 200.00,
        '500' => 500.00,
        '1000' => 1000.00,
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Get active shift
        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        // 2. Guess shift code based on time
        $nowHM = date('H:i');
        $suggestedShift = 'morning';
        if ($nowHM >= '15:00' && $nowHM < '23:00') $suggestedShift = 'evening';
        if ($nowHM >= '23:00' || $nowHM < '07:00') $suggestedShift = 'night';

        // 3. Get last closed shift's closing cash to suggest as opening cash
        $lastShift = ShiftSession::where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->orderBy('id', 'desc')
            ->first();
        $suggestedOpeningCash = $lastShift ? $lastShift->closing_cash : 0.00;
        $suggestedOpeningDenominations = $lastShift ? $lastShift->closing_denominations : null;
        $suggestedOpeningCashMinibar = $lastShift ? $lastShift->closing_cash_minibar : 0.00;
        $suggestedOpeningDenominationsMinibar = $lastShift ? $lastShift->closing_denominations_minibar : null;

        // 4. Calculate live drawer cash if shift is active
        $liveSummary = null;
        if ($activeShift) {
            $liveStart = $activeShift->started_at;
            $liveEnd = now();

            $salesStats = $this->getShiftSalesSummary($user->id, $liveStart, $liveEnd);
            
            $expensesSum = (float)\App\Models\Expense::where('recorded_by', $user->id)
                ->whereBetween('created_at', [$liveStart, $liveEnd])
                ->sum('amount');

            $incomesSum = (float)\App\Models\Income::where('recorded_by', $user->id)
                ->whereBetween('created_at', [$liveStart, $liveEnd])
                ->sum('amount');

            $expectedDrawerCash = $activeShift->opening_cash + $salesStats['rooms_cash'];
            $expectedDrawerCashMinibar = $activeShift->opening_cash_minibar + $salesStats['minibar_cash'];

            $liveSummary = [
                'sales' => $salesStats,
                'expected_drawer_cash' => $expectedDrawerCash,
                'expected_drawer_cash_minibar' => $expectedDrawerCashMinibar,
                'live_end' => $liveEnd->format('Y-m-d H:i:s'),
                'expenses_sum' => $expensesSum,
                'incomes_sum' => $incomesSum,
            ];
        }

        // 5. Get recent closed shift sessions for reference
        $recentShiftsQuery = ShiftSession::with('user')
            ->orderBy('id', 'desc');
            
        if ($user->role !== 'admin') {
            $recentShiftsQuery->where('user_id', $user->id);
        }
        $recentShifts = $recentShiftsQuery->limit(10)->get();

        return Inertia::render('Shifts/Index', [
            'activeShift' => $activeShift,
            'suggestedShift' => $suggestedShift,
            'suggestedOpeningCash' => $suggestedOpeningCash,
            'suggestedOpeningDenominations' => $suggestedOpeningDenominations,
            'suggestedOpeningCashMinibar' => $suggestedOpeningCashMinibar,
            'suggestedOpeningDenominationsMinibar' => $suggestedOpeningDenominationsMinibar,
            'liveSummary' => $liveSummary,
            'recentShifts' => $recentShifts,
        ]);
    }

    public function start(Request $request)
    {
        $request->validate([
            'shift_code' => 'required|in:morning,evening,night',
            'opening_cash' => 'required|numeric|min:0',
            'opening_denominations' => 'nullable|array',
            'opening_denominations.*' => 'integer|min:0',
            'opening_cash_minibar' => 'required|numeric|min:0',
            'opening_denominations_minibar' => 'nullable|array',
            'opening_denominations_minibar.*' => 'integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        // Check if there is an active shift already
        $existing = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if ($existing) {
            return back()->with('warning', 'You already have an active shift. Please end your current shift first.');
        }

        $openingDenominations = $this->sanitizeDenominations($request->opening_denominations);
        $openingDenominationsMinibar = $this->sanitizeDenominations($request->opening_denominations_minibar);
        $hasOpeningDenominations = is_array($request->opening_denominations)
            && count($request->opening_denominations) > 0;
        $hasOpeningDenominationsMinibar = is_array($request->opening_denominations_minibar)
            && count($request->opening_denominations_minibar) > 0;
        $openingCash = $hasOpeningDenominations
            ? $this->calculateDenominationTotal($openingDenominations)
            : (float) $request->opening_cash;
        $openingCashMinibar = $hasOpeningDenominationsMinibar
            ? $this->calculateDenominationTotal($openingDenominationsMinibar)
            : (float) $request->opening_cash_minibar;

        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => $request->shift_code,
            'opening_cash' => $openingCash,
            'opening_denominations' => $openingDenominations,
            'opening_cash_minibar' => $openingCashMinibar,
            'opening_denominations_minibar' => $openingDenominationsMinibar,
            'started_at' => now(),
            'notes' => $request->notes,
        ]);

        \Illuminate\Support\Facades\Cache::forget("active_shift_{$user->id}");

        BookingService::auditLog($user->id, 'SHIFT_START', 'shift_sessions', $shift->id, null, $request->shift_code, 'Shift started. Rooms opening cash: ' . $openingCash . ', Minibar opening cash: ' . $openingCashMinibar);

        return redirect()->route('shifts.index')->with('success', 'Shift started successfully: ' . ucfirst($request->shift_code) . ' Shift.');
    }

    public function end(Request $request)
    {
        $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'closing_denominations' => 'nullable|array',
            'closing_denominations.*' => 'integer|min:0',
            'closing_cash_minibar' => 'required|numeric|min:0',
            'closing_denominations_minibar' => 'nullable|array',
            'closing_denominations_minibar.*' => 'integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (!$activeShift) {
            return back()->with('error', 'No active shift found to end.');
        }

        $closingDenominations = $this->sanitizeDenominations($request->closing_denominations);
        $closingDenominationsMinibar = $this->sanitizeDenominations($request->closing_denominations_minibar);
        $closingCash = $request->closing_denominations !== null
            ? $this->calculateDenominationTotal($closingDenominations)
            : (float) $request->closing_cash;
        $closingCashMinibar = $request->closing_denominations_minibar !== null
            ? $this->calculateDenominationTotal($closingDenominationsMinibar)
            : (float) $request->closing_cash_minibar;

        $activeShift->ended_at = now();
        $activeShift->closing_cash = $closingCash;
        $activeShift->closing_denominations = $closingDenominations;
        $activeShift->closing_cash_minibar = $closingCashMinibar;
        $activeShift->closing_denominations_minibar = $closingDenominationsMinibar;
        if ($request->notes) {
            $activeShift->notes = trim($activeShift->notes . "\nClosing Notes: " . $request->notes);
        }
        $activeShift->save();

        \Illuminate\Support\Facades\Cache::forget("active_shift_{$user->id}");

        BookingService::auditLog($user->id, 'SHIFT_END', 'shift_sessions', $activeShift->id, null, null, 'Shift ended. Rooms closing cash: ' . $closingCash . ', Minibar closing cash: ' . $closingCashMinibar);

        return redirect()->route('shifts.report', $activeShift->id)->with('success', 'Shift ended. Here is your Shift Report.');
    }

    public function report($id, Request $request)
    {
        $user = $request->user();
        
        $shift = ShiftSession::with('user')->findOrFail($id);

        // Security check: only admin or the shift owner can view this shift report
        if ($user->role !== 'admin' && $shift->user_id !== $user->id) {
            abort(403, 'Unauthorized access.');
        }

        $start = $shift->started_at;
        $end = $shift->ended_at ?: now();
        $shiftUserId = $shift->user_id;

        // 1. Transaction lists and summary
        $sales = $this->getShiftSalesSummary($shiftUserId, $start, $end);

        $expenses = \App\Models\Expense::with('user')->where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $incomes = \App\Models\Income::with('user')->where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $expensesSum = (float)$expenses->sum('amount');
        $incomesSum = (float)$incomes->sum('amount');
        
        $expectedDrawerCash = $shift->opening_cash + $sales['rooms_cash'];
        $cashVariance = null;
        if ($shift->ended_at !== null) {
            $cashVariance = round($shift->closing_cash - $expectedDrawerCash, 2);
        }

        $expectedDrawerCashMinibar = $shift->opening_cash_minibar + $sales['minibar_cash'];
        $cashVarianceMinibar = null;
        if ($shift->ended_at !== null) {
            $cashVarianceMinibar = round($shift->closing_cash_minibar - $expectedDrawerCashMinibar, 2);
        }

        // 2. Count bookings checked in/out during shift
        $checkinCount = Transaction::where('processed_by', $shiftUserId)
            ->where('transaction_type', 'check_in')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $checkoutCount = Transaction::where('processed_by', $shiftUserId)
            ->where('transaction_type', 'check_out')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $activeRoomsCount = \App\Models\Booking::where('status', 'active')->count();
        $cleaningRoomsCount = \App\Models\Room::where('status', 'cleaning')->count();

        // 3. Shift Adjustments, Discounts & Waivers
        $shiftDiscounts = \App\Models\Booking::where('checked_in_by', $shiftUserId)
            ->whereBetween('check_in', [$start, $end])
            ->where('discount_amount', '>', 0)
            ->select('booking_ref', 'guest_name', 'discount_type', 'discount_amount')
            ->get();
        $totalDiscountsSum = (float)$shiftDiscounts->sum('discount_amount');

        $waivedLateCheckouts = \App\Models\Booking::where('checked_out_by', $shiftUserId)
            ->whereBetween('check_out', [$start, $end])
            ->where('late_hours', '>', 0)
            ->where('late_checkout_fee', 0.00)
            ->get();

        $waivedLateCheckoutsData = [];
        foreach ($waivedLateCheckouts as $wbc) {
            $potentialFee = BookingService::calculateLateCheckoutFee($wbc->expected_check_out, $wbc->check_out);
            $waivedLateCheckoutsData[] = [
                'booking_ref' => $wbc->booking_ref,
                'guest_name' => $wbc->guest_name,
                'late_hours' => $wbc->late_hours,
                'waived_fee' => $potentialFee,
            ];
        }
        $totalWaivedLateFeesSum = (float)collect($waivedLateCheckoutsData)->sum('waived_fee');

        // 4. Inventory usages during shift
        $inventorySummary = InventoryUsage::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(quantity), 0) as total_qty, COALESCE(SUM(total_price), 0) as total_value')
            ->first();

        $inventoryItems = InventoryUsage::join('inventory_items', 'inventory_usage.item_id', '=', 'inventory_items.id')
            ->where('inventory_usage.recorded_by', $shiftUserId)
            ->whereBetween('inventory_usage.created_at', [$start, $end])
            ->select('inventory_items.item_name', 'inventory_usage.item_id', DB::raw('SUM(inventory_usage.quantity) as qty'), DB::raw('SUM(inventory_usage.total_price) as total'))
            ->groupBy('inventory_usage.item_id', 'inventory_items.item_name')
            ->orderBy('total', 'desc')
            ->get();

        // 5. Low stock inventory items
        $lowStock = InventoryItem::where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->get();

        // 6. Raw list of transactions
        $transactions = Transaction::with(['booking', 'booking.room', 'inventoryUsages.item'])
            ->where('processed_by', $shiftUserId)
            ->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment', 'pos_sale'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'desc')
            ->get();

        // 6b. Detailed room bookings stays list for Log Book
        $bookings = \App\Models\Booking::with(['room', 'room.type', 'transactions'])
            // The front-desk room-sales ledger records actual arrivals and
            // departures during this shift. Future reservation deposits stay
            // out of room sales until the guest checks in.
            ->where(function($query) use ($shiftUserId, $start, $end) {
                $query->where(fn($q) => $q->where('checked_in_by', $shiftUserId)->whereBetween('check_in', [$start, $end]))
                    ->orWhere(fn($q) => $q->where('checked_out_by', $shiftUserId)->whereBetween('check_out', [$start, $end]));
            })
            ->orderBy('check_in', 'asc')
            ->get();
        $stayCollections = $this->appendBookingPaymentSummaries(
            $bookings,
            $shiftUserId,
            $start,
            $end
        );

        // The daily cash report is intentionally room-drawer only.  Its room
        // sales source is the verified payment ledger, so minibar/POS charges,
        // GCash, and future reservation deposits cannot inflate drawer cash.
        $roomExpenses = $expenses->where('cash_drawer', 'room')->values();
        $cashMovements = CashMovement::with('recorder:id,full_name')
            ->where('shift_session_id', $shift->id)
            ->where('cash_drawer', 'room')
            ->orderBy('moved_at')
            ->get();
        $cashierTransfers = (float) $cashMovements
            ->where('movement_type', 'cashier_transfer')
            ->sum('amount');
        $withdrawals = (float) $cashMovements
            ->where('movement_type', 'withdrawal')
            ->sum('amount');
        $dailyRoomSalesCash = (float) ($stayCollections['cash'] ?? 0);
        $dailyRoomExpenses = (float) $roomExpenses->sum('amount');
        $dailyExpectedCash = round(
            (float) $shift->opening_cash + $dailyRoomSalesCash - $dailyRoomExpenses - $cashierTransfers - $withdrawals,
            2
        );
        $dailyActualCash = $shift->ended_at ? (float) $shift->closing_cash : null;
        $dailyVariance = $dailyActualCash === null
            ? null
            : round($dailyExpectedCash - $dailyActualCash, 2);

        // 6c. Detailed inventory usages list
        $inventoryUsageDetails = \App\Models\InventoryUsage::with(['item', 'booking.room', 'recorder'])
            ->where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'desc')
            ->get();

        // 7. Maintenance tickets reported or resolved during shift
        $maintenanceTickets = \App\Models\MaintenanceTicket::with(['room', 'reportedBy', 'resolvedBy'])
            ->where(function($query) use ($shiftUserId, $start, $end) {
                $query->where(fn($q) => $q->where('reported_by', $shiftUserId)->whereBetween('created_at', [$start, $end]))
                      ->orWhere(fn($q) => $q->where('resolved_by', $shiftUserId)->whereBetween('resolved_at', [$start, $end]));
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // 8. Server-side recalculations for print layout report integrity
        $roomsOccupied = \App\Models\Room::where('status', 'occupied')->count();
        $roomsCheckedIn = \App\Models\Booking::where('checked_in_by', $shiftUserId)
            ->whereBetween('check_in', [$start, $end])
            ->count();
        $roomsCheckedOut = \App\Models\Booking::where('checked_out_by', $shiftUserId)
            ->whereBetween('check_out', [$start, $end])
            ->count();
        $reservationsCount = \App\Models\Booking::where('checked_in_by', $shiftUserId)
            ->whereBetween('check_in', [$start, $end])
            ->where('booking_ref', 'like', 'RES-%')
            ->count();
        $walkinsCount = \App\Models\Booking::where('checked_in_by', $shiftUserId)
            ->whereBetween('check_in', [$start, $end])
            ->where('booking_ref', 'like', 'BKG-%')
            ->count();
        $totalGuests = \App\Models\Booking::where('status', 'active')->sum('num_guests');
        $activeStays = \App\Models\Booking::where('status', 'active')->count();
        $vacantRooms = \App\Models\Room::where('status', 'vacant')->count();
        $maintenanceRooms = \App\Models\Room::where('status', 'out_of_order')->count();
        
        $minibarSales = (float)$inventorySummary->total_value;

        // Recalculating totals
        $checkoutMinibarCharges = (float)\App\Models\InventoryUsage::join('transactions', 'inventory_usage.transaction_id', '=', 'transactions.id')
            ->where('transactions.processed_by', $shiftUserId)
            ->whereBetween('transactions.created_at', [$start, $end])
            ->sum('inventory_usage.total_price');

        $stayTransactionsTotal = (float)$transactions->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment'])->sum('amount');
        $roomRevenue = $stayTransactionsTotal - $checkoutMinibarCharges;
        $minibarRevenue = $sales['possale_sales'] + $checkoutMinibarCharges;
        $posRevenue = (float)$sales['possale_sales'];

        $maintenanceCost = (float)$expenses->filter(function($e) {
            return stripos($e->notes, 'maintenance') !== false || stripos($e->notes, 'repair') !== false;
        })->sum('amount');

        $refunds = (float)abs($transactions->where('amount', '<', 0)->sum('amount'));

        $cashIn = (float)$transactions->where('cash_amount', '>', 0)->sum('cash_amount') + $incomesSum;
        $cashOut = (float)abs($transactions->where('cash_amount', '<', 0)->sum('cash_amount')) + $expensesSum;
        $netCash = $cashIn - $cashOut;

        $grandCashCollection = $expectedDrawerCash + $expectedDrawerCashMinibar;

        return Inertia::render('Shifts/Report', [
            'shift' => $shift,
            'report' => [
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
                'sales' => $sales,
                'expectedDrawerCash' => $expectedDrawerCash,
                'cashVariance' => $cashVariance,
                'expectedDrawerCashMinibar' => $expectedDrawerCashMinibar,
                'cashVarianceMinibar' => $cashVarianceMinibar,
                'checkins' => $checkinCount,
                'checkouts' => $checkoutCount,
                'active_rooms_count' => $activeRoomsCount,
                'cleaning_rooms_count' => $cleaningRoomsCount,
                'inventory_summary' => $inventorySummary,
                'inventory_items' => $inventoryItems,
                'low_stock' => $lowStock,
                'transactions' => $transactions,
                'expenses' => $expenses,
                'incomes' => $incomes,
                'expenses_sum' => $expensesSum,
                'incomes_sum' => $incomesSum,
                'discounts' => $shiftDiscounts,
                'discounts_sum' => $totalDiscountsSum,
                'waived_late_fees' => $waivedLateCheckoutsData,
                'waived_late_fees_sum' => $totalWaivedLateFeesSum,
                'maintenance_tickets' => $maintenanceTickets,
                'bookings' => $bookings,
                'stay_collections' => $stayCollections,
                'daily_cash_report' => [
                    'room_sales_cash' => $dailyRoomSalesCash,
                    'room_expenses' => $dailyRoomExpenses,
                    'cashier_transfers' => $cashierTransfers,
                    'withdrawals' => $withdrawals,
                    'expected_cash' => $dailyExpectedCash,
                    'actual_cash' => $dailyActualCash,
                    'variance' => $dailyVariance,
                    'expense_details' => $roomExpenses,
                    'cash_movements' => $cashMovements,
                ],
                'can_manage_daily_cash' => $shift->ended_at === null
                    && ($user->role === 'admin' || $shift->user_id === $user->id),
                'inventory_usage_details' => $inventoryUsageDetails,

                // New fields for print report redesign
                'rooms_occupied' => $roomsOccupied,
                'rooms_checked_in' => $roomsCheckedIn,
                'rooms_checked_out' => $roomsCheckedOut,
                'reservations' => $reservationsCount,
                'walk_ins' => $walkinsCount,
                'total_guests' => $totalGuests,
                'active_stays' => $activeStays,
                'vacant_rooms' => $vacantRooms,
                'maintenance_rooms' => $maintenanceRooms,
                'minibar_sales' => $minibarSales,
                'room_revenue' => $roomRevenue,
                'minibar_revenue' => $minibarRevenue,
                'pos_revenue' => $posRevenue,
                'maintenance_cost' => $maintenanceCost,
                'refunds' => $refunds,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'net_cash' => $netCash,
                'grand_cash_collection' => $grandCashCollection,
            ]
        ]);
    }

    public function printLedger($id, Request $request)
    {
        $user = $request->user();
        
        $shift = ShiftSession::with('user')->findOrFail($id);

        // Security check: only admin or the shift owner can view this shift report
        if ($user->role !== 'admin' && $shift->user_id !== $user->id) {
            abort(403, 'Unauthorized access.');
        }

        $start = $shift->started_at;
        $end = $shift->ended_at ?: now();
        $shiftUserId = $shift->user_id;

        // Detailed room bookings stays list for Log Book
        $bookings = \App\Models\Booking::with(['room', 'room.type', 'transactions'])
            ->where(function($query) use ($shiftUserId, $start, $end) {
                $query->where(fn($q) => $q->where('checked_in_by', $shiftUserId)->whereBetween('check_in', [$start, $end]))
                    ->orWhere(fn($q) => $q->where('checked_out_by', $shiftUserId)->whereBetween('check_out', [$start, $end]));
            })
            ->orderBy('check_in', 'asc')
            ->get();
            
        $stayCollections = $this->appendBookingPaymentSummaries(
            $bookings,
            $shiftUserId,
            $start,
            $end
        );

        // Mini bar logbook data for the official export.
        $minibarPosSales = Transaction::with('inventoryUsages.item')
            ->where('processed_by', $shiftUserId)
            ->where('transaction_type', 'pos_sale')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get();
        $minibarStayCharges = InventoryUsage::with(['item', 'booking.room'])
            ->whereNotNull('booking_id')
            ->where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get();
        $lowStock = InventoryItem::where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->orderBy('current_stock')
            ->orderBy('item_name')
            ->get();

        // --- Page 2: Daily Cash Tally data ---

        // Expenses from room drawer only (for deductions)
        $roomExpenses = \App\Models\Expense::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->where('cash_drawer', 'room')
            ->get();

        // Cash movements (withdrawals, cashier transfers) for the room drawer
        $cashMovements = CashMovement::with('recorder:id,full_name')
            ->where('shift_session_id', $shift->id)
            ->where('cash_drawer', 'room')
            ->orderBy('moved_at')
            ->get();

        $cashierTransfers = (float) $cashMovements->where('movement_type', 'cashier_transfer')->sum('amount');
        $withdrawals      = (float) $cashMovements->where('movement_type', 'withdrawal')->sum('amount');

        // Cash room sales collected this shift (from payment ledger)
        $roomSalesCash    = (float) ($stayCollections['cash'] ?? 0);

        // Other cash receipts (incomes from room drawer)
        $incomes = \App\Models\Income::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->where('cash_drawer', 'room')
            ->get();
        $otherCashReceipts = (float) $incomes->sum('amount');

        $minibarExpenses = \App\Models\Expense::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->where('cash_drawer', 'minibar')
            ->get();
        $minibarIncomes = \App\Models\Income::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->where('cash_drawer', 'minibar')
            ->get();
        $minibarCashMovements = CashMovement::with('recorder:id,full_name')
            ->where('shift_session_id', $shift->id)
            ->where('cash_drawer', 'minibar')
            ->orderBy('moved_at')
            ->get();
        $minibarCashierTransfers = (float) $minibarCashMovements->where('movement_type', 'cashier_transfer')->sum('amount');
        $minibarWithdrawals = (float) $minibarCashMovements->where('movement_type', 'withdrawal')->sum('amount');
        $minibarOtherCashReceipts = (float) $minibarIncomes->sum('amount');
        $minibarTotalExpenses = (float) $minibarExpenses->sum('amount');
        $minibarDrawerSummary = $this->getShiftSalesSummary($shiftUserId, $start, $end);
        // The shared shift summary includes drawer income and expenses. Remove
        // those adjustments here because this tally lists them separately.
        $minibarSalesCash = round(
            (float) ($minibarDrawerSummary['minibar_cash'] ?? 0)
                - $minibarOtherCashReceipts
                + $minibarTotalExpenses,
            2
        );
        $minibarTotalMovements = $minibarCashierTransfers + $minibarWithdrawals;
        $minibarOpeningCash = (float) $shift->opening_cash_minibar;
        $minibarExpectedCash = round(
            $minibarOpeningCash + $minibarSalesCash + $minibarOtherCashReceipts - $minibarTotalExpenses - $minibarTotalMovements,
            2
        );
        $minibarActualCash = $shift->ended_at ? (float) $shift->closing_cash_minibar : null;
        $minibarVariance = $minibarActualCash !== null ? round($minibarActualCash - $minibarExpectedCash, 2) : null;

        // Digital payments (non-cash)
        $digitalTotal = (float) (
            ($stayCollections['gcash'] ?? 0) +
            ($stayCollections['bank_transfer'] ?? 0) +
            ($stayCollections['card'] ?? 0) +
            ($stayCollections['maya'] ?? 0) +
            ($stayCollections['other_ewallet'] ?? 0) +
            ($stayCollections['other'] ?? 0)
        );

        // Outstanding balance = sum of balance_amount across all bookings
        $outstandingBalance = (float) $bookings->sum(fn($b) => max(0, $b->balance_amount ?? 0));

        // Total room sales = sum of total_amount for all bookings in shift
        $totalRoomSales = (float) $bookings->sum('total_amount');

        $totalExpenses   = (float) $roomExpenses->sum('amount');
        $totalMovements  = $cashierTransfers + $withdrawals;
        $openingCash     = (float) $shift->opening_cash;

        $expectedCash    = round($openingCash + $roomSalesCash + $otherCashReceipts - $totalExpenses - $totalMovements, 2);
        $actualCash      = $shift->ended_at ? (float) $shift->closing_cash : null;
        $variance        = $actualCash !== null ? round($actualCash - $expectedCash, 2) : null;

        // Denomination tables (opening and closing)
        $openingDenominations  = $shift->opening_denominations;
        $closingDenominations  = $shift->closing_denominations;

        $totalDpCash = (float) $bookings->sum(fn($b) => (float) collect($b->dp_methods ?? [])->get('cash', 0));
        $totalDpDigital = (float) $bookings->sum(fn($b) => collect($b->dp_methods ?? [])->except('cash')->sum());

        return Inertia::render('Reports/RoomBookingsLedgerPrint', [
            'shift'               => $shift,
            'bookings'            => $bookings,
            'stay_collections'    => $stayCollections,
            'date_printed'        => now()->format('n/j/Y, h:i:s A'),
            // Page 2 data
            'cash_tally' => [
                'opening_cash'          => $openingCash,
                'room_sales_cash'       => $roomSalesCash,
                'other_cash_receipts'   => $otherCashReceipts,
                'total_cash_available'  => round($openingCash + $roomSalesCash + $otherCashReceipts, 2),
                'incomes'               => $incomes,
                'expenses'              => $roomExpenses,
                'cash_movements'        => $cashMovements,
                'total_expenses'        => $totalExpenses,
                'total_movements'       => $totalMovements,
                'expected_cash'         => $expectedCash,
                'actual_cash'           => $actualCash,
                'variance'              => $variance,
                'opening_denominations' => $openingDenominations,
                'closing_denominations' => $closingDenominations,
            ],
            'minibar' => [
                'pos_sales' => $minibarPosSales,
                'stay_charges' => $minibarStayCharges,
                'low_stock' => $lowStock,
                'cash_tally' => [
                    'opening_cash' => $minibarOpeningCash,
                    'sales_cash' => $minibarSalesCash,
                    'other_cash_receipts' => $minibarOtherCashReceipts,
                    'total_cash_available' => round($minibarOpeningCash + $minibarSalesCash + $minibarOtherCashReceipts, 2),
                    'expenses' => $minibarExpenses,
                    'cash_movements' => $minibarCashMovements,
                    'incomes' => $minibarIncomes,
                    'total_expenses' => $minibarTotalExpenses,
                    'total_movements' => $minibarTotalMovements,
                    'expected_cash' => $minibarExpectedCash,
                    'actual_cash' => $minibarActualCash,
                    'variance' => $minibarVariance,
                    'closing_denominations' => $shift->closing_denominations_minibar,
                ],
            ],
            // Page 1 footer totals
            'totals' => [
                'total_room_sales'    => $totalRoomSales,
                'cash_collection'     => $roomSalesCash + $totalDpCash,
                'digital_payment'     => $digitalTotal + $totalDpDigital,
                'outstanding_balance' => $outstandingBalance,
            ],
        ]);
    }

    public function storeCashMovement($id, Request $request)
    {
        $shift = ShiftSession::findOrFail($id);
        $this->authorizeCashMovement($shift, $request);

        $validated = $request->validate([
            'movement_type' => 'required|in:cashier_transfer,withdrawal',
            'cash_drawer' => 'required|in:room,minibar',
            'amount' => 'required|numeric|min:0.01|max:9999999.99',
            'description' => 'required|string|max:500',
            'moved_at' => 'nullable|date',
        ]);

        $movement = CashMovement::create([
            'shift_session_id' => $shift->id,
            'movement_type' => $validated['movement_type'],
            'cash_drawer' => $validated['cash_drawer'],
            'amount' => $validated['amount'],
            'description' => trim($validated['description']),
            'moved_at' => $validated['moved_at'] ?? now(),
            'recorded_by' => $request->user()->id,
        ]);

        BookingService::auditLog(
            $request->user()->id,
            'CASH_MOVEMENT_RECORDED',
            'cash_movements',
            $movement->id,
            null,
            $movement->only(['movement_type', 'cash_drawer', 'amount', 'description', 'moved_at']),
            'Daily cash report movement recorded.'
        );

        return back()->with('success', 'Cash movement recorded in the daily cash report.');
    }

    public function destroyCashMovement($id, CashMovement $cashMovement, Request $request)
    {
        $shift = ShiftSession::findOrFail($id);
        $this->authorizeCashMovement($shift, $request);
        abort_unless($cashMovement->shift_session_id === $shift->id, 404);

        $oldValue = $cashMovement->only(['movement_type', 'cash_drawer', 'amount', 'description', 'moved_at']);
        $cashMovement->delete();
        BookingService::auditLog(
            $request->user()->id,
            'CASH_MOVEMENT_DELETED',
            'cash_movements',
            $cashMovement->id,
            $oldValue,
            null,
            'Daily cash report movement removed.'
        );

        return back()->with('success', 'Cash movement removed.');
    }

    private function authorizeCashMovement(ShiftSession $shift, Request $request): void
    {
        abort_if($shift->ended_at !== null, 422, 'Cash movements can only be edited before the shift is closed.');
        abort_unless(
            $request->user()->role === 'admin' || $shift->user_id === $request->user()->id,
            403,
            'Only the assigned front-desk staff or an administrator may edit this daily cash report.'
        );
    }

    public function getShiftSalesSummary($userId, $start, $end): array
    {
        $transactions = Transaction::where('processed_by', $userId)
            ->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment', 'pos_sale'])
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $roomsCash = 0.00;
        $minibarCash = 0.00;
        $roomsGcash = 0.00;
        $minibarGcash = 0.00;

        foreach ($transactions as $t) {
            if ($t->transaction_type === 'pos_sale') {
                $minibarCash += (float)$t->cash_amount;
                $minibarGcash += (float)$t->gcash_amount;
            } elseif ($t->transaction_type === 'check_out') {
                $minibarTotal = (float)\App\Models\InventoryUsage::where('transaction_id', $t->id)->sum('total_price');
                if ($t->amount > 0) {
                    $ratio = min(1.0, $minibarTotal / $t->amount);
                    $mCash = (float)$t->cash_amount * $ratio;
                    $mGcash = (float)$t->gcash_amount * $ratio;

                    $minibarCash += $mCash;
                    $minibarGcash += $mGcash;

                    $roomsCash += ((float)$t->cash_amount - $mCash);
                    $roomsGcash += ((float)$t->gcash_amount - $mGcash);
                }
            } else {
                $roomsCash += (float)$t->cash_amount;
                $roomsGcash += (float)$t->gcash_amount;
            }
        }

        $incomes = \App\Models\Income::where('recorded_by', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $expenses = \App\Models\Expense::where('recorded_by', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        foreach ($incomes as $inc) {
            if ($inc->cash_drawer === 'minibar') {
                $minibarCash += (float)$inc->amount;
            } else {
                $roomsCash += (float)$inc->amount;
            }
        }

        foreach ($expenses as $exp) {
            if ($exp->cash_drawer === 'minibar') {
                $minibarCash -= (float)$exp->amount;
            } else {
                $roomsCash -= (float)$exp->amount;
            }
        }

        return [
            'txn_count' => $transactions->count(),
            'total_collected' => round((float)$transactions->sum('amount'), 2),
            'cash' => round((float)$transactions->sum('cash_amount'), 2),
            'gcash' => round((float)$transactions->sum('gcash_amount'), 2),
            'card' => round((float)$transactions->where('payment_method', 'card')->sum('amount'), 2),
            'bank_transfer' => round((float)$transactions->where('payment_method', 'bank_transfer')->sum('amount'), 2),
            'split_total' => round((float)$transactions->where('payment_method', 'split')->sum('amount'), 2),
            'checkin_sales' => round((float)$transactions->where('transaction_type', 'check_in')->sum('amount'), 2),
            'checkout_sales' => round((float)$transactions->where('transaction_type', 'check_out')->sum('amount'), 2),
            'extension_sales' => round((float)$transactions->where('transaction_type', 'extension')->sum('amount'), 2),
            'adjustment_sales' => round((float)$transactions->where('transaction_type', 'adjustment')->sum('amount'), 2),
            'possale_sales' => round((float)$transactions->where('transaction_type', 'pos_sale')->sum('amount'), 2),
            'rooms_cash' => round($roomsCash, 2),
            'minibar_cash' => round($minibarCash, 2),
            'rooms_gcash' => round($roomsGcash, 2),
            'minibar_gcash' => round($minibarGcash, 2),
        ];
    }

    private function sanitizeDenominations(?array $denominations): ?array
    {
        if ($denominations === null) {
            return null;
        }

        $sanitized = [];
        foreach (self::DENOMINATION_VALUES as $key => $value) {
            $sanitized[$key] = max(0, (int) ($denominations[$key] ?? 0));
        }

        return $sanitized;
    }

    private function calculateDenominationTotal(?array $denominations): float
    {
        if ($denominations === null) {
            return 0.00;
        }

        $totalInCentavos = 0;
        foreach (self::DENOMINATION_VALUES as $key => $value) {
            $quantity = max(0, (int) ($denominations[$key] ?? 0));
            $totalInCentavos += (int) round($value * 100) * $quantity;
        }

        return round($totalInCentavos / 100, 2);
    }

    private function appendBookingPaymentSummaries($bookings, int $userId, $start, $end): array
    {
        $methodKeys = [
            'cash',
            'gcash',
            'bank_transfer',
            'card',
            'maya',
            'other_ewallet',
            'other',
        ];
        $summary = array_fill_keys($methodKeys, 0.00);
        $summary['total_received'] = 0.00;
        $summary['refunds'] = 0.00;
        $summary['net_collections'] = 0.00;

        $bookingIds = $bookings->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (empty($bookingIds)) {
            return $summary;
        }

        $bookingCollections = [];
        $bookingRefunds = [];
        $bookingMethods = [];
        $bookingReferences = [];

        $ledgerRows = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('payment_components as pc', 'pc.payment_id', '=', 'p.id')
            ->whereIn('pa.booking_id', $bookingIds)
            ->where('p.status', 'verified')
            ->where('p.recorded_by', $userId)
            ->whereBetween('p.received_at', [$start, $end])
            ->selectRaw("
                pa.booking_id,
                pc.payment_method_code,
                COALESCE(NULLIF(pc.reference_number, ''), NULLIF(p.reference_number, '')) AS reference_number,
                SUM(CASE
                    WHEN p.payment_type NOT IN ('refund', 'reversal')
                    THEN pc.amount * (pa.allocated_amount / NULLIF(p.amount, 0))
                    ELSE 0
                END) AS received_amount,
                SUM(CASE
                    WHEN p.payment_type IN ('refund', 'reversal')
                    THEN pc.amount * (pa.allocated_amount / NULLIF(p.amount, 0))
                    ELSE 0
                END) AS refund_amount
            ")
            ->groupBy(
                'pa.booking_id',
                'pc.payment_method_code',
                'pc.reference_number',
                'p.reference_number'
            )
            ->get();

        foreach ($ledgerRows as $row) {
            $bookingId = (int) $row->booking_id;
            $method = $this->normalizeCollectionMethod((string) $row->payment_method_code);
            $received = round((float) $row->received_amount, 2);
            $refund = round((float) $row->refund_amount, 2);

            $bookingCollections[$bookingId] = ($bookingCollections[$bookingId] ?? 0) + $received;
            $bookingRefunds[$bookingId] = ($bookingRefunds[$bookingId] ?? 0) + $refund;
            $bookingMethods[$bookingId][$method] = ($bookingMethods[$bookingId][$method] ?? 0) + $received;
            $reference = trim((string) ($row->reference_number ?? ''));
            if ($received > 0 && $method !== 'cash' && $reference !== '') {
                $bookingReferences[$bookingId][$method][] = $reference;
            }
            $summary[$method] += $received;
            $summary['refunds'] += $refund;
        }

        // Compatibility for receipts created before the payment ledger migration.
        // Ledger-backed transactions are excluded here to prevent double counting.
        $legacyTransactions = Transaction::whereIn('booking_id', $bookingIds)
            ->where('processed_by', $userId)
            ->whereNull('payment_id')
            // Room-billed POS/minibar items are charges, not room payments.
            // They belong only in the Minibar & POS report section.
            ->where('transaction_type', '!=', 'pos_sale')
            ->whereNotIn('payment_method', ['na', ''])
            ->whereBetween('created_at', [$start, $end])
            ->get();

        foreach ($legacyTransactions as $transaction) {
            $bookingId = (int) $transaction->booking_id;
            $components = [
                'cash' => (float) $transaction->cash_amount,
                'gcash' => (float) $transaction->gcash_amount,
            ];

            $bankMethod = in_array($transaction->payment_method, ['card', 'bank_transfer'], true)
                ? $transaction->payment_method
                : 'other';
            $components[$bankMethod] = ($components[$bankMethod] ?? 0) + (float) $transaction->bank_amount;

            if (array_sum(array_map('abs', $components)) < 0.01 && abs((float) $transaction->amount) >= 0.01) {
                $fallbackMethod = $this->normalizeCollectionMethod((string) $transaction->payment_method);
                $components[$fallbackMethod] = ($components[$fallbackMethod] ?? 0) + (float) $transaction->amount;
            }

            foreach ($components as $method => $amount) {
                if (abs($amount) < 0.01) {
                    continue;
                }

                $method = $this->normalizeCollectionMethod($method);
                if ($amount > 0) {
                    $bookingCollections[$bookingId] = ($bookingCollections[$bookingId] ?? 0) + $amount;
                    $bookingMethods[$bookingId][$method] = ($bookingMethods[$bookingId][$method] ?? 0) + $amount;
                    $reference = match ($method) {
                        'gcash' => trim((string) $transaction->gcash_ref),
                        'bank_transfer', 'card' => trim((string) $transaction->bank_ref),
                        default => '',
                    };
                    if ($method !== 'cash' && $reference !== '') {
                        $bookingReferences[$bookingId][$method][] = $reference;
                    }
                    $summary[$method] += $amount;
                } else {
                    $refund = abs($amount);
                    $bookingRefunds[$bookingId] = ($bookingRefunds[$bookingId] ?? 0) + $refund;
                    $summary['refunds'] += $refund;
                }
            }
        }

        // --- Fetch Downpayments (Previous Payments) ---
        $dpRows = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('payment_components as pc', 'pc.payment_id', '=', 'p.id')
            ->whereIn('pa.booking_id', $bookingIds)
            ->where('p.status', 'verified')
            ->where('p.received_at', '<', $start)
            ->selectRaw("
                pa.booking_id,
                pc.payment_method_code,
                COALESCE(NULLIF(pc.reference_number, ''), NULLIF(p.reference_number, '')) AS reference_number,
                SUM(CASE WHEN p.payment_type NOT IN ('refund', 'reversal') THEN pc.amount * (pa.allocated_amount / NULLIF(p.amount, 0)) ELSE 0 END) AS received_amount
            ")
            ->groupBy('pa.booking_id', 'pc.payment_method_code', 'pc.reference_number', 'p.reference_number')
            ->get();

        $dpCollections = [];
        $dpMethods = [];
        $dpReferences = [];
        foreach ($dpRows as $row) {
            $bookingId = (int) $row->booking_id;
            $method = $this->normalizeCollectionMethod((string) $row->payment_method_code);
            $received = round((float) $row->received_amount, 2);
            if ($received > 0) {
                $dpCollections[$bookingId] = ($dpCollections[$bookingId] ?? 0) + $received;
                $dpMethods[$bookingId][$method] = ($dpMethods[$bookingId][$method] ?? 0) + $received;
                $reference = trim((string) ($row->reference_number ?? ''));
                if ($method !== 'cash' && $reference !== '') {
                    $dpReferences[$bookingId][$method][] = $reference;
                }
            }
        }

        $legacyDpTransactions = Transaction::whereIn('booking_id', $bookingIds)
            ->whereNull('payment_id')
            ->where('transaction_type', '!=', 'pos_sale')
            ->whereNotIn('payment_method', ['na', ''])
            ->where('created_at', '<', $start)
            ->get();

        foreach ($legacyDpTransactions as $transaction) {
            $bookingId = (int) $transaction->booking_id;
            $components = [
                'cash' => (float) $transaction->cash_amount,
                'gcash' => (float) $transaction->gcash_amount,
            ];
            $bankMethod = in_array($transaction->payment_method, ['card', 'bank_transfer'], true) ? $transaction->payment_method : 'other';
            $components[$bankMethod] = ($components[$bankMethod] ?? 0) + (float) $transaction->bank_amount;

            if (array_sum(array_map('abs', $components)) < 0.01 && abs((float) $transaction->amount) >= 0.01) {
                $fallbackMethod = $this->normalizeCollectionMethod((string) $transaction->payment_method);
                $components[$fallbackMethod] = ($components[$fallbackMethod] ?? 0) + (float) $transaction->amount;
            }

            foreach ($components as $method => $amount) {
                if (abs($amount) < 0.01 || $amount <= 0) continue;
                $method = $this->normalizeCollectionMethod($method);
                $dpCollections[$bookingId] = ($dpCollections[$bookingId] ?? 0) + $amount;
                $dpMethods[$bookingId][$method] = ($dpMethods[$bookingId][$method] ?? 0) + $amount;
                $reference = match ($method) {
                    'gcash' => trim((string) $transaction->gcash_ref),
                    'bank_transfer', 'card' => trim((string) $transaction->bank_ref),
                    default => '',
                };
                if ($method !== 'cash' && $reference !== '') {
                    $dpReferences[$bookingId][$method][] = $reference;
                }
            }
        }
        // ----------------------------------------------

        $pendingByBooking = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('pa.booking_id', $bookingIds)
            ->where('p.status', 'pending')
            ->selectRaw('pa.booking_id, SUM(pa.allocated_amount) AS pending_amount')
            ->groupBy('pa.booking_id')
            ->pluck('pending_amount', 'booking_id');

        foreach ($bookings as $booking) {
            $bookingId = (int) $booking->id;
            $paid = round((float) $booking->amount_paid, 2);
            $balance = round(max(0, (float) $booking->total_amount - $paid), 2);
            $received = round((float) ($bookingCollections[$bookingId] ?? 0), 2);
            $refund = round((float) ($bookingRefunds[$bookingId] ?? 0), 2);
            $pending = round((float) ($pendingByBooking[$bookingId] ?? 0), 2);

            $booking->setAttribute('paid_amount', $paid);
            $booking->setAttribute('balance_amount', $balance);
            $booking->setAttribute('shift_collection_amount', $received);
            $booking->setAttribute('shift_refund_amount', $refund);
            $booking->setAttribute('pending_payment_amount', $pending);
            $booking->setAttribute(
                'report_payment_status',
                $pending > 0 ? 'pending_verification' : $booking->payment_status
            );
            $booking->setAttribute(
                'shift_collection_methods',
                collect($bookingMethods[$bookingId] ?? [])
                    ->filter(fn ($amount) => $amount > 0)
                    ->map(fn ($amount) => round((float) $amount, 2))
                    ->all()
            );
            $booking->setAttribute(
                'shift_collection_references',
                collect($bookingReferences[$bookingId] ?? [])
                    ->map(fn ($references) => collect($references)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all())
                    ->all()
            );
            $booking->setAttribute('dp_amount', round((float) ($dpCollections[$bookingId] ?? 0), 2));
            $booking->setAttribute(
                'dp_methods',
                collect($dpMethods[$bookingId] ?? [])
                    ->filter(fn ($amount) => $amount > 0)
                    ->map(fn ($amount) => round((float) $amount, 2))
                    ->all()
            );
            $booking->setAttribute(
                'dp_references',
                collect($dpReferences[$bookingId] ?? [])
                    ->map(fn ($references) => collect($references)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all())
                    ->all()
            );
            $booking->setAttribute(
                'shift_extra_charges',
                $this->extractShiftExtraCharges($booking, $userId, $start, $end)
            );
        }

        foreach ($methodKeys as $method) {
            $summary[$method] = round((float) $summary[$method], 2);
            $summary['total_received'] += $summary[$method];
        }
        $summary['total_received'] = round($summary['total_received'], 2);
        $summary['refunds'] = round($summary['refunds'], 2);
        $summary['net_collections'] = round($summary['total_received'] - $summary['refunds'], 2);

        return $summary;
    }

    private function extractShiftExtraCharges($booking, int $userId, $start, $end): array
    {
        return $booking->transactions
            ->filter(function ($transaction) use ($userId, $start, $end) {
                return in_array($transaction->transaction_type, ['check_out', 'adjustment'], true)
                    && (int) $transaction->processed_by === $userId
                    && $transaction->created_at
                    && $transaction->created_at->betweenIncluded($start, $end);
            })
            ->flatMap(function ($transaction) {
                preg_match_all(
                    '/Extra Charges \((.*?)\)\s*=\s*[^\d-]*([\d,]+(?:\.\d+)?)/iu',
                    (string) $transaction->description,
                    $matches,
                    PREG_SET_ORDER
                );

                $isSeparatePayment = str_contains((string) $transaction->description, 'Separate checkout charge');

                return collect($matches)->map(function ($match) use ($transaction, $isSeparatePayment) {
                    $description = trim((string) ($match[1] ?? ''));

                    return [
                        'description' => $description !== '' ? $description : 'Unspecified extra charge',
                        'amount' => round((float) str_replace(',', '', $match[2] ?? '0'), 2),
                        'payment_method' => $isSeparatePayment ? $transaction->payment_method : null,
                        'payment_reference' => $isSeparatePayment ? $transaction->gcash_ref : null,
                    ];
                });
            })
            ->filter(fn ($charge) => $charge['amount'] > 0)
            ->values()
            ->all();
    }

    private function normalizeCollectionMethod(string $method): string
    {
        return in_array($method, [
            'cash',
            'gcash',
            'bank_transfer',
            'card',
            'maya',
            'other_ewallet',
        ], true) ? $method : 'other';
    }
}
