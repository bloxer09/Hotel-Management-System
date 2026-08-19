import React, { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import BaseModal from '@/Components/UI/BaseModal';
import Button from '@/Components/UI/Button';
import { formatUtcToManila } from '@/Utils/datetime';

export const formatPaymentMoney = (value) => `₱${Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

export const paymentMethodLabel = (value) => String(value || '')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

export const staffName = (user) => user?.full_name || user?.name || '—';

export const paymentAmount = (payment) => Number(
    payment?.pivot?.allocated_amount ?? payment?.amount ?? 0
);

export const pendingPaymentTotal = (payments = []) => payments
    .filter((payment) => payment.status === 'pending')
    .reduce((sum, payment) => sum + paymentAmount(payment), 0);

export const canManagePayments = (role) => ['admin', 'front_desk'].includes(role);

export const paymentStatusMeta = (status) => {
    if (status === 'verified') {
        return { label: 'VERIFIED', className: 'text-emerald-400 bg-emerald-950/40 border-emerald-500/30' };
    }
    if (status === 'rejected') {
        return { label: 'REJECTED', className: 'text-rose-400 bg-rose-950/40 border-rose-500/30' };
    }
    if (status === 'pending') {
        return { label: 'PENDING VERIFICATION', className: 'text-amber-300 bg-amber-950/40 border-amber-500/30' };
    }
    return {
        label: String(status || 'UNKNOWN').replaceAll('_', ' ').toUpperCase(),
        className: 'text-slate-300 bg-slate-800 border-slate-600',
    };
};

export const rejectReasonFromRemarks = (remarks) => {
    const match = String(remarks || '').match(/Rejected:\s*([\s\S]+)$/i);
    return match ? match[1].trim() : '';
};

function requiresReference(payment) {
    const method = payment?.payment_method_code || payment?.payment_method;
    return Boolean(payment) && method !== 'cash';
}

export function PaymentVerificationModals({
    verifyPayment,
    rejectPayment,
    booking = null,
    onClose,
    onSuccess,
    zIndex = 'z-[100000]',
}) {
    const [referenceNumber, setReferenceNumber] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const [processing, setProcessing] = useState(false);

    const mode = verifyPayment ? 'verify' : (rejectPayment ? 'reject' : null);

    useEffect(() => {
        setReferenceNumber(verifyPayment?.reference_number || '');
        setReason('');
        setError('');
        setProcessing(false);
    }, [verifyPayment, rejectPayment]);

    const close = () => {
        if (processing) return;
        setError('');
        onClose?.();
    };

    const submitVerify = (event) => {
        event.preventDefault();
        if (!verifyPayment) return;
        const reference = referenceNumber.trim();
        if (requiresReference(verifyPayment) && !reference) {
            setError('A payment reference is required before verification.');
            return;
        }
        setProcessing(true);
        setError('');
        router.post(route('payments.verify', verifyPayment.id), {
            reference_number: reference || null,
        }, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) {
                    setProcessing(false);
                    setError(page.props.flash.error);
                    return;
                }
                setProcessing(false);
                onClose?.();
                onSuccess?.('verify', verifyPayment);
            },
            onError: (errors) => {
                setProcessing(false);
                setError(errors.reference_number || errors.error || 'Unable to verify this payment.');
            },
            onFinish: () => setProcessing(false),
        });
    };

    const submitReject = (event) => {
        event.preventDefault();
        if (!rejectPayment) return;
        const trimmed = reason.trim();
        if (!trimmed) {
            setError('A rejection reason is required.');
            return;
        }
        setProcessing(true);
        setError('');
        router.post(route('payments.reject', rejectPayment.id), {
            reason: trimmed,
        }, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) {
                    setProcessing(false);
                    setError(page.props.flash.error);
                    return;
                }
                setProcessing(false);
                onClose?.();
                onSuccess?.('reject', rejectPayment);
            },
            onError: (errors) => {
                setProcessing(false);
                setError(errors.reason || errors.error || 'Unable to reject this payment.');
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <BaseModal
                isOpen={mode === 'verify'}
                onClose={close}
                title="Verify Digital Payment"
                subtitle="Confirm this pending payment. Accounting updates only after verification."
                zIndex={zIndex}
                footer={(
                    <>
                        <Button type="button" variant="secondary" size="sm" onClick={close} disabled={processing}>Cancel</Button>
                        <Button
                            type="submit"
                            form="verify-digital-payment-form"
                            variant="emerald"
                            size="sm"
                            isLoading={processing}
                            disabled={processing || (requiresReference(verifyPayment) && !referenceNumber.trim())}
                        >
                            Confirm Verification
                        </Button>
                    </>
                )}
            >
                {verifyPayment && (
                    <form id="verify-digital-payment-form" onSubmit={submitVerify} className="space-y-3 text-xs">
                        <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div>
                                <dt className="text-slate-500 uppercase tracking-wider text-[10px] font-bold">Booking</dt>
                                <dd className="font-mono text-slate-100">{booking?.booking_ref || verifyPayment.booking_refs || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500 uppercase tracking-wider text-[10px] font-bold">Guest</dt>
                                <dd className="text-slate-100">{booking?.guest_name || verifyPayment.guest_names || verifyPayment.payer_name || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500 uppercase tracking-wider text-[10px] font-bold">Amount</dt>
                                <dd className="font-mono font-bold text-emerald-400">{formatPaymentMoney(paymentAmount(verifyPayment))}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500 uppercase tracking-wider text-[10px] font-bold">Method</dt>
                                <dd className="text-slate-100">{paymentMethodLabel(verifyPayment.payment_method_code || verifyPayment.payment_method)}</dd>
                            </div>
                        </dl>
                        {verifyPayment.payment_method_code === 'split' && (
                            <p className="rounded-lg border border-amber-500/20 bg-amber-950/20 px-3 py-2 text-[10px] text-amber-200">
                                This is one split payment. Cash and digital components stay pending together until verified.
                            </p>
                        )}
                        <label className="block space-y-1 text-slate-400">
                            Reference
                            <input
                                value={referenceNumber}
                                onChange={(event) => setReferenceNumber(event.target.value)}
                                required={requiresReference(verifyPayment)}
                                className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 font-mono text-slate-100"
                                placeholder="Transaction / approval reference"
                            />
                        </label>
                        {error && <p className="text-rose-400">{error}</p>}
                    </form>
                )}
            </BaseModal>

            <BaseModal
                isOpen={mode === 'reject'}
                onClose={close}
                title="Reject Payment"
                subtitle="Applies only to a pending payment. Verified payments cannot be unverified here."
                zIndex={zIndex}
                footer={(
                    <>
                        <Button type="button" variant="secondary" size="sm" onClick={close} disabled={processing}>Cancel</Button>
                        <Button
                            type="submit"
                            form="reject-digital-payment-form"
                            variant="danger"
                            size="sm"
                            isLoading={processing}
                            disabled={processing || !reason.trim()}
                        >
                            Reject Payment
                        </Button>
                    </>
                )}
            >
                {rejectPayment && (
                    <form id="reject-digital-payment-form" onSubmit={submitReject} className="space-y-3 text-xs">
                        <p className="text-slate-200">
                            {paymentMethodLabel(rejectPayment.payment_method_code || rejectPayment.payment_method)}{' '}
                            {formatPaymentMoney(paymentAmount(rejectPayment))}
                        </p>
                        <p className="font-mono text-slate-400">
                            Reference: {rejectPayment.reference_number || '—'}
                        </p>
                        <p className="rounded-lg border border-rose-500/20 bg-rose-950/20 px-3 py-2 text-[10px] text-rose-200">
                            Rejecting a pending payment does not reduce the booking balance and does not create a collection.
                        </p>
                        <label className="block space-y-1 text-slate-400">
                            Reason
                            <textarea
                                required
                                rows="3"
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                className="w-full rounded-xl border border-[#334155] bg-[#0f172a] p-3 text-slate-100"
                                placeholder="Why this pending payment is being rejected"
                            />
                        </label>
                        {error && <p className="text-rose-400">{error}</p>}
                    </form>
                )}
            </BaseModal>
        </>
    );
}

export function PaymentBillingSummary({ booking, className = '' }) {
    const verifiedPaid = Number(booking?.amount_paid || 0);
    const pending = Number(booking?.pending_payment_amount ?? pendingPaymentTotal(booking?.payments || []));
    const outstanding = Number(
        booking?.outstanding_verified_balance
        ?? Math.max(0, Number(booking?.total_amount || 0) - verifiedPaid)
    );

    return (
        <div className={`space-y-2 text-xs ${className}`}>
            <div className="flex justify-between">
                <span className="text-slate-400">Booking Total</span>
                <span className="font-mono text-slate-100 font-bold">{formatPaymentMoney(booking?.total_amount)}</span>
            </div>
            <div className="flex justify-between">
                <span className="text-slate-400">Verified Payments</span>
                <span className="font-mono text-emerald-400 font-bold">{formatPaymentMoney(verifiedPaid)}</span>
            </div>
            <div className="flex justify-between">
                <span className="text-slate-400">Pending Verification</span>
                <span className="font-mono text-amber-300 font-bold">{formatPaymentMoney(pending)}</span>
            </div>
            <div className="flex justify-between border-t border-[#334155] pt-2">
                <span className="text-slate-300 font-medium">Outstanding Verified Balance</span>
                <span className="font-mono text-rose-300 font-bold">{formatPaymentMoney(outstanding)}</span>
            </div>
            {pending > 0 && (
                <p className="text-[10px] text-amber-300">
                    Pending amounts are not counted as paid until verified.
                </p>
            )}
        </div>
    );
}

export default function PaymentLedgerPanel({
    booking,
    payments = null,
    compact = false,
    onSuccess,
}) {
    const { auth } = usePage().props;
    const canVerify = canManagePayments(auth?.user?.role);
    const rows = Array.isArray(payments) ? payments : (booking?.payments || []);
    const [verifyPayment, setVerifyPayment] = useState(null);
    const [rejectPayment, setRejectPayment] = useState(null);

    return (
        <div className="space-y-3">
            <PaymentBillingSummary booking={booking} />

            {rows.length === 0 ? (
                <p className="py-3 text-center text-xs text-slate-500">No payment ledger records yet.</p>
            ) : rows.map((payment) => {
                const status = paymentStatusMeta(payment.status);
                const amount = paymentAmount(payment);
                const isPending = payment.status === 'pending';
                const isSplit = (payment.payment_method_code || payment.payment_method) === 'split';

                return (
                    <div
                        key={payment.id}
                        className={`rounded-xl border p-3 ${isPending ? 'border-amber-500/30 bg-amber-950/10' : 'border-[#334155] bg-[#0f172a]/50'}`}
                    >
                        <div className={`flex flex-col gap-3 ${compact ? 'text-[11px]' : 'text-xs'} sm:flex-row sm:items-start sm:justify-between`}>
                            <dl className="grid flex-1 grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
                                <div>
                                    <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Method</dt>
                                    <dd className="text-slate-100">{paymentMethodLabel(payment.payment_method_code || payment.payment_method)}</dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Amount</dt>
                                    <dd className="font-mono font-bold text-slate-100">{formatPaymentMoney(amount)}</dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Receipt</dt>
                                    <dd className="font-mono text-brand-400">{payment.receipt_number || '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Reference</dt>
                                    <dd className="font-mono text-slate-200">{payment.reference_number || '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Recorded By</dt>
                                    <dd className="text-slate-200">{staffName(payment.recorder) !== '—' ? staffName(payment.recorder) : (payment.recorded_by || '—')}</dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Received At</dt>
                                    <dd className="font-mono text-slate-300">{formatUtcToManila(payment.received_at)}</dd>
                                </div>
                                {payment.status === 'verified' && (
                                    <>
                                        <div>
                                            <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Verified By</dt>
                                            <dd className="text-slate-200">{staffName(payment.verifier) !== '—' ? staffName(payment.verifier) : (payment.verified_by || '—')}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Verified At</dt>
                                            <dd className="font-mono text-slate-300">{formatUtcToManila(payment.verified_at)}</dd>
                                        </div>
                                    </>
                                )}
                                {payment.status === 'rejected' && (
                                    <>
                                        <div>
                                            <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Reviewed By</dt>
                                            <dd className="text-slate-200">{staffName(payment.verifier) !== '—' ? staffName(payment.verifier) : (payment.verified_by || '—')}</dd>
                                        </div>
                                        <div className="sm:col-span-2">
                                            <dt className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Reason</dt>
                                            <dd className="text-rose-200">{rejectReasonFromRemarks(payment.remarks) || payment.remarks || '—'}</dd>
                                        </div>
                                    </>
                                )}
                            </dl>
                            <div className="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                                <span className={`rounded-lg border px-2 py-1 text-[10px] font-bold ${status.className}`}>
                                    {status.label}
                                </span>
                                {isSplit && isPending && (
                                    <span className="text-[10px] text-amber-200">Split payment pending as one record</span>
                                )}
                                {canVerify && isPending && (
                                    <div className="flex gap-2">
                                        <Button type="button" variant="emerald" size="sm" onClick={() => setVerifyPayment(payment)}>
                                            Verify Payment
                                        </Button>
                                        <Button type="button" variant="danger" size="sm" onClick={() => setRejectPayment(payment)}>
                                            Reject
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })}

            <PaymentVerificationModals
                verifyPayment={verifyPayment}
                rejectPayment={rejectPayment}
                booking={booking}
                onClose={() => {
                    setVerifyPayment(null);
                    setRejectPayment(null);
                }}
                onSuccess={onSuccess}
            />
        </div>
    );
}
