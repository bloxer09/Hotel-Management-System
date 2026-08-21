import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import Pagination from '@/Components/Pagination';

export default function InventoryTurnoverHistory({
    turnovers,
    filters = {},
    status_options: statusOptions = {},
    employees = [],
    can_admin_resolve: canAdminResolve,
    disputed_count: disputedCount = 0,
}) {
    const { auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';

    const apply = (next) => {
        router.get(route('shifts.inventory_turnover.history'), { ...filters, ...next }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Inventory Turnover History" />
            <div className="max-w-7xl mx-auto flex flex-col gap-6">
                <div>
                    <Link href={route('shifts.inventory_turnover.show')} className="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-200 mb-2">
                        <ChevronLeft size={14} /> Inventory Turnover
                    </Link>
                    <h1 className="text-2xl font-outfit font-extrabold text-slate-100">Inventory Turnover History</h1>
                    <p className="text-sm text-slate-400 mt-1">Frozen accountability snapshots. This is not cash variance.</p>
                    {isAdmin && disputedCount > 0 && (
                        <p className="mt-2 text-xs text-amber-200">{disputedCount} disputed handover{disputedCount === 1 ? '' : 's'} require Admin review.</p>
                    )}
                </div>

                <form
                    className="grid md:grid-cols-4 gap-3 rounded-2xl border border-[#334155] bg-[#1e293b] p-4"
                    onSubmit={(e) => { e.preventDefault(); apply({}); }}
                >
                    <label className="text-xs text-slate-400">
                        Business date
                        <input type="date" value={filters.date || ''} onChange={(e) => apply({ date: e.target.value })} className="mt-1 w-full bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-2 text-slate-100" />
                    </label>
                    <label className="text-xs text-slate-400">
                        Status
                        <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value })} className="mt-1 w-full bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-2 text-slate-100">
                            <option value="">All</option>
                            {Object.entries(statusOptions).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs text-slate-400">
                        Shift #
                        <input type="number" min="1" value={filters.shift_session_id || ''} onChange={(e) => apply({ shift_session_id: e.target.value })} className="mt-1 w-full bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-2 text-slate-100" />
                    </label>
                    {isAdmin && (
                        <label className="text-xs text-slate-400">
                            Employee
                            <select value={filters.employee_id || ''} onChange={(e) => apply({ employee_id: e.target.value })} className="mt-1 w-full bg-[#0f172a] border border-[#334155] rounded-lg px-2 py-2 text-slate-100">
                                <option value="">All</option>
                                {employees.map((person) => (
                                    <option key={person.id} value={person.id}>{person.full_name}</option>
                                ))}
                            </select>
                        </label>
                    )}
                </form>

                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-x-auto">
                    <table className="w-full text-xs min-w-[1000px]">
                        <thead>
                            <tr className="border-b border-[#334155] text-slate-400 uppercase tracking-wider">
                                <th className="text-left px-4 py-3">Turnover / Shift</th>
                                <th className="text-left px-4 py-3">Business Date</th>
                                <th className="text-left px-4 py-3">Outgoing Front Desk</th>
                                <th className="text-left px-4 py-3">Status</th>
                                <th className="text-left px-4 py-3">Submitted At</th>
                                <th className="text-left px-4 py-3">Incoming / Accepted By</th>
                                <th className="text-left px-4 py-3">Accepted At</th>
                                <th className="text-right px-4 py-3">Short</th>
                                <th className="text-right px-4 py-3">Over</th>
                                <th className="text-left px-4 py-3">Handover Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(turnovers.data || []).length === 0 ? (
                                <tr><td colSpan={10} className="px-4 py-10 text-center text-slate-500">No matching turnovers.</td></tr>
                            ) : turnovers.data.map((row) => (
                                <tr key={row.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40">
                                    <td className="px-4 py-3">
                                        <Link href={route('shifts.inventory_turnover.show_record', row.id)} className="font-bold text-brand-300 hover:text-white">
                                            #{row.id} / Shift #{row.shift_session_id}
                                        </Link>
                                        {row.has_admin_override && <div className="text-[10px] text-amber-300 uppercase">Override</div>}
                                    </td>
                                    <td className="px-4 py-3">{row.business_date_manila || '—'}</td>
                                    <td className="px-4 py-3">{row.outgoing_operator_name || '—'}</td>
                                    <td className="px-4 py-3 font-bold uppercase">{row.status_label}</td>
                                    <td className="px-4 py-3">{row.submitted_at_manila || '—'}</td>
                                    <td className="px-4 py-3">{row.incoming_operator_name || '—'}</td>
                                    <td className="px-4 py-3">{row.accepted_at_manila || '—'}</td>
                                    <td className="px-4 py-3 text-right">{row.short_item_count}</td>
                                    <td className="px-4 py-3 text-right">{row.over_item_count}</td>
                                    <td className="px-4 py-3">{row.handover_status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={turnovers.links} />
                {canAdminResolve && <p className="text-[11px] text-slate-500">Admin can open a disputed row to accept the confirmed physical count or request a recount.</p>}
            </div>
        </AuthenticatedLayout>
    );
}
