import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import {
    Wrench, Plus, X, Check, ArrowRight, Play, RefreshCw, AlertOctagon,
    Clock, CheckCircle, HelpCircle, MessageSquare, Search, Calendar, UserCheck, Paperclip, ChevronRight
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import ActionModal from '@/Components/ActionModal';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';

const FILTER_TABS = [
    { key: 'all', label: 'All Tickets', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: 'open', label: 'Filed / Open', color: 'text-rose-400', dot: 'bg-rose-400' },
    { key: 'in_progress', label: 'Repairing', color: 'text-indigo-400', dot: 'bg-indigo-400' },
    { key: 'closed', label: 'Resolved / Closed', color: 'text-emerald-400', dot: 'bg-emerald-400' },
];

export default function Maintenance({ tickets, rooms, filters = {}, sortBy, sortDir }) {
    const { auth } = usePage().props;
    const currentUser = auth.user;

    const [isOpen, setIsOpen] = useState(false);
    const [isNotesOpen, setIsNotesOpen] = useState(false);
    const [selectedTicket, setSelectedTicket] = useState(null);
    const [nextStatus, setNextStatus] = useState('');
    const [currentFilter, setCurrentFilter] = useState(filters.status || 'all');
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [actionModalTicket, setActionModalTicket] = useState(null);
    const [dateFrom, setDateFrom] = useState(filters.from || '');
    const [dateTo, setDateTo] = useState(filters.to || '');

    const form = useForm({
        room_id: '',
        title: '',
        description: '',
        priority: 'medium',
        attachment: null,
        created_at: ''
    });

    const statusForm = useForm({
        status: '',
        notes: ''
    });

    const handleOpenAdd = () => {
        form.reset();
        form.clearErrors();
        setIsOpen(true);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        form.post(route('maintenance.store'), {
            onSuccess: () => {
                setIsOpen(false);
                form.reset();
            }
        });
    };

    const [isEditOpen, setIsEditOpen] = useState(false);
    const [editingTicket, setEditingTicket] = useState(null);

    const editForm = useForm({
        _method: 'PATCH',
        room_id: '',
        title: '',
        description: '',
        priority: 'medium',
        attachment: null,
        remove_attachment: false
    });

    const openEditModal = (ticket) => {
        setEditingTicket(ticket);
        editForm.setData({
            _method: 'PATCH',
            room_id: ticket.room_id || '',
            title: ticket.title || '',
            description: ticket.description || '',
            priority: ticket.priority || 'medium',
            attachment: null,
            remove_attachment: false
        });
        setIsEditOpen(true);
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        // Post request with method spoofing is required for multipart/form-data PATCHes
        editForm.post(route('maintenance.update', editingTicket.id), {
            onSuccess: () => {
                setIsEditOpen(false);
                setEditingTicket(null);
                editForm.reset();
            }
        });
    };

    const handleStatusTransition = (ticket, targetStatus) => {
        if (targetStatus === 'closed') {
            // Need notes modal first
            setSelectedTicket(ticket);
            setNextStatus('closed');
            statusForm.setData({
                status: 'closed',
                notes: ticket.notes || ''
            });
            setIsNotesOpen(true);
        } else {
            router.patch(route('maintenance.update', ticket.id), {
                status: targetStatus,
                notes: ticket.notes || ''
            });
        }
    };

    const handleNotesSubmit = (e) => {
        e.preventDefault();
        router.patch(route('maintenance.update', selectedTicket.id), statusForm.data, {
            onSuccess: () => {
                setIsNotesOpen(false);
                setSelectedTicket(null);
                statusForm.reset();
            }
        });
    };

    const cyclePriority = (ticket, e) => {
        e.stopPropagation();
        const priorities = ['low', 'medium', 'high', 'critical'];
        const currentIndex = priorities.indexOf(ticket.priority || 'medium');
        const nextPriority = priorities[(currentIndex + 1) % priorities.length];

        router.patch(route('maintenance.update', ticket.id), {
            priority: nextPriority,
            status: ticket.status
        });
    };

    const getPriorityStyle = (priority) => {
        switch (priority) {
            case 'critical':
                return 'bg-red-950/40 border-red-500/30 text-red-400';
            case 'high':
                return 'bg-orange-950/40 border-orange-500/30 text-orange-400';
            case 'medium':
                return 'bg-amber-950/40 border-amber-500/30 text-amber-400';
            default:
                return 'bg-slate-900 border-slate-700 text-slate-400';
        }
    };

    const canCloseTicket = ['admin', 'front_desk'].includes(currentUser.role);

    const handleSearch = (e) => {
        if (e) e.preventDefault();
        router.get(route('maintenance.index'), { search: searchTerm, status: currentFilter, from: dateFrom, to: dateTo }, { preserveState: true });
    };

    const handleClearFilters = () => {
        setSearchTerm('');
        setDateFrom('');
        setDateTo('');
        router.get(route('maintenance.index'), { status: currentFilter }, { preserveState: true });
    };

    const handleFilterChange = (key) => {
        setCurrentFilter(key);
        router.get(route('maintenance.index'), { search: searchTerm, status: key, from: dateFrom, to: dateTo }, { preserveState: true });
    };

    const activeTab = FILTER_TABS.find(t => t.key === currentFilter) || FILTER_TABS[0];
    const inputCls = "w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs";

    return (
        <AuthenticatedLayout>
            <Head title="Maintenance Tickets" />

            <div className="flex flex-col gap-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Maintenance Tickets
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Log property facility issues, assign repair priorities, and monitor housekeeping resolution status.</p>
                    </div>

                    <button
                        onClick={handleOpenAdd}
                        className="flex items-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-brand-600/20 active:scale-95 shrink-0 w-full sm:w-auto justify-center"
                    >
                        <Plus size={16} /> File New Ticket
                    </button>
                </div>

                {/* Tabs + Search */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155] overflow-x-auto mobile-scroll-tabs">
                        {FILTER_TABS.map(tab => {
                            return (
                                <button key={tab.key} onClick={() => handleFilterChange(tab.key)}
                                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${currentFilter === tab.key ? 'bg-[#0f172a] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'
                                        }`}>
                                    <span className={`w-1.5 h-1.5 rounded-full ${tab.dot} ${currentFilter === tab.key ? 'opacity-100' : 'opacity-40'}`} />
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                        <div className="flex items-center gap-2 bg-[#1e293b] p-1.5 rounded-xl border border-[#334155]">
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={e => setDateFrom(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2 py-1 focus:outline-none focus:border-brand-500 text-xs w-[120px]"
                            />
                            <span className="text-slate-500 text-[10px] font-bold">TO</span>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={e => setDateTo(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2 py-1 focus:outline-none focus:border-brand-500 text-xs w-[120px]"
                            />
                            <button type="button" onClick={handleSearch} className="px-2.5 py-1 bg-brand-600 hover:bg-brand-500 text-white font-bold text-[10px] rounded-lg transition-all">
                                Filter
                            </button>
                            {(searchTerm || dateFrom || dateTo) && (
                                <button type="button" onClick={handleClearFilters} className="px-2 text-slate-400 hover:text-white text-xs font-bold">
                                    Clear
                                </button>
                            )}
                        </div>
                        <div className="relative flex-1 sm:flex-initial">
                            <form onSubmit={handleSearch}>
                                <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                                <input type="text" value={searchTerm} onChange={e => setSearchTerm(e.target.value)}
                                    placeholder="Search room, issue, reporter..."
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs" />
                            </form>
                        </div>
                        <button type="button" onClick={() => router.reload({ only: ['tickets'] })} className="p-2.5 rounded-xl border border-[#334155] bg-[#1e293b] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 transition-all shrink-0 shadow-sm" title="Refresh Table">
                            <RefreshCw size={16} />
                        </button>
                    </div>
                </div>

                {/* Listing Table */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Room / Issue</th>
                                    <SortableHeader sortKey="priority" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Priority</SortableHeader>
                                    <SortableHeader sortKey="created_at" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Reported By / Date</SortableHeader>
                                    <SortableHeader sortKey="status" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Status</SortableHeader>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Resolution / Notes</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tickets.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-slate-500">
                                            {searchTerm ? `No results for "${searchTerm}"` : `No ${activeTab.label.toLowerCase()} found.`}
                                        </td>
                                    </tr>
                                ) : tickets.data.map((ticket, i) => (
                                    <motion.tr key={ticket.id} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">

                                        <td className="px-4 py-4">
                                            <span className="text-slate-300 font-extrabold text-[11px] block">Room {ticket.room?.room_number}</span>
                                            <span className="font-outfit font-bold text-slate-100 text-sm mt-0.5 block">{ticket.title}</span>
                                        </td>

                                        <td className="px-4 py-4">
                                            <span
                                                onClick={(e) => cyclePriority(ticket, e)}
                                                title="Click to cycle priority"
                                                className={`text-[9px] uppercase font-black px-2 py-1 rounded border cursor-pointer hover:scale-105 active:scale-95 transition-all select-none ${getPriorityStyle(ticket.priority)}`}
                                            >
                                                {ticket.priority}
                                            </span>
                                        </td>

                                        <td className="px-4 py-4 leading-normal">
                                            <div className="font-semibold text-slate-200">{ticket.reported_by?.name || ticket.reported_by}</div>
                                            <div className="text-[10px] text-slate-500 mt-0.5">{new Date(ticket.created_at).toLocaleDateString(undefined, { dateStyle: 'medium' })}</div>
                                        </td>

                                        <td className="px-4 py-4">
                                            {ticket.status === 'open' && (
                                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-rose-950/40 border border-rose-800 text-rose-400 text-[10px] rounded-full font-extrabold uppercase">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />
                                                    Open
                                                </span>
                                            )}
                                            {ticket.status === 'in_progress' && (
                                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-indigo-950/40 border border-indigo-800 text-indigo-400 text-[10px] rounded-full font-extrabold uppercase animate-pulse">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0" />
                                                    Repairing
                                                </span>
                                            )}
                                            {ticket.status === 'closed' && (
                                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-emerald-950/45 border border-emerald-800 text-emerald-400 text-[10px] rounded-full font-extrabold uppercase">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" />
                                                    Resolved
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-4 py-4 max-w-xs leading-relaxed">
                                            <div className="text-slate-300 text-xs truncate" title={ticket.description}>{ticket.description || '—'}</div>
                                            {ticket.attachment_path && (
                                                <div className="mt-1.5">
                                                    <a
                                                        href={ticket.attachment_path}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-1 text-[9px] font-extrabold text-brand-400 hover:text-brand-300 bg-brand-500/10 border border-brand-500/20 px-2 py-0.5 rounded transition-all hover:bg-brand-500/20 cursor-pointer"
                                                    >
                                                        <Paperclip size={10} /> View Attachment
                                                    </a>
                                                </div>
                                            )}
                                            {ticket.notes && (
                                                <div className="mt-1.5 p-2 rounded-lg bg-[#0f172a]/65 border border-[#334155]/50 flex gap-2 items-start">
                                                    <MessageSquare size={12} className="text-brand-400 mt-0.5 shrink-0" />
                                                    <div className="text-[10px] text-slate-300 font-medium">
                                                        <strong>Notes:</strong> {ticket.notes}
                                                    </div>
                                                </div>
                                            )}
                                        </td>

                                        <td className="px-4 py-4 text-right">
                                            <button onClick={() => setActionModalTicket(ticket)} className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] hover:bg-slate-800 border border-[#334155] rounded-lg text-[10px] font-bold text-slate-300 transition-colors">
                                                Manage
                                            </button>
                                        </td>

                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {/* Pagination */}
                    {tickets && tickets.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                            <span className="text-[10px] text-slate-500">
                                Showing {tickets.from}–{tickets.to} of {tickets.total} records
                            </span>
                            <Pagination links={tickets.links} />
                        </div>
                    )}
                </div>

                {/* MODAL: FILE NEW MAINTENANCE TICKET */}
                <AnimatePresence>
                    {isOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg flex items-center gap-2">
                                        <Wrench size={20} className="text-brand-400 animate-pulse" /> File Maintenance Ticket
                                    </h2>
                                    <button onClick={() => setIsOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                                    {/* Select Room */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Select Affected Room</label>
                                        <select
                                            value={form.data.room_id}
                                            onChange={e => form.setData('room_id', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        >
                                            <option value="">-- Choose Room --</option>
                                            {rooms.map(room => (
                                                <option key={room.id} value={room.id}>
                                                    Room {room.room_number} ({room.status})
                                                </option>
                                            ))}
                                        </select>
                                        {form.errors.room_id && <span className="text-[10px] text-red-400 font-semibold">{form.errors.room_id}</span>}
                                    </div>

                                    {/* Issue Title */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Issue Title</label>
                                        <input
                                            type="text"
                                            value={form.data.title}
                                            onChange={e => form.setData('title', e.target.value)}
                                            placeholder="e.g. Broken Air Conditioning Unit, Leaking Faucet..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                        {form.errors.title && <span className="text-[10px] text-red-400 font-semibold">{form.errors.title}</span>}
                                    </div>

                                    {/* Description */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Additional details</label>
                                        <textarea
                                            value={form.data.description}
                                            onChange={e => form.setData('description', e.target.value)}
                                            placeholder="Provide detail reports (e.g. AC makes rattling noises, water leaks near the bathroom floor...)"
                                            rows="4"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 resize-none"
                                        />
                                        {form.errors.description && <span className="text-[10px] text-red-400 font-semibold">{form.errors.description}</span>}
                                    </div>

                                    {/* Priority */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Severity Priority</label>
                                        <select
                                            value={form.data.priority}
                                            onChange={e => form.setData('priority', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                        >
                                            <option value="low">Low (Standard repair)</option>
                                            <option value="medium">Medium (Requires attention)</option>
                                            <option value="high">High (Disturbing stay)</option>
                                            <option value="critical">Critical (Needs immediate fix / Room unusable)</option>
                                        </select>
                                    </div>

                                    {/* Reported At */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Reported Date & Time (Optional)</label>
                                        <input
                                            type="datetime-local"
                                            value={form.data.created_at}
                                            onChange={e => form.setData('created_at', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 font-mono"
                                        />
                                        <p className="text-[9px] text-slate-500 mt-0.5">Leave empty to use the current date and time.</p>
                                    </div>

                                    {/* File Attachment */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">File Attachment (Optional)</label>
                                        <input
                                            type="file"
                                            onChange={e => form.setData('attachment', e.target.files[0])}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-400 px-4 py-2 focus:outline-none focus:border-brand-500"
                                        />
                                        {form.errors.attachment && <span className="text-[10px] text-red-400 font-semibold">{form.errors.attachment}</span>}
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={form.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Submit Ticket</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: EDIT MAINTENANCE TICKET */}
                <AnimatePresence>
                    {isEditOpen && editingTicket && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => { setIsEditOpen(false); setEditingTicket(null); }} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg flex items-center gap-2">
                                        <Wrench size={20} className="text-brand-400 animate-pulse" /> Edit Maintenance Ticket
                                    </h2>
                                    <button onClick={() => { setIsEditOpen(false); setEditingTicket(null); }} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleEditSubmit} className="p-6 space-y-4">
                                    {/* Select Room */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Select Affected Room</label>
                                        <select
                                            value={editForm.data.room_id}
                                            onChange={e => editForm.setData('room_id', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        >
                                            <option value="">-- Choose Room --</option>
                                            {rooms.map(room => (
                                                <option key={room.id} value={room.id}>
                                                    Room {room.room_number} ({room.status})
                                                </option>
                                            ))}
                                        </select>
                                        {editForm.errors.room_id && <span className="text-[10px] text-red-400 font-semibold">{editForm.errors.room_id}</span>}
                                    </div>

                                    {/* Issue Title */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Issue Title</label>
                                        <input
                                            type="text"
                                            value={editForm.data.title}
                                            onChange={e => editForm.setData('title', e.target.value)}
                                            placeholder="e.g. Broken Air Conditioning Unit, Leaking Faucet..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                        {editForm.errors.title && <span className="text-[10px] text-red-400 font-semibold">{editForm.errors.title}</span>}
                                    </div>

                                    {/* Description */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Additional details</label>
                                        <textarea
                                            value={editForm.data.description}
                                            onChange={e => editForm.setData('description', e.target.value)}
                                            placeholder="Provide detail reports (e.g. AC makes rattling noises, water leaks near the bathroom floor...)"
                                            rows="4"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 resize-none"
                                        />
                                        {editForm.errors.description && <span className="text-[10px] text-red-400 font-semibold">{editForm.errors.description}</span>}
                                    </div>

                                    {/* Priority */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Severity Priority</label>
                                        <select
                                            value={editForm.data.priority}
                                            onChange={e => editForm.setData('priority', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                        >
                                            <option value="low">Low (Standard repair)</option>
                                            <option value="medium">Medium (Requires attention)</option>
                                            <option value="high">High (Disturbing stay)</option>
                                            <option value="critical">Critical (Needs immediate fix / Room unusable)</option>
                                        </select>
                                    </div>

                                    {/* File Attachment */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Change File Attachment (Optional)</label>
                                        <input
                                            type="file"
                                            onChange={e => {
                                                editForm.setData('attachment', e.target.files[0]);
                                                editForm.setData('remove_attachment', false);
                                            }}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-400 px-4 py-2 focus:outline-none focus:border-brand-500"
                                        />
                                        {editingTicket.attachment_path && !editForm.data.remove_attachment && (
                                            <div className="flex items-center justify-between mt-2 p-2 rounded-lg bg-[#0f172a]/60 border border-[#334155]/60 text-[10px]">
                                                <span className="text-slate-350 truncate max-w-[200px]">Current: {editingTicket.attachment_path.split('/').pop()}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        editForm.setData('remove_attachment', true);
                                                        editForm.setData('attachment', null);
                                                    }}
                                                    className="text-red-400 hover:text-red-300 font-bold uppercase transition-colors"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        )}
                                        {editForm.data.remove_attachment && (
                                            <span className="text-[10px] text-amber-500 italic mt-1 block font-medium">Current attachment will be deleted upon save.</span>
                                        )}
                                        {editForm.errors.attachment && <span className="text-[10px] text-red-400 font-semibold">{editForm.errors.attachment}</span>}
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => { setIsEditOpen(false); setEditingTicket(null); }} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={editForm.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Save Changes</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: NOTES BEFORE RESOLVING */}
                <AnimatePresence>
                    {isNotesOpen && selectedTicket && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsNotesOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-sm uppercase flex items-center gap-2">
                                        <AlertOctagon size={16} className="text-emerald-400" /> Resolution notes
                                    </h2>
                                    <button onClick={() => setIsNotesOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleNotesSubmit} className="p-6 space-y-4">
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">How was the issue resolved?</label>
                                        <textarea
                                            value={statusForm.data.notes}
                                            onChange={e => statusForm.setData('notes', e.target.value)}
                                            placeholder="e.g. Replaced leaking copper valves; AC filters cleaned and checked..."
                                            rows="4"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 resize-none"
                                            required
                                        />
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsNotesOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Mark Resolved</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

            </div>

            <ActionModal
                isOpen={!!actionModalTicket}
                onClose={() => setActionModalTicket(null)}
                title={`Manage Ticket`}
            >
                {actionModalTicket && (
                    <>
                        {actionModalTicket.status === 'open' && (
                            <>
                                <button
                                    onClick={() => { setActionModalTicket(null); handleStatusTransition(actionModalTicket, 'in_progress'); }}
                                    className="w-full flex items-center gap-2 px-4 py-3 bg-indigo-950 hover:bg-indigo-900 border border-indigo-800 text-indigo-400 rounded-xl text-xs font-bold uppercase transition-colors"
                                >
                                    <Play size={16} /> Start Work
                                </button>
                                <button
                                    onClick={() => { setActionModalTicket(null); openEditModal(actionModalTicket); }}
                                    className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 rounded-xl text-xs font-bold text-amber-400 transition-colors uppercase"
                                >
                                    Edit Ticket
                                </button>
                            </>
                        )}
                        {actionModalTicket.status === 'in_progress' && (
                            <>
                                <button
                                    onClick={() => { setActionModalTicket(null); handleStatusTransition(actionModalTicket, 'open'); }}
                                    className="w-full flex items-center gap-2 px-4 py-3 bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-400 rounded-xl text-xs font-bold uppercase transition-colors"
                                >
                                    Put Back
                                </button>
                                <button
                                    onClick={() => { setActionModalTicket(null); openEditModal(actionModalTicket); }}
                                    className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 rounded-xl text-xs font-bold text-amber-400 transition-colors uppercase"
                                >
                                    Edit Ticket
                                </button>
                                {canCloseTicket ? (
                                    <button
                                        onClick={() => { setActionModalTicket(null); handleStatusTransition(actionModalTicket, 'closed'); }}
                                        className="w-full flex items-center gap-2 px-4 py-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold uppercase transition-colors"
                                    >
                                        <Check size={16} /> Resolve
                                    </button>
                                ) : (
                                    <div className="w-full flex items-center gap-2 px-4 py-3 bg-slate-800/50 rounded-xl text-xs font-bold text-slate-500 italic">
                                        Closing Restricted
                                    </div>
                                )}
                            </>
                        )}
                        {actionModalTicket.status === 'closed' && canCloseTicket && (
                            <button
                                onClick={() => { setActionModalTicket(null); handleStatusTransition(actionModalTicket, 'in_progress'); }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 text-brand-400 rounded-xl text-xs font-bold uppercase transition-colors"
                            >
                                <RefreshCw size={16} /> Reopen
                            </button>
                        )}
                    </>
                )}
            </ActionModal>
        </AuthenticatedLayout>
    );
}
