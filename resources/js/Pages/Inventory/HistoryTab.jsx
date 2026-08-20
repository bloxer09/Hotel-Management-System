import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { History as HistoryIcon } from 'lucide-react';
import CustomSelect from '@/Components/CustomSelect';
import Pagination from '@/Components/Pagination';

const typeOptions = [
    { id: '', name: 'All Types' },
    { id: 'create_item', name: 'New Item' },
    { id: 'add', name: 'Restock / Add' },
    { id: 'subtract', name: 'Subtract' },
    { id: 'set', name: 'Set Exact' },
    { id: 'pos_sale', name: 'POS Sale' },
    { id: 'booking_usage', name: 'Booking Usage' },
    { id: 'booking_reversal', name: 'Booking Reversal' },
    { id: 'complimentary_amenity', name: 'Complimentary Amenity' },
    { id: 'inventory_variance', name: 'Inventory Variance' },
];

const statusBadge = (status) => {
    const map = {
        pending: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        approved: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        rejected: 'bg-rose-500/10 text-rose-400 border-rose-500/20',
        applied: 'bg-slate-500/10 text-slate-300 border-slate-500/20',
    };
    return map[status] || 'bg-slate-500/10 text-slate-400 border-slate-500/20';
};

const typeLabel = (type) => ({
    create_item: 'New Item',
    initial_stock: 'New Item',
    add: 'Restock request',
    restock: 'Restock',
    manual_add: 'Add (legacy)',
    subtract: 'Subtract',
    manual_subtract: 'Subtract',
    set: 'Set Exact',
    manual_set: 'Set Exact',
    pos_sale: 'POS Sale',
    booking_usage: 'Booking Usage',
    booking_reversal: 'Booking Reversal',
    complimentary_amenity: 'Complimentary Amenity',
    inventory_variance: 'Inventory Variance',
}[type] || type);

const quantityClass = (row) => {
    if (row.request_status === 'rejected' || Number(row.quantity_change) === 0) {
        return 'text-slate-400';
    }
    return Number(row.quantity_change) < 0 ? 'text-rose-400' : 'text-emerald-400';
};

const quantityLabel = (row) => {
    if (row.request_status === 'rejected' && row.requested_display) {
        return row.requested_display;
    }
    return Number(row.quantity_change) > 0 ? `+${row.quantity_change}` : row.quantity_change;
};

