import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import Pagination from '@/Components/Pagination';
import { formatUtcToManila } from '@/Utils/datetime';

function money(val) {
    return '₱' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function CashActivityIndex({ activities, filters = {} }) {
    const apply = (patch) => {
        router.get(route('cash-activity.index'), { ...filters, page: 1, ...patch }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Cash Activity History" />
            <div className="space-y-6">
                <div>
                    <h1 className="text-3xl font-outfit font-extrabold text-slate-100">Cash Activity History</h1>
                    <p className="text-sm text-slate-400 mt-1">Admin review of Expenses and Additional Cash. Posted records are never silently deleted.</p>
                </div>
                <form
                    onSubmit={(e) => { e.preventDefault(); apply({ search: e.target.search.value, type: e.target.type.value, status: e.target.status.value }); }}
                    className="flex flex-wrap gap-2"
                >
                    <input name="search" defaultValue={filters.search || ''} placeholder="Search reference, reason, or person" className="bg-[#0f172a] border border-[#334155] rounded-xl px-3 py-2 text-xs min-w-[220px]" />
                    <select name="type" defaultValue={filters.type || ''} className="bg-[#0f172a] border border-[#334155] rounded-xl px-3 py-2 text-xs">
                        <option value="">All types</option>
                        <option value="expense">Expense</option>
                        <option value="additional_cash">Additional Cash</option>
                    </select>
                    <select name="status" defaultValue={filters.status || ''} className="bg-[#0f172a] border border-[#334155] rounded-xl px-3 py-2 text-xs">
                        <option value="">All statuses</option>
                        <option value="PENDING_APPROVAL">Pending Approval</option>
                        <option value="APPROVED">Approved</option>
                        <option value="POSTED">Posted</option>
                        <option value="REJECTED">Rejected</option>
                        <option value="VOIDED">Voided</option>
                    </select>
                    <button type="submit" className="px-4 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold uppercase">Filter</button>
                </form>

                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden">
                    <table className="w-full text-xs">
                        <thead>
                            <tr className="border-b border-[#334155] bg-[#0f172a]/60 text-slate-400 uppercase tracking-wider">
                                <th className="text-left px-4 py-3">Reference</th>
                                <th className="text-left px-4 py-3">Type</th>
                                <th className="text-left px-4 py-3">Date / Time</th>
                                <th className="text-left px-4 py-3">Origin Shift</th>
                                <th className="text-left px-4 py-3">Posted Shift</th>
                                <th className="text-left px-4 py-3">Drawer</th>
                                <th className="text-right px-4 py-3">Amount</th>
                                <th className="text-left px-4 py-3">Recorded / Submitted By</th>
                                <th className="text-left px-4 py-3">Status</th>
                                <th className="text-left px-4 py-3">Reviewed By</th>
                                <th className="text-right px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(activities.data || []).length === 0 ? (
                                <tr><td colSpan={11} className="px-4 py-10 text-center text-slate-500">No cash activity found.</td></tr>
                            ) : activities.data.map((row) => (
                                <tr key={`${row.type_key}-${row.id}`} className="border-b border-[#334155]/40">
                                    <td className="px-4 py-3 font-mono text-brand-300">{row.reference}</td>
                                    <td className="px-4 py-3">{row.type}</td>
                                    <td className="px-4 py-3">{row.created_at_display || formatUtcToManila(row.created_at)}</td>
                                    <td className="px-4 py-3">{row.origin_shift_id ? `#${row.origin_shift_id}` : 'Legacy'}</td>
                                    <td className="px-4 py-3">{row.posted_shift_id ? `#${row.posted_shift_id}` : '—'}</td>
                                    <td className="px-4 py-3">{row.drawer === 'minibar' ? 'Minibar' : 'Rooms'}</td>
                                    <td className="px-4 py-3 text-right font-mono">{money(row.amount)}</td>
                                    <td className="px-4 py-3">{row.recorded_by_name}</td>
                                    <td className="px-4 py-3">{row.status_label}</td>
                                    <td className="px-4 py-3">{row.reviewed_by_name || '—'}</td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={row.type_key === 'expense' ? route('cash-activity.expenses.show', row.id) : route('cash-activity.additional-cash.show', row.id)}
                                            className="px-3 py-1.5 rounded-lg border border-[#334155] font-bold uppercase"
                                        >
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {activities.links && (
                        <div className="p-3 border-t border-[#334155]">
                            <Pagination links={activities.links} />
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
