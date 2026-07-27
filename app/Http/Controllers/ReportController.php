<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\InventoryUsage;
use App\Models\MaintenanceTicket;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Transaction;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Shuchkin\SimpleXLSXGen;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403, 'Unauthorized access to financial reports.');
        }

        $dateFrom = $request->input('from', date('Y-m-d'));
        $dateTo = $request->input('to', date('Y-m-d'));

        // Validate date formats
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = date('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = $dateFrom;
        }
        if ($dateFrom > $dateTo) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }

        $startCarbon = Carbon::parse($dateFrom)->startOfDay();
        $endCarbon = Carbon::parse($dateTo)->endOfDay();

        // Summary from bookings table (matching reference)
        $summary = DB::table('bookings')
            ->whereBetween(DB::raw('DATE(check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->selectRaw("
                COUNT(*) as total_bookings,
                COALESCE(SUM(CASE WHEN status='checked_out' THEN 1 ELSE 0 END),0) as checked_out,
                COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END),0) as still_active,
                COALESCE(SUM(amount_paid),0) as total_revenue,
                COALESCE(SUM(cash_amount),0) as total_cash,
                COALESCE(SUM(gcash_amount),0) as total_gcash,
                COALESCE(SUM(CASE WHEN payment_method='split' THEN amount_paid ELSE 0 END),0) as total_split,
                COALESCE(SUM(peak_surcharge),0) as total_surcharge,
                COALESCE(SUM(discount_amount),0) as total_discount,
                COALESCE(SUM(extension_fee),0) as total_extension,
                COALESCE(SUM(late_checkout_fee),0) as total_late,
                COALESCE(SUM(base_amount),0) as total_base
            ")
            ->first();

        // Cashier collections are grouped by the staff who recorded the payment,
        // using received_at instead of the stay/check-in date.
        $byCashier = DB::table('payments as p')
            ->join('payment_components as pc', 'pc.payment_id', '=', 'p.id')
            ->join('users as u', 'p.recorded_by', '=', 'u.id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$startCarbon, $endCarbon])
            ->selectRaw("
                u.full_name, u.username, u.role,
                COUNT(DISTINCT p.id) as txn_count,
                COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pc.amount ELSE -pc.amount END),0) as total_collected,
                COALESCE(SUM(CASE WHEN pc.payment_method_code='cash' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount WHEN pc.payment_method_code='cash' THEN -pc.amount ELSE 0 END),0) as cash,
                COALESCE(SUM(CASE WHEN pc.payment_method_code='gcash' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount WHEN pc.payment_method_code='gcash' THEN -pc.amount ELSE 0 END),0) as gcash,
                COALESCE(SUM(CASE WHEN p.payment_method_code='split' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount WHEN p.payment_method_code='split' THEN -pc.amount ELSE 0 END),0) as split_total
            ")
            ->groupBy('p.recorded_by', 'u.full_name', 'u.username', 'u.role')
            ->orderByDesc('total_collected')
            ->get();

        // By room type
        $byRoomType = DB::table('bookings as b')
            ->join('rooms as r', 'b.room_id', '=', 'r.id')
            ->join('room_types as rt', 'r.room_type_id', '=', 'rt.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->selectRaw('rt.type_name, COUNT(b.id) as cnt, COALESCE(SUM(b.base_amount + b.peak_surcharge + b.extra_pax_charges - b.discount_amount + b.extension_fee + b.late_checkout_fee),0) as revenue')
            ->groupBy('rt.id', 'rt.type_name')
            ->orderByDesc('revenue')
            ->get();

        // Full transaction detail list
        $transactions = DB::table('bookings as b')
            ->join('rooms as r', 'b.room_id', '=', 'r.id')
            ->join('room_types as rt', 'r.room_type_id', '=', 'rt.id')
            ->leftJoin('users as u', 'b.checked_out_by', '=', 'u.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->selectRaw('
                b.id, b.booking_ref, b.guest_name, b.booking_type, b.check_in, b.check_out, b.expected_check_out,
                b.base_amount, b.peak_surcharge, b.discount_type, b.discount_amount,
                b.extension_fee, b.late_checkout_fee, b.total_amount, b.amount_paid,
                b.payment_method, b.cash_amount, b.gcash_amount, b.gcash_ref,
                b.status, b.notes, b.is_peak,
                r.room_number, rt.type_name,
                u.full_name as cashier_name
            ')
            ->orderByDesc('b.check_in')
            ->get();

        // Occupancy board live status counts
        $roomsCount = Room::count();
        $vacant = Room::where('status', 'vacant')->count();
        $occupied = Room::where('status', 'occupied')->count();
        $cleaning = Room::where('status', 'cleaning')->count();
        $ooo = Room::where('status', 'out_of_order')->count();

        // Advanced Lodging Rooms vs Inventory Products Revenue Reconciliations
        $bookingsInventoryRevenue = (float) InventoryUsage::whereNotNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $walkinInventoryRevenue = (float) InventoryUsage::whereNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $productRevenue = $bookingsInventoryRevenue + $walkinInventoryRevenue;

        // Collection date and revenue-recognition date are intentionally separate.
        // The payment ledger is the source of truth for money received; stay dates
        // remain the source for operational/recognized lodging revenue.
        $ledgerPayments = Payment::where('status', 'verified')
            ->whereBetween('received_at', [$startCarbon, $endCarbon]);
        $collectionsReceived = (float) (clone $ledgerPayments)
            ->whereNotIn('payment_type', ['refund', 'reversal'])
            ->sum('amount');
        $refundsIssued = (float) (clone $ledgerPayments)
            ->whereIn('payment_type', ['refund', 'reversal'])
            ->sum('amount');

        $advanceDepositRow = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('bookings as b', 'b.id', '=', 'pa.booking_id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$startCarbon, $endCarbon])
            ->where('b.check_in', '>', now())
            ->selectRaw("
                COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pa.allocated_amount ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN p.payment_type IN ('refund','reversal') THEN pa.allocated_amount ELSE 0 END),0) AS net
            ")
            ->first();
        $advanceDeposits = (float) ($advanceDepositRow->net ?? 0);

        $recognizedStayRevenueRow = Booking::whereIn('status', ['active', 'checked_out'])
            ->whereBetween('check_in', [$startCarbon, $endCarbon])
            ->selectRaw('COALESCE(SUM(base_amount + peak_surcharge + extra_pax_charges - discount_amount + extension_fee + late_checkout_fee), 0) AS total')
            ->first();
        $recognizedStayRevenue = (float) ($recognizedStayRevenueRow->total ?? 0);
        $collectionChannels = DB::table('payment_components as pc')
            ->join('payments as p', 'p.id', '=', 'pc.payment_id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$startCarbon, $endCarbon])
            ->groupBy('pc.payment_method_code')
            ->selectRaw("pc.payment_method_code, COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pc.amount ELSE -pc.amount END),0) AS net")
            ->pluck('net', 'payment_method_code');

        // Main revenue is stay-period lodging revenue plus product revenue. Money
        // received is shown only in the separate ledger collection metrics.
        $summaryArray = (array) $summary;
        $summaryArray['total_revenue'] = round($recognizedStayRevenue + $productRevenue, 2);
        $summaryArray['total_cash'] = round((float) ($collectionChannels['cash'] ?? 0), 2);
        $summaryArray['total_gcash'] = round((float) ($collectionChannels['gcash'] ?? 0), 2);

        return Inertia::render('Reports/Index', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'summary' => $summaryArray,
            'byCashier' => $byCashier,
            'byRoomType' => $byRoomType,
            'transactions' => $transactions,
            'occupancy' => compact('roomsCount', 'vacant', 'occupied', 'cleaning', 'ooo'),
            'productRevenue' => $productRevenue,
            'roomRevenue' => $recognizedStayRevenue,
            'ledgerSummary' => [
                'collections_received' => round($collectionsReceived, 2),
                'refunds_issued' => round($refundsIssued, 2),
                'net_collections' => round($collectionsReceived - $refundsIssued, 2),
                'advance_deposits' => round($advanceDeposits, 2),
                'recognized_stay_revenue' => round($recognizedStayRevenue, 2),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $dateFrom = $request->input('from', date('Y-m-d'));
        $dateTo = $request->input('to', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = date('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = $dateFrom;
        }
        if ($dateFrom > $dateTo) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }

        $summary = DB::table('bookings')
            ->whereBetween(DB::raw('DATE(check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->selectRaw('COUNT(*) as total_bookings,COALESCE(SUM(amount_paid),0) as total_revenue,COALESCE(SUM(cash_amount),0) as total_cash,COALESCE(SUM(gcash_amount),0) as total_gcash,COALESCE(SUM(discount_amount),0) as total_discount')
            ->first();

        $transactions = DB::table('bookings as b')
            ->join('rooms as r', 'b.room_id', '=', 'r.id')
            ->join('room_types as rt', 'r.room_type_id', '=', 'rt.id')
            ->leftJoin('users as u', 'b.checked_out_by', '=', 'u.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->selectRaw('b.booking_ref,b.guest_name,r.room_number,rt.type_name,b.check_in,b.check_out,b.booking_type,b.base_amount,b.peak_surcharge,b.discount_type,b.discount_amount,b.extension_fee,b.late_checkout_fee,b.total_amount,b.amount_paid,b.payment_method,b.cash_amount,b.gcash_amount,b.gcash_ref,u.full_name as cashier_name,b.status,b.notes')
            ->orderByDesc('b.check_in')
            ->get();

        $exportStart = Carbon::parse($dateFrom)->startOfDay();
        $exportEnd = Carbon::parse($dateTo)->endOfDay();
        $byCashier = DB::table('payments as p')
            ->join('payment_components as pc', 'pc.payment_id', '=', 'p.id')
            ->join('users as u', 'p.recorded_by', '=', 'u.id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$exportStart, $exportEnd])
            ->selectRaw("
                u.full_name,u.username,u.role,COUNT(DISTINCT p.id) as txn_count,
                COALESCE(SUM(CASE WHEN pc.payment_method_code='cash' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount WHEN pc.payment_method_code='cash' THEN -pc.amount ELSE 0 END),0) as cash,
                COALESCE(SUM(CASE WHEN pc.payment_method_code='gcash' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount WHEN pc.payment_method_code='gcash' THEN -pc.amount ELSE 0 END),0) as gcash,
                COALESCE(SUM(CASE WHEN p.payment_method_code='split' AND p.payment_type NOT IN ('refund','reversal') THEN pc.amount WHEN p.payment_method_code='split' THEN -pc.amount ELSE 0 END),0) as split_total,
                COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pc.amount ELSE -pc.amount END),0) as total_collected
            ")
            ->groupBy('p.recorded_by', 'u.full_name', 'u.username', 'u.role')
            ->get();

        $bookingsInventoryRevenue = (float) InventoryUsage::whereNotNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $walkinInventoryRevenue = (float) InventoryUsage::whereNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $productRevenue = $bookingsInventoryRevenue + $walkinInventoryRevenue;
        $start = Carbon::parse($dateFrom)->startOfDay();
        $end = Carbon::parse($dateTo)->endOfDay();
        $ledgerPayments = Payment::where('status', 'verified')->whereBetween('received_at', [$start, $end]);
        $collectionsReceived = (float) (clone $ledgerPayments)
            ->whereNotIn('payment_type', ['refund', 'reversal'])->sum('amount');
        $refundsIssued = (float) (clone $ledgerPayments)
            ->whereIn('payment_type', ['refund', 'reversal'])->sum('amount');
        $advanceRow = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('bookings as b', 'b.id', '=', 'pa.booking_id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$start, $end])
            ->where('b.check_in', '>', now())
            ->selectRaw("COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pa.allocated_amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN p.payment_type IN ('refund','reversal') THEN pa.allocated_amount ELSE 0 END),0) AS net")
            ->first();
        $advanceDeposits = (float) ($advanceRow->net ?? 0);
        $revenueRow = Booking::whereIn('status', ['active', 'checked_out'])
            ->whereBetween('check_in', [$start, $end])
            ->selectRaw('COALESCE(SUM(base_amount + peak_surcharge + extra_pax_charges - discount_amount + extension_fee + late_checkout_fee), 0) AS total')
            ->first();
        $roomRevenue = (float) ($revenueRow->total ?? 0);
        $unifiedTotalRevenue = $roomRevenue + $productRevenue;
        $channelTotals = DB::table('payment_components as pc')
            ->join('payments as p', 'p.id', '=', 'pc.payment_id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$start, $end])
            ->groupBy('pc.payment_method_code')
            ->selectRaw("pc.payment_method_code, COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pc.amount ELSE -pc.amount END),0) AS net")
            ->pluck('net', 'payment_method_code');

        $filename = "sales_report_{$dateFrom}_to_{$dateTo}.xlsx";

        $rows = [];

        $rows[] = ['Hotel Management System — Sales Report'];
        $rows[] = ['Period:', "{$dateFrom} to {$dateTo}"];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['=== SUMMARY ==='];
        $rows[] = ['Metric', 'Value'];
        $rows[] = ['Total Bookings', $summary->total_bookings];
        $rows[] = ['Rooms & Lodging Revenue', $roomRevenue];
        $rows[] = ['Inventory & Products Revenue', $productRevenue];
        $rows[] = ['Recognized Revenue', $unifiedTotalRevenue];
        $rows[] = ['Collections Received', $collectionsReceived];
        $rows[] = ['Refunds Issued', -$refundsIssued];
        $rows[] = ['Net Collections', $collectionsReceived - $refundsIssued];
        $rows[] = ['Future-stay Advance Deposits', $advanceDeposits];
        $rows[] = ['Net Cash Collections', (float) ($channelTotals['cash'] ?? 0)];
        $rows[] = ['Net GCash Collections', (float) ($channelTotals['gcash'] ?? 0)];
        $rows[] = ['Discounts Given', '-'.$summary->total_discount];
        $rows[] = [];

        $rows[] = ['=== TRANSACTION DETAILS ==='];
        $rows[] = [
            'Receipt/Ref#', 'Guest Name', 'Room', 'Room Type', 'Check-In', 'Check-Out', 'Booking Type',
            'Base Amount', 'Peak Surcharge', 'Discount Type', 'Discount Amt', 'Extension Fee',
            'Late Checkout Fee', 'Total Amount', 'Amount Paid', 'Payment Method', 'Cash Amt',
            'GCash Amt', 'GCash Ref', 'Cashier', 'Status', 'Notes',
        ];
        foreach ($transactions as $t) {
            $rows[] = [
                $t->booking_ref,
                $t->guest_name,
                $t->room_number,
                $t->type_name,
                $t->check_in,
                $t->check_out ?? '',
                $t->booking_type,
                $t->base_amount,
                $t->peak_surcharge,
                $t->discount_type ?? '',
                $t->discount_amount,
                $t->extension_fee,
                $t->late_checkout_fee,
                $t->total_amount,
                $t->amount_paid,
                strtoupper($t->payment_method ?? ''),
                $t->cash_amount,
                $t->gcash_amount,
                $t->gcash_ref ?? '',
                $t->cashier_name ?? '',
                $t->status,
                $t->notes ?? '',
            ];
        }
        $rows[] = [];

        $rows[] = ['=== CASHIER REMITTANCE ==='];
        $rows[] = ['Staff Name', 'Username', 'Role', 'Transactions', 'Cash', 'GCash', 'Split', 'Total'];
        foreach ($byCashier as $c) {
            $rows[] = [
                $c->full_name,
                $c->username,
                $c->role,
                $c->txn_count,
                $c->cash,
                $c->gcash,
                $c->split_total,
                $c->total_collected,
            ];
        }

        $xlsx = SimpleXLSXGen::fromArray($rows);

        return response()->streamDownload(function () use ($xlsx) {
            echo (string) $xlsx;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function analytics(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'Unauthorized access to analytics.');
        }

        $monthStr = $request->input('month', Carbon::today()->format('Y-m'));
        $selectedRoomId = $request->input('room_id');

        try {
            $month = Carbon::parse($monthStr.'-01');
        } catch (\Exception $e) {
            $month = Carbon::today()->startOfMonth();
            $monthStr = $month->format('Y-m');
        }

        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $rooms = Room::orderBy('room_number', 'asc')->get(['id', 'room_number', 'status']);
        $roomsCount = $rooms->count();
        $selectedRoom = $selectedRoomId ? Room::find($selectedRoomId) : null;

        // Get bookings active during this month (filtered by selected room if applicable)
        $bookingsQuery = Booking::where('status', '!=', 'cancelled')
            ->where('check_in', '<=', $monthEnd)
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('check_out')
                    ->orWhere('check_out', '>=', $monthStart);
            });

        if ($selectedRoom) {
            $bookingsQuery->where('room_id', $selectedRoom->id);
        }
        $bookings = $bookingsQuery->get();

        // Get maintenance tickets open during this month (filtered by selected room if applicable)
        $ticketsQuery = MaintenanceTicket::where('created_at', '<=', $monthEnd)
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>=', $monthStart);
            });

        if ($selectedRoom) {
            $ticketsQuery->where('room_id', $selectedRoom->id);
        }
        $tickets = $ticketsQuery->get();

        $dailyStats = [];
        $daysInMonth = $month->daysInMonth;

        if ($selectedRoom) {
            // Live stats check for today's state
            $todayLiveOccupied = $selectedRoom->status === 'occupied' ? 1 : 0;
            $todayLiveVacant = $selectedRoom->status === 'vacant' ? 1 : 0;
            $todayLiveCleaning = $selectedRoom->status === 'cleaning' ? 1 : 0;
            $todayLiveOoo = $selectedRoom->status === 'out_of_order' ? 1 : 0;
            $todayLiveReserved = 0;

            if ($todayLiveVacant) {
                // If it is vacant today, check if it has a reserved booking starting today
                $hasReservationToday = Booking::where('room_id', $selectedRoom->id)
                    ->where('status', 'reserved')
                    ->whereDate('check_in', Carbon::today())
                    ->exists();
                if ($hasReservationToday) {
                    $todayLiveReserved = 1;
                    $todayLiveVacant = 0;
                }
            }

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day = $month->copy()->day($d);
                $dayStr = $day->format('Y-m-d');
                $isToday = $day->isToday();

                if ($isToday) {
                    $guestName = null;
                    if ($todayLiveOccupied) {
                        $activeBooking = Booking::where('room_id', $selectedRoom->id)
                            ->where('status', 'active')
                            ->first();
                        $guestName = $activeBooking ? $activeBooking->guest_name : 'Occupied';
                    } elseif ($todayLiveReserved) {
                        $resBooking = Booking::where('room_id', $selectedRoom->id)
                            ->where('status', 'reserved')
                            ->whereDate('check_in', Carbon::today())
                            ->first();
                        $guestName = $resBooking ? $resBooking->guest_name : 'Reserved';
                    }

                    $dailyStats[] = [
                        'date' => $dayStr,
                        'day' => $d,
                        'vacant' => $todayLiveVacant,
                        'occupied' => $todayLiveOccupied,
                        'reserved' => $todayLiveReserved,
                        'cleaning' => $todayLiveCleaning,
                        'out_of_order' => $todayLiveOoo,
                        'guest_name' => $guestName,
                        'ticket_title' => null,
                    ];
                } else {
                    $dayStart = $day->copy()->startOfDay();
                    $dayEnd = $day->copy()->endOfDay();

                    // Check if room is occupied or reserved on this specific day
                    $occupied = 0;
                    $reserved = 0;
                    $guestName = null;
                    foreach ($bookings as $booking) {
                        $checkIn = Carbon::parse($booking->check_in);
                        $checkOut = $booking->check_out ? Carbon::parse($booking->check_out) : null;
                        $expected = $booking->expected_check_out ? Carbon::parse($booking->expected_check_out) : null;
                        $effOut = $checkOut ?: $expected ?: now();

                        if ($checkIn->lt($dayEnd) && $effOut->gt($dayStart)) {
                            if ($booking->status === 'reserved') {
                                $reserved = 1;
                            } else {
                                $occupied = 1;
                            }
                            $guestName = $booking->guest_name;
                            break;
                        }
                    }

                    // Check if room is out of order on this specific day
                    $ooo = 0;
                    $ticketTitle = null;
                    if (! $occupied && ! $reserved) {
                        foreach ($tickets as $ticket) {
                            $created = Carbon::parse($ticket->created_at);
                            $resolved = $ticket->resolved_at ? Carbon::parse($ticket->resolved_at) : null;

                            if ($created->lt($dayEnd) && (! $resolved || $resolved->gt($dayStart))) {
                                $ooo = 1;
                                $ticketTitle = $ticket->title;
                                break;
                            }
                        }
                    }

                    $vacant = (! $occupied && ! $reserved && ! $ooo) ? 1 : 0;

                    $dailyStats[] = [
                        'date' => $dayStr,
                        'day' => $d,
                        'vacant' => $vacant,
                        'occupied' => $occupied,
                        'reserved' => $reserved,
                        'cleaning' => 0,
                        'out_of_order' => $ooo,
                        'guest_name' => $guestName,
                        'ticket_title' => $ticketTitle,
                    ];
                }
            }
        } else {
            // Live counts for today (Aggregated All Rooms)
            $liveVacant = Room::where('status', 'vacant')->count();
            $liveOccupied = Room::where('status', 'occupied')->count();
            $liveCleaning = Room::where('status', 'cleaning')->count();
            $liveOoo = Room::where('status', 'out_of_order')->count();
            $liveReserved = Booking::where('status', 'reserved')
                ->whereDate('check_in', Carbon::today())
                ->count();
            $liveVacant = max(0, $liveVacant - $liveReserved);

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $day = $month->copy()->day($d);
                $dayStr = $day->format('Y-m-d');
                $isToday = $day->isToday();

                if ($isToday) {
                    $dailyStats[] = [
                        'date' => $dayStr,
                        'day' => $d,
                        'vacant' => $liveVacant,
                        'occupied' => $liveOccupied,
                        'reserved' => $liveReserved,
                        'cleaning' => $liveCleaning,
                        'out_of_order' => $liveOoo,
                    ];
                } else {
                    $dayStart = $day->copy()->startOfDay();
                    $dayEnd = $day->copy()->endOfDay();

                    $occupied = 0;
                    $reserved = 0;
                    $occupiedRoomIds = [];
                    $reservedRoomIds = [];
                    foreach ($bookings as $booking) {
                        $checkIn = Carbon::parse($booking->check_in);
                        $checkOut = $booking->check_out ? Carbon::parse($booking->check_out) : null;
                        $expected = $booking->expected_check_out ? Carbon::parse($booking->expected_check_out) : null;
                        $effOut = $checkOut ?: $expected ?: now();

                        if ($checkIn->lt($dayEnd) && $effOut->gt($dayStart)) {
                            if ($booking->status === 'reserved') {
                                $reserved++;
                                $reservedRoomIds[] = $booking->room_id;
                            } else {
                                $occupied++;
                                $occupiedRoomIds[] = $booking->room_id;
                            }
                        }
                    }

                    $ooo = 0;
                    foreach ($tickets as $ticket) {
                        $created = Carbon::parse($ticket->created_at);
                        $resolved = $ticket->resolved_at ? Carbon::parse($ticket->resolved_at) : null;

                        if ($created->lt($dayEnd) && (! $resolved || $resolved->gt($dayStart))) {
                            if (! in_array($ticket->room_id, $occupiedRoomIds) && ! in_array($ticket->room_id, $reservedRoomIds)) {
                                $ooo++;
                            }
                        }
                    }

                    $occupied = min($occupied, $roomsCount);
                    $reserved = min($reserved, $roomsCount - $occupied);
                    $ooo = min($ooo, $roomsCount - $occupied - $reserved);
                    $cleaning = 0;

                    $vacant = max(0, $roomsCount - $occupied - $reserved - $ooo);

                    $dailyStats[] = [
                        'date' => $dayStr,
                        'day' => $d,
                        'vacant' => $vacant,
                        'occupied' => $occupied,
                        'reserved' => $reserved,
                        'cleaning' => $cleaning,
                        'out_of_order' => $ooo,
                    ];
                }
            }
        }

        $responseData = [
            'month' => $monthStr,
            'dailyStats' => $dailyStats,
            'roomsCount' => $roomsCount,
            'rooms' => $rooms,
            'selectedRoomId' => $selectedRoomId ? (int) $selectedRoomId : null,
        ];

        if ($request->wantsJson()) {
            return response()->json($responseData);
        }

        return Inertia::render('Reports/Analytics', $responseData);
    }
}
