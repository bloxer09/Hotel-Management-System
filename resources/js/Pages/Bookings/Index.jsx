import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Search,
    Calendar,
    Coins,
    User,
    ChevronRight,
    ArrowLeftRight,
    ClipboardCheck
} from 'lucide-react';
import { motion } from 'framer-motion';

export default function Index({ bookings, currentFilter }) {
    const [searchTerm, setSearchTerm] = useState('');

    const filters = [
        { name: 'Active stays', value: 'active', color: 'text-emerald-400 border-emerald-500 bg-emerald-500/10' },
        { name: 'Checked out stays', value: 'checked_out', color: 'text-slate-400 border-slate-700 bg-slate-800/40' },
        { name: 'Cancelled records', value: 'cancelled', color: 'text-rose-400 border-rose-500 bg-rose-500/10' }
    ];

    const filteredBookings = bookings.filter(b => {
        const q = searchTerm.toLowerCase();
        return (
            b.guest_name.toLowerCase().includes(q) ||
            b.booking_ref.toLowerCase().includes(q) ||
            (b.room?.room_number && b.room.room_number.toLowerCase().includes(q)) ||
            (b.guest_contact && b.guest_contact.toLowerCase().includes(q))
        );
    });

    return (
        <AuthenticatedLayout>
            <Head title="Stay History" />

            <div className="flex flex-col gap-8">

                {/* Title */}
                <div>
                    <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                        Stay History
                    </h1>
                    <p className="text-sm text-slate-400 font-medium mt-1">Review active stays, past checkouts, and stay cancellation histories.</p>
                </div>

                {/* Status Filter buttons + Search */}
                <div className="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                    <div className="flex flex-wrap gap-2.5">
                        {filters.map(f => (
                            <Link
                                key={f.value}
                                href={route('bookings.index', { status: f.value })}
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
                            placeholder="Search guest, ref, room..."
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
                                {filteredBookings.length > 0 ? (
                                    filteredBookings.map((b) => (
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
                                                <span className="font-bold text-slate-200 block">{b.guest_name}</span>
                                                <span className="text-xs text-slate-400 block mt-0.5">{b.guest_contact || 'No contact'}</span>
                                            </td>

                                            {/* Stay Schedule dates */}
                                            <td className="py-4 text-xs leading-normal">
                                                <div className="flex items-center gap-1.5 text-slate-300 font-medium">
                                                    <span className="text-brand-400 font-bold">IN:</span>
                                                    <span className="font-mono">{new Date(b.check_in).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</span>
                                                </div>
                                                <div className="flex items-center gap-1.5 text-slate-400 mt-1">
                                                    <span className="text-slate-500 font-bold">OUT:</span>
                                                    <span className="font-mono">
                                                        {b.status === 'active'
                                                            ? new Date(b.expected_check_out).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
                                                            : b.check_out
                                                                ? new Date(b.check_out).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
                                                                : 'Cancelled'}
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
                                                    Paid: {b.payment_method}
                                                </span>
                                            </td>

                                            {/* View details */}
                                            <td className="py-4 text-right">
                                                <Link
                                                    href={route('bookings.show', b.id)}
                                                    className="inline-flex items-center gap-1 px-4 py-2 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] rounded-xl text-xs font-bold text-brand-400 hover:text-brand-300 transition-colors"
                                                >
                                                    View Details <ChevronRight size={14} />
                                                </Link>
                                            </td>

                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="py-10 text-center text-slate-500">
                                            {searchTerm ? `No stay records match "${searchTerm}".` : 'No stay records found for this filter.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
