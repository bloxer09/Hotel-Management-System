import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Shield, Eye, Clock, User, HardDrive, HelpCircle, X, ChevronDown, ChevronUp, Search, Calendar } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function Audit({ logs, users = [], modules = [], filters = {} }) {
    const [keyword, setKeyword] = useState(filters.keyword || '');
    const [userId, setUserId] = useState(filters.user_id || '');
    const [moduleFilter, setModuleFilter] = useState(filters.module || '');
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');
    const [expandedLogId, setExpandedLogId] = useState(null);
    const [selectedLog, setSelectedLog] = useState(null);

    const prettyPrintJson = (val) => {
        if (!val) return 'None';
        try {
            const parsed = typeof val === 'string' ? JSON.parse(val) : val;
            return JSON.stringify(parsed, null, 2);
        } catch (e) {
            return String(val);
        }
    };

    const handleFilterSubmit = (e) => {
        if (e) e.preventDefault();
        router.get(route('settings.audit.index'), {
            keyword,
            user_id: userId,
            module: moduleFilter,
            date_from: dateFrom,
            date_to: dateTo
        }, {
            preserveState: true,
            replace: true
        });
    };

    const handleClearFilters = () => {
        setKeyword('');
        setUserId('');
        setModuleFilter('');
        setDateFrom('');
        setDateTo('');

        router.get(route('settings.audit.index'), {}, {
            preserveState: true,
            replace: true
        });
    };

    const toggleExpandRow = (id) => {
        setExpandedLogId(expandedLogId === id ? null : id);
    };

    const renderDiff = (log) => {
        let oldObj = {};
        let newObj = {};

        try {
            oldObj = typeof log.old_values === 'string' ? JSON.parse(log.old_values) : (log.old_values || {});
        } catch (e) { }

        try {
            newObj = typeof log.new_values === 'string' ? JSON.parse(log.new_values) : (log.new_values || {});
        } catch (e) { }

        // Exclude system fields from flooding the diff if desired (keep it simple for now)
        const allKeys = Array.from(new Set([...Object.keys(oldObj), ...Object.keys(newObj)]));
        const changedKeys = allKeys.filter(key => {
            const oldVal = oldObj[key];
            const newVal = newObj[key];
            return JSON.stringify(oldVal) !== JSON.stringify(newVal);
        });

        if (changedKeys.length === 0) {
            return (
                <div className="p-4 text-center text-xs text-slate-500 italic bg-[#0f172a]/40 border border-slate-800 rounded-xl">
                    No values were changed in this action.
                </div>
            );
        }

        const formatVal = (v) => {
            if (v === null || v === undefined) return <span className="text-slate-500 italic">null</span>;
            if (typeof v === 'boolean') return v ? 'true' : 'false';
            if (typeof v === 'object') return JSON.stringify(v);
            return String(v);
        };

        return (
            <div className="border border-slate-700/60 rounded-xl overflow-hidden bg-[#0f172a]/60 shadow-inner">
                <div className="grid grid-cols-3 gap-4 bg-slate-950/80 px-4 py-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest border-b border-[#334155]">
                    <div>Field</div>
                    <div>Old Value</div>
                    <div>New Value</div>
                </div>
                <div className="divide-y divide-[#334155]/40 text-xs">
                    {changedKeys.map(key => {
                        const oldVal = oldObj[key];
                        const newVal = newObj[key];

                        return (
                            <div key={key} className="grid grid-cols-3 gap-4 px-4 py-2.5 items-center hover:bg-[#0f172a]/30 transition-colors">
                                <div className="font-mono text-slate-300 font-bold break-all">{key}</div>
                                <div className="text-red-400 font-medium break-all bg-red-950/10 px-2 py-1 rounded border border-red-950/20 line-through decoration-red-700/60">
                                    {formatVal(oldVal)}
                                </div>
                                <div className="text-emerald-400 font-bold break-all bg-emerald-950/10 px-2 py-1 rounded border border-emerald-950/20">
                                    {formatVal(newVal)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Audit Logs" />

            <div className="flex flex-col gap-6">

                {/* Header title */}
                <div>
                    <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100 flex items-center gap-3">
                        <Shield className="text-brand-500" /> Audit Logs
                    </h1>
                    <p className="text-sm text-slate-400 font-medium mt-1">Audit administrative action trails, user operation histories, and system configuration modifications.</p>
                </div>

                {/* Filters card */}
                <form onSubmit={handleFilterSubmit} className="p-5 rounded-2xl bg-[#1e293b]/70 border border-[#334155]/60 shadow-lg backdrop-blur-sm grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4 items-end">
                    <div className="flex flex-col gap-1.5">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider flex items-center gap-1">
                            <Search size={10} /> Search
                        </label>
                        <input
                            type="text"
                            value={keyword}
                            onChange={(e) => setKeyword(e.target.value)}
                            placeholder="Search logs..."
                            className="bg-[#0f172a] border border-[#334155] rounded-xl px-4 py-2.5 text-slate-100 text-xs focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-all placeholder:text-slate-650"
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Staff</label>
                        <select
                            value={userId}
                            onChange={(e) => setUserId(e.target.value)}
                            className="bg-[#0f172a] border border-[#334155] rounded-xl px-4 py-2.5 text-slate-100 text-xs focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-all"
                        >
                            <option value="">All Staff</option>
                            {users.map(u => (
                                <option key={u.id} value={u.id}>{u.full_name || u.username} ({u.role.replace('_', ' ').toUpperCase()})</option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Module</label>
                        <select
                            value={moduleFilter}
                            onChange={(e) => setModuleFilter(e.target.value)}
                            className="bg-[#0f172a] border border-[#334155] rounded-xl px-4 py-2.5 text-slate-100 text-xs focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-all"
                        >
                            <option value="">All Modules</option>
                            {modules.map(m => (
                                <option key={m} value={m}>{m.toUpperCase()}</option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider flex items-center gap-1">
                            <Calendar size={10} /> Date From
                        </label>
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            className="bg-[#0f172a] border border-[#334155] rounded-xl px-4 py-2.5 text-slate-100 text-xs focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-all"
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                        <div className="flex flex-col gap-1.5">
                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider flex items-center gap-1">
                                <Calendar size={10} /> Date To
                            </label>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-xl px-4 py-2.5 text-slate-100 text-xs focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-all"
                            />
                        </div>
                        <div className="flex gap-1.5 items-end justify-end">
                            <button
                                type="button"
                                onClick={handleClearFilters}
                                className="px-3 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-slate-200 border border-[#334155] font-outfit font-extrabold text-[10px] rounded-xl transition-all grow text-center"
                            >
                                Reset
                            </button>
                            <button
                                type="submit"
                                className="px-3 py-2.5 bg-brand-660 hover:bg-brand-500 text-white font-outfit font-extrabold text-[10px] rounded-xl shadow-lg shadow-brand-650/20 transition-all grow text-center"
                            >
                                Apply
                            </button>
                        </div>
                    </div>
                </form>

                {/* Audit logs listing table */}
                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-6">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr className="border-b border-[#334155] text-slate-400 uppercase tracking-wider font-semibold">
                                    <th className="pb-3">Date</th>
                                    <th className="pb-3">Staff</th>
                                    <th className="pb-3">Action</th>
                                    <th className="pb-3">Module</th>
                                    <th className="pb-3">Description</th>
                                    <th className="pb-3 text-right">Details</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#334155]/65 text-slate-300">
                                {logs.data.length > 0 ? (
                                    logs.data.map((log) => (
                                        <React.Fragment key={log.id}>
                                            <tr
                                                onClick={() => (log.old_values || log.new_values) && toggleExpandRow(log.id)}
                                                className={`transition-colors cursor-pointer select-none ${expandedLogId === log.id
                                                        ? 'bg-[#0f172a]/50 border-l-2 border-brand-500'
                                                        : 'hover:bg-[#0f172a]/20'
                                                    }`}
                                            >
                                                <td className="py-3.5 font-mono text-slate-400">
                                                    {new Date(log.created_at).toLocaleString()}
                                                </td>
                                                <td className="py-3.5">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="font-outfit font-extrabold text-slate-200">{log.user?.full_name || log.user?.username || 'System Auto'}</span>
                                                        <span className="text-[9px] bg-[#334155] text-slate-400 px-1.5 py-0.5 rounded font-mono font-bold uppercase">{log.user?.role || 'SYSTEM'}</span>
                                                    </div>
                                                </td>
                                                <td className="py-3.5">
                                                    <span className="inline-flex items-center text-[10px] font-bold font-mono px-2 py-0.5 rounded bg-brand-950 border border-brand-800/40 text-brand-400 uppercase">
                                                        {log.action}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 font-mono text-slate-400">
                                                    {log.module || log.auditable_type || 'system'}
                                                    {log.auditable_id ? ` (ID: ${log.auditable_id})` : ''}
                                                </td>
                                                <td className="py-3.5 max-w-sm truncate text-slate-300" title={log.description}>
                                                    {log.description}
                                                </td>
                                                <td className="py-3.5 text-right">
                                                    {(log.old_values || log.new_values) ? (
                                                        <div className="flex items-center justify-end gap-2">
                                                            <span className="text-[10px] font-semibold text-brand-400/80 hover:text-brand-300 flex items-center gap-1">
                                                                {expandedLogId === log.id ? (
                                                                    <>Collapse <ChevronUp size={12} /></>
                                                                ) : (
                                                                    <>View Changes <ChevronDown size={12} /></>
                                                                )}
                                                            </span>
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setSelectedLog(log);
                                                                }}
                                                                className="px-2.5 py-1 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] text-slate-400 hover:text-slate-200 font-outfit font-bold text-[9px] rounded-md transition-all"
                                                                title="Raw JSON"
                                                            >
                                                                JSON
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <span className="text-[10px] text-slate-500 font-medium italic">No changes</span>
                                                    )}
                                                </td>
                                            </tr>
                                            {expandedLogId === log.id && (log.old_values || log.new_values) && (
                                                <tr className="bg-[#0f172a]/30">
                                                    <td colSpan="6" className="p-4 border-b border-[#334155]/60">
                                                        <div className="flex flex-col gap-3">
                                                            <div className="flex justify-between items-center px-1">
                                                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                                                                    <Shield size={12} className="text-brand-400" /> Changes
                                                                </span>
                                                                <span className="text-[9px] text-slate-500 italic">
                                                                    Showing only modified values.
                                                                </span>
                                                            </div>
                                                            {renderDiff(log)}
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </React.Fragment>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="py-8 text-center text-slate-500">
                                            No logs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination control bar */}
                    {logs.links && logs.links.length > 3 && (
                        <div className="pt-4 border-t border-[#334155]/60 flex items-center justify-between">
                            <span className="text-xs text-slate-400">
                                Showing records <span className="font-bold font-mono text-slate-300">{logs.from || 0}</span> to <span className="font-bold font-mono text-slate-300">{logs.to || 0}</span> of <span className="font-bold font-mono text-slate-300">{logs.total}</span> entries
                            </span>
                            <div className="flex gap-1">
                                {logs.links.map((link) => (
                                    <Link
                                        key={link.label}
                                        href={link.url || '#'}
                                        disabled={!link.url}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                        className={`px-3.5 py-2 border rounded-xl text-xs font-outfit font-extrabold transition-all ${link.active
                                                ? 'bg-brand-600 text-slate-50 border-brand-500/40 shadow-lg shadow-brand-600/20'
                                                : link.url
                                                    ? 'bg-[#0f172a]/40 border-[#334155] text-slate-400 hover:text-slate-200'
                                                    : 'opacity-40 border-[#334155] text-slate-650 cursor-not-allowed'
                                            }`}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* MODAL: INSPECT VALUES */}
                <AnimatePresence>
                    {selectedLog && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setSelectedLog(null)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-4xl shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <div className="flex flex-col">
                                        <h2 className="font-outfit font-black text-slate-100 text-lg">Log Details</h2>
                                        <span className="text-[10px] text-slate-500">Log ID: #{selectedLog.id} • Action: {selectedLog.action}</span>
                                    </div>
                                    <button onClick={() => setSelectedLog(null)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <div className="p-6 space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

                                        {/* Old Values */}
                                        <div className="flex flex-col gap-2">
                                            <span className="text-[10px] uppercase font-bold text-red-400 tracking-wider">Old Values</span>
                                            <pre className="w-full bg-[#0f172a] border border-red-950/40 rounded-xl text-[10px] font-mono text-red-200 p-4 min-h-[300px] overflow-auto scrollbar-thin select-all">
                                                {prettyPrintJson(selectedLog.old_values)}
                                            </pre>
                                        </div>

                                        {/* New Values */}
                                        <div className="flex flex-col gap-2">
                                            <span className="text-[10px] uppercase font-bold text-emerald-400 tracking-wider">New Values</span>
                                            <pre className="w-full bg-[#0f172a] border border-emerald-950/40 rounded-xl text-[10px] font-mono text-emerald-200 p-4 min-h-[300px] overflow-auto scrollbar-thin select-all">
                                                {prettyPrintJson(selectedLog.new_values)}
                                            </pre>
                                        </div>

                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex items-center justify-between text-[10px] text-slate-500">
                                        <span>IP Address: <span className="font-bold text-slate-400">{selectedLog.ip_address || 'Unknown'}</span></span>
                                        <button type="button" onClick={() => setSelectedLog(null)} className="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Close</button>
                                    </div>
                                </div>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

            </div>
        </AuthenticatedLayout>
    );
}
