import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Search,
    Calendar,
    Coins,
    User,
    ChevronRight,
    UserCheck,
    XCircle,
    Plus,
    AlertTriangle,
    Crown
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function Index({ reservations, currentFilter }) {
    const [searchTerm, setSearchTerm] = useState('');
    
    // Modals states
    const [showCheckInModal, setShowCheckInModal] = useState(false);
    const [selectedCheckInRes, setSelectedCheckInRes] = useState(null);
    
    const [showCancelModal, setShowCancelModal] = useState(false);
    const [selectedCancelRes, setSelectedCancelRes] = useState(null);
    const [cancelReason, setCancelReason] = useState('');

    const filters = [
        { name: 'Pending Bookings', value: 'reserved', color: 'text-indigo-400 border-indigo-500 bg-indigo-500/10' },
        { name: 'Checked In', value: 'active', color: 'text-emerald-400 border-emerald-500 bg-emerald-500/10' },
        { name: 'Completed Stays', value: 'checked_out', color: 'text-slate-400 border-slate-700 bg-slate-800/40' },
        { name: 'Cancelled Stays', value: 'cancelled', color: 'text-rose-400 border-rose-500 bg-rose-500/10' }
    ];

    // Client-side quick search filter
    const filteredReservations = reservations.filter(res => {
        const query = searchTerm.toLowerCase();
        return (
            res.booking_ref.toLowerCase().includes(query) ||
            res.guest_name.toLowerCase().includes(query) ||
            (res.guest_contact && res.guest_contact.toLowerCase().includes(query)) ||
            (res.room && res.room.room_number.toLowerCase().includes(query))
        );
    });

    const triggerCheckIn = (res) => {
        setSelectedCheckInRes(res);
        setShowCheckInModal(true);
    };

    const confirmCheckIn = () => {
        if (!selectedCheckInRes) return;
        router.post(route('reservations.checkin', selectedCheckInRes.id), {}, {
            onSuccess: () => {
                setShowCheckInModal(false);
                setSelectedCheckInRes(null);
            }
        });
    };

    const triggerCancel = (res) => {
        setSelectedCancelRes(res);
        setCancelReason('');
        setShowCancelModal(true);
    };

    const confirmCancel = () => {
        if (!selectedCancelRes || !cancelReason.trim()) return;
        router.post(route('reservations.cancel', selectedCancelRes.id), {
            reason: cancelReason
        }, {
            onSuccess: () => {
                setShowCancelModal(false);
                setSelectedCancelRes(null);
                setCancelReason('');
            }
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Bookings & Reservations" />

            <div className="flex flex-col gap-8">

                {/* Title */}
                <div className="flex flex-col sm:flex-row justify-between sm:items-center gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Bookings & Reservations
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Register future stays, process scheduled guest arrivals, and oversee deposit accounts.</p>
                    </div>

                    <Link
                        href={route('reservations.create')}
                        className="inline-flex items-center gap-2 px-5 py-3 bg-brand-600 hover:bg-brand-500 border border-brand-500/30 text-white rounded-xl text-xs font-black uppercase tracking-wider transition-all shadow-lg shadow-brand-950/20 self-start"
                    >
                        <Plus size={16} /> New Booking
                    </Link>
                </div>

                {/* Status Filter buttons & Search */}
                <div className="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                    <div className="flex flex-wrap gap-2.5">
                        {filters.map(f => (
                            <Link
                                key={f.value}
                                href={route('reservations.index', { status: f.value })}
                                className={`px-4 py-2.5 rounded-xl border text-xs font-bold font-outfit tracking-wide transition-all ${currentFilter === f.value
                                    ? f.color.split(' ').slice(0, 3).join(' ') + ' shadow-lg shadow-brand-950/20'
                                    : 'bg-[#1e293b] border-[#334155] text-slate-400 hover:bg-[#334155]/40'
                                    }`}
                            >
                                {f.name}
                            </Link>
                        ))}
                    </div>

                    {/* Search bar */}
                    <div className="relative w-full md:w-80">
                        <Search className="absolute left-3.5 top-3 text-slate-500" size={16} />
                        <input
                            type="text"
                            value={searchTerm}
                            onChange={e => setSearchTerm(e.target.value)}
                            placeholder="Search reference, guest, room..."
                            className="w-full bg-[#1e293b] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-semibold"
                        />
                    </div>
                </div>

                {/* Stays list table */}
                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr className="border-b border-[#334155] text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                    <th className="pb-3">Ref ID / Room</th>
                                    <th className="pb-3">Guest Profile</th>
                                    <th className="pb-3">Stay Schedule</th>
                                    <th className="pb-3">Type</th>
                                    <th className="pb-3">Payments</th>
                                    <th className="pb-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#334155]/60 text-slate-300">
                                {filteredReservations.length > 0 ? (
                                    filteredReservations.map((b) => (
                                        <tr key={b.id} className="hover:bg-[#0f172a]/20 transition-colors">

                                            {/* Room ID */}
                                            <td className="py-4">
                                                <span className="font-mono text-xs text-slate-400 font-bold block">
                                                    {b.booking_ref}
                                                </span>
                                                <span className="font-outfit font-black text-sm text-slate-200 block mt-1">
                                                    Room {b.room?.room_number || 'N/A'}
                                                </span>
                                            </td>

                                            {/* Guest Name */}
                                            <td className="py-4">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="font-bold text-slate-200 block">{b.guest_name}</span>
                                                    {b.guest_profile?.is_vip && (
                                                        <span className="inline-flex items-center text-[8px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1 rounded font-bold uppercase shrink-0">
                                                            VIP
                                                        </span>
                                                    )}
                                                </div>
                                                <span className="text-xs text-slate-400 block mt-0.5">{b.guest_contact || 'No contact'}</span>
                                            </td>

                                            {/* Stay Schedule dates */}
                                            <td className="py-4 text-xs leading-normal">
                                                <div className="flex items-center gap-1.5 text-slate-300 font-medium">
                                                    <span className="text-indigo-400 font-bold">IN:</span>
                                                    <span className="font-mono">{new Date(b.check_in).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</span>
                                                </div>
                                                <div className="flex items-center gap-1.5 text-slate-400 mt-1">
                                                    <span className="text-slate-500 font-bold">OUT:</span>
                                                    <span className="font-mono">
                                                        {new Date(b.expected_check_out).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}
                                                    </span>
                                                </div>
                                            </td>

                                            {/* Stay Type */}
                                            <td className="py-4">
                                                <span className="text-xs bg-[#0f172a] border border-[#334155] text-slate-300 px-2 py-0.5 rounded capitalize font-mono font-bold">
                                                    {b.booking_type === 'overnight' ? 'Overnight' : `${b.short_time_hours}h hourly`}
                                                </span>
                                            </td>

                                            {/* Paid amount */}
                                            <td className="py-4">
                                                <span className="font-mono text-emerald-400 font-bold block">
                                                    ₱{b.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                                <span className="text-[10px] text-slate-400 block capitalize mt-0.5 font-bold">
                                                    Deposit: {b.payment_method}
                                                </span>
                                            </td>

                                            {/* View details / checkin / cancel */}
                                            <td className="py-4 text-right">
                                                <div className="inline-flex items-center gap-2">
                                                    {b.status === 'reserved' && (
                                                        <>
                                                            <button
                                                                onClick={() => triggerCheckIn(b)}
                                                                className="inline-flex items-center gap-1 px-3 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-xs font-bold text-white transition-colors"
                                                            >
                                                                <UserCheck size={13} /> Check In
                                                            </button>
                                                            <button
                                                                onClick={() => triggerCancel(b)}
                                                                className="inline-flex items-center gap-1 px-3 py-2 bg-[#0f172a]/60 hover:bg-rose-900/30 border border-[#334155] rounded-xl text-xs font-bold text-rose-400 hover:text-rose-300 transition-colors"
                                                            >
                                                                <XCircle size={13} /> Cancel
                                                            </button>
                                                        </>
                                                    )}
                                                    <Link
                                                        href={route('bookings.show', b.id)}
                                                        className="inline-flex items-center gap-1 px-3 py-2 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] rounded-xl text-xs font-bold text-brand-400 hover:text-brand-300 transition-colors"
                                                    >
                                                        View Details <ChevronRight size={13} />
                                                    </Link>
                                                </div>
                                            </td>

                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="py-8 text-center text-slate-500">
                                            No reservations found matching filter.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            {/* Check-In Confirmation Modal (SOLID, NON-BLURRY BACKDROP) */}
            <AnimatePresence>
                {showCheckInModal && selectedCheckInRes && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                        {/* Solid dark backdrop */}
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            onClick={() => setShowCheckInModal(false)}
                            className="fixed inset-0 bg-[#070b13]/85"
                        />

                        {/* Modal container */}
                        <motion.div
                            initial={{ scale: 0.95, opacity: 0, y: 15 }}
                            animate={{ scale: 1, opacity: 1, y: 0 }}
                            exit={{ scale: 0.95, opacity: 0, y: 15 }}
                            transition={{ type: 'spring', damping: 25, stiffness: 350 }}
                            className="relative w-full max-w-md bg-[#1e293b] border border-[#334155] rounded-3xl p-6 shadow-2xl z-10"
                        >
                            <div className="flex flex-col items-center text-center gap-4">
                                <div className="p-3.5 bg-emerald-500/10 text-emerald-400 rounded-2xl">
                                    <UserCheck size={28} />
                                </div>

                                <div className="space-y-1">
                                    <h3 className="font-outfit font-black text-slate-100 text-lg uppercase tracking-wide">
                                        Activate Stay Check-In
                                    </h3>
                                    <p className="text-xs text-slate-400 font-medium">
                                        Confirm check-in for guest <strong className="text-slate-200">{selectedCheckInRes.guest_name}</strong> into Room <strong className="text-slate-200">{selectedCheckInRes.room?.room_number}</strong>.
                                    </p>
                                </div>

                                <div className="w-full bg-[#0f172a]/60 border border-[#334155]/60 rounded-2xl p-4 text-left text-xs space-y-2 mt-2">
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium">Reference ID:</span>
                                        <span className="font-mono text-slate-200 font-bold">{selectedCheckInRes.booking_ref}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium">Schedule:</span>
                                        <span className="font-mono text-slate-200">
                                            {new Date(selectedCheckInRes.check_in).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium">Stay Duration:</span>
                                        <span className="text-slate-200 font-bold capitalize">
                                            {selectedCheckInRes.booking_type === 'overnight' ? 'Overnight stay' : 'Hourly stay'}
                                        </span>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3 w-full mt-4">
                                    <button
                                        type="button"
                                        onClick={() => setShowCheckInModal(false)}
                                        className="px-4 py-3 bg-[#0f172a] hover:bg-[#1e293b] border border-[#334155] rounded-xl text-xs font-black text-slate-400 hover:text-slate-200 uppercase transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        onClick={confirmCheckIn}
                                        className="px-4 py-3 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-xs font-black text-white uppercase transition-colors"
                                    >
                                        Confirm Check-In
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            {/* Cancel Reservation Modal (SOLID, NON-BLURRY BACKDROP) */}
            <AnimatePresence>
                {showCancelModal && selectedCancelRes && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                        {/* Solid dark backdrop */}
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            onClick={() => setShowCancelModal(false)}
                            className="fixed inset-0 bg-[#070b13]/85"
                        />

                        {/* Modal container */}
                        <motion.div
                            initial={{ scale: 0.95, opacity: 0, y: 15 }}
                            animate={{ scale: 1, opacity: 1, y: 0 }}
                            exit={{ scale: 0.95, opacity: 0, y: 15 }}
                            transition={{ type: 'spring', damping: 25, stiffness: 350 }}
                            className="relative w-full max-w-md bg-[#1e293b] border border-[#334155] rounded-3xl p-6 shadow-2xl z-10"
                        >
                            <div className="flex flex-col items-center text-center gap-4">
                                <div className="p-3.5 bg-rose-500/10 text-rose-400 rounded-2xl">
                                    <AlertTriangle size={28} />
                                </div>

                                <div className="space-y-1">
                                    <h3 className="font-outfit font-black text-slate-100 text-lg uppercase tracking-wide">
                                        Cancel Reservation
                                    </h3>
                                    <p className="text-xs text-slate-400 font-medium">
                                        Are you sure you want to cancel the reservation for guest <strong className="text-slate-200">{selectedCancelRes.guest_name}</strong> in Room <strong className="text-slate-200">{selectedCancelRes.room?.room_number}</strong>?
                                    </p>
                                </div>

                                <div className="w-full text-left mt-2 flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Reason for Cancellation</label>
                                    <textarea
                                        value={cancelReason}
                                        onChange={e => setCancelReason(e.target.value)}
                                        placeholder="Type cancellation reason here..."
                                        rows="3"
                                        required
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-3 focus:outline-none focus:border-brand-500 text-xs font-medium"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3 w-full mt-4">
                                    <button
                                        type="button"
                                        onClick={() => setShowCancelModal(false)}
                                        className="px-4 py-3 bg-[#0f172a] hover:bg-[#1e293b] border border-[#334155] rounded-xl text-xs font-black text-slate-400 hover:text-slate-200 uppercase transition-colors"
                                    >
                                        Back
                                    </button>
                                    <button
                                        type="button"
                                        onClick={confirmCancel}
                                        disabled={!cancelReason.trim()}
                                        className="px-4 py-3 bg-rose-600 hover:bg-rose-500 disabled:bg-rose-950/40 disabled:text-rose-400/50 rounded-xl text-xs font-black text-white uppercase transition-colors"
                                    >
                                        Cancel Booking
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

        </AuthenticatedLayout>
    );
}
