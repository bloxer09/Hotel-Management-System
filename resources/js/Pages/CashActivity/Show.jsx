import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { formatUtcToManila } from '@/Utils/datetime';
import VoidPostedCashForm, { ClosedPostedCashNotice } from '@/Components/PostedCashVoid';

function money(val) {
    return '₱' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function CashActivityShow({ activity }) {
    const [showVoidForm, setShowVoidForm] = useState(false);
    const voidRoute = activity.type_key === 'expense'
        ? route('expenses.void', activity.id)
        : route('additional-cash.void', activity.id);
    const auditNoteRoute = activity.type_key === 'expense'
        ? route('expenses.audit-note', activity.id)
        : route('additional-cash.audit-note', activity.id);

    return (
        <AuthenticatedLayout>
            <Head title={activity.reference} />
            <div className="max-w-3xl space-y-6">
                <Link href={route('cash-activity.index')} className="inline-flex items-center gap-1 text-xs text-slate-400">
                    <ChevronLeft size={14} /> Cash Activity History
                </Link>
                <h1 className="text-3xl font-outfit font-extrabold text-slate-100">{activity.reference}</h1>
                <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5 text-sm space-y-2">
                    <p>Type: {activity.type}</p>
                    <p>Status: <strong>{activity.status_label}</strong></p>
                    <p>Recorded: {activity.created_at_display || formatUtcToManila(activity.created_at)}</p>
                    <p>Origin shift: {activity.origin_shift_id ? `#${activity.origin_shift_id}` : 'Legacy / unlinked'}</p>
                    <p>Posted shift: {activity.posted_shift_id ? `#${activity.posted_shift_id}` : '—'}</p>
                    <p>Drawer: {activity.drawer === 'minibar' ? 'Minibar' : 'Rooms'}</p>
                    <p>Amount: {money(activity.amount)}</p>
                    <p>Recorded / submitted by: {activity.recorded_by_name}</p>
                    <p>Reviewed by: {activity.reviewed_by_name || '—'}</p>
                    <p>Source / reason: {activity.reason}</p>
                </div>

                <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5">
                    <h2 className="font-outfit font-bold mb-3">Immutable timeline</h2>
                    <ul className="space-y-3 text-sm">
                        {(activity.timeline || []).length === 0 && <li className="text-slate-500">No audit events recorded for this item (legacy row).</li>}
                        {(activity.timeline || []).map((event, index) => (
                            <li key={index} className="border-b border-[#334155]/40 pb-3">
                                <div className="text-slate-400 text-xs">{event.created_at_display || formatUtcToManila(event.created_at)}</div>
                                <div className="font-bold">{event.action.replaceAll('_', ' ')}</div>
                                <div>by {event.actor}</div>
                                {event.reason && <div className="text-slate-400">Reason: {event.reason}</div>}
                                {event.posted_shift_id && <div className="text-slate-500">Shift #{event.posted_shift_id}</div>}
                            </li>
                        ))}
                    </ul>
                </div>

                {activity.status === 'POSTED' && activity.can_void && (
                    <div className="rounded-2xl border border-rose-500/30 bg-[#1e293b] p-5">
                        {showVoidForm ? (
                            <VoidPostedCashForm
                                action={voidRoute}
                                reference={activity.reference}
                                onCancel={() => setShowVoidForm(false)}
                            />
                        ) : (
                            <button
                                type="button"
                                onClick={() => setShowVoidForm(true)}
                                className="px-4 py-2 rounded-xl bg-rose-700 text-white text-xs font-bold uppercase"
                            >
                                Void erroneous posting
                            </button>
                        )}
                    </div>
                )}

                {activity.status === 'POSTED' && !activity.can_void && (
                    <ClosedPostedCashNotice
                        postedShiftId={activity.posted_shift_id}
                        auditNoteRoute={auditNoteRoute}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
