import React, { useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, Info, Printer } from 'lucide-react';
import { formatPHP } from '@/Utils/currency';

const OUTGOING_SHORT_HINT = 'Difference between expected closing inventory and outgoing physical count. This belongs to the outgoing register accountability.';
const HANDOVER_DIFFERENCE_HINT = 'Difference between outgoing declared inventory and incoming verified physical count. This identifies a handover discrepancy and requires review.';

function HintLabel({ children, hint }) {
    return (
        <span className="inline-flex items-center gap-1" title={hint}>
            {children}
            <Info size={11} className="inline text-slate-500" aria-label={hint} />
        </span>
    );
}

function varianceClass(label) {
    if (!label || label === 'BALANCED') return 'text-emerald-400';
    if (String(label).startsWith('SHORT')) return 'text-rose-400';
    return 'text-sky-400';
}

export default function InventoryTurnoverShow({ turnover, can_admin_resolve: canAdminResolve }) {
    const { auth, errors } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';
    const items = turnover?.items || [];
    const defaults = useMemo(
        () => items.map((item) => ({
            inventory_item_id: item.inventory_item_id,
            quantity: item.incoming_verified_quantity ?? item.handover_expected_quantity ?? '',
        })),
        [turnover?.id, turnover?.status]
    );
    const resolveForm = useForm({ counts: defaults, reason: turnover?.resolution_notes || '' });
    const recountForm = useForm({ reason: '' });

    const setCount = (index, value) => {
        const next = [...resolveForm.data.counts];
        next[index] = { ...next[index], quantity: value };
        resolveForm.setData('counts', next);
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Inventory Turnover #${turnover.id}`} />
            <div className="max-w-7xl mx-auto flex flex-col gap-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <Link href={route('shifts.inventory_turnover.history')} className="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-200 mb-2">
                            <ChevronLeft size={14} /> History
                        </Link>
                        <h1 className="text-2xl font-outfit font-extrabold text-slate-100">Turnover #{turnover.id} · Shift #{turnover.shift_session_id}</h1>
                        <p className="text-sm text-slate-400 mt-1">{turnover.status_label}: {turnover.status_description}</p>
                    </div>
                    <Link
                        href={route('shifts.inventory_turnover.print', turnover.id)}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-[#334155] text-xs font-bold text-slate-200"
                    >
                        <Printer size={14} /> Print / PDF
                    </Link>
                </div>

                {turnover.admin_override_reason && (
                    <div className="rounded-2xl border border-amber-500/40 bg-amber-950/20 p-4 text-xs text-amber-100">
                        <div className="font-extrabold uppercase tracking-wide">Inventory turnover override</div>
                        <div>Reason: {turnover.admin_override_reason}</div>
                        <div>Authorized by: {turnover.admin_override_by_name || 'Admin'}</div>
                        <div>Timestamp: {turnover.admin_override_at_manila || '—'}</div>
                    </div>
                )}

                <div className="grid md:grid-cols-2 gap-3 text-xs text-slate-300 rounded-2xl border border-[#334155] bg-[#1e293b] p-4">
                    <div>Outgoing Front Desk accountability: <strong>{turnover.outgoing_operator_name || '—'}</strong></div>
                    <div>Incoming Front Desk verification: <strong>{turnover.incoming_operator_name || turnover.disputed_by_name || '—'}</strong></div>
                    <div>Counted by: {turnover.counted_by_name || '—'}</div>
                    <div>Count started: {turnover.freeze_started_at_manila || '—'}</div>
                    <div>Submitted: {turnover.submitted_at_manila || '—'}</div>
                    <div>Accepted / resolved: {turnover.accepted_at_manila || turnover.resolved_at_manila || '—'}</div>
                    <div>Business date: {turnover.business_date_manila || '—'}</div>
                    <div>Handover status: <strong>{turnover.status === 'disputed' ? 'DISPUTED / UNDER REVIEW' : (turnover.handover_status || turnover.status_label)}</strong></div>
                    <div className="md:col-span-2">Notes: {turnover.notes || '—'}</div>
                    {turnover.disputed_reason && <div className="md:col-span-2 text-rose-300">Dispute reason: {turnover.disputed_reason} · {turnover.disputed_by_name} · {turnover.disputed_at_manila}</div>}
                    {turnover.resolution_notes && <div className="md:col-span-2 text-sky-300">Resolution notes: {turnover.resolution_notes} · {turnover.resolved_by_name} · {turnover.resolved_at_manila}</div>}
                </div>

                {turnover.has_manual_set && (
                    <div className="rounded-xl border border-amber-500/40 bg-amber-950/30 p-3 text-xs text-amber-100">
                        Manual stock SET occurred during this shift.
                        {(turnover.manual_set_movements || []).map((move, index) => (
                            <div key={index} className="mt-1 font-mono">{move.item_name}: net {move.quantity_change} · {move.performed_by_name} · {move.created_at_manila}</div>
                        ))}
                    </div>
                )}

                <div className="overflow-x-auto rounded-2xl border border-[#334155] bg-[#1e293b]">
                    <table className="w-full text-xs min-w-[1200px]">
                        <thead>
                            <tr className="text-[10px] uppercase text-slate-400 border-b border-[#334155]">
                                <th className="p-2.5 text-left">Product</th>
                                <th className="p-2.5 text-right">Opening</th>
                                <th className="p-2.5 text-right">Restock</th>
                                <th className="p-2.5 text-right">Returns</th>
                                <th className="p-2.5 text-right">Sold</th>
                                <th className="p-2.5 text-right">Complimentary</th>
                                <th className="p-2.5 text-right">Other Out</th>
                                <th className="p-2.5 text-right">Expected</th>
                                <th className="p-2.5 text-right">Outgoing Actual</th>
                                <th className="p-2.5 text-right">
                                    <HintLabel hint={OUTGOING_SHORT_HINT}>Outgoing Inventory Variance</HintLabel>
                                </th>
                                <th className="p-2.5 text-right">Between-Shift</th>
                                <th className="p-2.5 text-right">Expected at Handover</th>
                                <th className="p-2.5 text-right">Incoming Count</th>
                                <th className="p-2.5 text-right">
                                    <HintLabel hint={HANDOVER_DIFFERENCE_HINT}>Incoming Handover Difference</HintLabel>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.inventory_item_id} className="border-b border-[#334155]/40 font-mono">
                                    <td className="p-2.5 font-sans font-bold text-slate-100">{item.item_name}</td>
                                    <td className="p-2.5 text-right">{item.opening_quantity ?? '—'}</td>
                                    <td className="p-2.5 text-right">{item.restock_quantity}</td>
                                    <td className="p-2.5 text-right">{item.return_quantity}</td>
                                    <td className="p-2.5 text-right">{item.sold_quantity}</td>
                                    <td className="p-2.5 text-right">{item.complimentary_quantity}</td>
                                    <td className="p-2.5 text-right">{item.other_out_quantity}</td>
                                    <td className="p-2.5 text-right">{item.expected_closing_quantity ?? '—'}</td>
                                    <td className="p-2.5 text-right">{item.outgoing_actual_quantity ?? '—'}</td>
                                    <td className={`p-2.5 text-right font-bold ${varianceClass(item.variance_label)}`}>
                                        {item.variance_label || '—'}
                                        {item.variance_quantity < 0 && (
                                            <div className="text-[10px] font-sans font-normal text-slate-500">Reference Retail Value: {formatPHP(item.reference_retail_value || 0)}</div>
                                        )}
                                    </td>
                                    <td className="p-2.5 text-right">{item.gap_net_label ?? item.gap_net_quantity}</td>
                                    <td className="p-2.5 text-right">{item.handover_expected_quantity ?? '—'}</td>
                                    <td className="p-2.5 text-right">{item.incoming_verified_quantity ?? '—'}</td>
                                    <td className={`p-2.5 text-right font-bold ${varianceClass(item.handover_difference_label)}`}>
                                        {item.handover_difference === null || item.handover_difference === undefined ? '—' : item.handover_difference}
                                        {item.handover_difference < 0 && (
                                            <div className="text-[10px] font-sans font-normal text-slate-500">Reference Retail Value: {formatPHP(item.handover_reference_retail_value || 0)}</div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="text-[11px] text-slate-500">
                    Outgoing Inventory Variance belongs to the outgoing shift.
                    Incoming Handover Difference belongs to incoming verification.
                    Do not add them together.
                </p>
                <p className="text-[11px] text-slate-500">
                    Reference Retail Value is informational only and is not cash shortage, amount due, or employee liability.
                </p>

                {canAdminResolve && isAdmin && (
                    <section className="rounded-2xl border border-sky-500/30 bg-sky-950/20 p-5 flex flex-col gap-3">
                        <h2 className="text-sm font-outfit font-bold text-slate-100">Admin resolution</h2>
                        <p className="text-xs text-slate-300">
                            Accept the confirmed incoming physical count, or request a recount. Outgoing expected, actual, and variance stay frozen. This does not affect cash, minibar expected cash, sales, or booking balances.
                        </p>
                        {items.map((item, index) => (
                            <label key={item.inventory_item_id} className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-200">
                                <span>
                                    {item.item_name}: outgoing expected {item.expected_closing_quantity}, actual {item.outgoing_actual_quantity} ({item.variance_label}).
                                    Expected at handover {item.handover_expected_quantity}. Incoming currently {item.incoming_verified_quantity}.
                                </span>
                                <input
                                    type="number"
                                    min="0"
                                    value={resolveForm.data.counts[index]?.quantity ?? ''}
                                    onChange={(e) => setCount(index, e.target.value)}
                                    className="w-24 bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-1.5 font-mono"
                                />
                            </label>
                        ))}
                        <textarea
                            value={resolveForm.data.reason}
                            onChange={(e) => resolveForm.setData('reason', e.target.value)}
                            placeholder="Resolution reason. Example: Confirmed physical stock is 42."
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-sm text-slate-100 p-3"
                            rows={2}
                        />
                        {errors.reason && <p className="text-xs text-rose-400">{errors.reason}</p>}
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                disabled={resolveForm.processing}
                                onClick={() => resolveForm.post(route('shifts.inventory_turnover.resolve', turnover.id))}
                                className="px-4 py-2 rounded-xl bg-sky-700 text-white text-xs font-bold"
                            >
                                Accept incoming physical count
                            </button>
                        </div>
                        <textarea
                            value={recountForm.data.reason}
                            onChange={(e) => recountForm.setData('reason', e.target.value)}
                            placeholder="Recount reason"
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-sm text-slate-100 p-3"
                            rows={2}
                        />
                        <button
                            type="button"
                            disabled={recountForm.processing}
                            onClick={() => recountForm.post(route('shifts.inventory_turnover.recount', turnover.id))}
                            className="self-start px-4 py-2 rounded-xl border border-amber-500/40 text-amber-100 text-xs font-bold"
                        >
                            Require recount
                        </button>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
