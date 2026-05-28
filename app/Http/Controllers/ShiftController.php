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
        if ($nowHM >= '23:00' || $nowHM < '08:30') $suggestedShift = 'night';

        // 3. Get last closed shift's closing cash to suggest as opening cash
        $lastShift = ShiftSession::where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->orderBy('id', 'desc')
            ->first();
        $suggestedOpeningCash = $lastShift ? $lastShift->closing_cash : 0.00;

        // 4. Calculate live drawer cash if shift is active
        $liveSummary = null;
        if ($activeShift) {
            $liveStart = $activeShift->started_at;
            $liveEnd = now();

            $salesStats = $this->getShiftSalesSummary($user->id, $liveStart, $liveEnd);
            $expectedDrawerCash = $activeShift->opening_cash + $salesStats['cash'];

            $liveSummary = [
                'sales' => $salesStats,
                'expected_drawer_cash' => $expectedDrawerCash,
                'live_end' => $liveEnd->format('Y-m-d H:i:s'),
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
            'liveSummary' => $liveSummary,
            'recentShifts' => $recentShifts,
        ]);
    }

    public function start(Request $request)
    {
        $request->validate([
            'shift_code' => 'required|in:morning,evening,night',
            'opening_cash' => 'required|numeric|min:0',
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
            'started_at' => now(),
            'notes' => $request->notes,
        ]);

        BookingService::auditLog($user->id, 'SHIFT_START', 'shift_sessions', $shift->id, null, $request->shift_code, 'Shift started with opening cash: ' . $request->opening_cash);

        return redirect()->route('shifts.index')->with('success', 'Shift started successfully: ' . ucfirst($request->shift_code) . ' Shift.');
    }

    public function end(Request $request)
    {
        $request->validate([
            'closing_cash' => 'required|numeric|min:0',
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
        if ($request->notes) {
            $activeShift->notes = trim($activeShift->notes . "\nClosing Notes: " . $request->notes);
        }
        $activeShift->save();

        BookingService::auditLog($user->id, 'SHIFT_END', 'shift_sessions', $activeShift->id, null, null, 'Shift ended with closing cash: ' . $request->closing_cash);

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
        
        $expectedDrawerCash = $shift->opening_cash + $sales['cash'];
        $cashVariance = null;
        if ($shift->ended_at !== null) {
            $cashVariance = round($shift->closing_cash - $expectedDrawerCash, 2);
        }

        // 2. Count bookings checked in during shift
        $checkinCount = Transaction::where('processed_by', $shiftUserId)
            ->where('transaction_type', 'check_in')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // 3. Inventory usages during shift
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

        // 4. Low stock inventory items
        $lowStock = InventoryItem::where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->get();

        // 5. Raw list of transactions
        $transactions = Transaction::with(['booking', 'booking.room'])
            ->where('processed_by', $shiftUserId)
            ->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment'])
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
                'checkins' => $checkinCount,
                'inventory_summary' => $inventorySummary,
                'inventory_items' => $inventoryItems,
                'low_stock' => $lowStock,
                'transactions' => $transactions,
            ]
        ]);
    }

    private function getShiftSalesSummary($userId, $start, $end): array
    {
        $transactions = Transaction::where('processed_by', $userId)
            ->whereIn('transaction_type', ['check_in', 'check_out', 'extension', 'adjustment'])
            ->whereBetween('created_at', [$start, $end])
            ->get();

        return [
            'txn_count' => $transactions->count(),
            'total_collected' => (float)$transactions->sum('amount'),
            'cash' => (float)$transactions->sum('cash_amount'),
            'gcash' => (float)$transactions->sum('gcash_amount'),
            'card' => (float)$transactions->where('payment_method', 'card')->sum('amount'),
            'bank_transfer' => (float)$transactions->where('payment_method', 'bank_transfer')->sum('amount'),
            'split_total' => (float)$transactions->where('payment_method', 'split')->sum('amount'),
            'checkin_sales' => (float)$transactions->where('transaction_type', 'check_in')->sum('amount'),
            'checkout_sales' => (float)$transactions->where('transaction_type', 'check_out')->sum('amount'),
            'extension_sales' => (float)$transactions->where('transaction_type', 'extension')->sum('amount'),
            'adjustment_sales' => (float)$transactions->where('transaction_type', 'adjustment')->sum('amount'),
        ];
    }
}
