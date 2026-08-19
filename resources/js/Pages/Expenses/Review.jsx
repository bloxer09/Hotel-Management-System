import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { formatUtcToManila } from '@/Utils/datetime';

function money(val) {
    return '₱' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function ExpenseReview({ expense }) {
    const rejectForm = useForm({ review_notes: '' });

    return (
        <AuthenticatedLayout>
            <Head title={`Review ${expense.reference}`} />
            <div className="max-w-3xl space-y-6">
                <Link href={route('expenses.approvals')} className="inline-flex items-center gap-1 text-xs text-slate-400">
                    <ChevronLeft size={14} /> Expense Approvals
                </Link>
                <h1 className="text-3xl font-outfit font-extrabold text-slate-100">{expense.reference}</h1>
                <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5 text-sm text-slate-200 space-y-2">
                    <p>Status: <strong>{expense.status_label}</strong></p>
                    <p>Submitter: {expense.recorded_by_name}</p>
                    <p>Recorded: {expense.created_at_display || formatUtcToManila(expense.created_at)}</p>
                    <p>Origin shift: {expense.origin_shift_id ? `#${expense.origin_shift_id}` : '—'}</p>
                    <p>Posted shift: {expense.posted_shift_id ? `#${expense.posted_shift_id}` : '—'}</p>
                    <p>Category: {expense.category || '—'}</p>
                    <p>Drawer: {expense.drawer === 'minibar' ? 'Minibar' : 'Rooms'}</p>
                    <p>Amount: {money(expense.amount)}</p>
                    <p>Reason: {expense.reason}</p>
                    {expense.receipt_path && <a href={`/storage/${expense.receipt_path}`} target="_blank" rel="noreferrer" className="text-indigo-400 font-bold">View receipt</a>}
                </div>

                {expense.status === 'PENDING_APPROVAL' && (
                    <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5 space-y-3">
                        <textarea
                            value={rejectForm.data.review_notes}
                            onChange={(e) => rejectForm.setData('review_notes', e.target.value)}
                            placeholder="Rejection reason (required to reject)"
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl p-3 text-sm"
                            rows={3}
                        />
                        <div className="flex gap-2">
                            <button type="button" className="px-4 py-2 rounded-xl border border-rose-500/40 text-rose-300 text-xs font-bold uppercase" onClick={() => rejectForm.post(route('expenses.reject', expense.id))}>Reject</button>
                            <button type="button" className="px-4 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold uppercase" onClick={() => router.post(route('expenses.approve', expense.id))}>Approve</button>
                        </div>
                    </div>
                )}

                <div className="rounded-2xl border border-[#334155] bg-[#1e293b] p-5">
                    <h2 className="font-outfit font-bold mb-3">Event history</h2>
                    <ul className="space-y-3 text-sm">
                        {(expense.timeline || []).map((event, index) => (
                            <li key={index} className="border-b border-[#334155]/40 pb-3">
                                <div className="text-slate-400 text-xs">{event.created_at_display || formatUtcToManila(event.created_at)}</div>
                                <div className="font-bold text-slate-100">{event.action.replaceAll('_', ' ')}</div>
                                <div className="text-slate-300">by {event.actor}</div>
                                {event.reason && <div className="text-slate-400">Reason: {event.reason}</div>}
                                {event.posted_shift_id && <div className="text-slate-500">Shift #{event.posted_shift_id}</div>}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
