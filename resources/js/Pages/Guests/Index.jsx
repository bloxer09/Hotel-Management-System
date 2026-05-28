import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage, router } from '@inertiajs/react';
import {
    Search,
    Star,
    Calendar,
    TrendingUp,
    ChevronRight,
    UserCheck,
    RefreshCw,
    Users,
    Crown
} from 'lucide-react';
import { motion } from 'framer-motion';

export default function Index({ guests, currentSearch, currentVip, stats }) {
    const { auth } = usePage().props;
    const user = auth.user;

    const { data, setData, get } = useForm({
        search: currentSearch || ''
    });

    const syncForm = useForm({});

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('guests.index'), { search: data.search, vip: currentVip ?? '' }, { preserveState: false });
    };

    const handleVipFilter = (vipVal) => {
        router.get(route('guests.index'), { search: data.search, vip: vipVal }, { preserveState: false });
    };

    const handleSyncSubmit = (e) => {
        e.preventDefault();
        syncForm.post(route('guests.sync'));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Guests" />

            <div className="flex flex-col gap-8">

                {/* Title + Actions */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Guest History
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Query unified guest profiles, track transaction histories, and manage privileged VIP classifications.</p>
                    </div>
                    {user.role === 'admin' && (
                        <form onSubmit={handleSyncSubmit}>
                            <button
                                type="submit"
                                disabled={syncForm.processing}
                                className="flex items-center gap-2 px-4 py-2.5 bg-slate-800 border border-slate-700/60 hover:bg-slate-700 rounded-xl text-slate-200 text-xs font-bold font-outfit shadow-sm transition-all disabled:opacity-50"
                            >
                                <RefreshCw size={14} className={syncForm.processing ? 'animate-spin' : ''} /> Sync from Bookings
                            </button>
                        </form>
                    )}
                </div>

                {/* Stats KPI */}
                {stats && (
                    <div className="grid grid-cols-3 gap-4">
                        <button onClick={() => handleVipFilter('')}
                            className={`p-4 rounded-xl border text-left transition-all ${!currentVip ? 'bg-brand-600/20 border-brand-500/50 text-brand-100' : 'bg-[#1e293b] border-[#334155] text-slate-400 hover:bg-[#334155]/40'}`}>
                            <span className="text-[10px] uppercase font-bold tracking-wider block mb-1 flex items-center gap-1"><Users size={11} /> All Guests</span>
                            <span className="text-2xl font-outfit font-bold text-slate-100">{stats.totalCount}</span>
                        </button>
                        <button onClick={() => handleVipFilter('1')}
                            className={`p-4 rounded-xl border text-left transition-all ${currentVip === '1' ? 'bg-amber-600/20 border-amber-500/50 text-amber-100' : 'bg-[#1e293b] border-[#334155] text-slate-400 hover:bg-[#334155]/40'}`}>
                            <span className="text-[10px] uppercase font-bold tracking-wider block mb-1 flex items-center gap-1"><Crown size={11} /> VIP Guests</span>
                            <span className="text-2xl font-outfit font-bold text-amber-400">{stats.vipCount}</span>
                        </button>
                        <button onClick={() => handleVipFilter('0')}
                            className={`p-4 rounded-xl border text-left transition-all ${currentVip === '0' ? 'bg-slate-600/20 border-slate-500/50 text-slate-100' : 'bg-[#1e293b] border-[#334155] text-slate-400 hover:bg-[#334155]/40'}`}>
                            <span className="text-[10px] uppercase font-bold tracking-wider block mb-1 flex items-center gap-1"><Users size={11} /> Regular</span>
                            <span className="text-2xl font-outfit font-bold text-slate-300">{stats.regularCount}</span>
                        </button>
                    </div>
                )}

                {/* Search panel */}
                <form onSubmit={handleSearchSubmit} className="p-4 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-lg flex gap-4">
                    <div className="relative flex-1">
                        <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                        <input
                            type="text"
                            value={data.search}
                            onChange={e => setData('search', e.target.value)}
                            placeholder="Search guests by name, phone number, email..."
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs"
                        />
                    </div>
                    <button
                        type="submit"
                        className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 text-xs font-bold font-outfit"
                    >
                        Search
                    </button>
                </form>

                {/* Profiles Table */}
                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr className="border-b border-[#334155] text-xs font-semibold text-slate-400 uppercase tracking-wider pb-3">
                                    <th className="pb-3">Guest Profile</th>
                                    <th className="pb-3">Contact info</th>
                                    <th className="pb-3">Stays</th>
                                    <th className="pb-3">Total Spent</th>
                                    <th className="pb-3">Last Visit</th>
                                    <th className="pb-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#334155]/60 text-slate-300">
                                {guests.length > 0 ? (
                                    guests.map((g) => (
                                        <tr key={g.id} className="hover:bg-[#0f172a]/20 transition-colors">

                                            {/* Full name & VIP flag */}
                                            <td className="py-4">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-outfit font-black text-slate-200">{g.full_name}</span>
                                                    {g.is_vip && (
                                                        <span className="inline-flex items-center gap-0.5 text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-bold">
                                                            <Crown size={9} /> VIP
                                                        </span>
                                                    )}
                                                </div>
                                                <span className="text-[10px] text-slate-500 font-mono block mt-1">ID: {g.id_type || 'None'} / {g.id_number || '-'}</span>
                                                {g.is_vip && g.vip_notes && (
                                                    <span className="text-[10px] text-amber-600/70 italic block mt-0.5 truncate max-w-[200px]">{g.vip_notes}</span>
                                                )}
                                            </td>

                                            {/* Contact */}
                                            <td className="py-4 text-xs">
                                                <span className="block">{g.contact_number || '-'}</span>
                                                <span className="text-slate-400 block mt-0.5">{g.email || '-'}</span>
                                            </td>

                                            {/* Stays count */}
                                            <td className="py-4 text-xs font-mono font-bold text-slate-300">
                                                {g.total_stays} stay(s)
                                            </td>

                                            {/* Spent */}
                                            <td className="py-4 text-xs font-mono font-bold text-brand-300">
                                                ₱{Number(g.total_spent).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </td>

                                            {/* Last Visit */}
                                            <td className="py-4 text-xs font-mono text-slate-400">
                                                {g.last_visit ? new Date(g.last_visit).toLocaleDateString() : '-'}
                                            </td>

                                            {/* Actions */}
                                            <td className="py-4 text-right">
                                                <div className="flex items-center justify-end gap-2 flex-wrap">
                                                    <Link
                                                        href={route('checkin.index', { guest_id: g.id })}
                                                        className="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-950/45 hover:bg-emerald-900/60 border border-emerald-900/40 rounded-xl text-xs font-bold text-emerald-400 hover:text-emerald-300 transition-colors shadow-sm"
                                                    >
                                                        <UserCheck size={14} /> Quick Check-In
                                                    </Link>
                                                    <Link
                                                        href={route('guests.show', g.id)}
                                                        className="inline-flex items-center gap-1 px-3 py-2 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] rounded-xl text-xs font-bold text-brand-400 hover:text-brand-300 transition-colors"
                                                    >
                                                        View Details <ChevronRight size={14} />
                                                    </Link>
                                                </div>
                                            </td>

                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="py-10 text-center text-slate-500">
                                            {currentVip === '1' ? 'No VIP guests found.' : currentVip === '0' ? 'No regular guests found.' : 'No guest profiles found matching query.'}
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
