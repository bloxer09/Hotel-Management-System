import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    TrendingUp,
    DollarSign,
    Smartphone,
    BedDouble,
    FileText,
    Calendar,
    Download,
    Printer,
    Users,
    X,
    ChevronDown,
    ChevronRight,
    AlertCircle,
    Receipt,
    Tag
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

const fmt = (val) => '₱' + Number(val || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Index({ dateFrom, dateTo, summary, byCashier, byRoomType, transactions, occupancy, productRevenue = 0, roomRevenue = 0 }) {
    const { auth } = usePage().props;
    const user = auth.user;

    const [from, setFrom] = useState(dateFrom);
    const [to, setTo] = useState(dateTo);
    const [showEOD, setShowEOD] = useState(false);

    const applyDateFilter = () => {
        router.get(route('reports.index'), { from, to }, { preserveState: false });
    };

    const quickRange = (range) => {
        const today = new Date();
        let f, t;
        t = today.toISOString().split('T')[0];
        if (range === 'today') {
            f = t;
        } else if (range === 'week') {
            const d = new Date(today);
            d.setDate(d.getDate() - d.getDay());
            f = d.toISOString().split('T')[0];
        } else if (range === 'month') {
            f = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-01`;
        }
        setFrom(f); setTo(t);
        router.get(route('reports.index'), { from: f, to: t }, { preserveState: false });
    };

    const exportExcel = () => {
        window.location.href = route('reports.export') + `?from=${from}&to=${to}`;
    };

    const printEOD = () => {
        window.print();
    };

    const paymentMethodLabel = (m) => {
        if (m === 'cash') return { label: 'Cash', color: 'text-emerald-400' };
        if (m === 'gcash') return { label: 'GCash', color: 'text-blue-400' };
        if (m === 'split') return { label: 'Split', color: 'text-amber-400' };
        return { label: m || '—', color: 'text-slate-400' };
    };

    const totalRevenue = Number(summary.total_revenue || 0);
    const totalCash = Number(summary.total_cash || 0);
    const totalGcash = Number(summary.total_gcash || 0);
    const totalDiscount = Number(summary.total_discount || 0);
    const totalExtension = Number(summary.total_extension || 0);
    const totalSurcharge = Number(summary.total_surcharge || 0);
    const totalLate = Number(summary.total_late || 0);

    return (
        <AuthenticatedLayout>
            <Head title="Sales & Remittance Reports" />

            <div className="flex flex-col gap-8 print:gap-4">

                {/* Header */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 print:hidden">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Sales & Remittance Reports
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Audit daily payment collections, close shift register remittances, and review financial metrics.</p>
                    </div>
                    <div className="flex flex-col sm:flex-row items-center gap-2 w-full sm:w-auto mt-2 sm:mt-0">
                        <button onClick={() => setShowEOD(true)}
                            className="flex items-center justify-center gap-2 px-4 py-2 bg-indigo-600/20 border border-indigo-500/40 hover:bg-indigo-600/30 text-indigo-300 rounded-xl text-xs font-bold transition-all w-full sm:w-auto">
                            <Printer size={14} /> EOD Summary
                        </button>
                        <button onClick={exportExcel}
                            className="flex items-center justify-center gap-2 px-4 py-2 bg-emerald-600/20 border border-emerald-500/40 hover:bg-emerald-600/30 text-emerald-300 rounded-xl text-xs font-bold transition-all w-full sm:w-auto">
                            <Download size={14} /> Export Excel
                        </button>
                    </div>
                </div>

                {/* Date Range Filter */}
                <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-4 p-4 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-lg print:hidden">
                    {/* Left: Quick Ranges Segmented Tabs */}
                    <div className="flex gap-1 bg-[#0f172a] p-1 rounded-xl border border-[#334155] w-full xl:w-fit shrink-0 overflow-x-auto mobile-scroll-tabs">
                        {[
                            { key: 'today', label: 'Today' },
                            { key: 'week', label: 'This Week' },
                            { key: 'month', label: 'This Month' },
                        ].map(tab => {
                            const todayStr = new Date().toISOString().split('T')[0];
                            const firstOfWeek = new Date();
                            firstOfWeek.setDate(firstOfWeek.getDate() - firstOfWeek.getDay());
                            const weekStartStr = firstOfWeek.toISOString().split('T')[0];
                            const firstOfMonthStr = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-01`;

                            const isActive = 
                                (tab.key === 'today' && dateFrom === todayStr && dateTo === todayStr) ||
                                (tab.key === 'week' && dateFrom === weekStartStr && dateTo === todayStr) ||
                                (tab.key === 'month' && dateFrom === firstOfMonthStr && dateTo === todayStr);

                            return (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => quickRange(tab.key)}
                                    className={`px-4 py-1.5 rounded-lg text-xs font-bold transition-all uppercase tracking-wider ${
                                        isActive
                                            ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/60'
                                            : 'text-slate-400 hover:text-slate-200'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>

                    {/* Right: Custom Date Range Picker */}
                    <div className="flex flex-wrap items-center gap-3 w-full xl:w-auto">
                        <div className="flex items-center gap-2">
                            <Calendar size={14} className="text-slate-500" />
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mr-1">Custom Range</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <input
                                type="date"
                                value={from}
                                onChange={e => setFrom(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-xl text-slate-200 px-3 py-2 text-xs focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500/20 font-medium transition-all"
                            />
                            <span className="text-slate-500 text-xs font-medium">to</span>
                            <input
                                type="date"
                                value={to}
                                onChange={e => setTo(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-xl text-slate-200 px-3 py-2 text-xs focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500/20 font-medium transition-all"
                            />
                        </div>
                        <button
                            type="button"
                            onClick={applyDateFilter}
                            className="px-5 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold shadow-lg shadow-brand-600/10 hover:shadow-brand-600/20 transition-all font-outfit"
                        >
                            Apply Filter
                        </button>
                        <span className="text-xs text-slate-400 font-mono font-bold bg-[#0f172a] px-3.5 py-2 rounded-xl border border-[#334155]/60 min-w-[180px] text-center xl:ml-2">
                            {dateFrom === dateTo ? dateFrom : `${dateFrom} → ${dateTo}`}
                        </span>
                    </div>
                </div>

                {/* Summary KPI Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {[
                        { label: 'Total Revenue', value: totalRevenue, icon: TrendingUp, color: 'emerald', sub: `${summary.total_bookings || 0} bookings` },
                        { label: 'Cash Collections', value: totalCash, icon: DollarSign, color: 'brand', sub: 'Cash drawer total' },
                        { label: 'GCash Collections', value: totalGcash, icon: Smartphone, color: 'blue', sub: 'Electronic transfers' },
                        { label: 'Discounts Given', value: totalDiscount, icon: Tag, color: 'amber', sub: 'Senior / PWD / Promo' },
                    ].map(({ label, value, icon: Icon, color, sub }) => (
                        <div key={label} className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                            <div className="flex items-center justify-between mb-3">
                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{label}</span>
                                <Icon size={15} className={`text-${color}-400`} />
                            </div>
                            <div className={`font-mono font-black text-xl tracking-tight text-${color}-300`}>{fmt(value)}</div>
                            <div className="text-[10px] text-slate-500 mt-1">{sub}</div>
                        </div>
                    ))}
                </div>

                {/* Rooms vs Products Income Separation */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <motion.div
                        initial={{ opacity: 0, y: 15 }}
                        animate={{ opacity: 1, y: 0 }}
                        className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex items-center justify-between gap-4 group hover:border-[#475569] transition-all duration-300"
                    >
                        <div className="flex flex-col gap-1 min-w-0">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Rooms & Lodging Revenue</span>
                            <span className="text-2xl font-outfit font-extrabold text-indigo-300 mt-1 font-mono">{fmt(roomRevenue)}</span>
                            <span className="text-[10px] text-slate-500 mt-1 truncate">Base rates, extension fees, late checkout fees, peak surcharges</span>
                        </div>
                        <div className="p-3.5 rounded-xl bg-indigo-500/10 text-indigo-400 shrink-0">
                            <BedDouble size={24} />
                        </div>
                    </motion.div>

                    <motion.div
                        initial={{ opacity: 0, y: 15 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.1 }}
                        className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex items-center justify-between gap-4 group hover:border-[#475569] transition-all duration-300"
                    >
                        <div className="flex flex-col gap-1 min-w-0">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Inventory & Product Revenue</span>
                            <span className="text-2xl font-outfit font-extrabold text-emerald-300 mt-1 font-mono">{fmt(productRevenue)}</span>
                            <span className="text-[10px] text-slate-500 mt-1 truncate">Minibar consumption, laundry/toiletrie sales, direct walk-ins</span>
                        </div>
                        <div className="p-3.5 rounded-xl bg-emerald-500/10 text-emerald-400 shrink-0">
                            <Tag size={24} />
                        </div>
                    </motion.div>
                </div>

                {/* Secondary breakdown */}
                <div className="grid grid-cols-3 sm:grid-cols-3 gap-4">
                    <div className="p-4 rounded-2xl bg-[#1e293b] border border-[#334155]">
                        <div className="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Extension Fees</div>
                        <div className="font-mono font-bold text-amber-300">{fmt(totalExtension)}</div>
                    </div>
                    <div className="p-4 rounded-2xl bg-[#1e293b] border border-[#334155]">
                        <div className="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Peak Surcharges</div>
                        <div className="font-mono font-bold text-rose-300">{fmt(totalSurcharge)}</div>
                    </div>
                    <div className="p-4 rounded-2xl bg-[#1e293b] border border-[#334155]">
                        <div className="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Late Checkout Fees</div>
                        <div className="font-mono font-bold text-orange-300">{fmt(totalLate)}</div>
                    </div>
                </div>

                {/* Cashier Remittance + Room Type */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">

                    {/* Cashier Remittance */}
                    <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl flex flex-col gap-4">
                        <div className="px-6 py-4 border-b border-[#334155]">
                            <h2 className="text-sm font-outfit font-black uppercase tracking-wider text-slate-300 flex items-center gap-2">
                                <Users size={15} className="text-brand-400" /> Cashier Remittance Summary
                            </h2>
                        </div>
                        {byCashier.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs table-fixed">
                                    <thead>
                                        <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Staff</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Txns</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Cash</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">GCash</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-300 uppercase tracking-wider text-right font-bold">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {byCashier.map((c, i) => (
                                            <tr key={i} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">
                                                <td className="px-4 py-3">
                                                    <div className="font-bold text-slate-200">{c.full_name}</div>
                                                    <div className="text-[9px] text-slate-500 capitalize">{c.role}</div>
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">{c.txn_count}</td>
                                                <td className="px-4 py-3 text-right font-mono text-emerald-400">{fmt(c.cash)}</td>
                                                <td className="px-4 py-3 text-right font-mono text-blue-400">{fmt(c.gcash)}</td>
                                                <td className="px-4 py-3 text-right font-mono font-black text-slate-100">{fmt(c.total_collected)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="text-center text-xs text-slate-500 py-6">No checkout transactions for this period.</div>
                        )}
                    </div>

                    {/* By Room Type */}
                    <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                        <h2 className="text-sm font-outfit font-black uppercase tracking-wider text-slate-300 flex items-center gap-2">
                            <BedDouble size={15} className="text-indigo-400" /> Revenue by Room Type
                        </h2>
                        <div className="space-y-3">
                            {byRoomType.length > 0 ? byRoomType.map((rt, i) => {
                                const pct = totalRevenue > 0 ? (rt.revenue / totalRevenue) * 100 : 0;
                                return (
                                    <div key={i}>
                                        <div className="flex justify-between text-xs mb-1">
                                            <span className="font-semibold text-slate-300">{rt.type_name} <span className="text-slate-500">({rt.cnt} stays)</span></span>
                                            <span className="font-mono text-slate-100">{fmt(rt.revenue)}</span>
                                        </div>
                                        <div className="w-full bg-[#0f172a] h-1.5 rounded-full overflow-hidden">
                                            <div className="h-full bg-indigo-500" style={{ width: `${pct}%` }} />
                                        </div>
                                    </div>
                                );
                            }) : (
                                <div className="text-xs text-slate-500 py-4 text-center">No room revenue in this period.</div>
                            )}
                        </div>

                        {/* Occupancy mini */}
                        <div className="mt-2 pt-4 border-t border-[#334155]">
                            <div className="text-[10px] text-slate-500 uppercase tracking-wider mb-2">Live Occupancy</div>
                            <div className="grid grid-cols-4 gap-2 text-center text-xs">
                                {[
                                    { label: 'Vacant', count: occupancy.vacant, color: 'emerald' },
                                    { label: 'Occupied', count: occupancy.occupied, color: 'rose' },
                                    { label: 'Cleaning', count: occupancy.cleaning, color: 'amber' },
                                    { label: 'OOO', count: occupancy.ooo, color: 'slate' },
                                ].map(({ label, count, color }) => (
                                    <div key={label} className="p-2 bg-[#0f172a]/50 rounded-xl border border-[#334155]/60">
                                        <div className={`font-outfit font-black text-lg text-${color}-400`}>{count}</div>
                                        <div className="text-[9px] text-slate-500">{label}</div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                </div>

                {/* Full Transactions Table */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl flex flex-col gap-4">
                    <div className="px-6 py-4 border-b border-[#334155]">
                        <h2 className="text-sm font-outfit font-black uppercase tracking-wider text-slate-300 flex items-center gap-2">
                            <Receipt size={15} className="text-teal-400" /> Transactions ({transactions.length} records)
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Ref / Guest</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Room</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Check-In</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Type</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Base</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Extras</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Discount</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Total</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Payment</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Cashier</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.length > 0 ? transactions.map((tx, i) => {
                                    const pm = paymentMethodLabel(tx.payment_method);
                                    const extras = (Number(tx.extension_fee || 0) + Number(tx.late_checkout_fee || 0) + Number(tx.peak_surcharge || 0));
                                    return (
                                        <tr key={tx.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">
                                            <td className="px-4 py-3">
                                                <div className="font-mono text-brand-300 text-[10px]">{tx.booking_ref}</div>
                                                <div className="font-bold text-slate-200">{tx.guest_name}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-bold text-slate-300">Rm {tx.room_number}</div>
                                                <div className="text-[9px] text-slate-500">{tx.type_name}</div>
                                            </td>
                                            <td className="px-4 py-3 font-mono text-slate-400 text-[10px]">
                                                {tx.check_in ? new Date(tx.check_in).toLocaleDateString() : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`text-[9px] px-1.5 py-0.5 rounded font-bold uppercase ${tx.booking_type === 'overnight' ? 'bg-brand-900/40 text-brand-300' : 'bg-amber-900/30 text-amber-300'}`}>
                                                    {tx.booking_type === 'overnight' ? 'Overnight' : `${tx.booking_type}`}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-mono">{fmt(tx.base_amount)}</td>
                                            <td className="px-4 py-3 font-mono text-amber-300">{extras > 0 ? fmt(extras) : '—'}</td>
                                            <td className="px-4 py-3 font-mono text-rose-400">{Number(tx.discount_amount || 0) > 0 ? `-${fmt(tx.discount_amount)}` : '—'}</td>
                                            <td className="px-4 py-3 font-mono font-black text-slate-100">{fmt(tx.amount_paid)}</td>
                                            <td className="px-4 py-3">
                                                <span className={`font-bold ${pm.color}`}>{pm.label}</span>
                                                {tx.gcash_ref && <div className="text-[9px] text-slate-500 font-mono">{tx.gcash_ref}</div>}
                                            </td>
                                            <td className="px-4 py-3 text-[10px] text-slate-400">{tx.cashier_name || '—'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`text-[9px] px-1.5 py-0.5 rounded font-bold uppercase ${tx.status === 'checked_out' ? 'bg-blue-900/30 text-blue-300' : tx.status === 'active' ? 'bg-emerald-900/30 text-emerald-300' : 'bg-slate-800 text-slate-400'}`}>
                                                    {tx.status}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                }) : (
                                    <tr>
                                        <td colSpan={11} className="py-10 text-center text-slate-500">No transactions in this date range.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            {/* EOD Summary Modal */}
            <AnimatePresence>
                {showEOD && (
                    <>
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                            onClick={() => setShowEOD(false)} className="fixed inset-0 bg-[#070b13]/90 z-[999]" />
                        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }}
                            className="fixed inset-0 z-[1000] flex items-center justify-center p-4">
                            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
                                <div className="flex items-center justify-between mb-5">
                                    <h2 className="font-outfit font-black text-lg text-slate-100 flex items-center gap-2">
                                        <Printer size={18} className="text-indigo-400" /> End-of-Day Report
                                    </h2>
                                    <button onClick={() => setShowEOD(false)} className="text-slate-400 hover:text-slate-100"><X size={20} /></button>
                                </div>

                                <div className="space-y-4 text-xs">
                                    <div className="text-center py-3 bg-[#0f172a]/50 border border-[#334155] rounded-xl">
                                        <div className="font-bold text-slate-400 uppercase tracking-widest text-[10px] mb-1">Period</div>
                                        <div className="font-mono text-slate-200 font-bold">{from === to ? from : `${from} to ${to}`}</div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-3">
                                        {[
                                            { label: 'Total Bookings', value: summary.total_bookings || 0, mono: true },
                                            { label: 'Checked Out', value: summary.checked_out || 0, mono: true },
                                            { label: 'Still Active', value: summary.still_active || 0, mono: true },
                                            { label: 'Total Revenue', value: fmt(totalRevenue), bold: true },
                                            { label: 'Rooms Revenue', value: fmt(roomRevenue), bold: true, colorClass: 'text-indigo-400' },
                                            { label: 'Products Revenue', value: fmt(productRevenue), bold: true, colorClass: 'text-emerald-400' },
                                            { label: 'Cash Collected', value: fmt(totalCash), cash: true },
                                            { label: 'GCash Collected', value: fmt(totalGcash), gcash: true },
                                            { label: 'Extension Fees', value: fmt(totalExtension) },
                                            { label: 'Peak Surcharges', value: fmt(totalSurcharge) },
                                            { label: 'Late Checkout Fees', value: fmt(totalLate) },
                                            { label: 'Total Discounts', value: `(${fmt(totalDiscount)})`, discount: true },
                                        ].map(({ label, value, mono, bold, cash, gcash, discount, colorClass }) => (
                                            <div key={label} className="flex flex-col p-3 bg-[#0f172a]/40 border border-[#334155]/60 rounded-xl">
                                                <span className="text-[10px] text-slate-500 uppercase tracking-wider">{label}</span>
                                                <span className={`font-mono font-bold mt-1 ${colorClass ? colorClass : bold ? 'text-slate-50 text-base' : cash ? 'text-emerald-400' : gcash ? 'text-blue-400' : discount ? 'text-rose-400' : 'text-slate-300'}`}>
                                                    {value}
                                                </span>
                                            </div>
                                        ))}
                                    </div>

                                    {byCashier.length > 0 && (
                                        <div className="mt-3 border-t border-[#334155] pt-4">
                                            <div className="text-[10px] uppercase tracking-widest text-slate-500 font-bold mb-3">Cashier Remittances</div>
                                            {byCashier.map((c, i) => (
                                                <div key={i} className="flex items-center justify-between py-1.5 border-b border-[#334155]/40">
                                                    <div>
                                                        <span className="font-bold text-slate-200">{c.full_name}</span>
                                                        <span className="text-slate-500 text-[10px] ml-1">({c.txn_count} txns)</span>
                                                    </div>
                                                    <div className="text-right">
                                                        <div className="font-mono font-bold text-slate-100">{fmt(c.total_collected)}</div>
                                                        <div className="text-[9px] text-slate-500">Cash: {fmt(c.cash)} | GCash: {fmt(c.gcash)}</div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="flex justify-end gap-3 mt-5">
                                    <button onClick={() => setShowEOD(false)}
                                        className="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-sm font-semibold">Close</button>
                                    <button onClick={printEOD}
                                        className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm flex items-center gap-2">
                                        <Printer size={14} /> Print / PDF
                                    </button>
                                    <button onClick={exportExcel}
                                        className="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-sm flex items-center gap-2">
                                        <Download size={14} /> Export Excel
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </>
                )}
            </AnimatePresence>

        </AuthenticatedLayout>
    );
}