export default function HistoryTab({ history, historyItems, historyUsers, historyFilters, isAdmin }) {
    const [item, setItem] = useState(historyFilters.history_item ? String(historyFilters.history_item) : '');
    const [search, setSearch] = useState(historyFilters.history_search || '');
    const [type, setType] = useState(historyFilters.history_type || '');
    const [status, setStatus] = useState(historyFilters.history_status || '');
    const [userId, setUserId] = useState(historyFilters.history_user ? String(historyFilters.history_user) : '');
    const [from, setFrom] = useState(historyFilters.history_from || '');
    const [to, setTo] = useState(historyFilters.history_to || '');

    const applyFilters = (e) => {
        e?.preventDefault();
        router.get(route('inventory.index'), {
            tab: 'history',
            history_item: item || undefined,
            history_search: search || undefined,
            history_type: type || undefined,
            history_status: status || undefined,
            history_user: isAdmin ? (userId || undefined) : undefined,
            history_from: from || undefined,
            history_to: to || undefined,
        }, { preserveState: true });
    };

    const clearFilters = () => {
        setItem('');
        setSearch('');
        setType('');
        setStatus('');
        setUserId('');
        setFrom('');
        setTo('');
        router.get(route('inventory.index'), { tab: 'history' }, { preserveState: true });
    };

    return (
        <div className="flex flex-col gap-4">
            <form onSubmit={applyFilters} className="flex flex-col lg:flex-row flex-wrap items-start lg:items-center gap-2 bg-[#1e293b] p-2 rounded-xl border border-[#334155]">
                <div className="w-full sm:w-44">
                    <CustomSelect value={item} onChange={(e) => setItem(e.target.value)}>
                        <option value="">All Items</option>
                        {historyItems.map((entry) => (
                            <option key={entry.id} value={String(entry.id)}>{entry.item_name}</option>
                        ))}
                    </CustomSelect>
                </div>
                <div className="w-full sm:w-44">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search item name"
                        className="w-full bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-3 py-1.5 text-xs"
                    />
                </div>
                <div className="w-full sm:w-44">
                    <CustomSelect value={type} onChange={(e) => setType(e.target.value)}>
                        {typeOptions.map((option) => (
                            <option key={option.id || 'all'} value={option.id}>{option.name}</option>
                        ))}
                    </CustomSelect>
                </div>
                <div className="w-full sm:w-36">
                    <CustomSelect value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">All Statuses</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="applied">Applied</option>
                    </CustomSelect>
                </div>
                {isAdmin && (
                    <div className="w-full sm:w-44">
                        <CustomSelect value={userId} onChange={(e) => setUserId(e.target.value)}>
                            <option value="">All Users</option>
                            {historyUsers.map((entry) => (
                                <option key={entry.id} value={String(entry.id)}>{entry.full_name}</option>
                            ))}
                        </CustomSelect>
                    </div>
                )}
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-3 py-1.5 text-xs" />
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-3 py-1.5 text-xs" />
                <button type="submit" className="px-3 py-1.5 bg-brand-600 hover:bg-brand-500 text-white font-bold text-xs rounded-lg">Filter</button>
                <button type="button" onClick={clearFilters} className="px-3 py-1.5 text-slate-400 hover:text-white text-xs font-bold">Clear</button>
            </form>

            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-xl overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Date / Time</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Item</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Type</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Context</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Qty Change</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Before</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">After</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Status</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Reference / Room / Stay</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Reason</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Requested By</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Reviewed / Performed</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Register</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase">Review Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.data.length > 0 ? history.data.map((row) => (
                                <tr key={`${row.row_kind}-${row.row_id}`} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40">
                                    <td className="px-4 py-3 text-[11px] font-mono text-brand-400 font-bold whitespace-nowrap">{row.occurred_at_manila}</td>
                                    <td className="px-4 py-3 text-xs font-bold text-slate-100">{row.item_name}</td>
                                    <td className="px-4 py-3 text-[11px] text-slate-300">{typeLabel(row.type_key)}</td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400 whitespace-nowrap">{row.issue_context_label || '—'}</td>
                                    <td className={`px-4 py-3 font-mono text-xs font-bold ${quantityClass(row)}`}>
                                        {quantityLabel(row)}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-slate-400">{row.stock_before ?? '—'}</td>
                                    <td className="px-4 py-3 font-mono text-xs text-slate-200">{row.stock_after ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold border capitalize ${statusBadge(row.request_status)}`}>
                                            {row.request_status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400">
                                        {row.issue_reference || row.room_number || row.booking_ref ? (
                                            <div className="flex flex-col gap-0.5">
                                                {row.issue_reference && <span className="font-mono text-emerald-300">{row.issue_reference}</span>}
                                                {row.room_number && <span>Room {row.room_number}</span>}
                                                {row.booking_ref && <span className="font-mono">{row.booking_ref}</span>}
                                            </div>
                                        ) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400 max-w-[180px]">{row.reason || '—'}</td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400">{row.requested_by_name || '—'}</td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400">{row.actor_name || row.performed_by_name || '—'}</td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400 whitespace-nowrap">
                                        {row.shift_session_id
                                            ? (row.register_label || `Shift #${row.shift_session_id}`)
                                            : 'No register / Between Shifts'}
                                    </td>
                                    <td className="px-4 py-3 text-[11px] text-slate-500">{row.review_note || '—'}</td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan={14} className="py-16 text-center text-slate-500">
                                        <div className="flex flex-col items-center gap-3">
                                            <HistoryIcon size={32} className="opacity-20" />
                                            <span>No inventory history found.</span>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                {history.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                        <span className="text-[10px] text-slate-500">
                            Showing {history.from}–{history.to} of {history.total} records
                        </span>
                        <Pagination links={history.links} />
                    </div>
                )}
            </div>
        </div>
    );
}
