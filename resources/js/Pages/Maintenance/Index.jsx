import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import {
    Wrench, Plus, X, Check, ArrowRight, Play, RefreshCw, AlertOctagon,
    Clock, CheckCircle, HelpCircle, MessageSquare
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function Maintenance({ tickets, rooms }) {
    const { auth } = usePage().props;
    const currentUser = auth.user;

    const [isOpen, setIsOpen] = useState(false);
    const [isNotesOpen, setIsNotesOpen] = useState(false);
    const [selectedTicket, setSelectedTicket] = useState(null);
    const [nextStatus, setNextStatus] = useState('');

    const form = useForm({
        room_id: '',
        title: '',
        description: '',
        priority: 'medium'
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

    // Filter tickets by column
    const openTickets = tickets.filter(t => t.status === 'open');
    const inProgressTickets = tickets.filter(t => t.status === 'in_progress');
    const closedTickets = tickets.filter(t => t.status === 'closed');

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

    return (
        <AuthenticatedLayout>
            <Head title="Room Maintenance Ticketing Board" />

            <div className="flex flex-col gap-8">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Maintenance Tickets
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Log property facility issues, assign repair priorities, and monitor housekeeping resolution status.</p>
                    </div>

                    <button
                        onClick={handleOpenAdd}
                        className="inline-flex items-center gap-2 px-5 py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all self-start"
                    >
                        <Plus size={16} /> File New Ticket
                    </button>
                </div>

                {/* Kanban Board Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

                    {/* COLUMN: OPEN */}
                    <div className="flex flex-col gap-4 bg-[#1e293b]/55 border border-[#334155]/60 rounded-2xl p-4 min-h-[500px]">
                        <div className="flex items-center justify-between border-b border-[#334155] pb-2">
                            <span className="font-outfit font-bold text-slate-200 text-sm flex items-center gap-2">
                                <Clock size={16} className="text-rose-400" /> Filed / Open
                            </span>
                            <span className="text-xs bg-[#334155] text-slate-300 font-mono font-bold px-2 py-0.5 rounded">
                                {openTickets.length}
                            </span>
                        </div>

                        <div className="flex flex-col gap-3">
                            {openTickets.length > 0 ? (
                                openTickets.map(ticket => (
                                    <motion.div
                                        layoutId={`ticket-${ticket.id}`}
                                        key={ticket.id}
                                        className="p-4 rounded-xl bg-[#1e293b] border border-[#334155] hover:border-[#475569] shadow-md flex flex-col gap-3 transition-colors"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-xs font-bold text-slate-200 font-outfit">
                                                Room {ticket.room?.room_number}
                                            </span>
                                            <span 
                                                onClick={(e) => cyclePriority(ticket, e)}
                                                title="Click to cycle priority"
                                                className={`text-[9px] uppercase font-bold px-2 py-0.5 rounded border cursor-pointer hover:scale-105 active:scale-95 transition-all select-none ${getPriorityStyle(ticket.priority)}`}
                                            >
                                                {ticket.priority}
                                            </span>
                                        </div>

                                        <div>
                                            <h4 className="font-outfit font-bold text-sm text-slate-100">{ticket.title}</h4>
                                            <p className="text-xs text-slate-400 mt-1 leading-relaxed whitespace-pre-wrap">{ticket.description}</p>
                                        </div>

                                        <div className="border-t border-[#334155]/60 pt-2 mt-1 flex flex-col gap-1.5 text-[10px] text-slate-400">
                                            <span>Reported by: <strong>{ticket.reported_by?.name || ticket.reported_by}</strong></span>
                                            <span>Date: {new Date(ticket.created_at).toLocaleDateString(undefined, { dateStyle: 'medium' })}</span>
                                        </div>

                                        {/* Status transitions */}
                                        <div className="flex justify-end gap-2 pt-2 border-t border-[#334155]/60">
                                            <button
                                                onClick={() => handleStatusTransition(ticket, 'in_progress')}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-950/40 border border-indigo-900/30 hover:bg-indigo-900/40 text-indigo-400 rounded-lg text-[10px] font-bold uppercase transition-all"
                                            >
                                                <Play size={10} /> Start Work <ArrowRight size={10} />
                                            </button>
                                        </div>
                                    </motion.div>
                                ))
                            ) : (
                                <div className="py-12 border border-dashed border-[#334155] rounded-xl text-center text-xs text-slate-500">
                                    No pending open tickets.
                                </div>
                            )}
                        </div>
                    </div>

                    {/* COLUMN: IN PROGRESS */}
                    <div className="flex flex-col gap-4 bg-[#1e293b]/55 border border-[#334155]/60 rounded-2xl p-4 min-h-[500px]">
                        <div className="flex items-center justify-between border-b border-[#334155] pb-2">
                            <span className="font-outfit font-bold text-slate-200 text-sm flex items-center gap-2">
                                <Wrench size={16} className="text-indigo-400 animate-pulse" /> Repairing / In Progress
                            </span>
                            <span className="text-xs bg-[#334155] text-slate-300 font-mono font-bold px-2 py-0.5 rounded">
                                {inProgressTickets.length}
                            </span>
                        </div>

                        <div className="flex flex-col gap-3">
                            {inProgressTickets.length > 0 ? (
                                inProgressTickets.map(ticket => (
                                    <motion.div
                                        layoutId={`ticket-${ticket.id}`}
                                        key={ticket.id}
                                        className="p-4 rounded-xl bg-[#1e293b] border border-[#334155] hover:border-[#475569] shadow-md flex flex-col gap-3 transition-colors"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-xs font-bold text-slate-200 font-outfit">
                                                Room {ticket.room?.room_number}
                                            </span>
                                            <span 
                                                onClick={(e) => cyclePriority(ticket, e)}
                                                title="Click to cycle priority"
                                                className={`text-[9px] uppercase font-bold px-2 py-0.5 rounded border cursor-pointer hover:scale-105 active:scale-95 transition-all select-none ${getPriorityStyle(ticket.priority)}`}
                                            >
                                                {ticket.priority}
                                            </span>
                                        </div>

                                        <div>
                                            <h4 className="font-outfit font-bold text-sm text-slate-100">{ticket.title}</h4>
                                            <p className="text-xs text-slate-400 mt-1 leading-relaxed whitespace-pre-wrap">{ticket.description}</p>
                                        </div>

                                        <div className="border-t border-[#334155]/60 pt-2 mt-1 flex flex-col gap-1.5 text-[10px] text-slate-400">
                                            <span>Reported by: <strong>{ticket.reported_by?.name || ticket.reported_by}</strong></span>
                                            <span>Date: {new Date(ticket.created_at).toLocaleDateString(undefined, { dateStyle: 'medium' })}</span>
                                        </div>

                                        {/* Status transitions */}
                                        <div className="flex justify-between gap-2 pt-2 border-t border-[#334155]/60">
                                            <button
                                                onClick={() => handleStatusTransition(ticket, 'open')}
                                                className="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-900 border border-slate-700 text-slate-400 hover:text-slate-300 rounded-lg text-[10px] font-bold uppercase transition-all"
                                            >
                                                Put Back
                                            </button>

                                            {canCloseTicket ? (
                                                <button
                                                    onClick={() => handleStatusTransition(ticket, 'closed')}
                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-950/40 border border-emerald-900/30 hover:bg-emerald-900/40 text-emerald-400 rounded-lg text-[10px] font-bold uppercase transition-all shadow-sm"
                                                >
                                                    <Check size={10} /> Resolve Issue
                                                </button>
                                            ) : (
                                                <span className="text-[9px] text-slate-500 italic mt-1.5">Closing restricted</span>
                                            )}
                                        </div>
                                    </motion.div>
                                ))
                            ) : (
                                <div className="py-12 border border-dashed border-[#334155] rounded-xl text-center text-xs text-slate-500">
                                    No active maintenance tasks in progress.
                                </div>
                            )}
                        </div>
                    </div>

                    {/* COLUMN: RESOLVED / CLOSED */}
                    <div className="flex flex-col gap-4 bg-[#1e293b]/55 border border-[#334155]/60 rounded-2xl p-4 min-h-[500px]">
                        <div className="flex items-center justify-between border-b border-[#334155] pb-2">
                            <span className="font-outfit font-bold text-slate-200 text-sm flex items-center gap-2">
                                <CheckCircle size={16} className="text-emerald-400" /> Resolved / Closed
                            </span>
                            <span className="text-xs bg-[#334155] text-slate-300 font-mono font-bold px-2 py-0.5 rounded">
                                {closedTickets.length}
                            </span>
                        </div>

                        <div className="flex flex-col gap-3">
                            {closedTickets.length > 0 ? (
                                closedTickets.map(ticket => (
                                    <motion.div
                                        layoutId={`ticket-${ticket.id}`}
                                        key={ticket.id}
                                        className="p-4 rounded-xl bg-[#1e293b]/40 border border-[#334155]/40 opacity-75 shadow-sm flex flex-col gap-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-xs font-bold text-slate-300 font-outfit">
                                                Room {ticket.room?.room_number}
                                            </span>
                                            <span className="text-[9px] uppercase font-bold px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-slate-500">
                                                resolved
                                            </span>
                                        </div>

                                        <div>
                                            <h4 className="font-outfit font-bold text-sm text-slate-300 line-through">{ticket.title}</h4>
                                            <p className="text-xs text-slate-400 mt-1 leading-relaxed whitespace-pre-wrap">{ticket.description}</p>
                                        </div>

                                        {ticket.notes && (
                                            <div className="p-2.5 rounded-lg bg-[#0f172a]/65 border border-[#334155]/50 flex gap-2 items-start mt-1">
                                                <MessageSquare size={12} className="text-slate-400 mt-0.5 shrink-0" />
                                                <div className="text-[10px] text-slate-300 leading-normal font-medium">
                                                    <strong>Resolution notes:</strong> {ticket.notes}
                                                </div>
                                            </div>
                                        )}

                                        <div className="border-t border-[#334155]/30 pt-2 mt-1 flex flex-col gap-1 text-[10px] text-slate-500 font-medium">
                                            <span>Closed by: <strong>{ticket.resolved_by?.name || 'Staff'}</strong></span>
                                            <span>Date: {ticket.resolved_at ? new Date(ticket.resolved_at).toLocaleDateString(undefined, { dateStyle: 'medium' }) : ''}</span>
                                        </div>

                                        {/* Status transitions */}
                                        {canCloseTicket && (
                                            <div className="flex justify-end gap-2 pt-2 border-t border-[#334155]/30">
                                                <button
                                                    onClick={() => handleStatusTransition(ticket, 'in_progress')}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-slate-200 rounded-lg text-[10px] font-bold uppercase transition-all"
                                                >
                                                    <RefreshCw size={10} /> Reopen Ticket
                                                </button>
                                            </div>
                                        )}
                                    </motion.div>
                                ))
                            ) : (
                                <div className="py-12 border border-dashed border-[#334155] rounded-xl text-center text-xs text-slate-500">
                                    No completed tickets.
                                </div>
                            )}
                        </div>
                    </div>

                </div>

                {/* MODAL: FILE NEW MAINTENANCE TICKET */}
                <AnimatePresence>
                    {isOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsOpen(false)} />
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

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={form.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Submit Ticket</button>
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
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsNotesOpen(false)} />
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
        </AuthenticatedLayout>
    );
}
