import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import {
    Wrench, Plus, X, Check, ArrowRight, Play, RefreshCw, AlertOctagon,
    Clock, CheckCircle, HelpCircle, MessageSquare, Search, Calendar, UserCheck, Paperclip, ChevronRight, ChevronDown
} from 'lucide-react';
import { motion } from 'framer-motion';
import Modal from '@/Components/Modal';
import ActionModal from '@/Components/ActionModal';
import CustomSelect from '@/Components/CustomSelect';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';

const FILTER_TABS = [
    { key: 'all', label: 'All Tickets', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: 'open', label: 'Filed / Open', color: 'text-rose-400', dot: 'bg-rose-400' },
    { key: 'in_progress', label: 'Repairing', color: 'text-indigo-400', dot: 'bg-indigo-400' },
    { key: 'for_verification', label: 'For Verification', color: 'text-amber-400', dot: 'bg-amber-400' },
    { key: 'closed', label: 'Resolved / Closed', color: 'text-emerald-400', dot: 'bg-emerald-400' },
];

export default function Maintenance({ tickets, rooms, filters = {}, sortBy, sortDir }) {
    const { auth } = usePage().props;
    const currentUser = auth.user;

    const [isOpen, setIsOpen] = useState(false);
    const [isResolutionOpen, setIsResolutionOpen] = useState(false);
    const [selectedTicket, setSelectedTicket] = useState(null);
    const [currentFilter, setCurrentFilter] = useState(filters.status || 'all');
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [isDetailsOpen, setIsDetailsOpen] = useState(false);
    const [detailTicket, setDetailTicket] = useState(null);
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

    const resolutionForm = useForm({
        _method: 'PATCH',
        status: 'for_verification',
        resolution_notes: '',
        repaired_by: '',
        repaired_at: '',
        repair_cost: '0',
        receipt_reference: '',
        receipt_attachment: null,
        after_repair_attachment: null,
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

    const openDetailsModal = (ticket) => {
        setDetailTicket(ticket);
        setIsDetailsOpen(true);
    };

    const closeDetailsModal = () => {
        setIsDetailsOpen(false);
        setDetailTicket(null);
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
        if (targetStatus === 'for_verification') {
            setSelectedTicket(ticket);
            resolutionForm.setData({
                _method: 'PATCH',
                status: 'for_verification',
                resolution_notes: ticket.resolution_notes || ticket.notes || '',
                repaired_by: ticket.repaired_by || '',
                repaired_at: ticket.repaired_at ? ticket.repaired_at.slice(0, 16) : '',
                repair_cost: ticket.repair_cost ?? '0',
                receipt_reference: ticket.receipt_reference || '',
                receipt_attachment: null,
                after_repair_attachment: null,
            });
            setIsResolutionOpen(true);
        } else {
            router.patch(route('maintenance.update', ticket.id), {
                status: targetStatus,
            });
        }
    };

    const handleResolutionSubmit = (e) => {
        e.preventDefault();
        resolutionForm.post(route('maintenance.update', selectedTicket.id), {
            forceFormData: true,
            onSuccess: () => {
                setIsResolutionOpen(false);
                setSelectedTicket(null);
                resolutionForm.reset();
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

    const canVerifyTicket = currentUser.role === 'admin';
    const formatMoney = (amount) => `₱${Number(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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
                    {/* Ticket CustomSelect Dropdown */}
                    <CustomSelect
                        value={currentFilter}
                        onChange={handleFilterChange}
                        containerClassName="sm:w-56"
                        options={FILTER_TABS}
                    />
                    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                        <div className="flex items-center gap-2 bg-[#1e293b] px-3 py-1.5 rounded-xl border border-[#334155]">
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={e => setDateFrom(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2.5 py-1.5 focus:outline-none focus:border-brand-500 text-xs w-[125px]"
                            />
                            <span className="text-slate-500 text-[10px] font-bold">TO</span>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={e => setDateTo(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2.5 py-1.5 focus:outline-none focus:border-brand-500 text-xs w-[125px]"
                            />
                            <button type="button" onClick={handleSearch} className="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white font-bold text-[10px] rounded-lg transition-all">
                                Filter
                            </button>
                            {(searchTerm || dateFrom || dateTo) && (
                                <button type="button" onClick={handleClearFilters} className="px-2 py-1.5 text-slate-400 hover:text-white text-xs font-bold">
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
                        <table className="w-full min-w-[980px] text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[23%]">Room / Issue</th>
                                    <SortableHeader sortKey="priority" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[9%]">Priority</SortableHeader>
                                    <SortableHeader sortKey="created_at" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[16%]">Reported By / Date</SortableHeader>
                                    <SortableHeader sortKey="status" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[13%]">Status</SortableHeader>
                                    <th className="px-5 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[27%]">Resolution / Notes</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right w-[12%]">Actions</th>
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
                                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-indigo-950/40 border border-indigo-800 text-indigo-400 text-[10px] rounded-full font-extrabold uppercase">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0" />
                                                    Repairing
                                                </span>
                                            )}
                                            {ticket.status === 'for_verification' && (
                                                <span className="inline-flex max-w-full items-center gap-1.5 px-2 py-0.5 bg-amber-950/40 border border-amber-700 text-amber-400 text-[10px] rounded-full font-extrabold uppercase whitespace-normal">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0" />
                                                    For Verification
                                                </span>
                                            )}
                                            {ticket.status === 'closed' && (
                                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-emerald-950/45 border border-emerald-800 text-emerald-400 text-[10px] rounded-full font-extrabold uppercase">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" />
                                                    Resolved
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-5 py-4 leading-relaxed break-words">
                                            <div className="text-slate-300 text-xs truncate" title={ticket.description}>{ticket.description || '—'}</div>
                                            {ticket.attachment_path && (
                                                <div className="hidden mt-1.5">
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
                                            {ticket.resolution_notes && (
                                                <div className="mt-1.5 p-2 rounded-lg bg-[#0f172a]/65 border border-[#334155]/50 flex gap-2 items-start">
                                                    <MessageSquare size={12} className="text-brand-400 mt-0.5 shrink-0" />
                                                    <div className="text-[10px] text-slate-300 font-medium truncate" title={ticket.resolution_notes}>
                                                        <strong>Resolution:</strong> {ticket.resolution_notes}
                                                    </div>
                                                </div>
                                            )}
                                            {ticket.repaired_by && (
                                                <div className="hidden mt-1.5 text-[10px] text-slate-400 space-y-0.5">
                                                    <div><strong className="text-slate-300">Repaired by:</strong> {ticket.repaired_by}</div>
                                                    {ticket.repaired_at && <div><strong className="text-slate-300">Date fixed:</strong> {new Date(ticket.repaired_at).toLocaleString()}</div>}
                                                    <div><strong className="text-slate-300">Cost:</strong> {formatMoney(ticket.repair_cost)}</div>
                                                    {ticket.receipt_reference && <div><strong className="text-slate-300">Receipt ref:</strong> {ticket.receipt_reference}</div>}
                                                </div>
                                            )}
                                            {(ticket.receipt_attachment_path || ticket.after_repair_attachment_path) && (
                                                <div className="hidden mt-2 flex flex-wrap gap-1.5">
                                                    {ticket.receipt_attachment_path && <a href={ticket.receipt_attachment_path} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[9px] font-extrabold text-amber-300 bg-amber-500/10 border border-amber-500/20 px-2 py-0.5 rounded"><Paperclip size={10} /> Receipt</a>}
                                                    {ticket.after_repair_attachment_path && <a href={ticket.after_repair_attachment_path} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[9px] font-extrabold text-emerald-300 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded"><Paperclip size={10} /> After repair photo</a>}
                                                </div>
                                            )}
                                            {ticket.verified_by && (
                                                <div className="hidden mt-1.5 text-[9px] text-emerald-400 font-semibold">
                                                    Verified by {ticket.verified_by.name || 'Admin'}{ticket.verified_at ? ` · ${new Date(ticket.verified_at).toLocaleString()}` : ''}
                                                </div>
                                            )}
                                            {!ticket.resolution_notes && ticket.notes && (
                                                <div className="mt-1.5 p-2 rounded-lg bg-[#0f172a]/65 border border-[#334155]/50 flex gap-2 items-start">
                                                    <MessageSquare size={12} className="text-brand-400 mt-0.5 shrink-0" />
                                                    <div className="text-[10px] text-slate-300 font-medium truncate" title={ticket.notes}><strong>Notes:</strong> {ticket.notes}</div>
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
                <Modal show={isOpen} onClose={() => setIsOpen(false)} maxWidth="md">
                    <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                        <h2 className="font-outfit font-black text-slate-100 text-lg flex items-center gap-2">
                            <Wrench size={20} className="text-brand-400" /> File Maintenance Ticket
                        </h2>
                        <button onClick={() => setIsOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                    </div>

                    <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                        {/* Select Room */}
                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Select Affected Room</label>
                            <CustomSelect
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
                            </CustomSelect>
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
                            <CustomSelect
                                value={form.data.priority}
                                onChange={e => form.setData('priority', e.target.value)}
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                            >
                                <option value="low">Low (Standard repair)</option>
                                <option value="medium">Medium (Requires attention)</option>
                                <option value="high">High (Disturbing stay)</option>
                                <option value="critical">Critical (Needs immediate fix / Room unusable)</option>
                            </CustomSelect>
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
                </Modal>

                {/* MODAL: EDIT MAINTENANCE TICKET */}
                <Modal show={isEditOpen && !!editingTicket} onClose={() => { setIsEditOpen(false); setEditingTicket(null); }} maxWidth="md">
                    <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                        <h2 className="font-outfit font-black text-slate-100 text-lg flex items-center gap-2">
                            <Wrench size={20} className="text-brand-400" /> Edit Maintenance Ticket
                        </h2>
                        <button onClick={() => { setIsEditOpen(false); setEditingTicket(null); }} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                    </div>

                    <form onSubmit={handleEditSubmit} className="p-6 space-y-4">
                        {/* Select Room */}
                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Select Affected Room</label>
                            <CustomSelect
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
                            </CustomSelect>
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
                            <CustomSelect
                                value={editForm.data.priority}
                                onChange={e => editForm.setData('priority', e.target.value)}
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                            >
                                <option value="low">Low (Standard repair)</option>
                                <option value="medium">Medium (Requires attention)</option>
                                <option value="high">High (Disturbing stay)</option>
                                <option value="critical">Critical (Needs immediate fix / Room unusable)</option>
                            </CustomSelect>
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
                            {editingTicket?.attachment_path && !editForm.data.remove_attachment && (
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
                </Modal>

                {/* MODAL: SUBMIT COMPLETED REPAIR FOR VERIFICATION */}
                <Modal show={isResolutionOpen && !!selectedTicket} onClose={() => setIsResolutionOpen(false)} maxWidth="md">
                    <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                        <h2 className="font-outfit font-black text-slate-100 text-sm uppercase flex items-center gap-2">
                            <AlertOctagon size={16} className="text-amber-400" /> Submit Repair for Verification
                        </h2>
                        <button onClick={() => setIsResolutionOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                    </div>

                    <form onSubmit={handleResolutionSubmit} className="p-6 space-y-4">
                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">How was the issue resolved?</label>
                            <textarea
                                value={resolutionForm.data.resolution_notes}
                                onChange={e => resolutionForm.setData('resolution_notes', e.target.value)}
                                placeholder="e.g. Replaced leaking copper valves; AC filters cleaned and checked..."
                                rows="4"
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 resize-none"
                                required
                            />
                            {resolutionForm.errors.resolution_notes && <span className="text-[10px] text-red-400 font-semibold">{resolutionForm.errors.resolution_notes}</span>}
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="flex flex-col gap-1">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Repaired By</label>
                                <input type="text" value={resolutionForm.data.repaired_by} onChange={e => resolutionForm.setData('repaired_by', e.target.value)} placeholder="Staff or technician name" className={inputCls} required />
                                {resolutionForm.errors.repaired_by && <span className="text-[10px] text-red-400 font-semibold">{resolutionForm.errors.repaired_by}</span>}
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Date & Time Fixed</label>
                                <input type="datetime-local" value={resolutionForm.data.repaired_at} onChange={e => resolutionForm.setData('repaired_at', e.target.value)} className={inputCls} required />
                                {resolutionForm.errors.repaired_at && <span className="text-[10px] text-red-400 font-semibold">{resolutionForm.errors.repaired_at}</span>}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="flex flex-col gap-1">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Repair Cost (₱)</label>
                                <input type="number" min="0" step="0.01" value={resolutionForm.data.repair_cost} onChange={e => resolutionForm.setData('repair_cost', e.target.value)} className={inputCls} required />
                                <p className="text-[9px] text-slate-500">Use 0.00 if no repair expense was incurred.</p>
                                {resolutionForm.errors.repair_cost && <span className="text-[10px] text-red-400 font-semibold">{resolutionForm.errors.repair_cost}</span>}
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Receipt / Expense Reference (Optional)</label>
                                <input type="text" value={resolutionForm.data.receipt_reference} onChange={e => resolutionForm.setData('receipt_reference', e.target.value)} placeholder="e.g. EXP-102 or supplier receipt no." className={inputCls} />
                                <p className="text-[9px] text-slate-500">Encode drawer expenses separately in Expenses.</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="flex flex-col gap-1">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Receipt Photo / PDF (Optional)</label>
                                <input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={e => resolutionForm.setData('receipt_attachment', e.target.files[0])} className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-400 px-3 py-2" />
                                {resolutionForm.errors.receipt_attachment && <span className="text-[10px] text-red-400 font-semibold">{resolutionForm.errors.receipt_attachment}</span>}
                            </div>
                            <div className="flex flex-col gap-1">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">After-Repair Photo (Optional)</label>
                                <input type="file" accept="image/jpeg,image/png" onChange={e => resolutionForm.setData('after_repair_attachment', e.target.files[0])} className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-400 px-3 py-2" />
                                {resolutionForm.errors.after_repair_attachment && <span className="text-[10px] text-red-400 font-semibold">{resolutionForm.errors.after_repair_attachment}</span>}
                            </div>
                        </div>

                        <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                            <button type="button" onClick={() => setIsResolutionOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                            <button type="submit" disabled={resolutionForm.processing} className="px-5 py-2.5 bg-amber-600 hover:bg-amber-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Submit for Verification</button>
                        </div>
                    </form>
                </Modal>

            </div>

            <Modal show={isDetailsOpen && !!detailTicket} onClose={closeDetailsModal} maxWidth="3xl">
                {detailTicket && (
                    <>
                        <div className="p-5 border-b border-[#334155] flex items-start justify-between gap-4">
                            <div>
                                <p className="text-[10px] uppercase tracking-wider text-slate-500 font-bold">Maintenance Ticket #{detailTicket.id}</p>
                                <h2 className="font-outfit font-black text-slate-100 text-lg mt-1">Room {detailTicket.room?.room_number} · {detailTicket.title}</h2>
                                <p className="text-xs text-slate-400 mt-1">Status: <span className="font-bold text-slate-200">{detailTicket.status.replace('_', ' ')}</span></p>
                            </div>
                            <button onClick={closeDetailsModal} className="text-slate-400 hover:text-slate-100"><X size={20} /></button>
                        </div>

                        <div className="p-5 space-y-5 max-h-[70vh] overflow-y-auto">
                            <section className="rounded-xl bg-[#0f172a]/65 border border-[#334155]/60 p-4">
                                <h3 className="text-[10px] uppercase tracking-wider font-black text-brand-400 mb-2">Reported Damage / Issue</h3>
                                <p className="text-sm font-bold text-slate-100">{detailTicket.title}</p>
                                <p className="text-xs leading-relaxed text-slate-300 mt-1 whitespace-pre-wrap">{detailTicket.description || 'No additional report details.'}</p>
                                <div className="mt-3 text-[10px] text-slate-400">Reported by {detailTicket.reported_by?.name || detailTicket.reported_by || '-'} · {new Date(detailTicket.created_at).toLocaleString()}</div>
                                {detailTicket.attachment_path && <a href={detailTicket.attachment_path} target="_blank" rel="noopener noreferrer" className="mt-3 inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-brand-500/10 border border-brand-500/25 text-brand-300 text-xs font-bold"><Paperclip size={13} /> View damage / before-repair attachment</a>}
                            </section>

                            <section className="rounded-xl bg-[#0f172a]/65 border border-[#334155]/60 p-4">
                                <h3 className="text-[10px] uppercase tracking-wider font-black text-emerald-400 mb-2">Repair & Resolution</h3>
                                {detailTicket.resolution_notes ? (
                                    <>
                                        <p className="text-xs leading-relaxed text-slate-200 whitespace-pre-wrap">{detailTicket.resolution_notes}</p>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 mt-4 text-xs">
                                            <div><span className="text-slate-500">Repaired by:</span> <span className="font-semibold text-slate-200">{detailTicket.repaired_by || '-'}</span></div>
                                            <div><span className="text-slate-500">Date fixed:</span> <span className="font-semibold text-slate-200">{detailTicket.repaired_at ? new Date(detailTicket.repaired_at).toLocaleString() : '-'}</span></div>
                                            <div><span className="text-slate-500">Repair cost:</span> <span className="font-semibold text-emerald-300">{formatMoney(detailTicket.repair_cost)}</span></div>
                                            <div><span className="text-slate-500">Receipt / expense ref:</span> <span className="font-semibold text-slate-200">{detailTicket.receipt_reference || '-'}</span></div>
                                        </div>
                                        <div className="flex flex-wrap gap-2 mt-4">
                                            {detailTicket.receipt_attachment_path && <a href={detailTicket.receipt_attachment_path} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/25 text-amber-300 text-xs font-bold"><Paperclip size={13} /> Open receipt</a>}
                                            {detailTicket.after_repair_attachment_path && <a href={detailTicket.after_repair_attachment_path} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs font-bold"><Paperclip size={13} /> Open after-repair photo</a>}
                                        </div>
                                    </>
                                ) : <p className="text-xs text-slate-500 italic">No completed repair details submitted yet.</p>}
                            </section>

                            {detailTicket.verified_by && <section className="rounded-xl bg-emerald-950/20 border border-emerald-800/50 p-4 text-xs text-emerald-200"><strong>Verified & closed by:</strong> {detailTicket.verified_by.name || 'Admin'}{detailTicket.verified_at ? ` on ${new Date(detailTicket.verified_at).toLocaleString()}` : ''}</section>}

                            <section className="border-t border-[#334155] pt-4">
                                <h3 className="text-[10px] uppercase tracking-wider font-black text-slate-400 mb-3">Ticket Actions</h3>
                                <div className="flex flex-wrap gap-2">
                                    {detailTicket.status === 'open' && <><button onClick={() => { const ticket = detailTicket; closeDetailsModal(); handleStatusTransition(ticket, 'in_progress'); }} className="px-4 py-2.5 bg-indigo-950 hover:bg-indigo-900 border border-indigo-800 text-indigo-300 rounded-xl text-xs font-bold"><Play size={14} className="inline mr-1" /> Start Work</button><button onClick={() => { const ticket = detailTicket; closeDetailsModal(); openEditModal(ticket); }} className="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold">Edit Ticket</button></>}
                                    {detailTicket.status === 'in_progress' && <><button onClick={() => { const ticket = detailTicket; closeDetailsModal(); handleStatusTransition(ticket, 'for_verification'); }} className="px-4 py-2.5 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-xs font-bold"><Check size={14} className="inline mr-1" /> Submit for Verification</button><button onClick={() => { const ticket = detailTicket; closeDetailsModal(); openEditModal(ticket); }} className="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold">Edit Ticket</button><button onClick={() => { const ticket = detailTicket; closeDetailsModal(); handleStatusTransition(ticket, 'open'); }} className="px-4 py-2.5 bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-300 rounded-xl text-xs font-bold">Put Back</button></>}
                                    {detailTicket.status === 'for_verification' && <><button onClick={() => { const ticket = detailTicket; closeDetailsModal(); handleStatusTransition(ticket, 'in_progress'); }} className="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold"><RefreshCw size={14} className="inline mr-1" /> Return to Repairing</button>{canVerifyTicket ? <button onClick={() => { const ticket = detailTicket; closeDetailsModal(); handleStatusTransition(ticket, 'closed'); }} className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold"><Check size={14} className="inline mr-1" /> Verify & Close</button> : <span className="px-4 py-2.5 bg-amber-950/40 border border-amber-800 text-amber-300 rounded-xl text-xs font-bold">Awaiting Admin Verification</span>}</>}
                                    {detailTicket.status === 'closed' && canVerifyTicket && <button onClick={() => { const ticket = detailTicket; closeDetailsModal(); handleStatusTransition(ticket, 'in_progress'); }} className="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold"><RefreshCw size={14} className="inline mr-1" /> Reopen</button>}
                                </div>
                            </section>
                        </div>
                    </>
                )}
            </Modal>
            {/* ACTION MODAL */}
            <ActionModal
                isOpen={!!actionModalTicket}
                onClose={() => setActionModalTicket(null)}
                title={`Ticket #${actionModalTicket?.id} — Room ${actionModalTicket?.room?.room_number}`}
            >
                {actionModalTicket && (
                    <>
                        <button
                            onClick={() => { const t = actionModalTicket; setActionModalTicket(null); openDetailsModal(t); }}
                            className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 rounded-xl text-xs font-bold text-brand-400 transition-colors uppercase"
                        >
                            <HelpCircle size={16} /> View Details
                        </button>

                        <button
                            onClick={() => { const t = actionModalTicket; setActionModalTicket(null); handleStatusTransition(t, t.status === 'open' ? 'in_progress' : t.status === 'in_progress' ? 'for_verification' : t.status === 'for_verification' ? 'closed' : 'in_progress'); }}
                            className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 rounded-xl text-xs font-bold text-amber-400 transition-colors uppercase"
                        >
                            <Wrench size={16} /> Update Status
                        </button>

                        <button
                            onClick={(e) => { const t = actionModalTicket; cyclePriority(t, e); setActionModalTicket(null); }}
                            className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-indigo-600/20 border border-[#334155] hover:border-indigo-500/40 rounded-xl text-xs font-bold text-indigo-400 transition-colors uppercase"
                        >
                            <AlertOctagon size={16} /> Cycle Priority ({actionModalTicket.priority})
                        </button>
                    </>
                )}
            </ActionModal>
        </AuthenticatedLayout>
    );
}
