<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FrontDeskReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $this->validDate($request->input('from'), now()->format('Y-m-d'));
        $to = $this->validDate($request->input('to'), $from);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $method = $request->input('method');
        $recordedBy = $request->input('recorded_by');

        $collectionQuery = Payment::with([
            'allocations.booking.room.type',
            'components',
            'recorder',
            'verifier',
            'originalPayment',
        ])
            ->where('status', 'verified')
            ->whereBetween('received_at', [$start, $end])
            ->when($method, fn ($q) => $q->whereHas('components', fn ($c) => $c->where('payment_method_code', $method)))
            ->when($recordedBy, fn ($q) => $q->where('recorded_by', $recordedBy))
            ->orderByDesc('received_at');

        $collections = $collectionQuery->get()->map(fn (Payment $payment) => $this->collectionRow($payment));

        $methodTotals = DB::table('payment_components as pc')
            ->join('payments as p', 'p.id', '=', 'pc.payment_id')
            ->where('p.status', 'verified')
            ->whereBetween('p.received_at', [$start, $end])
            ->when($method, fn ($q) => $q->where('pc.payment_method_code', $method))
            ->when($recordedBy, fn ($q) => $q->where('p.recorded_by', $recordedBy))
            ->groupBy('pc.payment_method_code')
            ->selectRaw("
                pc.payment_method_code,
                COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund','reversal') THEN pc.amount ELSE 0 END),0) AS gross,
                COALESCE(SUM(CASE WHEN p.payment_type IN ('refund','reversal') THEN pc.amount ELSE 0 END),0) AS refunds
            ")
            ->get()
            ->map(function ($row) {
                $row->net = round((float) $row->gross - (float) $row->refunds, 2);

                return $row;
            });

        $gross = round((float) $methodTotals->sum('gross'), 2);
        $refunds = round((float) $methodTotals->sum('refunds'), 2);
        $net = round($gross - $refunds, 2);
        $cashNet = round((float) optional($methodTotals->firstWhere('payment_method_code', 'cash'))->net, 2);

        $advanceRows = $this->advanceBookings($request);

        $pending = Payment::with(['allocations.booking.room.type', 'components', 'recorder'])
            ->where('status', 'pending')
            ->whereIn('payment_method_code', ['gcash', 'bank_transfer', 'card', 'maya', 'other_ewallet', 'other', 'split'])
            ->orderBy('received_at')
            ->get()
            ->map(fn (Payment $payment) => $this->collectionRow($payment));

        return Inertia::render('Reports/FrontDesk', [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'method' => $method ?: '',
                'recorded_by' => $recordedBy ?: '',
                'booking_from' => $request->input('booking_from', ''),
                'booking_to' => $request->input('booking_to', ''),
                'check_in_from' => $request->input('check_in_from', ''),
                'check_in_to' => $request->input('check_in_to', ''),
                'booking_status' => $request->input('booking_status', ''),
                'verification_status' => $request->input('verification_status', 'verified'),
            ],
            'collections' => $collections,
            'methodTotals' => $methodTotals,
            'summary' => [
                'gross' => $gross,
                'refunds' => $refunds,
                'net' => $net,
                'cash_expected' => $cashNet,
                'electronic_collections' => round($net - $cashNet, 2),
            ],
            'advanceBookings' => $advanceRows,
            'pendingPayments' => $pending,
            'staff' => User::whereIn('role', ['admin', 'front_desk', 'cashier'])
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'methods' => ['cash', 'gcash', 'bank_transfer', 'card', 'maya', 'other_ewallet', 'other', 'split'],
        ]);
    }

    private function advanceBookings(Request $request)
    {
        $status = $request->input('booking_status');
        $method = $request->input('method');
        $recordedBy = $request->input('recorded_by');
        $verificationStatus = $request->input('verification_status', 'verified');

        return Booking::with([
            'room.type',
            'bookedBy',
            'paymentAllocations.payment.components',
            'paymentAllocations.payment.recorder',
        ])
            ->where('check_in', '>', HotelDateTime::toDatabase())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('booking_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->booking_from))
            ->when($request->filled('booking_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->booking_to))
            ->when($request->filled('check_in_from'), fn ($q) => $q->whereDate('check_in', '>=', $request->check_in_from))
            ->when($request->filled('check_in_to'), fn ($q) => $q->whereDate('check_in', '<=', $request->check_in_to))
            ->whereHas('paymentAllocations.payment', function ($q) use ($verificationStatus, $method, $recordedBy) {
                if ($verificationStatus) {
                    $q->where('status', $verificationStatus);
                }
                if ($method) {
                    $q->whereHas('components', fn ($c) => $c->where('payment_method_code', $method));
                }
                if ($recordedBy) {
                    $q->where('recorded_by', $recordedBy);
                }
            })
            ->orderBy('check_in')
            ->get()
            ->map(function (Booking $booking) use ($verificationStatus, $method, $recordedBy) {
                $matchingAllocations = $booking->paymentAllocations->filter(function ($allocation) use ($verificationStatus, $method, $recordedBy) {
                    $payment = $allocation->payment;
                    if (! $payment || ($verificationStatus && $payment->status !== $verificationStatus)) {
                        return false;
                    }
                    if ($recordedBy && (string) $payment->recorded_by !== (string) $recordedBy) {
                        return false;
                    }

                    return ! $method || $payment->components->contains('payment_method_code', $method);
                });

                // Filters decide which bookings appear, but paid/balance figures
                // always use the booking's complete verified ledger.
                $allocations = $booking->paymentAllocations
                    ->filter(fn ($allocation) => $allocation->payment?->status === 'verified');
                $inflows = $allocations
                    ->reject(fn ($a) => in_array($a->payment->payment_type, ['refund', 'reversal'], true))
                    ->sum('allocated_amount');
                $outflows = $allocations
                    ->filter(fn ($a) => in_array($a->payment->payment_type, ['refund', 'reversal'], true))
                    ->sum('allocated_amount');
                $netPaid = round(max(0, (float) $inflows - (float) $outflows), 2);

                return [
                    'id' => $booking->id,
                    'booking_ref' => $booking->booking_ref,
                    'date_booked' => $booking->created_at,
                    'guest_name' => $booking->guest_name,
                    'booker_name' => $booking->booker_name ?: $booking->guest_name,
                    'booker_contact' => $booking->booker_contact ?: $booking->guest_contact,
                    'check_in' => $booking->check_in,
                    'check_out' => $booking->expected_check_out,
                    'num_nights' => $booking->num_nights,
                    'room_number' => $booking->room?->room_number,
                    'room_type' => $booking->room?->type?->type_name,
                    'total_amount' => (float) $booking->total_amount,
                    'total_paid' => $netPaid,
                    'outstanding_balance' => round(max(0, (float) $booking->total_amount - $netPaid), 2),
                    'payment_methods' => $allocations
                        ->flatMap(fn ($a) => $a->payment->components->pluck('payment_method_code'))
                        ->unique()->values(),
                    'booking_status' => $booking->status,
                    'matching_payment_amount' => round((float) $matchingAllocations->sum('allocated_amount'), 2),
                ];
            })
            ->filter(fn ($row) => $row['total_paid'] > 0 || $row['matching_payment_amount'] > 0)
            ->values();
    }

    private function collectionRow(Payment $payment): array
    {
        $bookings = $payment->allocations->pluck('booking')->filter()->values();
        $first = $bookings->first();

        return [
            'id' => $payment->id,
            'received_at' => $payment->received_at,
            'date_booked' => $bookings->min('created_at'),
            'receipt_number' => $payment->receipt_number,
            'booking_refs' => $bookings->pluck('booking_ref')->join(', '),
            'guest_names' => $bookings->pluck('guest_name')->unique()->join(', '),
            'payer_name' => $payment->payer_name,
            'payer_contact' => $payment->payer_contact,
            'booker_names' => $bookings->map(fn ($b) => $b->booker_name ?: $b->guest_name)->unique()->join(', '),
            'contact_numbers' => $bookings->map(fn ($b) => $b->booker_contact ?: $b->guest_contact)->filter()->unique()->join(', '),
            'check_in' => $first?->check_in,
            'check_out' => $first?->expected_check_out,
            'num_nights' => $first?->num_nights,
            'rooms' => $bookings->map(fn ($b) => trim(($b->room?->type?->type_name ?: '').' '.($b->room?->room_number ?: '')))->join(', '),
            'payment_method' => $payment->payment_method_code,
            'components' => $payment->components,
            'reference_number' => $payment->reference_number,
            'payment_type' => $payment->payment_type,
            'amount' => (float) $payment->amount,
            'total_booking_amount' => round((float) $bookings->sum('total_amount'), 2),
            'total_amount_paid' => round((float) $bookings->sum('amount_paid'), 2),
            'outstanding_balance' => round((float) $bookings->sum(fn ($b) => max(0, (float) $b->total_amount - (float) $b->amount_paid)), 2),
            'booking_statuses' => $bookings->pluck('status')->unique()->join(', '),
            'status' => $payment->status,
            'recorded_by' => $payment->recorder?->full_name,
            'verified_by' => $payment->verifier?->full_name,
            'verified_at' => $payment->verified_at,
            'remarks' => $payment->remarks,
            'original_receipt' => $payment->originalPayment?->receipt_number,
        ];
    }

    private function validDate(?string $value, string $fallback): string
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
    }
}
