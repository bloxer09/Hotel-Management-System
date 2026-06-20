<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Booking;
use App\Models\InventoryUsage;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403, 'Unauthorized access to financial reports.');
        }

        $dateFrom = $request->input('from', date('Y-m-d'));
        $dateTo   = $request->input('to',   date('Y-m-d'));

        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $dateFrom;
        if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }

        $startCarbon = Carbon::parse($dateFrom)->startOfDay();
        $endCarbon   = Carbon::parse($dateTo)->endOfDay();

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

        // By cashier (who performed checkout)
        $byCashier = DB::table('bookings as b')
            ->join('users as u', 'b.checked_out_by', '=', 'u.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->where('b.status', 'checked_out')
            ->selectRaw("
                u.full_name, u.username, u.role,
                COUNT(b.id) as txn_count,
                COALESCE(SUM(b.amount_paid),0) as total_collected,
                COALESCE(SUM(b.cash_amount),0) as cash,
                COALESCE(SUM(b.gcash_amount),0) as gcash,
                COALESCE(SUM(CASE WHEN b.payment_method='split' THEN b.amount_paid ELSE 0 END),0) as split_total
            ")
            ->groupBy('b.checked_out_by', 'u.full_name', 'u.username', 'u.role')
            ->orderByDesc('total_collected')
            ->get();

        // By room type
        $byRoomType = DB::table('bookings as b')
            ->join('rooms as r', 'b.room_id', '=', 'r.id')
            ->join('room_types as rt', 'r.room_type_id', '=', 'rt.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->selectRaw("rt.type_name, COUNT(b.id) as cnt, COALESCE(SUM(b.amount_paid),0) as revenue")
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
            ->selectRaw("
                b.id, b.booking_ref, b.guest_name, b.booking_type, b.check_in, b.check_out, b.expected_check_out,
                b.base_amount, b.peak_surcharge, b.discount_type, b.discount_amount,
                b.extension_fee, b.late_checkout_fee, b.total_amount, b.amount_paid,
                b.payment_method, b.cash_amount, b.gcash_amount, b.gcash_ref,
                b.status, b.notes, b.is_peak,
                r.room_number, rt.type_name,
                u.full_name as cashier_name
            ")
            ->orderByDesc('b.check_in')
            ->get();

        // Occupancy board live status counts
        $roomsCount = Room::count();
        $vacant     = Room::where('status', 'vacant')->count();
        $occupied   = Room::where('status', 'occupied')->count();
        $cleaning   = Room::where('status', 'cleaning')->count();
        $ooo        = Room::where('status', 'out_of_order')->count();

        // Advanced Lodging Rooms vs Inventory Products Revenue Reconciliations
        $bookingsInventoryRevenue = (float)\App\Models\InventoryUsage::whereNotNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $walkinInventoryRevenue = (float)\App\Models\InventoryUsage::whereNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $productRevenue = $bookingsInventoryRevenue + $walkinInventoryRevenue;
        $roomRevenue = max(0.00, (float)$summary->total_revenue - $bookingsInventoryRevenue);
        $unifiedTotalRevenue = $roomRevenue + $productRevenue;

        // Merge unifiedTotalRevenue into summary so total_revenue has the corrected figure
        $summaryArray = (array) $summary;
        $summaryArray['total_revenue'] = $unifiedTotalRevenue;

        return Inertia::render('Reports/Index', [
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'summary'       => $summaryArray,
            'byCashier'     => $byCashier,
            'byRoomType'    => $byRoomType,
            'transactions'  => $transactions,
            'occupancy'     => compact('roomsCount', 'vacant', 'occupied', 'cleaning', 'ooo'),
            'productRevenue' => $productRevenue,
            'roomRevenue'   => $roomRevenue,
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $dateFrom = $request->input('from', date('Y-m-d'));
        $dateTo   = $request->input('to',   date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $dateFrom;
        if ($dateFrom > $dateTo) { $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp; }

        $summary = DB::table('bookings')
            ->whereBetween(DB::raw('DATE(check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->selectRaw("COUNT(*) as total_bookings,COALESCE(SUM(amount_paid),0) as total_revenue,COALESCE(SUM(cash_amount),0) as total_cash,COALESCE(SUM(gcash_amount),0) as total_gcash,COALESCE(SUM(discount_amount),0) as total_discount")
            ->first();

        $transactions = DB::table('bookings as b')
            ->join('rooms as r', 'b.room_id', '=', 'r.id')
            ->join('room_types as rt', 'r.room_type_id', '=', 'rt.id')
            ->leftJoin('users as u', 'b.checked_out_by', '=', 'u.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->selectRaw("b.booking_ref,b.guest_name,r.room_number,rt.type_name,b.check_in,b.check_out,b.booking_type,b.base_amount,b.peak_surcharge,b.discount_type,b.discount_amount,b.extension_fee,b.late_checkout_fee,b.total_amount,b.amount_paid,b.payment_method,b.cash_amount,b.gcash_amount,b.gcash_ref,u.full_name as cashier_name,b.status,b.notes")
            ->orderByDesc('b.check_in')
            ->get();

        $byCashier = DB::table('bookings as b')
            ->join('users as u', 'b.checked_out_by', '=', 'u.id')
            ->whereBetween(DB::raw('DATE(b.check_in)'), [$dateFrom, $dateTo])
            ->where('b.status', 'checked_out')
            ->selectRaw("u.full_name,u.username,u.role,COUNT(b.id) as txn_count,COALESCE(SUM(b.cash_amount),0) as cash,COALESCE(SUM(b.gcash_amount),0) as gcash,COALESCE(SUM(CASE WHEN b.payment_method='split' THEN b.amount_paid ELSE 0 END),0) as split_total,COALESCE(SUM(b.amount_paid),0) as total_collected")
            ->groupBy('b.checked_out_by', 'u.full_name', 'u.username', 'u.role')
            ->get();

        $bookingsInventoryRevenue = (float)\App\Models\InventoryUsage::whereNotNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $walkinInventoryRevenue = (float)\App\Models\InventoryUsage::whereNull('booking_id')
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo])
            ->sum('total_price');

        $productRevenue = $bookingsInventoryRevenue + $walkinInventoryRevenue;
        $roomRevenue = max(0.00, (float)$summary->total_revenue - $bookingsInventoryRevenue);
        $unifiedTotalRevenue = $roomRevenue + $productRevenue;

        $filename = "sales_report_{$dateFrom}_to_{$dateTo}.csv";
        $headers = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($summary, $transactions, $byCashier, $dateFrom, $dateTo, $user, $roomRevenue, $productRevenue, $unifiedTotalRevenue) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

            fputcsv($out, ['Hotel Management System — Sales Report']);
            fputcsv($out, ['Period:', "{$dateFrom} to {$dateTo}"]);
            fputcsv($out, ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name]);
            fputcsv($out, []);

            fputcsv($out, ['=== SUMMARY ===']);
            fputcsv($out, ['Total Bookings', $summary->total_bookings]);
            fputcsv($out, ['Rooms & Lodging Revenue', $roomRevenue]);
            fputcsv($out, ['Inventory & Products Revenue', $productRevenue]);
            fputcsv($out, ['Total Revenue', $unifiedTotalRevenue]);
            fputcsv($out, ['Total Cash', $summary->total_cash]);
            fputcsv($out, ['Total GCash', $summary->total_gcash]);
            fputcsv($out, ['Discounts Given', '-' . $summary->total_discount]);
            fputcsv($out, []);

            fputcsv($out, ['=== TRANSACTION DETAILS ===']);
            fputcsv($out, ['Receipt/Ref#','Guest Name','Room','Room Type','Check-In','Check-Out','Booking Type','Base Amount','Peak Surcharge','Discount Type','Discount Amt','Extension Fee','Late Checkout Fee','Total Amount','Amount Paid','Payment Method','Cash Amt','GCash Amt','GCash Ref','Cashier','Status','Notes']);
            foreach ($transactions as $t) {
                fputcsv($out, [$t->booking_ref,$t->guest_name,$t->room_number,$t->type_name,$t->check_in,$t->check_out ?? '',$t->booking_type,$t->base_amount,$t->peak_surcharge,$t->discount_type ?? '',$t->discount_amount,$t->extension_fee,$t->late_checkout_fee,$t->total_amount,$t->amount_paid,strtoupper($t->payment_method ?? ''),$t->cash_amount,$t->gcash_amount,$t->gcash_ref ?? '',$t->cashier_name ?? '',$t->status,$t->notes ?? '']);
            }
            fputcsv($out, []);

            fputcsv($out, ['=== CASHIER REMITTANCE ===']);
            fputcsv($out, ['Staff Name','Username','Role','Transactions','Cash','GCash','Split','Total']);
            foreach ($byCashier as $c) {
                fputcsv($out, [$c->full_name,$c->username,$c->role,$c->txn_count,$c->cash,$c->gcash,$c->split_total,$c->total_collected]);
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function analytics(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'Unauthorized access to analytics.');
        }

        $monthStr = $request->input('month', Carbon::today()->format('Y-m'));
        $selectedRoomId = $request->input('room_id');
        
        try {
            $month = Carbon::parse($monthStr . '-01');
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
            ->where(function($q) use ($monthStart) {
                $q->whereNull('check_out')
                  ->orWhere('check_out', '>=', $monthStart);
            });
        
        if ($selectedRoom) {
            $bookingsQuery->where('room_id', $selectedRoom->id);
        }
        $bookings = $bookingsQuery->get();

        // Get maintenance tickets open during this month (filtered by selected room if applicable)
        $ticketsQuery = \App\Models\MaintenanceTicket::where('created_at', '<=', $monthEnd)
            ->where(function($q) use ($monthStart) {
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
                    if (!$occupied && !$reserved) {
                        foreach ($tickets as $ticket) {
                            $created = Carbon::parse($ticket->created_at);
                            $resolved = $ticket->resolved_at ? Carbon::parse($ticket->resolved_at) : null;

                            if ($created->lt($dayEnd) && (!$resolved || $resolved->gt($dayStart))) {
                                $ooo = 1;
                                $ticketTitle = $ticket->title;
                                break;
                            }
                        }
                    }

                    $vacant = (!$occupied && !$reserved && !$ooo) ? 1 : 0;

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

                        if ($created->lt($dayEnd) && (!$resolved || $resolved->gt($dayStart))) {
                            if (!in_array($ticket->room_id, $occupiedRoomIds) && !in_array($ticket->room_id, $reservedRoomIds)) {
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
            'selectedRoomId' => $selectedRoomId ? (int)$selectedRoomId : null,
        ];

        if ($request->wantsJson()) {
            return response()->json($responseData);
        }

        return Inertia::render('Reports/Analytics', $responseData);
    }
}
