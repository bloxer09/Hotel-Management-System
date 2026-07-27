<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, PaymentService $payments)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'payer_name' => 'required|string|max:150',
            'payer_contact' => 'nullable|string|max:50',
            'payment_method_code' => 'required|in:cash,gcash,bank_transfer,card,maya,other_ewallet,other,split',
            'reference_number' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0.01',
            'payment_type' => 'required|in:deposit,partial,full,final',
            'remarks' => 'nullable|string|max:1000',
            'components' => 'nullable|array',
            'components.*.payment_method_code' => 'required_with:components|in:cash,gcash,bank_transfer,card,maya,other_ewallet,other',
            'components.*.amount' => 'required_with:components|numeric|min:0.01',
            'components.*.reference_number' => 'nullable|string|max:100',
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);
        $amount = round((float) $validated['amount'], 2);
        $status = $validated['payment_method_code'] === 'cash' ? 'verified' : 'pending';
        if ($validated['payment_method_code'] !== 'cash' && blank($validated['reference_number'] ?? null)) {
            throw ValidationException::withMessages([
                'reference_number' => 'A reference number is required for non-cash payments.',
            ]);
        }
        $outstanding = round(max(0, (float) $booking->total_amount - (float) $booking->amount_paid), 2);
        if ($amount > $outstanding + 0.01) {
            throw ValidationException::withMessages([
                'amount' => "Payment exceeds the outstanding balance of {$outstanding}.",
            ]);
        }
        if (in_array($validated['payment_type'], ['full', 'final'], true) && abs($amount - $outstanding) > 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Full/final payment must exactly match the outstanding balance.',
            ]);
        }

        try {
            $payment = $payments->record([
                'payer_name' => $validated['payer_name'],
                'payer_contact' => $validated['payer_contact'] ?? null,
                'payment_method_code' => $validated['payment_method_code'],
                'reference_number' => $validated['reference_number'] ?? null,
                'amount' => $amount,
                'payment_type' => $validated['payment_type'],
                'status' => $status,
                'recorded_by' => $request->user()->id,
                'remarks' => $validated['remarks'] ?? null,
            ], [$booking->id => $amount], $validated['components'] ?? [], [
                'transaction_type' => 'adjustment',
                'description' => "Additional payment for {$booking->booking_ref}",
            ]);

            return back()->with(
                'success',
                "Payment {$payment->receipt_number} recorded as {$payment->status}."
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function verify(Payment $payment, Request $request, PaymentService $payments)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'front_desk'], true), 403);
        $validated = $request->validate(['reference_number' => 'nullable|string|max:100']);

        try {
            $payments->verify($payment, $request->user()->id, $validated['reference_number'] ?? null);

            return back()->with('success', "Payment {$payment->receipt_number} verified.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Payment $payment, Request $request, PaymentService $payments)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'front_desk'], true), 403);
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $payments->reject($payment, $request->user()->id, $validated['reason']);

            return back()->with('success', "Payment {$payment->receipt_number} rejected.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function refund(Payment $payment, Request $request, PaymentService $payments)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'front_desk'], true), 403);
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'payment_method_code' => 'nullable|in:cash,gcash,bank_transfer,card,maya,other_ewallet,other',
            'reference_number' => 'nullable|string|max:100',
        ]);

        try {
            $refund = $payments->refund(
                $payment,
                (float) $validated['amount'],
                $request->user()->id,
                $validated['reason'],
                $validated['payment_method_code'] ?? null,
                $validated['reference_number'] ?? null
            );

            return back()->with('success', "Refund {$refund->receipt_number} recorded as {$refund->status}.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
