import React, { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ClipboardCheck, Package } from 'lucide-react';
import { formatPHP } from '@/Utils/currency';

function emptyCounts(items) {
    return (items || []).map((item) => ({
        inventory_item_id: item.inventory_item_id || item.id,
        quantity: item.quantity === 0 || item.quantity ? String(item.quantity) : '',
    }));
}

function varianceLabel(value) {
    if (value === '' || value === null || value === undefined || Number.isNaN(Number(value))) {
        return null;
    }
    const n = Number(value);
    if (n === 0) return 'BALANCED';
    return n < 0 ? `SHORT ${Math.abs(n)}` : `OVER ${n}`;
}

function varianceClass(label) {
    if (!label || label === 'BALANCED') {
        return 'text-emerald-400';
    }
    if (String(label).startsWith('SHORT')) {
        return 'text-rose-400';
    }
    return 'text-sky-400';
}

function StatusChip({ turnover }) {
    if (!turnover) return null;
    return (
        <div className="rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2">
            <div className="text-[10px] uppercase font-bold tracking-wider text-brand-300">{turnover.status_label || turnover.status}</div>
            <p className="text-[11px] text-slate-400 mt-0.5">{turnover.status_description}</p>
        </div>
    );
}

export default function InventoryTurnover({
    has_tracked_items: hasTrackedItems,
    tracked_count: trackedCount,
    bootstrap_pending: bootstrapPending,
    counting_freeze: countingFreeze,
    pending_handover: pendingHandover,
    current_turnover: currentTurnover,
    can_admin_resolve: canAdminResolve,
    same_operator_checkpoint: sameOperatorCheckpoint,
}) {
    const { auth, errors } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';
    const currentItems = currentTurnover?.items || [];
    const pendingItems = pendingHandover?.items || [];

    const openingDefaults = useMemo(
        () => emptyCounts(currentItems.length ? currentItems : []),
        [currentTurnover?.id, currentItems.length]
    );
    const outgoingDefaults = useMemo(
        () => emptyCounts(currentItems.map((item) => ({
            inventory_item_id: item.inventory_item_id,
            quantity: item.outgoing_actual_quantity,
        }))),
        [currentTurnover?.id, currentTurnover?.status]
    );
    const incomingDefaults = useMemo(
        () => emptyCounts(pendingItems.map((item) => ({
            inventory_item_id: item.inventory_item_id,
            quantity: sameOperatorCheckpoint
                ? (item.handover_expected_quantity ?? item.outgoing_actual_quantity)
                : item.incoming_verified_quantity,
        }))),
        [pendingHandover?.id, pendingHandover?.status, sameOperatorCheckpoint]
    );

    const openingForm = useForm({ counts: openingDefaults });
    const outgoingForm = useForm({ counts: outgoingDefaults, notes: '' });
    const incomingForm = useForm({ counts: incomingDefaults, notes: '', reason: '' });
    const startForm = useForm({});
    const cancelForm = useForm({});
    const [confirmSameOperator, setConfirmSameOperator] = useState(false);
    const [submitOpen, setSubmitOpen] = useState(false);
    const [submitAck, setSubmitAck] = useState(false);
    const [acceptOpen, setAcceptOpen] = useState(false);
    const [expandedGap, setExpandedGap] = useState(null);

    const setCount = (form, index, value) => {
        const next = [...form.data.counts];
        next[index] = { ...next[index], quantity: value };
        form.setData('counts', next);
    };

    const currentStatus = currentTurnover?.status || 'none';
    const showOpening = Boolean(
        hasTrackedItems
        && currentTurnover
        && (bootstrapPending || (currentTurnover.is_bootstrap && currentStatus === 'open' && currentTurnover.items?.some((item) => item.opening_quantity === null)))
    );
    const showOutgoing = Boolean(
        hasTrackedItems
        && currentTurnover
        && ['open', 'counting'].includes(currentStatus)
        && currentTurnover.items?.some((item) => item.opening_quantity !== null)
    );
    const canStartCount = currentStatus === 'open' && currentTurnover?.items?.every((item) => item.opening_quantity !== null);
    const canSubmitCount = currentStatus === 'counting';
    const pendingIsDisputed = pendingHandover?.status === 'disputed';

    const liveOutgoingRows = currentItems.map((item, index) => {
        const entered = outgoingForm.data.counts[index]?.quantity;
        const expected = Number(item.expected_closing_quantity ?? 0);
        const live = entered === '' || entered === undefined || entered === null ? null : Number(entered) - expected;
        return {
            ...item,
            live_variance: live,
            live_label: live === null ? (item.variance_label || '—') : varianceLabel(live),
        };
    });
    const liveShorts = liveOutgoingRows.filter((row) => row.live_variance < 0);
    const liveOvers = liveOutgoingRows.filter((row) => row.live_variance > 0);

    const incomingDiffs = pendingItems.map((item, index) => {
        const entered = incomingForm.data.counts[index]?.quantity;
        const expected = Number(item.handover_expected_quantity ?? ((item.outgoing_actual_quantity || 0) + (item.gap_net_quantity || 0)));
        if (entered === '' || entered === undefined || entered === null) {
            return { expected, diff: item.handover_difference, label: item.handover_difference_label };
        }
        const diff = Number(entered) - expected;
        return { expected, diff, label: varianceLabel(diff) };
    });
    const incomingBalanced = incomingDiffs.length > 0
        && incomingDiffs.every((row) => row.diff === 0)
        && incomingForm.data.counts.every((row) => row.quantity !== '' && row.quantity !== undefined && row.quantity !== null);
    const incomingHasMismatch = incomingDiffs.some((row) => row.diff !== null && row.diff !== 0 && row.diff !== undefined);

    return (
        <AuthenticatedLayout>
            <Head title="Inventory Turnover" />
            <div className="max-w-7xl mx-auto flex flex-col gap-6">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Link href={route('shifts.index')} className="p-2 rounded-xl border border-[#334155] text-slate-400 hover:text-white">
                            <ChevronLeft size={18} />
                        </Link>
                        <div>
                            <h1 className="text-xl font-outfit font-bold text-slate-100">Inventory Turnover</h1>
                            <p className="text-xs text-slate-400">Physical outgoing count and incoming acceptance. Separate from cash variance.</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('shifts.inventory_turnover.history')} className="text-[10px] uppercase font-bold tracking-wider text-brand-300 hover:text-white">
                            Turnover history
                        </Link>
                        <div className="flex items-center gap-2 text-[10px] uppercase font-bold tracking-wider text-slate-400">
                            <ClipboardCheck size={14} className="text-brand-400" />
                            {trackedCount} tracked product{trackedCount === 1 ? '' : 's'}
                        </div>
                    </div>
                </div>

                {!hasTrackedItems && (
                    <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5 text-sm text-slate-300">
                        No products are marked <span className="font-bold text-emerald-300">Turnover tracked</span>.
                        Admin must select the notebook items (for example Mineral Water, Coke, Safeguard, Shampoo) on Inventory before a physical count is required.
                    </div>
                )}

                {countingFreeze && (
                    <div className="rounded-2xl border border-amber-500/40 bg-amber-950/30 p-4 text-sm text-amber-100">
                        Physical inventory count is in progress. Tracked inventory is temporarily locked.
                    </div>
                )}

                {pendingHandover && (
                    <section className="rounded-2xl border border-[#334155] bg-[#1e293b] p-6 flex flex-col gap-4">
                        <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-outfit font-bold text-slate-100">Incoming handover verification</h2>
                                <p className="text-xs text-slate-400 mt-1">
                                    Expected at handover is outgoing actual plus authorized between-shift movement. A restock of +10 is not a discrepancy.
                                    Handover difference is separate from outgoing inventory variance. Do not combine them into a total shortage.
                                </p>
                            </div>
                            <StatusChip turnover={pendingHandover} />
                        </div>
                        {sameOperatorCheckpoint && (
                            <p className="text-xs text-sky-300">
                                Confirm these physical counts as your new opening inventory. The snapshot boundary is still required even when the same operator continues.
                            </p>
                        )}
                        {pendingHandover.disputed_reason && (
                            <p className="text-xs text-rose-300">Dispute reason: {pendingHandover.disputed_reason}</p>
                        )}
                        {pendingIsDisputed && (
                            <p className="text-xs text-amber-200">This handover is disputed. Front Desk cannot normal-accept. Admin must resolve or request a recount.</p>
                        )}
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs text-left min-w-[900px]">
                                <thead>
                                    <tr className="text-[10px] uppercase text-slate-400 border-b border-[#334155]">
                                        <th className="py-2 pr-3">Product</th>
                                        <th className="py-2 pr-3">Outgoing actual</th>
                                        <th className="py-2 pr-3">Between-shift movement</th>
                                        <th className="py-2 pr-3">Expected at handover</th>
                                        <th className="py-2 pr-3">Incoming physical count</th>
                                        <th className="py-2">Incoming Handover Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pendingItems.map((item, index) => {
                                        const row = incomingDiffs[index];
                                        const gapMoves = (pendingHandover.gap_movements || []).filter((move) => move.inventory_item_id === item.inventory_item_id);
                                        return (
                                            <tr key={item.inventory_item_id} className="border-b border-[#334155]/40 align-top">
                                                <td className="py-2 pr-3 font-bold text-slate-100">{item.item_name}</td>
                                                <td className="py-2 pr-3 font-mono">{item.outgoing_actual_quantity}</td>
                                                <td className="py-2 pr-3 font-mono">
                                                    <button type="button" className="underline decoration-dotted" onClick={() => setExpandedGap(expandedGap === item.inventory_item_id ? null : item.inventory_item_id)}>
                                                        {item.gap_net_label ?? (item.gap_net_quantity > 0 ? `+${item.gap_net_quantity}` : item.gap_net_quantity)}
                                                    </button>
                                                    {expandedGap === item.inventory_item_id && (
                                                        <div className="mt-1 text-[10px] text-slate-400 font-sans space-y-1">
                                                            {gapMoves.length === 0 ? 'No authorized between-shift movements.' : gapMoves.map((move, i) => (
                                                                <div key={i}>
                                                                    {move.movement_type === 'restock' ? 'Admin Restock' : move.movement_type} {move.quantity_label}
                                                                    <div>{move.created_at_manila} · {move.performed_by_name || 'Unknown'}</div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-2 pr-3 font-mono font-bold">{row.expected}</td>
                                                <td className="py-2 pr-3">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="1"
                                                        value={incomingForm.data.counts[index]?.quantity ?? ''}
                                                        onChange={(e) => setCount(incomingForm, index, e.target.value)}
                                                        className="w-24 bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-1.5 font-mono text-slate-100"
                                                    />
                                                </td>
                                                <td className={`py-2 font-mono font-bold ${varianceClass(row.label)}`}>
                                                    {row.diff === null || row.diff === undefined ? (row.label || '—') : row.diff}
                                                    {row.diff < 0 && (
                                                        <div className="text-[10px] font-normal text-slate-500">
                                                            Reference Retail Value: {formatPHP(Math.abs(row.diff) * Number(item.selling_price || 0))}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {errors.counts && <p className="text-xs text-rose-400">{errors.counts}</p>}
                        <textarea
                            value={incomingForm.data.notes}
                            onChange={(e) => incomingForm.setData('notes', e.target.value)}
                            placeholder="Optional verification notes"
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-sm text-slate-100 p-3"
                            rows={2}
                        />
                        <div className="flex flex-col sm:flex-row gap-3 flex-wrap">
                            {sameOperatorCheckpoint && (
                                <label className="flex items-center gap-2 text-xs text-slate-300">
                                    <input
                                        type="checkbox"
                                        checked={confirmSameOperator}
                                        onChange={(e) => setConfirmSameOperator(e.target.checked)}
                                    />
                                    I confirm these physical counts as my new opening inventory.
                                </label>
                            )}
                            <button
                                type="button"
                                disabled={incomingForm.processing || pendingIsDisputed || !incomingBalanced || (sameOperatorCheckpoint && !confirmSameOperator)}
                                onClick={() => setAcceptOpen(true)}
                                className="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 text-white text-xs font-bold"
                            >
                                Accept handover
                            </button>
                            <button
                                type="button"
                                className="px-4 py-2.5 rounded-xl border border-[#334155] text-slate-300 text-xs font-bold"
                                onClick={() => document.querySelector('input[type=number]')?.focus()}
                            >
                                Recount
                            </button>
                        </div>
                        {incomingHasMismatch && !pendingIsDisputed && (
                            <div className="rounded-xl border border-rose-500/20 bg-rose-950/20 p-4 flex flex-col gap-2">
                                <p className="text-xs text-rose-200">Incoming count does not match expected physical stock at handover. Recheck the count, or mark disputed with a reason. This will not change live stock or the outgoing snapshot.</p>
                                <textarea
                                    value={incomingForm.data.reason}
                                    onChange={(e) => incomingForm.setData('reason', e.target.value)}
                                    placeholder='Required dispute reason. Example: "One Coke could not be located during incoming verification."'
                                    className="w-full bg-[#0f172a] border border-rose-500/30 rounded-xl text-sm text-slate-100 p-3"
                                    rows={2}
                                />
                                {errors.reason && <p className="text-xs text-rose-400">{errors.reason}</p>}
                                <button
                                    type="button"
                                    disabled={incomingForm.processing}
                                    onClick={() => incomingForm.post(route('shifts.inventory_turnover.dispute'))}
                                    className="self-start px-4 py-2 rounded-xl border border-rose-500/40 text-rose-200 text-xs font-bold"
                                >
                                    Mark disputed
                                </button>
                            </div>
                        )}
                        {canAdminResolve && pendingIsDisputed && (
                            <Link
                                href={route('shifts.inventory_turnover.show_record', pendingHandover.id)}
                                className="self-start px-4 py-2 rounded-xl bg-sky-700 text-white text-xs font-bold"
                            >
                                Open Admin resolution
                            </Link>
                        )}
                    </section>
                )}

                {showOpening && (
                    <section className="rounded-2xl border border-[#334155] bg-[#1e293b] p-6 flex flex-col gap-4">
                        <div>
                            <h2 className="text-lg font-outfit font-bold text-slate-100">First opening count</h2>
                            <p className="text-xs text-slate-400 mt-1">
                                There is no prior accepted turnover. Count every tracked product now. This physical count becomes opening quantity.
                            </p>
                        </div>
                        <div className="grid sm:grid-cols-2 gap-3">
                            {currentItems.map((item, index) => (
                                <label key={item.inventory_item_id} className="flex items-center justify-between gap-3 bg-[#0f172a] rounded-xl px-3 py-2 border border-[#334155]">
                                    <span className="text-sm text-slate-100 font-bold">{item.item_name}</span>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={openingForm.data.counts[index]?.quantity ?? ''}
                                        onChange={(e) => setCount(openingForm, index, e.target.value)}
                                        className="w-24 bg-[#1e293b] border border-[#334155] rounded-lg px-2 py-1.5 font-mono text-slate-100"
                                    />
                                </label>
                            ))}
                        </div>
                        {errors.counts && <p className="text-xs text-rose-400">{errors.counts}</p>}
                        <button
                            type="button"
                            disabled={openingForm.processing}
                            onClick={() => openingForm.post(route('shifts.inventory_turnover.opening'))}
                            className="self-start px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold"
                        >
                            Save opening count
                        </button>
                    </section>
                )}

                {showOutgoing && currentTurnover && (
                    <section className="rounded-2xl border border-[#334155] bg-[#1e293b] p-6 flex flex-col gap-4">
                        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-outfit font-bold text-slate-100">Outgoing physical count</h2>
                                <p className="text-xs text-slate-400 mt-1">
                                    Expected closing is frozen at count start. Variance is physical count minus expected. Shortages are quantity accountability only — they do not change cash variance.
                                </p>
                            </div>
                            <StatusChip turnover={currentTurnover} />
                        </div>
                        {currentTurnover.has_manual_set && (
                            <div className="rounded-xl border border-amber-500/40 bg-amber-950/30 p-3 text-xs text-amber-100">
                                Manual stock SET occurred during this shift. Review this item before submitting turnover.
                                {(currentTurnover.manual_set_movements || []).map((move, index) => (
                                    <div key={index} className="mt-1 font-mono text-[11px]">
                                        {move.item_name}: net {move.quantity_change} · {move.performed_by_name} · {move.created_at_manila}
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs text-left min-w-[1100px]">
                                <thead>
                                    <tr className="text-[10px] uppercase text-slate-400 border-b border-[#334155]">
                                        <th className="py-2 pr-2">Product</th>
                                        <th className="py-2 pr-2">Opening</th>
                                        <th className="py-2 pr-2">Restock</th>
                                        <th className="py-2 pr-2">Returns</th>
                                        <th className="py-2 pr-2">Sold</th>
                                        <th className="py-2 pr-2">Complimentary</th>
                                        <th className="py-2 pr-2">Other out</th>
                                        <th className="py-2 pr-2">Manual adjustment</th>
                                        <th className="py-2 pr-2">Expected closing</th>
                                        <th className="py-2 pr-2">Physical count</th>
                                        <th className="py-2">Outgoing Inventory Variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {liveOutgoingRows.map((item, index) => (
                                        <tr key={item.inventory_item_id} className="border-b border-[#334155]/40">
                                            <td className="py-2 pr-2 font-bold text-slate-100">{item.item_name}</td>
                                            <td className="py-2 pr-2 font-mono">{item.opening_quantity ?? '—'}</td>
                                            <td className="py-2 pr-2 font-mono">{item.restock_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.return_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.sold_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.complimentary_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.other_out_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.manual_set_quantity || '—'}</td>
                                            <td className="py-2 pr-2 font-mono font-bold">{item.expected_closing_quantity ?? '—'}</td>
                                            <td className="py-2 pr-2">
                                                {canSubmitCount ? (
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="1"
                                                        value={outgoingForm.data.counts[index]?.quantity ?? ''}
                                                        onChange={(e) => setCount(outgoingForm, index, e.target.value)}
                                                        className="w-24 bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-1.5 font-mono text-slate-100"
                                                    />
                                                ) : (
                                                    <span className="font-mono">{item.outgoing_actual_quantity ?? '—'}</span>
                                                )}
                                            </td>
                                            <td className={`py-2 font-mono font-bold ${varianceClass(item.live_label)}`}>
                                                {item.live_label || '—'}
                                                {item.live_variance < 0 && (
                                                    <div className="text-[10px] font-normal text-slate-500">
                                                        Reference Retail Value: {formatPHP(Math.abs(item.live_variance) * Number(item.selling_price || 0))}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {errors.counts && <p className="text-xs text-rose-400">{errors.counts}</p>}
                        {errors.inventory_turnover && <p className="text-xs text-rose-400">{errors.inventory_turnover}</p>}
                        <div className="flex flex-wrap gap-2">
                            {canStartCount && (
                                <button
                                    type="button"
                                    disabled={startForm.processing}
                                    onClick={() => startForm.post(route('shifts.inventory_turnover.start_counting'))}
                                    className="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold"
                                >
                                    Start outgoing count
                                </button>
                            )}
                            {canSubmitCount && (
                                <>
                                    <textarea
                                        value={outgoingForm.data.notes}
                                        onChange={(e) => outgoingForm.setData('notes', e.target.value)}
                                        placeholder="Optional count notes"
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-sm text-slate-100 p-3"
                                        rows={2}
                                    />
                                    <button
                                        type="button"
                                        disabled={outgoingForm.processing}
                                        onClick={() => { setSubmitAck(false); setSubmitOpen(true); }}
                                        className="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
                                    >
                                        Review and submit
                                    </button>
                                    <button
                                        type="button"
                                        disabled={cancelForm.processing}
                                        onClick={() => cancelForm.post(route('shifts.inventory_turnover.cancel_counting'))}
                                        className="px-4 py-2.5 rounded-xl border border-[#334155] text-slate-300 text-xs font-bold"
                                    >
                                        Cancel count
                                    </button>
                                </>
                            )}
                        </div>
                    </section>
                )}

                {currentTurnover?.admin_override_reason && (
                    <div className="rounded-2xl border border-amber-500/40 bg-amber-950/20 p-4 text-xs text-amber-100">
                        <div className="font-extrabold uppercase tracking-wide">Inventory turnover override</div>
                        <div>Reason: {currentTurnover.admin_override_reason}</div>
                        <div>Authorized by: {currentTurnover.admin_override_by_name || 'Admin'}</div>
                        <div>Timestamp: {currentTurnover.admin_override_at_manila || '—'}</div>
                    </div>
                )}

                <div className="flex items-center gap-2 text-[11px] text-slate-500">
                    <Package size={14} />
                    Untracked products, lodging check-in/out, rooms, and cash functions remain usable during count and handover gates.
                </div>
            </div>

            {submitOpen && (
                <div className="fixed inset-0 z-[4000] bg-black/70 flex items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-[#1e293b] border border-[#334155] p-6 flex flex-col gap-4">
                        <h3 className="text-lg font-outfit font-bold text-slate-100">Submit inventory turnover</h3>
                        <p className="text-sm text-slate-300">
                            You are submitting the physical inventory count for Shift #{currentTurnover?.shift_session_id}.
                            The submitted Expected, Physical Count, and Variance values will be frozen for audit history.
                        </p>
                        {(liveShorts.length > 0 || liveOvers.length > 0) && (
                            <div className="text-xs space-y-1">
                                {liveShorts.map((row) => <div key={`s-${row.inventory_item_id}`} className="text-rose-300">{row.item_name} — {row.live_label}</div>)}
                                {liveOvers.map((row) => <div key={`o-${row.inventory_item_id}`} className="text-sky-300">{row.item_name} — {row.live_label}</div>)}
                            </div>
                        )}
                        <label className="flex items-start gap-2 text-xs text-slate-200">
                            <input type="checkbox" checked={submitAck} onChange={(e) => setSubmitAck(e.target.checked)} className="mt-0.5" />
                            I confirm these physical counts are correct and should be frozen for audit history.
                        </label>
                        <div className="flex gap-2 justify-end">
                            <button type="button" onClick={() => setSubmitOpen(false)} className="px-4 py-2 rounded-xl border border-[#334155] text-xs font-bold text-slate-300">Cancel</button>
                            <button
                                type="button"
                                disabled={!submitAck || outgoingForm.processing}
                                onClick={() => { outgoingForm.post(route('shifts.inventory_turnover.submit')); setSubmitOpen(false); }}
                                className="px-4 py-2 rounded-xl bg-emerald-600 disabled:opacity-40 text-white text-xs font-bold"
                            >
                                Confirm submit
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {acceptOpen && (
                <div className="fixed inset-0 z-[4000] bg-black/70 flex items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-[#1e293b] border border-[#334155] p-6 flex flex-col gap-4">
                        <h3 className="text-lg font-outfit font-bold text-slate-100">Accept handover</h3>
                        <p className="text-sm text-slate-300">
                            Incoming counts match expected physical stock at handover. Accepting will freeze incoming verified quantities as the next opening. Outgoing expected, actual, and variance stay unchanged.
                        </p>
                        <div className="flex gap-2 justify-end">
                            <button type="button" onClick={() => setAcceptOpen(false)} className="px-4 py-2 rounded-xl border border-[#334155] text-xs font-bold text-slate-300">Cancel</button>
                            <button
                                type="button"
                                disabled={incomingForm.processing}
                                onClick={() => { incomingForm.post(route('shifts.inventory_turnover.accept')); setAcceptOpen(false); }}
                                className="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold"
                            >
                                Confirm accept
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
