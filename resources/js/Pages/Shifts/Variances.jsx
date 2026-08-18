import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote, ChevronLeft } from 'lucide-react';
import { formatUtcToManila } from '@/Utils/datetime';

const FILTERS = [
    { id: 'pending', label: 'Pending Review' },
    { id: 'partial', label: 'Partially Resolved' },
    { id: 'resolved', label: 'Resolved' },
    { id: 'all', label: 'All' },
];

function formatCurrency(val) {
    const num = Number(val);
    return Number.isNaN(num)
        ? '₱0.00'
        : '₱' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function statusClass(status) {
    if (status === 'RESOLVED') return 'bg-emerald-950/60 border-emerald-500/40 text-emerald-300';
    if (status === 'PARTIALLY_RESOLVED') return 'bg-amber-950/60 border-amber-500/40 text-amber-300';
    return 'bg-rose-950/60 border-rose-500/40 text-rose-300';
}

function reviewHref(row) {
    return row.review_url || `${route('shifts.report', row.shift_id)}?tab=variance`;
}

export default function Variances({ filter = 'pending', rows = [] }) {
    const setFilter = (next) => {
        router.get(route('shifts.variances.index'), { filter: next }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Cash Variance Review" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                    <div>
                        <Link href={route('shifts.index')} className="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-200 mb-2">
                            <ChevronLeft size={14} /> Shift Register
                        </Link>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Cash Variance Review
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">
                            Original expected, actual, and variance stay frozen. Approve resolutions without rewriting the closed shift.
                        </p>
                    </div>
                    <div className="flex items-center gap-2 text-brand-300">
                        <Banknote size={18} />
                        <span className="text-xs font-bold uppercase tracking-wider">{rows.length} drawer rows</span>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {FILTERS.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setFilter(item.id)}
                            className={`px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors ${
                                filter === item.id
                                    ? 'bg-brand-600/30 border-brand-500 text-brand-100'
                                    : 'bg-[#1e293b] border-[#334155] text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60 text-slate-400 uppercase tracking-wider">
                                    <th className="px-4 py-3 text-left">Shift</th>
                                    <th className="px-4 py-3 text-left">Front Desk</th>
                                    <th className="px-4 py-3 text-left">Closed At</th>
                                    <th className="px-4 py-3 text-left">Drawer</th>
                                    <th className="px-4 py-3 text-right">Expected</th>
                                    <th className="px-4 py-3 text-right">Actual</th>
                                    <th className="px-4 py-3 text-right">Original Variance</th>
                                    <th className="px-4 py-3 text-right">Resolved</th>
                                    <th className="px-4 py-3 text-right">Remaining</th>
                                    <th className="px-4 py-3 text-left">Status</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={11} className="px-4 py-10 text-center text-slate-500">
                                            No cash variances in this filter.
                                        </td>
                                    </tr>
                                )}
                                {rows.map((row) => (
                                    <tr key={`${row.shift_id}-${row.drawer}`} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40">
                                        <td className="px-4 py-3">
                                            <Link href={reviewHref(row)} className="font-bold text-brand-300 hover:text-brand-200">
                                                Shift #{row.shift_id}
                                            </Link>
                                            <div className="text-[10px] text-slate-500 uppercase">{row.shift_code}</div>
                                        </td>
                                        <td className="px-4 py-3 font-semibold text-slate-200">{row.front_desk || '—'}</td>
                                        <td className="px-4 py-3 text-slate-300">
                                            {row.closed_at_display || (row.closed_at ? formatUtcToManila(row.closed_at) : '—')}
                                        </td>
                                        <td className="px-4 py-3 font-bold text-slate-200">{row.label}</td>
                                        <td className="px-4 py-3 text-right font-mono">{formatCurrency(row.original_expected)}</td>
                                        <td className="px-4 py-3 text-right font-mono">{formatCurrency(row.original_actual)}</td>
                                        <td className={`px-4 py-3 text-right font-mono font-bold ${row.original_variance < 0 ? 'text-rose-400' : row.original_variance > 0 ? 'text-sky-400' : 'text-emerald-400'}`}>
                                            {row.original_variance > 0 ? '+' : ''}{formatCurrency(row.original_variance)}
                                            <div className="text-[10px] uppercase">{row.original_label}</div>
                                        </td>
                                        <td className="px-4 py-3 text-right font-mono">{formatCurrency(row.resolved_amount)}</td>
                                        <td className="px-4 py-3 text-right font-mono font-bold">
                                            {formatCurrency(row.remaining)}
                                            {row.remaining > 0 && (
                                                <div className="text-[10px] uppercase text-slate-400">{row.remaining_label}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex text-[10px] font-black px-2 py-0.5 rounded border ${statusClass(row.status)}`}>
                                                {(row.status || '').replaceAll('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={reviewHref(row)}
                                                className="inline-flex items-center justify-center rounded-lg border border-amber-500/40 bg-amber-900/40 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-amber-50 hover:border-amber-400 hover:bg-amber-800/50"
                                            >
                                                Review
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
