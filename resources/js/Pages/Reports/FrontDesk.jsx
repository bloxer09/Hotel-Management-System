import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BadgeCheck, Banknote, CalendarDays, Clock3, FileCheck2, RotateCcw } from 'lucide-react';

const money = (value) => `₱${Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

const dateTime = (value) => value
    ? new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
    : '—';

const methodLabel = (value) => String(value || '')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, char => char.toUpperCase());

export default function FrontDesk({
    filters,
    collections = [],
    methodTotals = [],
    summary = {},
    advanceBookings = [],
    pendingPayments = [],
    staff = [],
    methods = [],
}) {
    const { auth, flash = {} } = usePage().props;
    const canVerify = ['admin', 'front_desk'].includes(auth?.user?.role);
    const [activeTab, setActiveTab] = useState('collections');
    const [form, setForm] = useState(filters);

    const applyFilters = () => {
        router.get(route('reports.front_desk'), form, { preserveState: true, preserveScroll: true });
    };

    const verify = payment => {
        let reference = payment.reference_number;
        if (!reference) {
            reference = window.prompt(`Reference number required for ${payment.receipt_number}:`);
            if (!reference?.trim()) return;
        }
        router.post(route('payments.verify', payment.id), { reference_number: reference }, { preserveScroll: true });
    };
    const reject = payment => {
        const reason = window.prompt(`Reason for rejecting ${payment.receipt_number}:`);
        if (reason?.trim()) {
            router.post(route('payments.reject', payment.id), { reason }, { preserveScroll: true });
        }
    };
    const refund = payment => {
        const amount = window.prompt(`Refund amount for ${payment.receipt_number}:`, payment.amount);
        if (!amount) return;
        const reason = window.prompt('Refund reason:');
        if (reason?.trim()) {
            router.post(route('payments.refund', payment.id), { amount, reason }, { preserveScroll: true });
        }
    };

    const inputClass = 'w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2 text-xs text-slate-200 focus:border-brand-500 focus:ring-brand-500';

    return (
        <AuthenticatedLayout>
            <Head title="Front Desk Payments" />

            <div className="space-y-5 p-4 sm:p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="font-outfit text-2xl font-extrabold text-slate-100">Front Desk Payments</h1>
                        <p className="mt-1 text-xs text-slate-400">
                            Collections are dated by actual payment receipt, never by guest check-in.
                        </p>
                    </div>
                    <Link href={route('reports.index')} className="text-xs font-bold text-brand-400 hover:text-brand-300">
                        Back to Sales & Revenue
                    </Link>
                </div>

                {(flash.success || flash.error) && (
                    <div className={`rounded-xl border px-4 py-3 text-xs font-semibold ${
                        flash.error ? 'border-rose-500/40 bg-rose-950/40 text-rose-300' : 'border-emerald-500/40 bg-emerald-950/30 text-emerald-300'
                    }`}>
                        {flash.error || flash.success}
                    </div>
                )}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    {[
                        ['Gross Collections', summary.gross, Banknote],
                        ['Refunds', summary.refunds, RotateCcw],
                        ['Net Collections', summary.net, BadgeCheck],
                        ['Cash Expected', summary.cash_expected, Banknote],
                        ['Electronic', summary.electronic_collections, FileCheck2],
                    ].map(([label, value, Icon]) => (
                        <div key={label} className="rounded-2xl border border-[#334155] bg-[#1e293b] p-4">
                            <div className="flex items-center gap-2 text-[10px] font-bold uppercase text-slate-400">
                                <Icon size={14} className="text-brand-400" /> {label}
                            </div>
                            <div className="mt-2 font-mono text-lg font-extrabold text-slate-100">{money(value)}</div>
                        </div>
                    ))}
                </div>

                <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-4">
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
                        <input type="date" value={form.from || ''} onChange={e => setForm({ ...form, from: e.target.value })} className={inputClass} />
                        <input type="date" value={form.to || ''} onChange={e => setForm({ ...form, to: e.target.value })} className={inputClass} />
                        <select value={form.method || ''} onChange={e => setForm({ ...form, method: e.target.value })} className={inputClass}>
                            <option value="">All methods</option>
                            {methods.map(method => <option key={method} value={method}>{methodLabel(method)}</option>)}
                        </select>
                        <select value={form.recorded_by || ''} onChange={e => setForm({ ...form, recorded_by: e.target.value })} className={inputClass}>
                            <option value="">All staff</option>
                            {staff.map(user => <option key={user.id} value={user.id}>{user.full_name}</option>)}
                        </select>
                        <select value={form.booking_status || ''} onChange={e => setForm({ ...form, booking_status: e.target.value })} className={inputClass}>
                            <option value="">All booking statuses</option>
                            <option value="reserved">Confirmed/Pending</option>
                            <option value="active">Checked-in</option>
                            <option value="checked_out">Checked-out</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="no_show">No-show</option>
                        </select>
                        <select value={form.verification_status || 'verified'} onChange={e => setForm({ ...form, verification_status: e.target.value })} className={inputClass}>
                            <option value="verified">Verified deposits</option>
                            <option value="pending">Pending deposits</option>
                            <option value="">Any verification status</option>
                        </select>
                        <label className="space-y-1 text-[10px] font-bold uppercase text-slate-500">
                            Booking from
                            <input type="date" value={form.booking_from || ''} onChange={e => setForm({ ...form, booking_from: e.target.value })} className={inputClass} />
                        </label>
                        <label className="space-y-1 text-[10px] font-bold uppercase text-slate-500">
                            Booking to
                            <input type="date" value={form.booking_to || ''} onChange={e => setForm({ ...form, booking_to: e.target.value })} className={inputClass} />
                        </label>
                        <label className="space-y-1 text-[10px] font-bold uppercase text-slate-500">
                            Check-in from
                            <input type="date" value={form.check_in_from || ''} onChange={e => setForm({ ...form, check_in_from: e.target.value })} className={inputClass} />
                        </label>
                        <label className="space-y-1 text-[10px] font-bold uppercase text-slate-500">
                            Check-in to
                            <input type="date" value={form.check_in_to || ''} onChange={e => setForm({ ...form, check_in_to: e.target.value })} className={inputClass} />
                        </label>
                        <button type="button" onClick={applyFilters} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-bold text-white hover:bg-brand-500">
                            Apply filters
                        </button>
                    </div>
                </div>

                <div className="flex gap-2 overflow-x-auto">
                    {[
                        ['collections', `Daily Collections (${collections.length})`],
                        ['advance', `Advance Deposits (${advanceBookings.length})`],
                        ['verification', `Verification Queue (${pendingPayments.length})`],
                    ].map(([key, label]) => (
                        <button key={key} type="button" onClick={() => setActiveTab(key)}
                            className={`whitespace-nowrap rounded-xl px-4 py-2 text-xs font-bold ${
                                activeTab === key ? 'bg-brand-600 text-white' : 'border border-[#334155] bg-[#1e293b] text-slate-400'
                            }`}>
                            {label}
                        </button>
                    ))}
                </div>

                {activeTab === 'collections' && (
                    <div className="space-y-4">
                        <div className="overflow-x-auto rounded-2xl border border-[#334155] bg-[#1e293b]">
                            <table className="min-w-[1850px] w-full text-left text-xs">
                                <thead className="bg-[#0f172a]/70 text-[10px] uppercase text-slate-400">
                                    <tr>
                                        {['Received / Booked', 'Receipt', 'Reservation', 'Guest / Booker / Payer', 'Stay / Room', 'Method / Ref', 'Type', 'Received', 'Booking Total', 'Total Paid', 'Balance', 'Payment / Booking Status', 'Recorded / Verified', 'Remarks', 'Action'].map(h => (
                                            <th key={h} className="px-3 py-3">{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {collections.map(payment => (
                                        <tr key={payment.id} className="border-t border-[#334155]/60 align-top text-slate-300">
                                            <td className="px-3 py-3 font-mono">{dateTime(payment.received_at)}<div className="text-slate-500">Booked: {dateTime(payment.date_booked)}</div></td>
                                            <td className="px-3 py-3 font-mono font-bold text-brand-400">{payment.receipt_number}</td>
                                            <td className="px-3 py-3 font-mono">{payment.booking_refs || '—'}</td>
                                            <td className="px-3 py-3"><b>{payment.guest_names}</b><div className="text-slate-500">Booker: {payment.booker_names}</div><div className="text-slate-500">Payer: {payment.payer_name} · {payment.payer_contact || payment.contact_numbers || '—'}</div></td>
                                            <td className="px-3 py-3">{dateTime(payment.check_in)}<div className="text-slate-500">{payment.rooms || 'Unassigned'}</div></td>
                                            <td className="px-3 py-3 capitalize">{methodLabel(payment.payment_method)}<div className="font-mono text-slate-500">{payment.reference_number || '—'}</div></td>
                                            <td className="px-3 py-3 capitalize">{payment.payment_type}</td>
                                            <td className={`px-3 py-3 font-mono font-bold ${['refund', 'reversal'].includes(payment.payment_type) ? 'text-rose-400' : 'text-emerald-400'}`}>
                                                {['refund', 'reversal'].includes(payment.payment_type) ? '-' : ''}{money(payment.amount)}
                                            </td>
                                            <td className="px-3 py-3 font-mono">{money(payment.total_booking_amount)}</td>
                                            <td className="px-3 py-3 font-mono text-emerald-400">{money(payment.total_amount_paid)}</td>
                                            <td className="px-3 py-3 font-mono text-rose-400">{money(payment.outstanding_balance)}</td>
                                            <td className="px-3 py-3 capitalize">{payment.status}<div className="text-slate-500">{payment.booking_statuses}</div></td>
                                            <td className="px-3 py-3">{payment.recorded_by || 'System'}<div className="text-slate-500">{payment.verified_by || '—'}</div></td>
                                            <td className="max-w-xs px-3 py-3 text-slate-400">{payment.remarks || '—'}</td>
                                            <td className="px-3 py-3">
                                                {!['refund', 'reversal'].includes(payment.payment_type) && canVerify && (
                                                    <button type="button" onClick={() => refund(payment)} className="text-[10px] font-bold text-rose-400 hover:text-rose-300">Refund</button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {!collections.length && <tr><td colSpan="15" className="px-4 py-12 text-center text-slate-500">No verified payments received in this period.</td></tr>}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {methodTotals.map(total => (
                                <div key={total.payment_method_code} className="rounded-xl border border-[#334155] bg-[#1e293b] p-3 text-xs">
                                    <div className="font-bold text-slate-200">{methodLabel(total.payment_method_code)}</div>
                                    <div className="mt-2 grid grid-cols-3 gap-2 font-mono text-[10px]">
                                        <span className="text-slate-400">Gross<br />{money(total.gross)}</span>
                                        <span className="text-rose-400">Refund<br />{money(total.refunds)}</span>
                                        <span className="text-emerald-400">Net<br />{money(total.net)}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {activeTab === 'advance' && (
                    <div className="overflow-x-auto rounded-2xl border border-[#334155] bg-[#1e293b]">
                        <table className="min-w-[1200px] w-full text-left text-xs">
                            <thead className="bg-[#0f172a]/70 text-[10px] uppercase text-slate-400">
                                <tr>{['Reservation', 'Date Booked', 'Guest / Booker', 'Stay Dates', 'Room', 'Nights', 'Booking Total', 'Verified Paid', 'Balance', 'Methods', 'Status'].map(h => <th key={h} className="px-3 py-3">{h}</th>)}</tr>
                            </thead>
                            <tbody>
                                {advanceBookings.map(booking => (
                                    <tr key={booking.id} className="border-t border-[#334155]/60 text-slate-300">
                                        <td className="px-3 py-3 font-mono font-bold text-brand-400">{booking.booking_ref}</td>
                                        <td className="px-3 py-3">{dateTime(booking.date_booked)}</td>
                                        <td className="px-3 py-3"><b>{booking.guest_name}</b><div className="text-slate-500">{booking.booker_name} · {booking.booker_contact}</div></td>
                                        <td className="px-3 py-3">{dateTime(booking.check_in)}<div className="text-slate-500">to {dateTime(booking.check_out)}</div></td>
                                        <td className="px-3 py-3">{booking.room_type} {booking.room_number}</td>
                                        <td className="px-3 py-3 font-mono">{booking.num_nights || '—'}</td>
                                        <td className="px-3 py-3 font-mono">{money(booking.total_amount)}</td>
                                        <td className="px-3 py-3 font-mono text-emerald-400">{money(booking.total_paid)}</td>
                                        <td className="px-3 py-3 font-mono text-rose-400">{money(booking.outstanding_balance)}</td>
                                        <td className="px-3 py-3">{booking.payment_methods.map(methodLabel).join(', ')}</td>
                                        <td className="px-3 py-3 capitalize">{booking.booking_status}</td>
                                    </tr>
                                ))}
                                {!advanceBookings.length && <tr><td colSpan="11" className="px-4 py-12 text-center text-slate-500">No future bookings with matching verified payments.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                )}

                {activeTab === 'verification' && (
                    <div className="grid gap-3">
                        {pendingPayments.map(payment => (
                            <div key={payment.id} className="flex flex-col gap-3 rounded-2xl border border-amber-500/30 bg-amber-950/10 p-4 lg:flex-row lg:items-center">
                                <Clock3 className="shrink-0 text-amber-400" size={20} />
                                <div className="grid flex-1 gap-2 text-xs sm:grid-cols-3">
                                    <div><b className="text-slate-200">{payment.receipt_number}</b><div className="text-slate-500">{payment.booking_refs}</div></div>
                                    <div className="text-slate-300">{payment.payer_name}<div className="capitalize text-slate-500">{methodLabel(payment.payment_method)} · {payment.reference_number || 'No reference'}</div></div>
                                    <div className="font-mono font-bold text-amber-400">{money(payment.amount)}<div className="font-sans font-normal text-slate-500">Recorded by {payment.recorded_by}</div></div>
                                </div>
                                {canVerify && (
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => verify(payment)} className="rounded-lg bg-emerald-600 px-3 py-2 text-[10px] font-bold text-white">Verify</button>
                                        <button type="button" onClick={() => reject(payment)} className="rounded-lg bg-rose-600 px-3 py-2 text-[10px] font-bold text-white">Reject</button>
                                    </div>
                                )}
                            </div>
                        ))}
                        {!pendingPayments.length && (
                            <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-12 text-center text-xs text-slate-500">
                                <CalendarDays className="mx-auto mb-3" /> No pending digital payments.
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
