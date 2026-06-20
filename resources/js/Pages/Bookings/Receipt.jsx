import React, { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Printer, ArrowLeft, Building, User, Calendar, CreditCard, Clipboard, Award, Crown } from 'lucide-react';

export default function Receipt({ booking, transactions, settings }) {
    const { app_name } = usePage().props;
    const [logoError, setLogoError] = useState(false);

    // Auto print if ?print=1 is present in URL
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('print') === '1') {
            const timer = setTimeout(() => {
                window.print();
            }, 600);
            return () => clearTimeout(timer);
        }
    }, []);

    // Formatting helpers
    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val || 0);
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '—';
        return new Date(dateStr).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
    };

    const balanceDue = Math.max(0, (booking.total_amount || 0) - (booking.amount_paid || 0));

    const vatEnabled = false;
    const vatPercent = settings?.vat_percent || 12;

    const orNumbers = (transactions || [])
        .filter(t => t.formatted_or_number)
        .map(t => t.formatted_or_number);
    const orDisplay = orNumbers.length > 0 ? orNumbers.join(', ') : '';

    // Build receipt itemized charges
    const charges = [
        { label: 'Base Room Charge', amount: booking.base_amount },
    ];
    if (Number(booking.peak_surcharge) > 0) {
        charges.push({ label: 'Peak Date Surcharge', amount: booking.peak_surcharge });
    }
    if (Number(booking.extension_fee) > 0) {
        charges.push({ label: 'Extension Stay Fee', amount: booking.extension_fee });
    }
    if (Number(booking.late_checkout_fee) > 0) {
        const lateLabel = `Late Check-Out Fee ${booking.late_hours ? `(${booking.late_hours} hr/s)` : ''}`;
        charges.push({ label: lateLabel, amount: booking.late_checkout_fee });
    }

    // Determine display stay type
    const isHourly = booking.booking_type === 'short_time' || booking.booking_type === 'hourly';
    const displayStayType = isHourly ? 'Short Time (Hourly)' : 'Overnight Stay';

    return (
        <AuthenticatedLayout>
            <Head title={`Guest Receipt - ${booking.booking_ref}`} />

            <style dangerouslySetInnerHTML={{
                __html: `
                @media print {
                    @page {
                        size: 80mm auto;
                        margin: 0;
                    }
                    body {
                        background: white !important;
                        color: black !important;
                        padding: 0 !important;
                        margin: 0 !important;
                    }
                    .print-thermal-only {
                        display: block !important;
                    }
                    .print-hide-all {
                        display: none !important;
                    }
                }
            `}} />

            {/* Receipt Navigation and Controls (Hidden on Print) */}
            <div className="max-w-4xl mx-auto mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 print-hide-all">
                <div>
                    <h1 className="font-outfit font-extrabold text-2xl tracking-tight text-slate-100 flex items-center gap-2.5">
                        <Printer className="text-brand-400" /> Guest Receipt
                    </h1>
                    <p className="text-slate-400 text-sm font-medium mt-1">
                        Printable receipt for booking <span className="font-mono text-brand-300">{booking.booking_ref}</span>
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Link
                        href={route('bookings.show', booking.id)}
                        className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-800 border border-slate-700/60 hover:bg-slate-700 hover:text-slate-100 font-bold text-sm transition-all"
                    >
                        <ArrowLeft size={16} /> Back to Booking
                    </Link>
                    <button
                        onClick={() => window.print()}
                        className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 font-bold text-sm text-slate-50 shadow-lg shadow-brand-600/30 hover:shadow-brand-600/40 hover:-translate-y-0.5 transition-all"
                    >
                        <Printer size={16} /> Print Receipt
                    </button>
                </div>
            </div>

            {/* Receipt Sheet */}
            <div className="max-w-4xl mx-auto bg-slate-900 border border-slate-800 rounded-3xl shadow-2xl overflow-hidden print-hide-all">
                <div className="p-8 md:p-12">

                    {/* Header: Brand & Invoice Meta */}
                    <div className="flex flex-col md:flex-row justify-between items-start md:items-stretch gap-6 border-b border-slate-800 print:border-slate-300 pb-8 mb-8">
                        <div className="flex items-center gap-4">
                            <div className="h-16 w-16 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center overflow-hidden shrink-0 print:border-slate-300">
                                {!logoError ? (
                                    <img
                                        src="/images/logo.jpg"
                                        alt="Logo"
                                        className="h-full w-full object-cover animate-fade-in"
                                        onError={() => setLogoError(true)}
                                    />
                                ) : (
                                    <Building className="h-9 w-9 text-brand-400 print:text-black" />
                                )}
                            </div>
                            <div>
                                <h2 className="font-outfit font-black text-2xl tracking-wide text-slate-100 print:text-black">
                                    {app_name || 'UPTOWN PENSION HOUSE'}
                                </h2>
                                {/* <p className="text-slate-400 text-xs font-semibold print:text-slate-600 mt-0.5">
                                    Brgy. Mansilingan, Bacolod City, Negros Occidental
                                </p>
                                <p className="text-slate-400 text-xs font-semibold print:text-slate-600">
                                    Tel: +63 (34) 434-1234 • Email: info@uptownpensionhouse.com
                                </p> */}
                            </div>
                        </div>
                        <div className="md:text-right flex flex-col justify-between items-start md:items-end">
                            <div>
                                <span className="text-[10px] uppercase font-bold tracking-widest bg-brand-500/10 border border-brand-500/30 text-brand-400 px-3 py-1 rounded-full print:border-slate-400 print:text-black print:bg-slate-200">
                                    Guest Receipt
                                </span>
                            </div>
                            <div className="mt-4">
                                <div className="font-mono font-bold text-lg text-brand-300 print:text-black">
                                    {orDisplay || `RCP-${booking.booking_ref.replace(/[^A-Z0-9]/gi, '').toUpperCase()}`}
                                </div>
                                {orDisplay && (
                                    <div className="text-[11px] text-slate-400 print:text-slate-600 mt-0.5 font-medium">
                                        OR Number: <span className="font-bold text-brand-400 print:text-black">{orDisplay}</span>
                                    </div>
                                )}
                                <div className="text-[11px] text-slate-400 print:text-slate-600 mt-0.5 font-medium">
                                    Reference: <span className="font-semibold text-slate-200 print:text-black">{booking.booking_ref}</span>
                                </div>
                                <div className="text-[11px] text-slate-400 print:text-slate-600 font-medium">
                                    Issued: {new Date().toLocaleString()}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Stay & Guest Metadata Columns */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8 bg-slate-950/40 p-6 rounded-2xl border border-slate-800/80 print:bg-white print:border-slate-200 print:rounded-none">

                        {/* Guest Profile Details */}
                        <div>
                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold border-b border-slate-800/50 print:border-slate-200 pb-2 mb-3">
                                Guest Info
                            </h3>
                            <div className="space-y-2">
                                <div className="flex gap-2">
                                    <User className="h-4 w-4 text-slate-500 shrink-0 mt-0.5" />
                                    <div>
                                        <div className="text-sm font-bold text-slate-200 print:text-black flex items-center gap-1.5">
                                            {booking.guest_name}
                                            {booking.guest_profile?.is_vip && (
                                                <span className="inline-flex items-center gap-1 text-[9px] bg-amber-500/10 border border-amber-500/30 text-amber-400 px-1.5 py-0.5 rounded font-black print:text-black print:border-black uppercase">
                                                    <Crown size={9} /> VIP
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-[11px] text-slate-400 print:text-slate-600 font-semibold mt-0.5">
                                            Contact: {booking.guest_contact || '—'}
                                        </div>
                                        <div className="text-[11px] text-slate-400 print:text-slate-600 font-medium">
                                            ID Verified: {booking.guest_id_type ? `${booking.guest_id_type} (${booking.guest_id_number || 'No ID'})` : '—'}
                                        </div>
                                        <div className="text-[11px] text-slate-400 print:text-slate-600 font-medium">
                                            Address: {booking.guest_profile?.address || '—'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Stay / Room Specifications */}
                        <div>
                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold border-b border-slate-800/50 print:border-slate-200 pb-2 mb-3">
                                Stay Details
                            </h3>
                            <div className="space-y-2 text-xs">
                                <div className="grid grid-cols-3 py-0.5">
                                    <span className="text-slate-500 font-bold">Room</span>
                                    <span className="col-span-2 text-slate-200 print:text-black font-extrabold">
                                        Room {booking.room?.room_number} — {booking.room?.type?.type_name}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 py-0.5">
                                    <span className="text-slate-500 font-bold">Type</span>
                                    <span className="col-span-2 text-slate-300 print:text-black font-semibold">
                                        {displayStayType} {booking.short_time_hours ? `(${booking.short_time_hours} hrs)` : ''}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 py-0.5">
                                    <span className="text-slate-500 font-bold">Check-In</span>
                                    <span className="col-span-2 text-slate-300 print:text-black font-medium">
                                        {formatDate(booking.check_in)}
                                    </span>
                                </div>
                                <div className="grid grid-cols-3 py-0.5">
                                    <span className="text-slate-500 font-bold">Check-Out</span>
                                    <span className="col-span-2 text-slate-300 print:text-black font-medium">
                                        {formatDate(booking.check_out || booking.expected_check_out)}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Pricing Detail Items Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-8 items-start">

                        {/* Room Charges Table */}
                        <div className="lg:col-span-7">
                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-3">
                                Room Charges
                            </h3>
                            <div className="border border-slate-800 print:border-slate-300 rounded-2xl overflow-hidden print:rounded-none">
                                <table className="w-full text-left text-xs border-collapse table-fixed">
                                    <thead>
                                        <tr className="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase tracking-wider font-bold print:bg-slate-100 print:border-slate-300 print:text-black">
                                            <th className="px-4 py-3.5">Description</th>
                                            <th className="px-4 py-3.5 text-right w-36">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-800 print:divide-slate-200">
                                        {charges.map((c, i) => (
                                            <tr key={i} className="hover:bg-slate-800/10 print:text-black">
                                                <td className="px-4 py-3.5 text-slate-300 print:text-black font-medium">{c.label}</td>
                                                <td className="px-4 py-3.5 text-right font-bold text-slate-100 print:text-black">
                                                    {formatCurrency(c.amount)}
                                                </td>
                                            </tr>
                                        ))}
                                        {Number(booking.discount_amount) > 0 && (
                                            <tr className="print:text-black">
                                                <td className="px-4 py-3.5 text-rose-400 print:text-black font-semibold">
                                                    DiscountsApplied {booking.discount_type ? `(${booking.discount_type.replace(/_/g, ' ').toUpperCase()})` : ''}
                                                </td>
                                                <td className="px-4 py-3.5 text-right font-extrabold text-rose-400 print:text-black">
                                                    - {formatCurrency(booking.discount_amount)}
                                                </td>
                                            </tr>
                                        )}
                                        <tr className="bg-brand-650/10 font-bold border-t border-slate-800 print:bg-slate-100 print:border-slate-300 print:text-black">
                                            <td className="px-4 py-4 text-brand-300 print:text-black font-black uppercase text-xs tracking-wider">
                                                Total Room Charges
                                            </td>
                                            <td className="px-4 py-4 text-right text-brand-300 print:text-black font-black text-sm">
                                                {formatCurrency(booking.total_amount)}
                                            </td>
                                        </tr>
                                        {vatEnabled && (
                                            <>
                                                <tr className="text-slate-400 print:text-slate-650 text-[11px] border-t border-slate-800/40 print:border-slate-200">
                                                    <td className="px-4 py-2 pl-8 font-medium">Vatable Sales (VAT-Inclusive)</td>
                                                    <td className="px-4 py-2 text-right font-semibold text-slate-300 print:text-black">
                                                        {formatCurrency(booking.total_amount / (1 + vatPercent / 100))}
                                                    </td>
                                                </tr>
                                                <tr className="text-slate-400 print:text-slate-650 text-[11px] border-b border-slate-800/40 print:border-slate-200">
                                                    <td className="px-4 py-2 pl-8 font-medium">{vatPercent}% Output VAT</td>
                                                    <td className="px-4 py-2 text-right font-semibold text-slate-300 print:text-black">
                                                        {formatCurrency(booking.total_amount - (booking.total_amount / (1 + vatPercent / 100)))}
                                                    </td>
                                                </tr>
                                            </>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Payment Settlement Ledger Info */}
                        <div className="lg:col-span-5">
                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-3">
                                Payment Details
                            </h3>
                            <div className="p-5 rounded-2xl bg-slate-950 border border-slate-800/80 space-y-3 print:bg-white print:border-slate-300 print:rounded-none">
                                <div className="flex justify-between items-center py-1.5 border-b border-slate-800/40 print:border-slate-200">
                                    <span className="text-xs text-slate-500 font-bold">Total Stay</span>
                                    <span className="text-sm font-extrabold text-slate-300 print:text-black">
                                        {formatCurrency(booking.total_amount)}
                                    </span>
                                </div>
                                {vatEnabled && (
                                    <>
                                        <div className="flex justify-between items-center py-1 border-b border-slate-800/40 print:border-slate-200 text-[11px]">
                                            <span className="text-slate-500 pl-2 font-medium">Vatable Sales</span>
                                            <span className="font-semibold text-slate-400 print:text-black">
                                                {formatCurrency(booking.total_amount / (1 + vatPercent / 100))}
                                            </span>
                                        </div>
                                        <div className="flex justify-between items-center py-1 border-b border-slate-800/40 print:border-slate-200 text-[11px]">
                                            <span className="text-slate-500 pl-2 font-medium">{vatPercent}% Output VAT</span>
                                            <span className="font-semibold text-slate-400 print:text-black">
                                                {formatCurrency(booking.total_amount - (booking.total_amount / (1 + vatPercent / 100)))}
                                            </span>
                                        </div>
                                    </>
                                )}
                                <div className="flex justify-between items-center py-1.5 border-b border-slate-800/40 print:border-slate-200">
                                    <span className="text-xs text-slate-500 font-bold">Amount Paid</span>
                                    <span className="text-sm font-extrabold text-emerald-400 print:text-black">
                                        {formatCurrency(booking.amount_paid)}
                                    </span>
                                </div>
                                <div className="flex justify-between items-center py-1.5 border-b border-slate-800/40 print:border-slate-200">
                                    <span className="text-xs text-slate-500 font-bold">Balance Due</span>
                                    <span className={`text-sm font-extrabold ${balanceDue > 0 ? 'text-rose-400 print:text-black' : 'text-emerald-400 print:text-black'}`}>
                                        {formatCurrency(balanceDue)}
                                    </span>
                                </div>
                                <div className="flex justify-between items-center py-1.5 border-b border-slate-800/40 print:border-slate-200">
                                    <span className="text-xs text-slate-500 font-bold">Payment Method</span>
                                    <span className="text-xs font-black text-slate-200 print:text-black uppercase">
                                        {booking.payment_method === 'split' ? 'SPLIT (CASH + GCASH)' : booking.payment_method}
                                    </span>
                                </div>
                                {Number(booking.cash_amount) > 0 && (
                                    <div className="flex justify-between items-center py-0.5">
                                        <span className="text-[11px] text-slate-500">Cash Paid</span>
                                        <span className="text-xs text-slate-300 print:text-black font-semibold">
                                            {formatCurrency(booking.cash_amount)}
                                        </span>
                                    </div>
                                )}
                                {Number(booking.gcash_amount) > 0 && (
                                    <div className="flex justify-between items-center py-0.5">
                                        <span className="text-[11px] text-slate-500">GCash Paid</span>
                                        <span className="text-xs text-slate-300 print:text-black font-semibold">
                                            {formatCurrency(booking.gcash_amount)}
                                        </span>
                                    </div>
                                )}
                                {booking.gcash_ref && (
                                    <div className="flex justify-between items-center py-0.5">
                                        <span className="text-[11px] text-slate-500 font-bold">GCash Ref</span>
                                        <span className="text-xs font-mono font-bold text-brand-300 print:text-black">
                                            {booking.gcash_ref}
                                        </span>
                                    </div>
                                )}
                                <div className="flex justify-between items-center pt-2 border-t border-slate-800/60 print:border-slate-200">
                                    <span className="text-xs text-slate-500 font-bold">Cashier</span>
                                    <span className="text-xs text-slate-300 print:text-black font-bold">
                                        {booking.checkout_staff?.name || booking.checkin_staff?.name || 'Front Desk Staff'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Payment & Activity Ledger Log Trail */}
                    {transactions && transactions.length > 0 && (
                        <div className="mb-8">
                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-3">
                                Payment History
                            </h3>
                            <div className="border border-slate-800 print:border-slate-300 rounded-2xl overflow-hidden print:rounded-none">
                                <table className="w-full text-left text-xs border-collapse table-fixed">
                                    <thead>
                                        <tr className="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase tracking-wider font-bold print:bg-slate-100 print:border-slate-300 print:text-black">
                                            <th className="px-4 py-3">Date</th>
                                            <th className="px-4 py-3">Type</th>
                                            <th className="px-4 py-3">Description</th>
                                            <th className="px-4 py-3 text-right">Amount Paid</th>
                                            <th className="px-4 py-3">Method</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-800 print:divide-slate-200">
                                        {transactions.map((txn) => (
                                            <tr key={txn.id} className="hover:bg-slate-800/10 print:text-black">
                                                <td className="px-4 py-3.5 text-slate-400 print:text-black font-medium">{formatDate(txn.created_at)}</td>
                                                <td className="px-4 py-3.5">
                                                    <span className="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-slate-800 border border-slate-700/60 text-slate-300 print:border-slate-400 print:bg-slate-200 print:text-black">
                                                        {txn.transaction_type.replace(/_/g, ' ')}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3.5 text-slate-300 print:text-black leading-relaxed font-medium">{txn.description}</td>
                                                <td className="px-4 py-3.5 text-right font-bold text-slate-100 print:text-black">
                                                    {formatCurrency(txn.amount)}
                                                </td>
                                                <td className="px-4 py-3.5 text-slate-300 print:text-black uppercase font-bold tracking-wider">
                                                    {txn.payment_method}
                                                    {txn.formatted_or_number && (
                                                        <div className="text-[10px] font-mono text-brand-400 print:text-black font-extrabold">
                                                            {txn.formatted_or_number}
                                                        </div>
                                                    )}
                                                    {txn.gcash_ref && (
                                                        <div className="text-[9px] font-mono text-slate-500 font-normal normal-case">
                                                            Ref: {txn.gcash_ref}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Booking Folio Notes (If any) */}
                    {booking.notes && (
                        <div className="mt-8 pt-5 border-t border-slate-800 print:border-slate-300 print:text-black">
                            <h4 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-2">
                                Notes
                            </h4>
                            <p className="text-slate-300 print:text-black text-xs leading-relaxed whitespace-pre-line font-medium">
                                {booking.notes}
                            </p>
                        </div>
                    )}

                    {/* Footer Signature Layout Blocks */}
                    <div className="mt-12 pt-8 border-t border-slate-800 print:border-slate-300 flex flex-col sm:flex-row justify-between items-end gap-8">
                        <div className="text-xs text-slate-500 max-w-sm font-medium">
                            <p className="leading-relaxed">
                                Thank you for staying with {app_name || 'Uptown Pension House'}. This receipt details your room charges and payments.
                            </p>
                        </div>
                        <div className="text-right shrink-0">
                            <div className="text-xs text-slate-500 font-bold mb-12">Guest Signature</div>
                            <div className="w-64 border-t border-slate-600 print:border-slate-400 py-1 text-xs font-black text-slate-300 print:text-black">
                                {booking.guest_name}
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {/* Dedicated 80mm POS Thermal Receipt Slip (Hidden on Screen, Visible on Print) */}
            <div className="hidden print-thermal-only font-mono text-[11px] leading-tight p-4 w-[76mm] mx-auto text-black bg-white">
                <div className="text-center font-bold text-sm uppercase mb-1">
                    {app_name || 'UPTOWN PENSION HOUSE'}
                </div>
                {/* <div className="text-center text-[10px] mb-3">
                    Mansilingan, Bacolod City<br />
                    Tel: +63 (34) 434-1234<br />
                    --------------------------------
                </div> */}

                <div className="space-y-1 mb-3 text-[10px]">
                    <div><strong>OR #:</strong> {orDisplay || `RCP-${booking.booking_ref.replace(/[^A-Z0-9]/gi, '').toUpperCase()}`}</div>
                    <div><strong>Ref:</strong> {booking.booking_ref}</div>
                    <div><strong>Date:</strong> {new Date().toLocaleString()}</div>
                    <div><strong>Guest:</strong> {booking.guest_name}</div>
                    <div><strong>Room:</strong> Room {booking.room?.room_number} ({booking.room?.type?.type_name})</div>
                    <div><strong>Stay:</strong> {displayStayType} {booking.short_time_hours ? `(${booking.short_time_hours}h)` : ''}</div>
                    <div><strong>Check-In:</strong> {formatDate(booking.check_in)}</div>
                    <div><strong>Check-Out:</strong> {formatDate(booking.check_out || booking.expected_check_out)}</div>
                </div>

                <div className="border-t border-dashed border-black my-2"></div>
                <div className="text-center font-bold uppercase text-[9px] mb-1">Itemized Room Charges</div>
                <table className="w-full text-[10px] text-left border-collapse table-fixed">
                    <tbody>
                        {charges.map((c, i) => (
                            <tr key={i}>
                                <td>{c.label}</td>
                                <td className="text-right font-bold">{formatCurrency(c.amount)}</td>
                            </tr>
                        ))}
                        {Number(booking.discount_amount) > 0 && (
                            <tr className="font-bold text-red-700">
                                <td>Discount ({booking.discount_type?.toUpperCase()})</td>
                                <td className="text-right font-bold">-{formatCurrency(booking.discount_amount)}</td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {/* Minibar purchases */}
                {booking.inventory_usages && booking.inventory_usages.length > 0 && (
                    <>
                        <div className="border-t border-dashed border-black my-2"></div>
                        <div className="text-center font-bold uppercase text-[9px] mb-1">Minibar / Shop Charges</div>
                        <table className="w-full text-[10px] text-left border-collapse table-fixed">
                            <tbody>
                                {booking.inventory_usages.map((u, i) => (
                                    <tr key={i}>
                                        <td>{u.item?.item_name || 'Minibar Item'} x {u.quantity}</td>
                                        <td className="text-right font-bold">{formatCurrency(u.total_cost || (u.quantity * (u.item?.selling_price || 0)))}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </>
                )}

                <div className="border-t border-dashed border-black my-2"></div>
                <div className="space-y-1 text-[10px]">
                    <div className="flex justify-between font-bold">
                        <span>TOTAL STAY:</span>
                        <span>{formatCurrency(booking.total_amount)}</span>
                    </div>
                    {booking.inventory_usages && booking.inventory_usages.length > 0 && (
                        <div className="flex justify-between font-bold">
                            <span>TOTAL SHOP:</span>
                            <span>
                                {formatCurrency(
                                    booking.inventory_usages.reduce((acc, u) => acc + Number(u.total_cost || (u.quantity * (u.item?.selling_price || 0))), 0)
                                )}
                            </span>
                        </div>
                    )}
                    {vatEnabled && (
                        <>
                            <div className="flex justify-between text-[9px] pl-2">
                                <span>Vatable Sales:</span>
                                <span>{formatCurrency(booking.total_amount / (1 + vatPercent / 100))}</span>
                            </div>
                            <div className="flex justify-between text-[9px] pl-2">
                                <span>Output VAT ({vatPercent}%):</span>
                                <span>{formatCurrency(booking.total_amount - (booking.total_amount / (1 + vatPercent / 100)))}</span>
                            </div>
                        </>
                    )}
                    <div className="border-t border-black my-1"></div>
                    <div className="flex justify-between font-bold text-xs">
                        <span>GRAND TOTAL DUE:</span>
                        <span>
                            {formatCurrency(
                                Number(booking.total_amount) +
                                Number(booking.inventory_usages?.reduce((acc, u) => acc + Number(u.total_cost || (u.quantity * (u.item?.selling_price || 0))), 0) || 0)
                            )}
                        </span>
                    </div>
                    <div className="flex justify-between">
                        <span>Amount Paid:</span>
                        <span className="font-bold">{formatCurrency(booking.amount_paid)}</span>
                    </div>
                    <div className="flex justify-between font-bold text-xs">
                        <span>Balance Due:</span>
                        <span>{formatCurrency(balanceDue)}</span>
                    </div>
                    <div className="flex justify-between text-[9px]">
                        <span>Payment Method:</span>
                        <span className="uppercase">{booking.payment_method}</span>
                    </div>
                </div>

                <div className="border-t border-dashed border-black my-3"></div>
                <div className="text-center text-[9px] mb-4">
                    Thank you for choosing Uptown!<br />
                    Please come again!
                </div>

                <div className="flex justify-between text-[8px] mt-8 pt-4">
                    <div className="text-center w-24">
                        <div className="border-t border-black pt-1">Cashier</div>
                        <div>{booking.checkout_staff?.name || booking.checkin_staff?.name || 'Staff'}</div>
                    </div>
                    <div className="text-center w-24">
                        <div className="border-t border-black pt-1">Guest</div>
                        <div className="truncate">{booking.guest_name}</div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
