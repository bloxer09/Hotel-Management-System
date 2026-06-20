import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage } from '@inertiajs/react';
import { 
    ChevronLeft, 
    Star, 
    User, 
    Phone, 
    Mail, 
    CreditCard, 
    Calendar,
    Bed, 
    Clock, 
    AlertCircle,
    UserCheck,
    FileText,
    Printer
} from 'lucide-react';
import { motion } from 'framer-motion';

export default function Show({ guest, bookings }) {
    const { auth } = usePage().props;
    const user = auth.user;
    const isAdmin = user.role === 'admin';

    // VIP Form
    const { data, setData, post, processing, errors } = useForm({
        is_vip: guest.is_vip ? true : false,
        vip_notes: guest.vip_notes || ''
    });

    const handleVipSubmit = (e) => {
        e.preventDefault();
        post(route('guests.vip', guest.id));
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Guest Profile: ${guest.full_name}`} />

            <div className="flex flex-col gap-8">
                
                {/* Header Back Button & Title */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <Link 
                            href={route('guests.index')}
                            className="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-slate-100 font-bold transition-colors mb-2 self-start"
                        >
                            <ChevronLeft size={14} /> Back to Guests
                        </Link>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                                {guest.full_name}
                            </h1>
                            {guest.is_vip && (
                                <span className="inline-flex items-center gap-1 text-xs bg-amber-950 border border-amber-600/40 text-amber-400 px-2.5 py-1 rounded-full font-bold shadow-md shadow-amber-950/50">
                                    <Star size={12} className="fill-amber-400" /> VIP Status
                                </span>
                            )}
                        </div>
                        <p className="text-xs text-slate-400 font-mono mt-0.5">Profile Reference: GUST-{guest.id.toString().padStart(5, '0')}</p>
                    </div>
                </div>

                {/* Stats Dashboard Row */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    
                    {/* Stay Stats */}
                    <div className="p-6 rounded-2xl bg-gradient-to-br from-[#1e293b] to-[#1e293b]/70 border border-[#334155] shadow-xl relative overflow-hidden group">
                        <div className="absolute right-0 bottom-0 translate-x-2 translate-y-2 opacity-5 pointer-events-none group-hover:scale-110 transition-transform duration-300">
                            <Calendar size={120} className="text-slate-100" />
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Stay Record</span>
                            <Calendar size={18} className="text-brand-400" />
                        </div>
                        <div className="mt-4 flex items-baseline gap-2">
                            <span className="text-4xl font-outfit font-black text-slate-50">{guest.total_stays}</span>
                            <span className="text-xs font-semibold text-slate-400">Total Booking Stays</span>
                        </div>
                        <div className="mt-2 text-xs text-slate-400">
                            Last visit recorded on <span className="font-mono font-semibold text-slate-300">{guest.last_visit ? new Date(guest.last_visit).toLocaleDateString(undefined, { dateStyle: 'medium' }) : 'Never'}</span>
                        </div>
                    </div>

                    {/* Monetary Spent */}
                    <div className="p-6 rounded-2xl bg-gradient-to-br from-[#1e293b] to-[#1e293b]/70 border border-[#334155] shadow-xl relative overflow-hidden group">
                        <div className="absolute right-0 bottom-0 translate-x-2 translate-y-2 opacity-5 pointer-events-none group-hover:scale-110 transition-transform duration-300">
                            <CreditCard size={120} className="text-slate-100" />
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Revenue contribution</span>
                            <CreditCard size={18} className="text-emerald-400" />
                        </div>
                        <div className="mt-4 flex items-baseline gap-1">
                            <span className="text-xs font-black text-slate-300 font-mono">₱</span>
                            <span className="text-4xl font-outfit font-black text-slate-50 font-mono">
                                {Number(guest.total_spent).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                            </span>
                        </div>
                        <div className="mt-2 text-xs text-slate-400">
                            Lifetime revenue check-in receipts & extensions.
                        </div>
                    </div>

                    {/* Guest Information */}
                    <div className="p-6 rounded-2xl bg-gradient-to-br from-[#1e293b] to-[#1e293b]/70 border border-[#334155] shadow-xl flex flex-col justify-between">
                        <div className="flex items-center justify-between border-b border-[#334155]/60 pb-3">
                            <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Contact & Credentials</span>
                            <User size={18} className="text-indigo-400" />
                        </div>
                        <div className="mt-3 space-y-2 text-xs text-slate-300">
                            <div className="flex items-center gap-2">
                                <Phone size={14} className="text-slate-500 shrink-0" />
                                <span className="font-mono">{guest.contact_number || 'No Phone Number'}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <Mail size={14} className="text-slate-500 shrink-0" />
                                <span className="truncate">{guest.email || 'No Registered Email'}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <FileText size={14} className="text-slate-500 shrink-0" />
                                <span>Govt ID: <span className="font-mono text-brand-300 font-bold">{guest.id_type || 'None'} / {guest.id_number || '-'}</span></span>
                            </div>
                        </div>
                    </div>

                </div>

                {/* Primary Content Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    {/* Booking History Ledger (Span 2) */}
                    <div className="lg:col-span-2 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                        <h2 className="text-lg font-outfit font-black text-slate-200 flex items-center gap-2">
                            <Bed className="text-brand-400" size={20} /> Stay History
                        </h2>
                        
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr className="border-b border-[#334155] text-slate-400 uppercase tracking-wider font-semibold">
                                        <th className="pb-3">Ref Code</th>
                                        <th className="pb-3">Room details</th>
                                        <th className="pb-3">Booking Type</th>
                                        <th className="pb-3">Dates & Stays</th>
                                        <th className="pb-3">Billing</th>
                                        <th className="pb-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#334155]/60 text-slate-300">
                                    {bookings.length > 0 ? (
                                        bookings.map((b) => (
                                            <tr key={b.id} className="hover:bg-[#0f172a]/20 transition-colors">
                                                <td className="py-3.5 font-mono font-bold text-slate-400">
                                                    <Link href={route('bookings.show', b.id)} className="text-brand-400 hover:underline">
                                                        {b.booking_ref}
                                                    </Link>
                                                </td>
                                                <td className="py-3.5">
                                                    <span className="font-outfit font-extrabold text-slate-200 block">Room {b.room ? b.room.room_number : '-'}</span>
                                                    <span className="text-[10px] text-slate-500 block">{b.room?.room_type?.type_name || '-'}</span>
                                                </td>
                                                <td className="py-3.5 capitalize font-mono text-[10px]">
                                                    {b.booking_type === 'short_time' ? (
                                                        <span className="bg-indigo-950 text-indigo-400 border border-indigo-900/50 px-2 py-0.5 rounded-full font-bold">
                                                            ST ({b.short_time_hours} Hours)
                                                        </span>
                                                    ) : (
                                                        <span className="bg-sky-950 text-sky-400 border border-sky-900/50 px-2 py-0.5 rounded-full font-bold">
                                                            Overnight
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3.5">
                                                    <div className="flex items-center gap-1 text-[10px] text-slate-400">
                                                        <span>{new Date(b.check_in).toLocaleDateString(undefined, { dateStyle: 'short' })}</span>
                                                        <span>→</span>
                                                        <span>{b.check_out ? new Date(b.check_out).toLocaleDateString(undefined, { dateStyle: 'short' }) : new Date(b.expected_check_out).toLocaleDateString(undefined, { dateStyle: 'short' })}</span>
                                                    </div>
                                                </td>
                                                <td className="py-3.5 font-mono">
                                                    <span className="font-bold text-brand-300">₱{Number(b.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    <span className="text-[10px] text-slate-500 block">via {b.payment_method}</span>
                                                </td>
                                                <td className="py-3.5">
                                                    {b.status === 'active' ? (
                                                        <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 bg-emerald-950 border border-emerald-800 text-emerald-400 text-[10px] rounded-full font-extrabold uppercase">
                                                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0 animate-pulse" />
                                                            Active
                                                        </span>
                                                    ) : b.status === 'checked_out' ? (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 bg-[#0f172a] border border-[#334155] text-slate-400 text-[10px] rounded-full font-extrabold uppercase">
                                                            Checked Out
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 bg-red-950 border border-red-800 text-red-400 text-[10px] rounded-full font-extrabold uppercase">
                                                            Cancelled
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="py-8 text-center text-slate-500">
                                                No stay records found under this profile.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* VIP Notes & Operations panel */}
                    <div className="flex flex-col gap-6">
                        
                        {/* Admin VIP Settings Card */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                            <div className="flex items-center justify-between border-b border-[#334155]/60 pb-3">
                                <h3 className="text-sm font-outfit font-black uppercase tracking-wider text-slate-300">VIP configuration</h3>
                                <Star size={16} className={guest.is_vip ? 'text-amber-400 fill-amber-400 animate-pulse' : 'text-slate-500'} />
                            </div>
                            
                            {isAdmin ? (
                                <form onSubmit={handleVipSubmit} className="space-y-4">
                                    {/* VIP Switch Toggle */}
                                    <div className="flex items-center justify-between p-3 rounded-xl bg-[#0f172a]/60 border border-[#334155]/60">
                                        <div className="flex flex-col gap-0.5">
                                            <span className="text-xs font-bold text-slate-200">VIP Status Tier</span>
                                            <span className="text-[10px] text-slate-500">Applies VIP banners during check-in</span>
                                        </div>
                                        <label className="relative inline-flex items-center cursor-pointer">
                                            <input 
                                                type="checkbox" 
                                                checked={data.is_vip}
                                                onChange={e => setData('is_vip', e.target.checked)}
                                                className="sr-only peer" 
                                            />
                                            <div className="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-200 after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600"></div>
                                        </label>
                                    </div>

                                    {/* Notes input */}
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-xs font-bold text-slate-400 uppercase tracking-wider">VIP Administrative Notes</label>
                                        <textarea
                                            value={data.vip_notes}
                                            onChange={e => setData('vip_notes', e.target.value)}
                                            placeholder="Specify privileges, company billing configurations, or specific room preferences..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 text-xs p-3 min-h-[100px] focus:outline-none focus:border-brand-500 placeholder-slate-600"
                                        />
                                        {errors.vip_notes && <span className="text-[10px] text-red-400 font-semibold">{errors.vip_notes}</span>}
                                    </div>

                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full py-2.5 bg-brand-600 hover:bg-brand-500 disabled:opacity-50 text-slate-50 font-outfit font-extrabold text-xs tracking-wide rounded-xl shadow-lg hover:shadow-brand-600/10 active:scale-95 transition-all"
                                    >
                                        {processing ? 'Applying updates...' : 'Save VIP Configurations'}
                                    </button>
                                </form>
                            ) : (
                                <div className="space-y-4">
                                    <div className="flex items-center gap-3 p-3 rounded-xl bg-slate-900/40 border border-[#334155] text-slate-400 text-xs">
                                        <AlertCircle size={16} className="text-amber-500 shrink-0" />
                                        <span>Only system administrators are authorized to toggle guest VIP credentials.</span>
                                    </div>

                                    {guest.vip_notes && (
                                        <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] text-xs">
                                            <span className="font-bold text-slate-400 block mb-2 uppercase tracking-wide">VIP Privileges & Preference Notes</span>
                                            <p className="text-slate-300 leading-relaxed italic">{guest.vip_notes}</p>
                                        </div>
                                    )}
                                </div>
                            )}

                        </div>

                        {/* Quick Check-in Launcher */}
                        {user.role !== 'cashier' && user.role !== 'housekeeping' && (
                            <div className="p-6 rounded-2xl bg-gradient-to-br from-brand-900/60 to-[#1e293b] border border-brand-800/40 shadow-xl flex flex-col gap-3">
                                <h3 className="font-outfit font-extrabold text-slate-100 text-sm">Need to Check-In guest?</h3>
                                <p className="text-xs text-slate-400 leading-relaxed">
                                    Launch a reservation or instant walk-in stay using this guest's information. VIP statuses are auto-imported.
                                </p>
                                <Link
                                    href={route('checkin.index', { guest_id: guest.id })}
                                    onClick={() => {
                                        sessionStorage.setItem('quickCheckinGuest', JSON.stringify(guest));
                                    }}
                                    className="w-full py-2.5 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 text-xs font-outfit font-extrabold text-center tracking-wider shadow-lg hover:shadow-brand-600/20 active:scale-95 transition-all mt-1"
                                >
                                    Check In Guest
                                </Link>
                            </div>
                        )}

                    </div>

                </div>

            </div>
        </AuthenticatedLayout>
    );
}
