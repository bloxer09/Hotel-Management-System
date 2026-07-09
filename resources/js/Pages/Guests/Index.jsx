import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage, router } from '@inertiajs/react';
import {
    Search, Star, Calendar, ChevronRight, UserCheck, RefreshCw, Users, Crown, CheckCircle,
    Phone, Mail, CreditCard, Bed, Clock, AlertCircle, FileText, Printer, X, User
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import axios from 'axios';
import StayDetailsModal from '@/Components/StayDetailsModal';
import ImagePreviewModal from '@/Components/ImagePreviewModal';
import ActionModal from '@/Components/ActionModal';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';

const FILTER_TABS = [
    { key: 'all', label: 'All Guests', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: '1', label: 'VIP', color: 'text-amber-400', dot: 'bg-amber-400' },
    { key: '0', label: 'Regular', color: 'text-slate-400', dot: 'bg-slate-400' },
];

export default function Index({ guests, currentSearch, currentVip, stats, sortBy, sortDir }) {
    const { auth } = usePage().props;
    const flash = usePage().props.flash || {};
    const user = auth.user;
    const isAdmin = user.role === 'admin';

    const [searchTerm, setSearchTerm] = useState(currentSearch || '');
    const [activeFilter, setActiveFilter] = useState(currentVip !== null && currentVip !== '' ? currentVip.toString() : 'all');

    const [selectedGuest, setSelectedGuest] = useState(null);
    const [viewStayId, setViewStayId] = useState(null);
    const [isImageModalOpen, setIsImageModalOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState(null);
    const [actionModalGuest, setActionModalGuest] = useState(null);
    const [selectedGuestBookings, setSelectedGuestBookings] = useState([]);
    const [loadingBookings, setLoadingBookings] = useState(false);

    const vipForm = useForm({
        is_vip: false,
        vip_notes: ''
    });

    const openGuestModal = async (guest) => {
        setSelectedGuest(guest);
        vipForm.setData({
            is_vip: guest.is_vip ? true : false,
            vip_notes: guest.vip_notes || ''
        });
        vipForm.clearErrors();
        setLoadingBookings(true);
        setSelectedGuestBookings([]);
        try {
            const res = await axios.get(route('guests.show', guest.id));
            setSelectedGuestBookings(res.data.bookings || []);
        } catch (err) {
            console.error("Failed to load guest stay history:", err);
        } finally {
            setLoadingBookings(false);
        }
    };

    const handleVipSubmit = (e) => {
        e.preventDefault();
        vipForm.post(route('guests.vip', selectedGuest.id), {
            preserveScroll: true,
            onSuccess: (page) => {
                // Update selectedGuest locally with new vip status from the loaded prop
                const updated = page.props.guests.find(g => g.id === selectedGuest.id);
                if (updated) {
                    setSelectedGuest(updated);
                }
            }
        });
    };

    const syncForm = useForm({});

    const handleSyncSubmit = (e) => {
        e.preventDefault();
        syncForm.post(route('guests.sync'));
    };

    // Fast frontend live filter
    const items = guests?.data || [];
    const filteredGuests = items.filter(g => {
        if (activeFilter === '1' && !g.is_vip) return false;
        if (activeFilter === '0' && g.is_vip) return false;
        if (searchTerm.trim() !== '') {
            const query = searchTerm.toLowerCase();
            const name = g.full_name?.toLowerCase() || '';
            const contact = g.contact_number || '';
            const email = g.email?.toLowerCase() || '';
            const idNum = g.id_number || '';
            return name.includes(query) || contact.includes(query) || email.includes(query) || idNum.includes(query);
        }
        return true;
    });

    const activeTab = FILTER_TABS.find(t => t.key === activeFilter) || FILTER_TABS[0];

    return (
        <AuthenticatedLayout>
            <Head title="Guest History" />

            <AnimatePresence>
                {flash.success && (
                    <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -20 }}
                        className="mb-4 p-4 rounded-xl bg-emerald-950/40 border border-emerald-500/30 text-emerald-300 text-sm font-medium flex items-center gap-2">
                        <CheckCircle size={16} /> {flash.success}
                    </motion.div>
                )}
            </AnimatePresence>

            <div className="flex flex-col gap-6">

                {/* Title + Actions */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Guest History
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Query unified guest profiles, track transaction histories, and manage privileged VIP classifications.</p>
                    </div>
                    {user.role === 'admin' && (
                        <form onSubmit={handleSyncSubmit}>
                            <button
                                type="submit"
                                disabled={syncForm.processing}
                                className="flex items-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-slate-800 border border-slate-700/60 hover:bg-slate-700 text-slate-200 text-xs font-bold font-outfit shadow-sm transition-all disabled:opacity-50 active:scale-95 shrink-0 w-full sm:w-auto justify-center"
                            >
                                <RefreshCw size={14} className={syncForm.processing ? 'animate-spin' : ''} /> Sync from Bookings
                            </button>
                        </form>
                    )}
                </div>

                {/* Tabs + Search */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155] overflow-x-auto mobile-scroll-tabs">
                        {FILTER_TABS.map(tab => {
                            const count = tab.key === 'all'
                                ? stats?.totalCount
                                : tab.key === '1' ? stats?.vipCount : stats?.regularCount;

                            return (
                                <button key={tab.key} onClick={() => setActiveFilter(tab.key)}
                                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${activeFilter === tab.key ? 'bg-[#0f172a] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'
                                        }`}>
                                    <span className={`w-1.5 h-1.5 rounded-full ${tab.dot} ${activeFilter === tab.key ? 'opacity-100' : 'opacity-40'}`} />
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex items-center gap-2 w-full sm:w-auto">
                        <div className="relative w-full sm:w-64">
                            <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                            <input type="text" value={searchTerm} onChange={e => setSearchTerm(e.target.value)}
                                placeholder="Search name, phone, email..."
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs" />
                        </div>
                        <button onClick={() => router.reload({ only: ['guests'] })} className="p-2.5 rounded-xl border border-[#334155] bg-[#1e293b] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 transition-all shrink-0 shadow-sm" title="Refresh Table">
                            <RefreshCw size={16} />
                        </button>
                    </div>
                </div>

                {/* Profiles Table */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <SortableHeader sortKey="full_name" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Guest Profile</SortableHeader>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Contact Info</th>
                                    <SortableHeader sortKey="total_stays" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Stays Count</SortableHeader>
                                    <SortableHeader sortKey="total_spent" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Total Spent</SortableHeader>
                                    <SortableHeader sortKey="last_visit" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Last Visit</SortableHeader>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredGuests.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-slate-500">
                                            {searchTerm ? `No results for "${searchTerm}"` : `No ${activeTab.label.toLowerCase()} profiles found.`}
                                        </td>
                                    </tr>
                                ) : filteredGuests.map((g, i) => (
                                    <motion.tr key={g.id} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">

                                        {/* Full name & VIP flag */}
                                        <td className="px-4 py-4">
                                            <div className="flex items-center gap-2">
                                                <span className="font-outfit font-black text-slate-200 text-sm">{g.full_name}</span>
                                                {g.is_vip && (
                                                    <span className="inline-flex items-center gap-1 text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-black uppercase">
                                                        <Crown size={9} /> VIP
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-[10px] text-slate-500 font-mono block mt-1">Govt ID: {g.id_type || 'None'} / {g.id_number || '—'}</span>
                                            {g.is_vip && g.vip_notes && (
                                                <span className="text-[10px] text-amber-600/70 italic block mt-0.5 truncate max-w-[220px]" title={g.vip_notes}>{g.vip_notes}</span>
                                            )}
                                        </td>

                                        {/* Contact */}
                                        <td className="px-4 py-4 leading-normal">
                                            <div className="font-mono text-slate-300">{g.contact_number || '—'}</div>
                                            <div className="text-slate-500 text-[10px] mt-0.5">{g.email || '—'}</div>
                                        </td>

                                        {/* Stays count */}
                                        <td className="px-4 py-4">
                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 bg-[#0f172a] border border-[#334155] text-slate-300 text-[10px] rounded-full font-bold uppercase">
                                                {g.total_stays} stay(s)
                                            </span>
                                        </td>

                                        {/* Spent */}
                                        <td className="px-4 py-4 font-mono font-bold text-brand-400">
                                            ₱{Number(g.total_spent).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </td>

                                        {/* Last Visit */}
                                        <td className="px-4 py-4 font-mono text-slate-400">
                                            {g.last_visit ? new Date(g.last_visit).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—'}
                                        </td>

                                        <td className="px-4 py-4 text-right">
                                            <button onClick={() => setActionModalGuest(g)} className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] hover:bg-slate-800 border border-[#334155] rounded-lg text-[10px] font-bold text-slate-300 transition-colors">
                                                Manage
                                            </button>
                                        </td>

                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {/* Pagination */}
                    {guests && guests.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                            <span className="text-[10px] text-slate-500">
                                Showing {guests.from}–{guests.to} of {guests.total} records
                            </span>
                            <Pagination links={guests.links} />
                        </div>
                    )}
                </div>

            </div>

            {/* ── GUEST DETAILS MODAL ── */}
            <AnimatePresence>
                {selectedGuest && (
                    <>
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            onClick={() => setSelectedGuest(null)}
                            className="fixed inset-0 bg-[#070b13]/90 z-[999]"
                        />
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 16 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 16 }}
                            transition={{ type: 'spring', damping: 26, stiffness: 320 }}
                            className="fixed inset-0 z-[1000] flex items-center justify-center p-4"
                        >
                            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden">

                                {/* Header */}
                                <div className="flex items-center justify-between border-b border-[#334155] px-6 py-4 shrink-0 bg-[#0f172a]/40">
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-3">
                                            <h2 className="text-2xl font-outfit font-black text-slate-100">{selectedGuest.full_name}</h2>
                                            {selectedGuest.is_vip && (
                                                <span className="inline-flex items-center gap-1 text-[10px] bg-amber-950 border border-amber-600/40 text-amber-400 px-2 py-0.5 rounded-full font-bold shadow">
                                                    <Star size={10} className="fill-amber-400 text-amber-400" /> VIP Status
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-[10px] text-slate-400 font-mono">Profile Reference: GUST-{selectedGuest.id.toString().padStart(5, '0')}</p>
                                    </div>
                                    <button
                                        onClick={() => setSelectedGuest(null)}
                                        className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors"
                                    >
                                        <X size={18} />
                                    </button>
                                </div>

                                {/* Body */}
                                <div className="flex-1 overflow-y-auto p-6 space-y-6">
                                    {/* Quick Stats Grid */}
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        {/* Stay Stats */}
                                        <div className="p-4 rounded-xl bg-[#0f172a]/40 border border-[#334155]/60 flex flex-col justify-between relative overflow-hidden group">
                                            <div className="flex items-center justify-between">
                                                <span className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Record</span>
                                                <Calendar size={14} className="text-brand-400" />
                                            </div>
                                            <div className="mt-2 flex items-baseline gap-1.5">
                                                <span className="text-3xl font-outfit font-black text-slate-50">{selectedGuest.total_stays}</span>
                                                <span className="text-[10px] font-semibold text-slate-400">Total Booking Stays</span>
                                            </div>
                                            <div className="mt-1 text-[10px] text-slate-400 truncate">
                                                Last visit on <span className="font-mono text-slate-300">{selectedGuest.last_visit ? new Date(selectedGuest.last_visit).toLocaleDateString(undefined, { dateStyle: 'medium' }) : 'Never'}</span>
                                            </div>
                                        </div>

                                        {/* Spent */}
                                        <div className="p-4 rounded-xl bg-[#0f172a]/40 border border-[#334155]/60 flex flex-col justify-between relative overflow-hidden group">
                                            <div className="flex items-center justify-between">
                                                <span className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Revenue Contribution</span>
                                                <CreditCard size={14} className="text-emerald-400" />
                                            </div>
                                            <div className="mt-2 flex items-baseline gap-1">
                                                <span className="text-[10px] font-bold text-slate-450 font-mono">₱</span>
                                                <span className="text-3xl font-outfit font-black text-slate-50 font-mono">
                                                    {Number(selectedGuest.total_spent).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                            </div>
                                            <div className="mt-1 text-[10px] text-slate-400">
                                                Lifetime stay payments & extensions.
                                            </div>
                                        </div>

                                        {/* Contact & ID */}
                                        <div className="p-4 rounded-xl bg-[#0f172a]/40 border border-[#334155]/60 flex flex-col justify-between">
                                            <div className="flex items-center justify-between border-b border-[#334155]/40 pb-1.5">
                                                <span className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Contact & Credentials</span>
                                                <User size={14} className="text-indigo-400" />
                                            </div>
                                            <div className="space-y-1 mt-2 text-[10px] text-slate-300">
                                                <div className="flex items-center gap-2">
                                                    <Phone size={12} className="text-slate-500 shrink-0" />
                                                    <span className="font-mono">{selectedGuest.contact_number || 'No Contact Number'}</span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Mail size={12} className="text-slate-500 shrink-0 animate-pulse" />
                                                    <span className="truncate">{selectedGuest.email || 'No Registered Email'}</span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <FileText size={12} className="text-slate-500 shrink-0" />
                                                    <span>Govt ID: <span className="font-mono text-brand-300 font-bold">{selectedGuest.id_type || 'None'} / {selectedGuest.id_number || '-'}</span></span>
                                                </div>
                                                {selectedGuest.id_image_path && (
                                                    <div className="mt-3">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block mb-1">Attached ID Image</label>
                                                        <div className="cursor-pointer" onClick={() => { setPreviewImage(`/storage/${selectedGuest.id_image_path}`); setIsImageModalOpen(true); }}>
                                                            <img src={`/storage/${selectedGuest.id_image_path}`} alt="Guest ID Document" className="w-full h-24 object-contain bg-[#0f172a] border border-[#334155]/60 rounded-xl hover:opacity-80 transition-opacity" />
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Columns for Stay History & VIP Form */}
                                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                                        {/* Stay History Table (Span 2) */}
                                        <div className="lg:col-span-2 p-5 rounded-xl bg-[#0f172a]/20 border border-[#334155]/60 flex flex-col gap-3">
                                            <h3 className="text-sm font-outfit font-black text-slate-200 flex items-center gap-1.5">
                                                <Bed className="text-brand-400" size={16} /> Booking Stays Ledger
                                            </h3>

                                            {loadingBookings ? (
                                                <div className="py-12 flex flex-col items-center justify-center gap-2 text-slate-500">
                                                    <RefreshCw size={24} className="animate-spin text-brand-500" />
                                                    <span className="text-xs font-mono">Fetching stay registry...</span>
                                                </div>
                                            ) : (
                                                <div className="overflow-x-auto">
                                                    <table className="w-full text-xs table-fixed">
                                                        <thead>
                                                            <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Ref Code</th>
                                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Room Details</th>
                                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Type</th>
                                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Dates</th>
                                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Billing</th>
                                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {selectedGuestBookings.length > 0 ? (
                                                                selectedGuestBookings.map((b) => (
                                                                    <tr key={b.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">
                                                                        <td className="px-4 py-3 font-mono font-bold text-slate-400">
                                                                            <button onClick={() => setViewStayId(b.id)} className="text-brand-400 hover:underline">
                                                                                {b.booking_ref}
                                                                            </button>
                                                                        </td>
                                                                        <td className="px-4 py-3">
                                                                            <span className="font-outfit font-extrabold text-slate-200 block">Room {b.room ? b.room.room_number : '-'}</span>
                                                                            <span className="text-[9px] text-slate-500 block">{b.room?.room_type?.type_name || '-'}</span>
                                                                        </td>
                                                                        <td className="px-4 py-3 capitalize font-mono text-[9px]">
                                                                            {b.booking_type === 'short_time' ? (
                                                                                <span className="bg-indigo-950 text-indigo-400 border border-indigo-900/40 px-1.5 py-0.5 rounded-full font-bold">
                                                                                    ST ({b.short_time_hours}h)
                                                                                </span>
                                                                            ) : (
                                                                                <span className="bg-sky-950 text-sky-400 border border-sky-900/40 px-1.5 py-0.5 rounded-full font-bold">
                                                                                    Overnight
                                                                                </span>
                                                                            )}
                                                                        </td>
                                                                        <td className="px-4 py-3 text-[10px]">
                                                                            <div className="flex items-center gap-1 text-slate-400">
                                                                                <span>{new Date(b.check_in).toLocaleDateString(undefined, { dateStyle: 'short' })}</span>
                                                                                <span>→</span>
                                                                                <span>{b.check_out ? new Date(b.check_out).toLocaleDateString(undefined, { dateStyle: 'short' }) : new Date(b.expected_check_out).toLocaleDateString(undefined, { dateStyle: 'short' })}</span>
                                                                            </div>
                                                                        </td>
                                                                        <td className="px-4 py-3 font-mono">
                                                                            <span className="font-bold text-brand-300">₱{Number(b.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                                            <span className="text-[9px] text-slate-500 block">via {b.payment_method}</span>
                                                                        </td>
                                                                        <td className="px-4 py-3">
                                                                            {b.status === 'active' ? (
                                                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-950 border border-emerald-800 text-emerald-450 text-[9px] rounded-full font-black uppercase">
                                                                                    Active
                                                                                </span>
                                                                            ) : b.status === 'checked_out' ? (
                                                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 bg-[#0f172a] border border-[#334155] text-slate-400 text-[9px] rounded-full font-black uppercase">
                                                                                    Resolved
                                                                                </span>
                                                                            ) : (
                                                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 bg-rose-950 border border-rose-800/60 text-rose-405 text-[9px] rounded-full font-black uppercase">
                                                                                    Cancelled
                                                                                </span>
                                                                            )}
                                                                        </td>
                                                                    </tr>
                                                                ))
                                                            ) : (
                                                                <tr>
                                                                    <td colSpan="6" className="px-4 py-8 text-center text-slate-500">
                                                                        No stay records found under this profile.
                                                                    </td>
                                                                </tr>
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            )}
                                        </div>

                                        {/* Right Sidebar: VIP Controls & checkin */}
                                        <div className="space-y-6">
                                            {/* VIP Config panel */}
                                            <div className="p-5 rounded-xl bg-[#0f172a]/20 border border-[#334155]/60 flex flex-col gap-4">
                                                <div className="flex items-center justify-between border-b border-[#334155]/40 pb-2">
                                                    <h3 className="text-xs font-outfit font-black uppercase tracking-wider text-slate-350">VIP Credentials Settings</h3>
                                                    <Star size={14} className={selectedGuest.is_vip ? 'text-amber-400 fill-amber-400' : 'text-slate-500'} />
                                                </div>

                                                {user.role === 'admin' ? (
                                                    <form onSubmit={handleVipSubmit} className="space-y-4">
                                                        {/* VIP Switch Toggle */}
                                                        <div className="flex items-center justify-between p-3 rounded-xl bg-[#0f172a] border border-[#334155]/60">
                                                            <div className="flex flex-col gap-0.5">
                                                                <span className="text-[11px] font-bold text-slate-200">VIP Privilege Status</span>
                                                                <span className="text-[9px] text-slate-550">Banners appear at guest check-in</span>
                                                            </div>
                                                            <label className="relative inline-flex items-center cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={vipForm.data.is_vip}
                                                                    onChange={e => vipForm.setData('is_vip', e.target.checked)}
                                                                    className="sr-only peer"
                                                                />
                                                                <div className="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-200 after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-amber-600"></div>
                                                            </label>
                                                        </div>

                                                        {/* Notes input */}
                                                        <div className="flex flex-col gap-1">
                                                            <label className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">VIP Admin Remarks & Preferences</label>
                                                            <textarea
                                                                value={vipForm.data.vip_notes}
                                                                onChange={e => vipForm.setData('vip_notes', e.target.value)}
                                                                placeholder="Specify special requirements, billing setups, discounts, room numbers preference..."
                                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 text-xs p-3 min-h-[90px] focus:outline-none focus:border-brand-500 placeholder-slate-650 resize-none"
                                                            />
                                                            {vipForm.errors.vip_notes && <span className="text-[9px] text-rose-450 font-bold">{vipForm.errors.vip_notes}</span>}
                                                        </div>

                                                        <button
                                                            type="submit"
                                                            disabled={vipForm.processing}
                                                            className="w-full py-2 bg-brand-600 hover:bg-brand-500 disabled:opacity-50 text-slate-50 font-outfit font-extrabold text-[11px] uppercase tracking-wider rounded-xl shadow transition-all active:scale-95 cursor-pointer"
                                                        >
                                                            {vipForm.processing ? 'Saving...' : 'Save Configuration'}
                                                        </button>
                                                    </form>
                                                ) : (
                                                    <div className="space-y-3">
                                                        <div className="flex items-center gap-2.5 p-3 rounded-xl bg-slate-900/40 border border-[#334155] text-slate-400 text-[10px]">
                                                            <AlertCircle size={14} className="text-amber-500 shrink-0" />
                                                            <span>Only system administrators are authorized to toggle guest VIP credentials.</span>
                                                        </div>

                                                        {selectedGuest.vip_notes && (
                                                            <div className="p-3.5 rounded-xl bg-[#0f172a] border border-[#334155] text-[10px]">
                                                                <span className="font-bold text-slate-400 block mb-1 uppercase tracking-wider">VIP Privileges Notes</span>
                                                                <p className="text-slate-300 leading-relaxed italic">{selectedGuest.vip_notes}</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>

                                            {/* Quick Check-in launcher */}
                                            {user.role !== 'cashier' && user.role !== 'housekeeping' && (
                                                <div className="p-5 rounded-xl bg-gradient-to-br from-brand-950/40 to-[#0f172a]/20 border border-brand-900/30 flex flex-col gap-2.5 shadow">
                                                    <h3 className="font-outfit font-extrabold text-slate-100 text-xs uppercase tracking-wider">Check-In Guest</h3>
                                                    <p className="text-[10px] text-slate-400 leading-relaxed">
                                                        Launch a fresh stay instantly. The guest details, phone, and VIP privilege profiles will automatically populate inside the Check-In registry.
                                                    </p>
                                                    <Link
                                                        href={route('checkin.index', { guest_id: selectedGuest.id })}
                                                        onClick={() => {
                                                            sessionStorage.setItem('quickCheckinGuest', JSON.stringify(selectedGuest));
                                                        }}
                                                        className="w-full py-2 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-slate-50 text-[11px] font-outfit font-black text-center uppercase tracking-wider shadow active:scale-95 transition-all mt-1"
                                                    >
                                                        Initiate Check-In
                                                    </Link>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </motion.div>
                    </>
                )}
            </AnimatePresence>

            <StayDetailsModal
                isOpen={!!viewStayId}
                onClose={() => setViewStayId(null)}
                bookingId={viewStayId}
                viewMode="bookings"
            />

            <ImagePreviewModal
                isOpen={isImageModalOpen}
                imageUrl={previewImage}
                onClose={() => { setIsImageModalOpen(false); setPreviewImage(null); }}
            />

            <ActionModal
                isOpen={!!actionModalGuest}
                onClose={() => setActionModalGuest(null)}
                title={`Manage ${actionModalGuest?.full_name}`}
            >
                {actionModalGuest && (
                    <>
                        <Link
                            href={route('checkin.index', { guest_id: actionModalGuest.id })}
                            className="w-full flex items-center justify-center gap-2 px-4 py-3 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-xs font-bold text-white transition-colors"
                        >
                            <UserCheck size={16} /> Quick Check-In
                        </Link>
                        <button
                            onClick={() => {
                                const guest = actionModalGuest;
                                setActionModalGuest(null);
                                openGuestModal(guest);
                            }}
                            className="w-full flex items-center justify-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 rounded-xl text-xs font-bold text-brand-400 transition-colors"
                        >
                            Details <ChevronRight size={16} />
                        </button>
                    </>
                )}
            </ActionModal>
        </AuthenticatedLayout>
    );
}
