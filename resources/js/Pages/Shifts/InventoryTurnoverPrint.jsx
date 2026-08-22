import React, { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { formatPHP } from '@/Utils/currency';

export default function InventoryTurnoverPrint({ hotel_name: hotelName, title, printed_at_manila: printedAt, turnover }) {
    useEffect(() => {
        window.print();
    }, []);

    const items = turnover?.items || [];
    const shorts = turnover?.outgoing_shorts || [];
    const overs = turnover?.outgoing_overs || [];
    const handoverIssues = turnover?.handover_issues || [];
    const balancedCount = items.filter((item) => item.variance_label === 'BALANCED').length;

    return (
        <>
            <Head title={`Inventory Turnover Report Shift #${turnover.shift_session_id}`} />
            <style>{`
                @page { size: landscape; margin: 12mm; }
                @media print {
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: white !important; color: black !important; }
                    .no-print { display: none !important; }
                }
                body { font-family: Arial, sans-serif; }
            `}</style>
            <div className="print-root bg-white text-black min-h-screen p-6">
                <button type="button" className="no-print mb-4 px-4 py-2 bg-slate-800 text-white rounded" onClick={() => window.print()}>
                    Print PDF
                </button>
                <div className="border-b-2 border-black pb-3 mb-4">
                    <div className="text-xs uppercase tracking-widest">{hotelName}</div>
                    <h1 className="text-2xl font-black">{title}</h1>
                    <p className="text-xs mt-1">Outgoing Inventory Variance and Incoming Handover Difference are separate accountability events. Do not add them together. This is not a cash shortage, payroll deduction, or employee liability statement.</p>
                </div>
                <div className="grid grid-cols-3 gap-2 text-xs mb-4">
                    <div>Shift #: {turnover.shift_session_id}</div>
                    <div>Business Date: {turnover.business_date_manila || '—'}</div>
                    <div>Status: {turnover.status_label}</div>
                    <div>Outgoing Front Desk: {turnover.outgoing_operator_name || '—'}</div>
                    <div>Incoming Front Desk: {turnover.incoming_operator_name || '—'}</div>
                    <div>Counted by: {turnover.counted_by_name || '—'}</div>
                    <div>Count started: {turnover.freeze_started_at_manila || '—'}</div>
                    <div>Submitted: {turnover.submitted_at_manila || '—'}</div>
                    <div>Accepted / Resolved: {turnover.accepted_at_manila || turnover.resolved_at_manila || '—'}</div>
                </div>
                {turnover.admin_override_reason && (
                    <div className="border-2 border-black p-3 mb-4 text-xs">
                        <strong>INVENTORY TURNOVER OVERRIDE</strong>
                        <div>Reason: {turnover.admin_override_reason}</div>
                        <div>Authorized by: {turnover.admin_override_by_name || 'Admin'}</div>
                        <div>Timestamp: {turnover.admin_override_at_manila || '—'}</div>
                    </div>
                )}
                {turnover.has_manual_set && (
                    <div className="border border-black p-2 mb-4 text-xs">Manual stock SET occurred during this shift. Review the Manual Adjustment column and movement notes.</div>
                )}
                <table className="w-full text-[10px] border-collapse mb-4">
                    <thead>
                        <tr>
                            {['Product', 'Opening', 'Restock', 'Returns', 'Sold', 'Comp', 'Other Out', 'Expected', 'Outgoing Actual', 'Outgoing Inventory Variance', 'Between-Shift', 'Expected at Handover', 'Incoming Count', 'Incoming Handover Difference'].map((h) => (
                                <th key={h} className="border border-black p-1 text-left">{h}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr key={item.inventory_item_id}>
                                <td className="border border-black p-1">{item.item_name}</td>
                                <td className="border border-black p-1">{item.opening_quantity ?? '—'}</td>
                                <td className="border border-black p-1">{item.restock_quantity}</td>
                                <td className="border border-black p-1">{item.return_quantity}</td>
                                <td className="border border-black p-1">{item.sold_quantity}</td>
                                <td className="border border-black p-1">{item.complimentary_quantity}</td>
                                <td className="border border-black p-1">{item.other_out_quantity}</td>
                                <td className="border border-black p-1">{item.expected_closing_quantity ?? '—'}</td>
                                <td className="border border-black p-1">{item.outgoing_actual_quantity ?? '—'}</td>
                                <td className="border border-black p-1">{item.variance_label || '—'}</td>
                                <td className="border border-black p-1">{item.gap_net_label ?? item.gap_net_quantity}</td>
                                <td className="border border-black p-1">{item.handover_expected_quantity ?? '—'}</td>
                                <td className="border border-black p-1">{item.incoming_verified_quantity ?? '—'}</td>
                                <td className="border border-black p-1">{item.handover_difference === null || item.handover_difference === undefined ? '—' : item.handover_difference}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="grid grid-cols-2 gap-6 text-xs mb-6">
                    <div>
                        <h2 className="font-bold uppercase mb-1">Outgoing Inventory Variance</h2>
                        <div>Outgoing shortage (this shift):</div>
                        {shorts.length === 0 ? <div>0</div> : shorts.map((row) => (
                            <div key={`s-${row.inventory_item_id}`}>{row.item_name}: {Math.abs(row.variance_quantity)} · Reference Retail Value: {formatPHP(row.reference_retail_value || 0)}</div>
                        ))}
                        <div className="mt-2">Outgoing Over:</div>
                        {overs.length === 0 ? <div>0</div> : overs.map((row) => (
                            <div key={`o-${row.inventory_item_id}`}>{row.item_name}: {row.variance_quantity}</div>
                        ))}
                        <div className="mt-2">BALANCED items: {balancedCount}</div>
                    </div>
                    <div>
                        <h2 className="font-bold uppercase mb-1">Incoming Handover Difference</h2>
                        <div>Incoming verification:</div>
                        {handoverIssues.length === 0 ? (
                            <div>0</div>
                        ) : handoverIssues.map((row) => (
                            <div key={`h-${row.inventory_item_id}`}>
                                {row.item_name}: {row.handover_difference}
                                {row.handover_difference < 0 ? ` · Reference Retail Value: ${formatPHP(row.handover_reference_retail_value || 0)}` : ''}
                            </div>
                        ))}
                        <div className="mt-2">Handover Status: {turnover.status === 'disputed' ? 'DISPUTED / UNDER REVIEW' : (turnover.handover_status || turnover.status_label)}</div>
                        {turnover.disputed_reason && <div className="mt-2">Dispute reason: {turnover.disputed_reason}</div>}
                        {turnover.resolution_notes && <div>Resolution notes: {turnover.resolution_notes}</div>}
                    </div>
                </div>

                <p className="text-[10px] mb-8">
                    Outgoing Inventory Variance and Incoming Handover Difference are separate accountability events. Do not add them together.
                    Reference Retail Value is informational only. It is not Amount Due, Cash Shortage, or Employee Liability.
                    Printed {printedAt}.
                </p>

                <div className="grid grid-cols-3 gap-8 text-xs pt-8">
                    <div>
                        Prepared / Counted by:
                        <div className="border-b border-black h-10 mt-6" />
                        Outgoing Front Desk<br />
                        {turnover.counted_by_name || turnover.outgoing_operator_name || ''} · {turnover.submitted_at_manila || ''}
                    </div>
                    <div>
                        Verified by:
                        <div className="border-b border-black h-10 mt-6" />
                        Incoming Front Desk<br />
                        {turnover.incoming_operator_name || ''} · {turnover.accepted_at_manila || ''}
                    </div>
                    <div>
                        Admin Resolution / Witness:
                        <div className="border-b border-black h-10 mt-6" />
                        Admin<br />
                        {turnover.resolved_by_name || (turnover.admin_override_by_name || '')} · {turnover.resolved_at_manila || turnover.admin_override_at_manila || ''}
                    </div>
                </div>
            </div>
        </>
    );
}
