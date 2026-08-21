import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import {
    Play,
    Power,
    Coins,
    FileSpreadsheet,
    Clock,
    AlertTriangle,
    CalendarClock,
    Printer,
    PenSquare,
    Hotel,
    PackageOpen
} from 'lucide-react';
import { motion } from 'framer-motion';
import ConfirmModal from '@/Components/ConfirmModal';
import CustomSelect from '@/Components/CustomSelect';
import { formatUtcToManila } from '@/Utils/datetime';

export default function Index({ activeShift, registerShift, isRegisterOperator, viewerMode, suggestedShift, suggestedOpeningCash, suggestedOpeningDenominations, suggestedOpeningCashMinibar, suggestedOpeningDenominationsMinibar, previousClosedShift, liveSummary, recentShifts, pendingVariances = [], canReviewVariances = false, unresolvedExpenses = { pending: 0, approved_unpaid: 0 }, inventoryTurnover = {} }) {
    const [showShutdownConfirm, setShowShutdownConfirm] = useState(false);

    const COINS = [0.01, 0.05, 0.25, 1, 5, 10];
    const BILLS = [20, 50, 100, 200, 500, 1000];
    const DENOMINATIONS = [...COINS, ...BILLS];

    const sanitizeQuantity = (qty) => Math.max(0, parseInt(qty, 10) || 0);

    const calculateDenominationTotal = (denominations = {}) => DENOMINATIONS.reduce(
        (total, denomination) => total
            + denomination * sanitizeQuantity(denominations[denomination.toString()]),
        0
    );

    // Start shift form
    const startForm = useForm({
        shift_code: suggestedShift || 'morning',
        opening_cash: suggestedOpeningCash || 0.00,
        opening_denominations: suggestedOpeningDenominations || {},
        opening_cash_minibar: suggestedOpeningCashMinibar || 0.00,
        opening_denominations_minibar: suggestedOpeningDenominationsMinibar || {},
        notes: '',
        handover_notes: ''
    });

    // End shift form
    const defaultDenominations = {};
    DENOMINATIONS.forEach(d => { defaultDenominations[d.toString()] = 0; });

    const endForm = useForm({
        closing_cash: 0.00,
        closing_denominations: { ...defaultDenominations },
        closing_cash_minibar: 0.00,
        closing_denominations_minibar: { ...defaultDenominations },
        notes: '',
        inventory_override_reason: ''
    });

    const handleDenominationChange = (denom, qty) => {
        const newDenoms = { ...endForm.data.closing_denominations, [denom]: sanitizeQuantity(qty) };
        endForm.setData(data => ({
            ...data,
            closing_denominations: newDenoms,
            closing_cash: calculateDenominationTotal(newDenoms)
        }));
    };

    const handleDenominationMinibarChange = (denom, qty) => {
        const newDenoms = { ...endForm.data.closing_denominations_minibar, [denom]: sanitizeQuantity(qty) };
        endForm.setData(data => ({
            ...data,
            closing_denominations_minibar: newDenoms,
            closing_cash_minibar: calculateDenominationTotal(newDenoms)
        }));
    };

    const handleStartDenominationChange = (denom, qty) => {
        const newDenoms = { ...startForm.data.opening_denominations, [denom]: sanitizeQuantity(qty) };
        startForm.setData(data => ({
            ...data,
            opening_denominations: newDenoms,
            opening_cash: calculateDenominationTotal(newDenoms)
        }));
    };

    const handleStartDenominationMinibarChange = (denom, qty) => {
        const newDenoms = { ...startForm.data.opening_denominations_minibar, [denom]: sanitizeQuantity(qty) };
        startForm.setData(data => ({
            ...data,
            opening_denominations_minibar: newDenoms,
            opening_cash_minibar: calculateDenominationTotal(newDenoms)
        }));
    };

    const handleStartShift = (e) => {
        e.preventDefault();
        startForm.post(route('shifts.start'));
    };

    const previousRoomsClosing = Number(previousClosedShift?.closing_cash || 0);
    const previousMinibarClosing = Number(previousClosedShift?.closing_cash_minibar || 0);
    const roomsHandoverDifference = Number(startForm.data.opening_cash || 0) - previousRoomsClosing;
    const minibarHandoverDifference = Number(startForm.data.opening_cash_minibar || 0) - previousMinibarClosing;
    const hasHandoverDifference = !!previousClosedShift && (
        Math.abs(roomsHandoverDifference) >= 0.01 || Math.abs(minibarHandoverDifference) >= 0.01
    );
    const roomsReconciliation = liveSummary?.reconciliation?.rooms;
    const minibarReconciliation = liveSummary?.reconciliation?.minibar;

    const handleEndShift = (e) => {
        e.preventDefault();
        setShowShutdownConfirm(true);
    };

    const executeEndShift = () => {
        setShowShutdownConfirm(false);
        endForm.post(route('shifts.end'));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Shift Control Panel" />

            <div className="flex flex-col gap-8">

                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Shift & Register Control Desk
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Monitor cash drawer operations, log work session intervals, and reconcile register balance reports.</p>
                    </div>
                    {canReviewVariances && (
                        <Link
                            href={route('shifts.variances.index')}
                            className="inline-flex items-center gap-2 rounded-xl border border-rose-500/40 bg-rose-950/30 px-4 py-2 text-xs font-bold text-rose-200 hover:border-rose-400"
                        >
                            <AlertTriangle size={14} /> Cash Variance Queue
                        </Link>
                    )}
                </div>

                {pendingVariances.length > 0 && (
                    <div className="rounded-2xl border border-rose-500/40 bg-rose-950/25 p-5 shadow-xl" data-testid="pending-cash-variance">
                        <h2 className="text-sm font-outfit font-extrabold uppercase tracking-wider text-rose-200">Pending Cash Variance</h2>
                        <div className="mt-4 grid gap-3">
                            {pendingVariances.map((item) => (
                                <div key={item.shift_id} className="rounded-xl border border-rose-500/20 bg-[#0f172a]/50 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div className="text-sm font-bold text-slate-100">Shift #{item.shift_id}</div>
                                            <div className="text-xs text-slate-400">
                                                Closed: {item.closed_at_display || (item.closed_at ? formatUtcToManila(item.closed_at) : '—')}
                                            </div>
                                        </div>
                                        <span className="text-[10px] font-black uppercase tracking-wider text-rose-300 border border-rose-500/30 rounded px-2 py-0.5">
                                            {(item.overall_status || '').replace('_', ' ')}
                                        </span>
                                    </div>
                                    {[item.rooms, item.minibar].filter((drawer) => Math.abs(Number(drawer?.original_variance || 0)) >= 0.01).map((drawer) => (
                                        <div key={drawer.drawer} className="mt-3 text-xs text-slate-300">
                                            <span className="font-bold text-slate-100">{drawer.label} {drawer.original_label === 'OVER' ? 'Overage' : 'Shortage'}:</span>
                                            {' '}₱{Number(Math.abs(drawer.original_variance)).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            <span className="text-slate-500"> · Resolved ₱{Number(drawer.resolved_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                            <span className="text-rose-300 font-bold"> · Remaining ₱{Number(drawer.remaining).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    ))}
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Link
                                            href={`${route('shifts.report', item.shift_id)}?tab=variance`}
                                            className="inline-flex items-center rounded-lg border border-[#334155] bg-[#1e293b] px-3 py-1.5 text-[11px] font-bold text-slate-200 hover:border-brand-500"
                                        >
                                            View Details
                                        </Link>
                                        <Link
                                            href={`${route('shifts.report', item.shift_id)}?tab=variance`}
                                            className="inline-flex items-center rounded-lg border border-rose-500/40 bg-rose-900/30 px-3 py-1.5 text-[11px] font-bold text-rose-100 hover:border-rose-400"
                                        >
                                            {canReviewVariances ? 'Review Variance' : 'Add Explanation / Submit Resolution'}
                                        </Link>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Main Shift Action Interface */}
                <div>

                    {/* Start / Active Shift control */}
                    <div>
                        {registerShift && !isRegisterOperator ? (

                            /* Existing register: staff are viewers; admins retain audited override access. */
                            <motion.div
                                initial={{ opacity: 0, scale: 0.98 }}
                                animate={{ opacity: 1, scale: 1 }}
                                className={`p-6 md:p-8 rounded-2xl border shadow-xl ${viewerMode
                                    ? 'bg-sky-950/25 border-sky-500/30'
                                    : 'bg-emerald-950/20 border-emerald-500/20'
                                }`}
                            >
                                <div className="flex flex-col sm:flex-row items-start justify-between gap-4">
                                    <div className="flex items-start gap-3.5">
                                        <div className={`p-3 rounded-xl ${viewerMode ? 'bg-sky-500/10 text-sky-400' : 'bg-emerald-500/10 text-emerald-400'}`}>
                                            {viewerMode ? <AlertTriangle size={24} /> : <Clock size={24} />}
                                        </div>
                                        <div>
                                            <h2 className="text-xl font-outfit font-bold text-slate-200">
                                                {viewerMode ? 'Viewer Mode — Register Is Read Only' : 'Register Currently Assigned'}
                                            </h2>
                                            <p className="mt-1 text-sm text-slate-300">
                                                Current operator: <strong>{registerShift.user?.name || 'Assigned staff'}</strong>
                                            </p>
                                            <p className="mt-1 text-xs text-slate-400">
                                                {registerShift.shift_code?.toUpperCase()} shift started {new Date(registerShift.started_at).toLocaleString()}.
                                                {viewerMode
                                                    ? ' You can view hotel records, but operational and payment changes are blocked.'
                                                    : ' Administrative changes remain available and are recorded as register overrides.'}
                                            </p>
                                        </div>
                                    </div>
                                    {!viewerMode && (
                                        <div className="flex flex-col sm:flex-row gap-2">
                                            <Link
                                                href={route('shifts.inventory_turnover.show')}
                                                className="inline-flex items-center gap-2 rounded-xl border border-brand-500/40 bg-brand-950/40 px-4 py-2 text-xs font-bold text-brand-200 hover:border-brand-400 hover:text-white"
                                            >
                                                <PackageOpen size={14} /> Inventory Turnover
                                            </Link>
                                            <Link
                                                href={route('shifts.inventory_turnover.history')}
                                                className="inline-flex items-center gap-2 rounded-xl border border-[#334155] bg-[#1e293b] px-4 py-2 text-xs font-bold text-slate-300 hover:border-brand-500 hover:text-white"
                                            >
                                                History
                                            </Link>
                                            <Link
                                                href={route('shifts.report', registerShift.id)}
                                                className="inline-flex items-center gap-2 rounded-xl border border-[#334155] bg-[#1e293b] px-4 py-2 text-xs font-bold text-slate-300 hover:border-brand-500 hover:text-white"
                                            >
                                                <Printer size={14} /> View Live Report
                                            </Link>
                                        </div>
                                    )}
                                </div>
                            </motion.div>

                        ) : !activeShift ? (

                            /* Start Shift Section */
                            <motion.div
                                initial={{ opacity: 0, scale: 0.98 }}
                                animate={{ opacity: 1, scale: 1 }}
                                className="p-6 md:p-8 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl"
                            >
                                <div className="flex items-center gap-3.5 mb-6">
                                    <div className="p-3 bg-brand-500/10 text-brand-400 rounded-xl">
                                        <Play size={24} />
                                    </div>
                                    <div>
                                        <h2 className="text-xl font-outfit font-bold text-slate-200">Start Register Shift Session</h2>
                                        <p className="text-xs text-slate-400 font-medium">Verify starting capital inside cash drawers before logging in.</p>
                                    </div>
                                </div>

                                <form onSubmit={handleStartShift} className="space-y-6">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">

                                        {/* Shift Type Select */}
                                        <div className="flex flex-col gap-2">
                                            <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Shift Code</label>
                                            <CustomSelect
                                                value={startForm.data.shift_code}
                                                onChange={e => startForm.setData('shift_code', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-4 py-3 focus:outline-none focus:border-brand-500 font-outfit"
                                            >
                                                <option value="morning">Morning Shift (7:00 AM - 4:00 PM)</option>
                                                <option value="evening">Evening Shift (3:00 PM - 12:00 AM)</option>
                                                <option value="night">Graveyard Shift (11:00 PM - 8:00 AM)</option>
                                            </CustomSelect>
                                        </div>

                                        {/* Starting Drawer Capitals (Rooms & Minibar side-by-side) */}
                                        <div className="sm:col-span-2 grid grid-cols-1 lg:grid-cols-2 gap-8">
                                            {/* Column 1: Rooms Cash Drawer */}
                                            <div className="flex flex-col gap-4 bg-[#0f172a]/20 p-5 rounded-2xl border border-[#334155]/50">
                                                <h3 className="text-sm font-outfit font-bold text-slate-355 border-b border-[#334155] pb-2 flex items-center gap-2">
                                                    <Hotel size={16} className="text-brand-400" /> Rooms Cash Drawer
                                                </h3>
                                                <div>
                                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-2">Starting Rooms Drawer Capital (₱)</label>
                                                    <div className="relative mb-2">
                                                        <Coins className="absolute left-4 top-3.5 text-brand-500" size={16} />
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            value={startForm.data.opening_cash}
                                                            readOnly
                                                            className="w-full bg-[#0f172a] border border-brand-500/50 rounded-xl text-brand-300 pl-11 pr-4 py-3 focus:outline-none font-mono font-bold text-lg opacity-80 cursor-not-allowed shadow-[0_0_15px_rgba(16,185,129,0.1)]"
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 bg-[#0f172a]/40 p-4 rounded-xl border border-[#334155]/60">
                                                    {/* Coins Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Coins</span>
                                                        {COINS.map(coin => (
                                                            <div key={coin} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{coin.toFixed(2)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={startForm.data.opening_denominations[coin.toString()] || ''}
                                                                    onChange={e => handleStartDenominationChange(coin.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>

                                                    {/* Bills Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Bills</span>
                                                        {BILLS.map(bill => (
                                                            <div key={bill} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{bill.toFixed(0)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={startForm.data.opening_denominations[bill.toString()] || ''}
                                                                    onChange={e => handleStartDenominationChange(bill.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Column 2: Minibar Cash Drawer */}
                                            <div className="flex flex-col gap-4 bg-[#0f172a]/20 p-5 rounded-2xl border border-[#334155]/50">
                                                <h3 className="text-sm font-outfit font-bold text-slate-355 border-b border-[#334155] pb-2 flex items-center gap-2">
                                                    <PackageOpen size={16} className="text-brand-400" /> Minibar Cash Drawer
                                                </h3>
                                                <div>
                                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-2">Starting Minibar Drawer Capital (₱)</label>
                                                    <div className="relative mb-2">
                                                        <Coins className="absolute left-4 top-3.5 text-brand-500" size={16} />
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            value={startForm.data.opening_cash_minibar}
                                                            readOnly
                                                            className="w-full bg-[#0f172a] border border-brand-500/50 rounded-xl text-brand-300 pl-11 pr-4 py-3 focus:outline-none font-mono font-bold text-lg opacity-80 cursor-not-allowed shadow-[0_0_15px_rgba(16,185,129,0.1)]"
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 bg-[#0f172a]/40 p-4 rounded-xl border border-[#334155]/60">
                                                    {/* Coins Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Coins</span>
                                                        {COINS.map(coin => (
                                                            <div key={coin} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{coin.toFixed(2)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={startForm.data.opening_denominations_minibar[coin.toString()] || ''}
                                                                    onChange={e => handleStartDenominationMinibarChange(coin.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>

                                                    {/* Bills Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Bills</span>
                                                        {BILLS.map(bill => (
                                                            <div key={bill} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{bill.toFixed(0)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={startForm.data.opening_denominations_minibar[bill.toString()] || ''}
                                                                    onChange={e => handleStartDenominationMinibarChange(bill.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Previous shift physical handover */}
                                    {previousClosedShift && (
                                        <div className="p-4 rounded-2xl bg-[#0f172a]/40 border border-[#334155] space-y-3">
                                            <div>
                                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-300">Previous Shift Closing Count</h3>
                                                <p className="text-[10px] text-slate-500 mt-0.5">
                                                    Physical cash presented for handover{previousClosedShift.user_name ? ` by ${previousClosedShift.user_name}` : ''}. Incoming opening cash is your own count, not the previous expected cash.
                                                </p>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                                <div className="flex justify-between p-3 rounded-xl bg-[#1e293b] border border-[#334155]">
                                                    <span className="text-slate-400">Rooms</span>
                                                    <span className="font-mono font-bold text-slate-100">₱{previousRoomsClosing.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                                <div className="flex justify-between p-3 rounded-xl bg-[#1e293b] border border-[#334155]">
                                                    <span className="text-slate-400">Minibar</span>
                                                    <span className="font-mono font-bold text-slate-100">₱{previousMinibarClosing.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            </div>
                                            <div>
                                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-300">Opening Count Received</h3>
                                                <p className="text-[10px] text-slate-500 mt-0.5">Count the drawer physically. Do not open from the previous expected amount.</p>
                                            </div>
                                            {hasHandoverDifference && (
                                                <div className="p-3 rounded-xl bg-amber-950/30 border border-amber-500/30 space-y-1 text-xs">
                                                    <div className="font-extrabold uppercase tracking-wider text-amber-300">Handover Difference</div>
                                                    <div className="flex justify-between font-mono text-amber-100">
                                                        <span>Rooms</span>
                                                        <span>{roomsHandoverDifference > 0 ? '+' : ''}₱{roomsHandoverDifference.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="flex justify-between font-mono text-amber-100">
                                                        <span>Minibar</span>
                                                        <span>{minibarHandoverDifference > 0 ? '+' : ''}₱{minibarHandoverDifference.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <p className="text-[10px] text-amber-200/80">This difference is recorded for later admin review. It is not assigned as a shortage resolution.</p>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {hasHandoverDifference && (
                                        <div className="flex flex-col gap-2">
                                            <label className="text-xs font-semibold text-rose-300 uppercase tracking-wider">Handover Explanation (Required)</label>
                                            <textarea
                                                value={startForm.data.handover_notes}
                                                onChange={e => startForm.setData('handover_notes', e.target.value)}
                                                placeholder="Explain why the opening count differs from the previous closing count..."
                                                rows="3"
                                                required
                                                className="w-full bg-[#0f172a] border border-rose-500/50 rounded-xl text-slate-100 p-4 focus:outline-none focus:border-rose-400 text-sm"
                                            />
                                            {startForm.errors.handover_notes && (
                                                <span className="text-[10px] text-rose-400 font-semibold">{startForm.errors.handover_notes}</span>
                                            )}
                                        </div>
                                    )}

                                    {/* Shift Opening Notes */}
                                    <div className="flex flex-col gap-2">
                                        <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Shift Opening Notes</label>
                                        <textarea
                                            value={startForm.data.notes}
                                            onChange={e => startForm.setData('notes', e.target.value)}
                                            placeholder="Specify cash denominations or handover details..."
                                            rows="3"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-4 focus:outline-none focus:border-brand-500 text-sm"
                                        />
                                    </div>

                                    {/* Action button */}
                                    <button
                                        type="submit"
                                        disabled={startForm.processing}
                                        className="w-full flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-slate-50 font-outfit font-extrabold text-sm tracking-wide shadow-lg shadow-brand-600/20 active:scale-95 transition-all"
                                    >
                                        <Play size={16} />
                                        <span>PROCEED AND OPEN REGISTER</span>
                                    </button>
                                </form>
                            </motion.div>

                        ) : (

                            /* Active Shift Live Board */
                            <motion.div
                                initial={{ opacity: 0, scale: 0.98 }}
                                animate={{ opacity: 1, scale: 1 }}
                                className="flex flex-col gap-6"
                            >
                                <div className="p-6 md:p-8 rounded-2xl bg-emerald-950/20 border border-emerald-500/20 shadow-xl">

                                    <div className="flex flex-col sm:flex-row justify-between items-start gap-4 mb-6">
                                        <div className="flex items-center gap-3.5">
                                            <div className="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl">
                                                <Clock size={24} />
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xl font-outfit font-bold text-slate-200 uppercase tracking-wide">{activeShift.shift_code} Shift Active</span>
                                                    <span className="h-2 w-2 bg-emerald-400 rounded-full"></span>
                                                </div>
                                                <p className="text-xs text-slate-400 font-medium">Logged in at {new Date(activeShift.started_at).toLocaleString()}</p>
                                            </div>
                                        </div>

                                        <Link
                                            href={route('shifts.report', activeShift.id)}
                                            className="w-full sm:w-auto px-4 py-2 bg-[#1e293b] border border-[#334155] rounded-xl text-slate-300 hover:text-slate-100 text-xs font-bold font-outfit flex justify-center items-center gap-1.5 transition-colors self-start sm:self-center mt-2 sm:mt-0"
                                        >
                                            <Printer size={14} />
                                            <span>Live Shift Report</span>
                                        </Link>
                                    </div>

                                    {/* Live Sales Metrics Grid */}
                                    {/* Live Sales Metrics Grid */}
                                    {liveSummary && (
                                        <>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 bg-[#0f172a]/30 p-5 border border-[#334155]/60 rounded-2xl">
                                                {/* Rooms Drawer Live */}
                                                <div className="flex flex-col gap-3 p-4 rounded-xl bg-[#0f172a]/55 border border-[#334155]/60">
                                                    <h4 className="text-xs font-bold text-slate-300 uppercase tracking-wider border-b border-[#334155] pb-1.5 flex items-center gap-1.5">
                                                        <Hotel size={14} className="text-brand-400" /> Rooms Cash Drawer
                                                    </h4>
                                                    <div className="grid grid-cols-3 gap-2 text-xs">
                                                        <div className="flex flex-col">
                                                            <span className="text-[9px] text-slate-500 font-semibold uppercase">Starting</span>
                                                            <span className="font-mono font-bold text-slate-300">₱{activeShift.opening_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-[9px] text-slate-500 font-semibold uppercase">Cash Collected</span>
                                                            <span className="font-mono font-bold text-brand-300">+₱{(roomsReconciliation?.cash_collections ?? liveSummary.sales.rooms_cash).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-[9px] text-emerald-500 font-bold uppercase">Expected Cash</span>
                                                            <span className="font-mono font-bold text-emerald-400">₱{liveSummary.expected_drawer_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            {(liveSummary.incomes_sum > 0 || liveSummary.expenses_sum > 0) && (
                                                                <span className="text-[8px] text-slate-500 font-medium font-mono leading-tight">
                                                                    In: +{liveSummary.incomes_sum.toLocaleString()} | Out: -{liveSummary.expenses_sum.toLocaleString()}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Minibar Drawer Live */}
                                                <div className="flex flex-col gap-3 p-4 rounded-xl bg-[#0f172a]/55 border border-[#334155]/60">
                                                    <h4 className="text-xs font-bold text-slate-300 uppercase tracking-wider border-b border-[#334155] pb-1.5 flex items-center gap-1.5">
                                                        <PackageOpen size={14} className="text-brand-400" /> Minibar Cash Drawer
                                                    </h4>
                                                    <div className="grid grid-cols-3 gap-2 text-xs">
                                                        <div className="flex flex-col">
                                                            <span className="text-[9px] text-slate-500 font-semibold uppercase">Starting</span>
                                                            <span className="font-mono font-bold text-slate-300">₱{activeShift.opening_cash_minibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-[9px] text-slate-500 font-semibold uppercase">Cash Collected</span>
                                                            <span className="font-mono font-bold text-brand-300">+₱{(minibarReconciliation?.cash_collections ?? liveSummary.sales.minibar_cash).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-[9px] text-emerald-500 font-bold uppercase">Expected Cash</span>
                                                            <span className="font-mono font-bold text-emerald-400">₱{liveSummary.expected_drawer_cash_minibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Detailed Multi-Channel Ledger Reconciler */}
                                            <div className="mt-4 p-4 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs">
                                                <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-2.5">Handover Ledger Audits</span>
                                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                    <div className="p-3 rounded-xl bg-[#0f172a]/55 border border-[#334155]/40 flex flex-col gap-0.5">
                                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Cash</span>
                                                        <span className="font-mono text-xs font-bold text-slate-350">₱{Number(liveSummary.sales.cash || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="p-3 rounded-xl bg-[#0f172a]/55 border border-[#334155]/40 flex flex-col gap-0.5">
                                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">GCash</span>
                                                        <span className="font-mono text-xs font-bold text-brand-300">₱{Number(liveSummary.sales.gcash || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="p-3 rounded-xl bg-[#0f172a]/55 border border-[#334155]/40 flex flex-col gap-0.5">
                                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Card</span>
                                                        <span className="font-mono text-xs font-bold text-slate-355">₱{Number(liveSummary.sales.card || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="p-3 rounded-xl bg-[#0f172a]/55 border border-[#334155]/40 flex flex-col gap-0.5">
                                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Bank Transfer</span>
                                                        <span className="font-mono text-xs font-bold text-slate-350">₱{Number(liveSummary.sales.bank_transfer || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                </div>
                                                <div className="mt-3.5 pt-2.5 border-t border-[#334155]/30 flex justify-between items-center text-[10px] text-slate-400 font-bold">
                                                    <span>Audited Collections (All electronic & physical channels):</span>
                                                    <span className="font-mono text-brand-300 text-xs">₱{Number(liveSummary.sales.total_collected || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>

                                                <div className="mt-3 grid grid-cols-2 gap-3 text-xs pt-3 border-t border-[#334155]/20">
                                                    <div className="p-3 rounded-xl bg-[#0f172a]/55 border border-[#334155]/40 flex justify-between items-center">
                                                        <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Shift Expenses Disbursed</span>
                                                        <span className="font-mono text-xs font-bold text-rose-400">-₱{Number(liveSummary.expenses_sum || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="p-3 rounded-xl bg-[#0f172a]/55 border border-[#334155]/40 flex justify-between items-center">
                                                        <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Shift Additional Cash</span>
                                                        <span className="font-mono text-xs font-bold text-emerald-400">+₱{Number(liveSummary.incomes_sum || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </>
                                    )}

                                    {activeShift.notes && (
                                        <div className="mt-4 p-3.5 bg-[#0f172a]/30 border border-[#334155]/60 rounded-xl text-xs text-slate-400">
                                            <span className="font-bold text-slate-300">Opening Notes:</span> {activeShift.notes}
                                        </div>
                                    )}
                                </div>

                                {/* End Shift Close Section */}
                                <div className="p-6 md:p-8 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                                    <div className="flex items-center gap-3.5 mb-6">
                                        <div className="p-3 bg-red-500/10 text-red-400 rounded-xl">
                                            <Power size={24} />
                                        </div>
                                        <div>
                                            <h2 className="text-lg font-outfit font-bold text-slate-200">Close Register & End Shift</h2>
                                            <p className="text-xs text-slate-400 font-medium">Count rooms and minibar cash, then complete the tracked inventory physical count before ending the shift.</p>
                                        </div>
                                    </div>
                                    <form onSubmit={handleEndShift} className="space-y-6">
                                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                            {/* Rooms Drawer End */}
                                            <div className="flex flex-col gap-4 bg-[#0f172a]/20 p-5 rounded-2xl border border-[#334155]/50">
                                                <h3 className="text-sm font-outfit font-bold text-slate-355 border-b border-[#334155] pb-2 flex items-center gap-2">
                                                    <Hotel size={16} className="text-brand-400" /> Rooms Closing Drawer Count
                                                </h3>
                                                <div>
                                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-2">Physical Rooms Drawer Count (₱)</label>
                                                    <div className="relative mb-2">
                                                        <Coins className="absolute left-4 top-3.5 text-brand-500" size={16} />
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            value={endForm.data.closing_cash}
                                                            readOnly
                                                            className="w-full bg-[#0f172a] border border-brand-500/50 rounded-xl text-brand-300 pl-11 pr-4 py-3 focus:outline-none font-mono font-bold text-lg opacity-80 cursor-not-allowed shadow-[0_0_15px_rgba(16,185,129,0.1)]"
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 bg-[#0f172a]/40 p-4 rounded-xl border border-[#334155]/60">
                                                    {/* Coins Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Coins</span>
                                                        {COINS.map(coin => (
                                                            <div key={coin} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{coin.toFixed(2)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={endForm.data.closing_denominations[coin.toString()] || ''}
                                                                    onChange={e => handleDenominationChange(coin.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>

                                                    {/* Bills Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Bills</span>
                                                        {BILLS.map(bill => (
                                                            <div key={bill} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{bill.toFixed(0)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={endForm.data.closing_denominations[bill.toString()] || ''}
                                                                    onChange={e => handleDenominationChange(bill.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>

                                                {liveSummary && (
                                                    <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex flex-col text-xs">
                                                        <div className="flex justify-between font-medium">
                                                            <span className="text-slate-400">Expected Rooms Drawer:</span>
                                                            <span className="font-mono text-slate-200 font-bold">₱{liveSummary.expected_drawer_cash.toLocaleString()}</span>
                                                        </div>
                                                        <div className="flex justify-between mt-2 border-t border-[#334155]/60 pt-2 font-medium">
                                                            <span className="text-slate-400">Physical count:</span>
                                                            <span className="font-mono text-slate-200 font-bold">₱{Number(endForm.data.closing_cash || 0).toLocaleString()}</span>
                                                        </div>
                                                        <div className="flex justify-between mt-2 border-t border-[#334155]/60 pt-2 font-bold font-outfit">
                                                            <span>Rooms Drawer Variance:</span>
                                                            <span className={`font-mono ${Number(endForm.data.closing_cash || 0) - liveSummary.expected_drawer_cash === 0
                                                                ? 'text-emerald-400'
                                                                : Number(endForm.data.closing_cash || 0) - liveSummary.expected_drawer_cash > 0
                                                                    ? 'text-blue-400'
                                                                    : 'text-rose-400'
                                                                }`}>
                                                                ₱{(Number(endForm.data.closing_cash || 0) - liveSummary.expected_drawer_cash).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                            </span>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Minibar Drawer End */}
                                            <div className="flex flex-col gap-4 bg-[#0f172a]/20 p-5 rounded-2xl border border-[#334155]/50">
                                                <h3 className="text-sm font-outfit font-bold text-slate-355 border-b border-[#334155] pb-2 flex items-center gap-2">
                                                    <PackageOpen size={16} className="text-brand-400" /> Minibar Closing Drawer Count
                                                </h3>
                                                <div>
                                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-2">Physical Minibar Drawer Count (₱)</label>
                                                    <div className="relative mb-2">
                                                        <Coins className="absolute left-4 top-3.5 text-brand-500" size={16} />
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            value={endForm.data.closing_cash_minibar}
                                                            readOnly
                                                            className="w-full bg-[#0f172a] border border-brand-500/50 rounded-xl text-brand-300 pl-11 pr-4 py-3 focus:outline-none font-mono font-bold text-lg opacity-80 cursor-not-allowed shadow-[0_0_15px_rgba(16,185,129,0.1)]"
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 bg-[#0f172a]/40 p-4 rounded-xl border border-[#334155]/60">
                                                    {/* Coins Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Coins</span>
                                                        {COINS.map(coin => (
                                                            <div key={coin} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{coin.toFixed(2)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={endForm.data.closing_denominations_minibar[coin.toString()] || ''}
                                                                    onChange={e => handleDenominationMinibarChange(coin.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>

                                                    {/* Bills Column */}
                                                    <div className="flex flex-col gap-2">
                                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-[#334155]/60 pb-1.5 mb-1">Bills</span>
                                                        {BILLS.map(bill => (
                                                            <div key={bill} className="flex items-center gap-2">
                                                                <span className="w-16 shrink-0 text-right font-mono text-sm text-slate-300">₱{bill.toFixed(0)}</span>
                                                                <span className="text-slate-600 shrink-0 text-xs font-bold">x</span>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={endForm.data.closing_denominations_minibar[bill.toString()] || ''}
                                                                    onChange={e => handleDenominationMinibarChange(bill.toString(), e.target.value)}
                                                                    className="flex-1 min-w-0 bg-[#1e293b] border border-[#334155] rounded-lg text-slate-200 px-2 py-1.5 font-mono text-sm focus:border-brand-500"
                                                                    placeholder="0"
                                                                />
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>

                                                {liveSummary && (
                                                    <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex flex-col text-xs">
                                                        <div className="flex justify-between font-medium">
                                                            <span className="text-slate-400">Expected Minibar Drawer:</span>
                                                            <span className="font-mono text-slate-200 font-bold">₱{liveSummary.expected_drawer_cash_minibar.toLocaleString()}</span>
                                                        </div>
                                                        <div className="flex justify-between mt-2 border-t border-[#334155]/60 pt-2 font-medium">
                                                            <span className="text-slate-400">Physical count:</span>
                                                            <span className="font-mono text-slate-200 font-bold">₱{Number(endForm.data.closing_cash_minibar || 0).toLocaleString()}</span>
                                                        </div>
                                                        <div className="flex justify-between mt-2 border-t border-[#334155]/60 pt-2 font-bold font-outfit">
                                                            <span>Minibar Drawer Variance:</span>
                                                            <span className={`font-mono ${Number(endForm.data.closing_cash_minibar || 0) - liveSummary.expected_drawer_cash_minibar === 0
                                                                ? 'text-emerald-400'
                                                                : Number(endForm.data.closing_cash_minibar || 0) - liveSummary.expected_drawer_cash_minibar > 0
                                                                    ? 'text-blue-400'
                                                                    : 'text-rose-400'
                                                                }`}>
                                                                ₱{(Number(endForm.data.closing_cash_minibar || 0) - liveSummary.expected_drawer_cash_minibar).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                            </span>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        {/* Closing notes */}
                                        {(() => {
                                            const varianceRooms = liveSummary ? (Number(endForm.data.closing_cash || 0) - liveSummary.expected_drawer_cash) : 0;
                                            const varianceMinibar = liveSummary ? (Number(endForm.data.closing_cash_minibar || 0) - liveSummary.expected_drawer_cash_minibar) : 0;
                                            const isDiscrepancy = Math.abs(varianceRooms) >= 0.01 || Math.abs(varianceMinibar) >= 0.01;
                                            return (
                                                <div className="flex flex-col gap-2">
                                                    <div className="flex justify-between items-center">
                                                        <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Shift Closing Notes & Handover Report</label>
                                                        {isDiscrepancy && (
                                                            <span className="text-[10px] bg-red-950 border border-red-500/30 text-rose-400 font-extrabold uppercase px-2 py-0.5 rounded">
                                                                ⚠️ EXPLAIN DISCREPANCY (REQUIRED)
                                                            </span>
                                                        )}
                                                    </div>
                                                    <textarea
                                                        value={endForm.data.notes}
                                                        onChange={e => endForm.setData('notes', e.target.value)}
                                                        placeholder={isDiscrepancy
                                                            ? "REQUIRED: Please explain the reason for the cash variance (shortage/overage) before shutting down."
                                                            : "Explain any drawer variances, cash deposit transfers, or handover remarks..."}
                                                        rows="3"
                                                        required={isDiscrepancy}
                                                        className={`w-full bg-[#0f172a] border rounded-xl text-slate-100 p-4 focus:outline-none text-sm transition-all duration-200 ${isDiscrepancy ? 'border-red-500/50 focus:border-red-500' : 'border-[#334155] focus:border-brand-500'}`}
                                                    />
                                                </div>
                                            );
                                        })()}

                                        {inventoryTurnover?.has_tracked_items && inventoryTurnover?.requires_count_before_end && (
                                            <div className="rounded-xl border border-amber-500/40 bg-amber-950/40 p-4 text-xs text-amber-100">
                                                Tracked inventory count is required before a normal End Shift.
                                                Current status: <span className="uppercase font-bold">{inventoryTurnover.current_status || 'not started'}</span>.
                                                {' '}<Link href={route('shifts.inventory_turnover.show')} className="underline font-bold">Open Inventory Turnover</Link>
                                            </div>
                                        )}
                                        {inventoryTurnover?.pending_handover_status && (
                                            <div className="rounded-xl border border-sky-500/40 bg-sky-950/40 p-4 text-xs text-sky-100">
                                                Previous inventory handover is {inventoryTurnover.pending_handover_status}. Incoming tracked stock cannot be used until it is accepted.
                                            </div>
                                        )}
                                        {endForm.errors.inventory_turnover && (
                                            <p className="text-xs text-rose-400 font-bold">{endForm.errors.inventory_turnover}</p>
                                        )}

                                        {((unresolvedExpenses?.pending || 0) + (unresolvedExpenses?.approved_unpaid || 0)) > 0 && (
                                            <div className="rounded-xl border border-amber-500/40 bg-amber-950/40 p-4 text-xs text-amber-100">
                                                {(unresolvedExpenses.pending + unresolvedExpenses.approved_unpaid)} expense request{(unresolvedExpenses.pending + unresolvedExpenses.approved_unpaid) === 1 ? '' : 's'} from this shift {(unresolvedExpenses.pending + unresolvedExpenses.approved_unpaid) === 1 ? 'is' : 'are'} still unresolved.
                                                They will remain in the approval/disbursement workflow after this shift closes and will not affect this shift's cash unless already POSTED.
                                            </div>
                                        )}

                                        {canReviewVariances && inventoryTurnover?.has_tracked_items && inventoryTurnover?.requires_count_before_end && (
                                            <div className="flex flex-col gap-2">
                                                <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Admin inventory override reason</label>
                                                <textarea
                                                    value={endForm.data.inventory_override_reason}
                                                    onChange={e => endForm.setData('inventory_override_reason', e.target.value)}
                                                    placeholder="Required only if ending the cash shift without a completed inventory turnover. This does not pretend stock was counted."
                                                    rows="2"
                                                    className="w-full bg-[#0f172a] border border-amber-500/40 rounded-xl text-slate-100 p-4 focus:outline-none text-sm"
                                                />
                                                {endForm.errors.inventory_override_reason && (
                                                    <p className="text-xs text-rose-400">{endForm.errors.inventory_override_reason}</p>
                                                )}
                                            </div>
                                        )}
                                        <button
                                            type="submit"
                                            disabled={endForm.processing}
                                            className="w-full flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-red-600 hover:bg-red-500 text-slate-50 font-outfit font-extrabold text-sm tracking-wide shadow-lg shadow-red-600/20 active:scale-95 transition-all"
                                        >
                                            <Power size={16} />
                                            <span>Log Off</span>
                                        </button>
                                    </form>
                                </div>
                            </motion.div>
                        )}
                    </div>
                </div>

                {/* History Session Records */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="px-6 py-4 border-b border-[#334155]">
                        <h2 className="text-lg font-outfit font-bold text-slate-200">Shift History</h2>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Employee</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Shift</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Started At</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Ended At</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Opening (₱)</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Closing (₱)</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Inventory</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentShifts.map((s, i) => (
                                    <motion.tr
                                        key={s.id}
                                        initial={{ opacity: 0, y: 6 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors"
                                    >
                                        <td className="px-4 py-3 font-bold font-outfit text-slate-200">
                                            {s.user?.name || 'Unknown'}
                                        </td>
                                        <td className="px-4 py-3 capitalize font-mono text-xs">
                                            {s.shift_code}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-slate-300">
                                            {new Date(s.started_at).toLocaleString()}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-slate-300">
                                            {s.ended_at ? new Date(s.ended_at).toLocaleString() : (
                                                <span className="text-emerald-400 font-bold uppercase">Running</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-slate-300">
                                            ₱{s.opening_cash.toLocaleString()}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-slate-300">
                                            {s.ended_at ? `₱${s.closing_cash.toLocaleString()}` : '-'}
                                        </td>
                                        <td className="px-4 py-3 text-[10px] uppercase font-bold text-slate-400">
                                            {s.inventory_turnover?.status || (inventoryTurnover?.has_tracked_items ? '—' : 'n/a')}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route('shifts.report', s.id)}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] border border-[#334155] rounded-xl text-xs font-bold text-slate-400 hover:text-slate-100 hover:border-brand-500 transition-colors"
                                            >
                                                <Printer size={12} />
                                                Report
                                            </Link>
                                        </td>
                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <ConfirmModal
                    isOpen={showShutdownConfirm}
                    onClose={() => setShowShutdownConfirm(false)}
                    onConfirm={executeEndShift}
                    title="Confirm Register Shutdown"
                    message="Are you sure you want to shut down the register and log off your shift session?"
                    confirmText="Shutdown & Log Off"
                    isDanger={true}
                />

            </div>
        </AuthenticatedLayout>
    );
}
