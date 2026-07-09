<?php

namespace App\Http\Controllers;

use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Models\InventoryUsage;
use App\Models\InventoryItem;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use DB;

class ShiftController extends Controller
{
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

            $expectedDrawerCash = $activeShift->opening_cash + $salesStats['rooms_cash'] + $incomesSum - $expensesSum;
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
            'opening_cash_minibar' => 'required|numeric|min:0',
            'opening_denominations_minibar' => 'nullable|array',
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

        $shift = ShiftSession::create([
            'user_id' => $user->id,
            'shift_code' => $request->shift_code,
            'opening_cash' => $request->opening_cash,
            'opening_denominations' => $request->opening_denominations,
            'opening_cash_minibar' => $request->opening_cash_minibar,
            'opening_denominations_minibar' => $request->opening_denominations_minibar,
            'started_at' => now(),
            'notes' => $request->notes,
        ]);

        \Illuminate\Support\Facades\Cache::forget("active_shift_{$user->id}");

        BookingService::auditLog($user->id, 'SHIFT_START', 'shift_sessions', $shift->id, null, $request->shift_code, 'Shift started. Rooms opening cash: ' . $request->opening_cash . ', Minibar opening cash: ' . $request->opening_cash_minibar);

        return redirect()->route('shifts.index')->with('success', 'Shift started successfully: ' . ucfirst($request->shift_code) . ' Shift.');
    }

    public function end(Request $request)
    {
        $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'closing_denominations' => 'nullable|array',
            'closing_cash_minibar' => 'required|numeric|min:0',
            'closing_denominations_minibar' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (!$activeShift) {
            return back()->with('error', 'No active shift found to end.');
        }

        $activeShift->ended_at = now();
        $activeShift->closing_cash = $request->closing_cash;
        $activeShift->closing_denominations = $request->closing_denominations;
        $activeShift->closing_cash_minibar = $request->closing_cash_minibar;
        $activeShift->closing_denominations_minibar = $request->closing_denominations_minibar;
        if ($request->notes) {
            $activeShift->notes = trim($activeShift->notes . "\nClosing Notes: " . $request->notes);
        }
        $activeShift->save();

        \Illuminate\Support\Facades\Cache::forget("active_shift_{$user->id}");

        BookingService::auditLog($user->id, 'SHIFT_END', 'shift_sessions', $activeShift->id, null, null, 'Shift ended. Rooms closing cash: ' . $request->closing_cash . ', Minibar closing cash: ' . $request->closing_cash_minibar);

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

        $expenses = \App\Models\Expense::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $incomes = \App\Models\Income::where('recorded_by', $shiftUserId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $expensesSum = (float)$expenses->sum('amount');
        $incomesSum = (float)$incomes->sum('amount');
        
        $expectedDrawerCash = $shift->opening_cash + $sales['rooms_cash'] + $incomesSum - $expensesSum;
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
        $transactions = Transaction::with(['booking', 'booking.room'])
            ->where('processed_by', $shiftUserId)
            ->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment', 'pos_sale'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'desc')
            ->get();

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
            ]
        ]);
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
}
