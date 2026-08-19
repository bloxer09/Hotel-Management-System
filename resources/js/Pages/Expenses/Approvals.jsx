import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Banknote, ChevronLeft } from 'lucide-react';
import { formatUtcToManila } from '@/Utils/datetime';

const FILTERS = [
    { id: 'pending', label: 'Pending Approval' },
    { id: 'approved', label: 'Awaiting Payment' },
    { id: 'all', label: 'All large expenses' },
];

function money(val) {
    return '₱' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusClass(status) {
    if (status === 'POSTED') return 'bg-emerald-950/60 border-emerald-500/40 text-emerald-300';
    if (status === 'APPROVED') return 'bg-amber-950/60 border-amber-500/40 text-amber-300';
    if (status === 'REJECTED' || status === 'VOIDED') return 'bg-slate-800 border-slate-500/40 text-slate-300';
    return 'bg-rose-950/60 border-rose-500/40 text-rose-300';
}

export default function ExpenseApprovals({ filter = 'pending', rows = [] }) {
    const [review, setReview] = useState(null);
    const rejectForm = useForm({ review_notes: '' });

    const setFilter = (next) => {
        router.get(route('expenses.approvals'), { filter: next }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Expense Approvals" />
            <div className="flex flex-col gap-6">
                <div>
                    <Link href={route('expenses.index')} className="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-200 mb-2">
                        <ChevronLeft size={14} /> Expenses
                    </Link>
                    <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">Expense Approvals</h1>
                    <p className="text-sm text-slate-400 mt-1">Expenses above ₱1,000 require Admin approval. Approval does not reduce drawer cash until Mark Paid.</p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {FILTERS.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setFilter(item.id)}
                            className={`px-3 py-1.5 rounded-lg text-xs font-bold border ${filter === item.id ? 'bg-brand-600/30 border-brand-500 text-brand-100' : 'bg-[#1e293b] border-[#334155] text-slate-400'}`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden">
                    <table className="w-full text-xs">
                        <thead>
                            <tr className="border-b border-[#334155] bg-[#0f172a]/60 text-slate-400 uppercase tracking-wider">
                                <th className="text-left px-4 py-3">Reference</th>
                                <th className="text-left px-4 py-3">Date / Time</th>
                                <th className="text-left px-4 py-3">Shift</th>
                                <th className="text-left px-4 py-3">Front Desk</th>
                                <th className="text-left px-4 py-3">Category</th>
                                <th className="text-left px-4 py-3">Drawer</th>
                                <th className="text-right px-4 py-3">Amount</th>
                                <th className="text-left px-4 py-3">Status</th>
                                <th className="text-right px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan={9} className="px-4 py-10 text-center text-slate-500">No matching expense requests.</td></tr>
                            ) : rows.map((row) => (
                                <tr key={row.id} className="border-b border-[#334155]/50">
                                    <td className="px-4 py-3 font-mono text-brand-300">{row.reference}</td>
                                    <td className="px-4 py-3">{row.created_at_display || formatUtcToManila(row.created_at)}</td>
                                    <td className="px-4 py-3">{row.origin_shift_id ? `#${row.origin_shift_id}` : '—'}</td>
                                    <td className="px-4 py-3">{row.recorded_by_name || row.user?.full_name}</td>
                                    <td className="px-4 py-3">{row.category?.name || row.category || '—'}</td>
                                    <td className="px-4 py-3">{row.drawer === 'minibar' ? 'Minibar' : 'Rooms'}</td>
                                    <td className="px-4 py-3 text-right font-mono">{money(row.amount)}</td>
                                    <td className="px-4 py-3">
                                        <span className={`px-2 py-0.5 rounded border text-[10px] font-bold ${statusClass(row.status)}`}>{row.status_label}</span>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button type="button" onClick={() => setReview(row)} className="px-3 py-1.5 rounded-lg border border-[#334155] text-[10px] font-bold uppercase text-slate-200 hover:border-brand-500">
                                            Review
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {review && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4" onClick={() => setReview(null)}>
                    <div className="w-full max-w-lg rounded-2xl bg-[#0f172a] border border-[#334155] p-6" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center gap-2 mb-4">
                            <Banknote size={18} className="text-brand-400" />
                            <h2 className="font-outfit font-bold text-lg">Review {review.reference}</h2>
                        </div>
                        <dl className="text-xs space-y-2 text-slate-300">
                            <div className="flex justify-between"><dt>Submitter</dt><dd>{review.recorded_by_name || review.user?.full_name}</dd></div>
                            <div className="flex justify-between"><dt>Recorded</dt><dd>{review.created_at_display || formatUtcToManila(review.created_at)}</dd></div>
                            <div className="flex justify-between"><dt>Origin shift</dt><dd>{review.origin_shift_id ? `#${review.origin_shift_id}` : '—'}</dd></div>
                            <div className="flex justify-between"><dt>Category</dt><dd>{review.category?.name || review.category || '—'}</dd></div>
                            <div className="flex justify-between"><dt>Drawer</dt><dd>{review.drawer === 'minibar' ? 'Minibar' : 'Rooms'}</dd></div>
                            <div className="flex justify-between"><dt>Amount</dt><dd className="font-mono">{money(review.amount)}</dd></div>
                            <div><dt className="text-slate-500 mb-1">Reason</dt><dd>{review.reason || review.notes}</dd></div>
                            {review.receipt_path && (
                                <a href={`/storage/${review.receipt_path}`} target="_blank" rel="noreferrer" className="text-indigo-400 font-bold">View receipt</a>
                            )}
                        </dl>
                        {review.status === 'PENDING_APPROVAL' && (
                            <div className="mt-5 space-y-3">
                                <textarea
                                    value={rejectForm.data.review_notes}
                                    onChange={(e) => rejectForm.setData('review_notes', e.target.value)}
                                    placeholder="Rejection reason (required to reject)"
                                    className="w-full bg-[#1e293b] border border-[#334155] rounded-xl text-sm p-3"
                                    rows={3}
                                />
                                <div className="flex gap-2 justify-end">
                                    <button
                                        type="button"
                                        className="px-4 py-2 rounded-xl border border-rose-500/40 text-rose-300 text-xs font-bold uppercase"
                                        onClick={() => rejectForm.post(route('expenses.reject', review.id), { onSuccess: () => setReview(null) })}
                                    >
                                        Reject
                                    </button>
                                    <button
                                        type="button"
                                        className="px-4 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold uppercase"
                                        onClick={() => router.post(route('expenses.approve', review.id), {}, { onSuccess: () => setReview(null) })}
                                    >
                                        Approve
                                    </button>
                                </div>
                            </div>
                        )}
                        {review.status !== 'PENDING_APPROVAL' && (
                            <button type="button" onClick={() => setReview(null)} className="mt-5 w-full py-2 rounded-xl border border-[#334155] text-xs font-bold uppercase">Close</button>
                        )}
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
