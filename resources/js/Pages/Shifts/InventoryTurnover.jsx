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

function varianceClass(label) {
    if (!label || label === 'BALANCED') {
        return 'text-emerald-400';
    }
    if (String(label).startsWith('SHORT')) {
        return 'text-rose-400';
    }
    return 'text-sky-400';
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
    const resolveForm = useForm({
        counts: incomingDefaults,
        reason: pendingHandover?.disputed_reason || '',
    });
    const startForm = useForm({});
    const cancelForm = useForm({});
    const [confirmSameOperator, setConfirmSameOperator] = useState(false);

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
                    <div className="flex items-center gap-2 text-[10px] uppercase font-bold tracking-wider text-slate-400">
                        <ClipboardCheck size={14} className="text-brand-400" />
                        {trackedCount} tracked product{trackedCount === 1 ? '' : 's'}
                    </div>
                </div>

                {!hasTrackedItems && (
                    <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5 text-sm text-slate-300">
                        No products are marked <span className="font-bold text-emerald-300">Turnover tracked</span>.
                        Admin must select the notebook items (for example Mineral Water, Coke, Safeguard, Shampoo) on Inventory before a physical count is required.
                        Laundry and other untracked products stay usable. End Shift will not invent an empty turnover.
                    </div>
                )}

                {countingFreeze && (
                    <div className="rounded-2xl border border-amber-500/40 bg-amber-950/30 p-4 text-sm text-amber-100">
                        Inventory turnover count is in progress. Tracked inventory is temporarily locked.
                    </div>
                )}

                {pendingHandover && (
                    <section className="rounded-2xl border border-[#334155] bg-[#1e293b] p-6 flex flex-col gap-4">
                        <div>
                            <h2 className="text-lg font-outfit font-bold text-slate-100">Incoming verification</h2>
                            <p className="text-xs text-slate-400 mt-1">
                                Count the stock in front of you. Expected at handover is outgoing actual plus authorized between-shift movements.
                                Incoming opening is the accepted physical count. Handover difference is vs expected at handover, not vs outgoing expected closing.
                                Status: <span className="uppercase font-bold text-brand-300">{pendingHandover.status}</span>
                                {pendingHandover.submitted_at_manila ? ` · Submitted ${pendingHandover.submitted_at_manila}` : ''}
                            </p>
                            {sameOperatorCheckpoint && (
                                <p className="mt-2 text-xs text-sky-300">
                                    Confirm these physical counts as your new opening inventory. The snapshot boundary is still required even when the same operator continues.
                                </p>
                            )}
                            {pendingHandover.disputed_reason && (
                                <p className="mt-2 text-xs text-rose-300">Dispute reason: {pendingHandover.disputed_reason}</p>
                            )}
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs text-left">
                                <thead>
                                    <tr className="text-[10px] uppercase text-slate-400 border-b border-[#334155]">
                                        <th className="py-2 pr-3">Product</th>
                                        <th className="py-2 pr-3">Outgoing actual</th>
                                        <th className="py-2 pr-3">Between-shift movement</th>
                                        <th className="py-2 pr-3">Expected at handover</th>
                                        <th className="py-2 pr-3">Incoming count</th>
                                        <th className="py-2">Handover difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pendingItems.map((item, index) => {
                                        const entered = incomingForm.data.counts[index]?.quantity;
                                        const expected = Number(item.handover_expected_quantity ?? ((item.outgoing_actual_quantity || 0) + (item.gap_net_quantity || 0)));
                                        const diff = entered === '' || entered === undefined || entered === null
                                            ? item.handover_difference
                                            : Number(entered) - expected;
                                        return (
                                            <tr key={item.inventory_item_id} className="border-b border-[#334155]/40">
                                                <td className="py-2 pr-3 font-bold text-slate-100">{item.item_name}</td>
                                                <td className="py-2 pr-3 font-mono">{item.outgoing_actual_quantity}</td>
                                                <td className="py-2 pr-3 font-mono">{item.gap_net_quantity > 0 ? `+${item.gap_net_quantity}` : item.gap_net_quantity}</td>
                                                <td className="py-2 pr-3 font-mono font-bold">{expected}</td>
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
                                                <td className={`py-2 font-mono font-bold ${diff === 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                                                    {entered === '' || entered === undefined ? (item.handover_difference ?? '—') : diff}
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
                        <div className="flex flex-col sm:flex-row gap-3">
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
                                disabled={incomingForm.processing || (sameOperatorCheckpoint && !confirmSameOperator)}
                                onClick={() => incomingForm.post(route('shifts.inventory_turnover.accept'))}
                                className="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
                            >
                                Accept handover
                            </button>
                        </div>
                        <div className="rounded-xl border border-rose-500/20 bg-rose-950/20 p-4 flex flex-col gap-2">
                            <p className="text-xs text-rose-200">If the physical count does not match the outgoing declaration, do not silent-accept. Recount, then dispute with a reason.</p>
                            <textarea
                                value={incomingForm.data.reason}
                                onChange={(e) => incomingForm.setData('reason', e.target.value)}
                                placeholder="Required dispute reason when counts differ"
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
                        {canAdminResolve && pendingHandover.status === 'disputed' && (
                            <div className="rounded-xl border border-sky-500/30 bg-sky-950/20 p-4 flex flex-col gap-2">
                                <p className="text-xs text-sky-200">Admin resolution confirms the accepted physical quantity. Outgoing expected/actual/variance stay frozen.</p>
                                {pendingItems.map((item, index) => (
                                    <label key={`resolve-${item.inventory_item_id}`} className="flex items-center justify-between gap-3 text-xs text-slate-200">
                                        <span>{item.item_name}</span>
                                        <input
                                            type="number"
                                            min="0"
                                            value={resolveForm.data.counts[index]?.quantity ?? ''}
                                            onChange={(e) => setCount(resolveForm, index, e.target.value)}
                                            className="w-24 bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-1.5 font-mono"
                                        />
                                    </label>
                                ))}
                                <textarea
                                    value={resolveForm.data.reason}
                                    onChange={(e) => resolveForm.setData('reason', e.target.value)}
                                    placeholder="Admin resolution reason"
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-sm text-slate-100 p-3"
                                    rows={2}
                                />
                                <button
                                    type="button"
                                    disabled={resolveForm.processing}
                                    onClick={() => resolveForm.post(route('shifts.inventory_turnover.resolve', pendingHandover.id))}
                                    className="self-start px-4 py-2 rounded-xl bg-sky-700 text-white text-xs font-bold"
                                >
                                    Resolve and accept physical count
                                </button>
                            </div>
                        )}
                    </section>
                )}

                {showOpening && (
                    <section className="rounded-2xl border border-[#334155] bg-[#1e293b] p-6 flex flex-col gap-4">
                        <div>
                            <h2 className="text-lg font-outfit font-bold text-slate-100">First opening count</h2>
                            <p className="text-xs text-slate-400 mt-1">
                                There is no prior accepted turnover. Count every tracked product now. This physical count becomes opening quantity. It is not reconstructed from history.
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
                                    Expected closing is frozen at count start. Variance is actual minus expected. Shortages are quantity accountability only — they do not change cash variance.
                                </p>
                            </div>
                            <div className="text-[10px] uppercase font-bold text-slate-400">
                                Status {currentStatus}
                                {currentTurnover.has_manual_set ? ' · Manual SET occurred — review' : ''}
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-xs text-left min-w-[900px]">
                                <thead>
                                    <tr className="text-[10px] uppercase text-slate-400 border-b border-[#334155]">
                                        <th className="py-2 pr-2">Product</th>
                                        <th className="py-2 pr-2">Opening</th>
                                        <th className="py-2 pr-2">Restock</th>
                                        <th className="py-2 pr-2">Returns</th>
                                        <th className="py-2 pr-2">Sold</th>
                                        <th className="py-2 pr-2">Complimentary</th>
                                        <th className="py-2 pr-2">Other out</th>
                                        <th className="py-2 pr-2">Expected</th>
                                        <th className="py-2 pr-2">Physical actual</th>
                                        <th className="py-2">Variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {currentItems.map((item, index) => (
                                        <tr key={item.inventory_item_id} className="border-b border-[#334155]/40">
                                            <td className="py-2 pr-2 font-bold text-slate-100">{item.item_name}</td>
                                            <td className="py-2 pr-2 font-mono">{item.opening_quantity ?? '—'}</td>
                                            <td className="py-2 pr-2 font-mono">{item.restock_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.return_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.sold_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.complimentary_quantity}</td>
                                            <td className="py-2 pr-2 font-mono">{item.other_out_quantity}</td>
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
                                            <td className={`py-2 font-mono font-bold ${varianceClass(item.variance_label)}`}>
                                                {item.variance_label || '—'}
                                                {item.variance_quantity < 0 && (
                                                    <div className="text-[10px] font-normal text-slate-500">
                                                        Reference Retail Value: {formatPHP(item.reference_retail_value || 0)}
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
                                        onClick={() => outgoingForm.post(route('shifts.inventory_turnover.submit'))}
                                        className="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold"
                                    >
                                        Submit inventory turnover
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

                {isAdmin && currentTurnover?.admin_override_reason && (
                    <div className="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-4 text-xs text-amber-100">
                        End Shift was overridden without a completed inventory count. Reason: {currentTurnover.admin_override_reason}
                    </div>
                )}

                <div className="flex items-center gap-2 text-[11px] text-slate-500">
                    <Package size={14} />
                    Untracked products, lodging check-in/out, rooms, and cash functions remain usable during count and handover gates.
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
