import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Bed,
    Coins,
    AlertTriangle,
    ChevronRight,
    Plus,
    LayoutGrid,
    ClipboardList,
    Users2,
    Lock,
    TrendingUp,
    Hourglass,
    CheckCircle2,
    ChevronDown
} from 'lucide-react';
import { motion } from 'framer-motion';
import {
    ResponsiveContainer,
    AreaChart,
    Area,
    BarChart,
    Bar,
    PieChart,
    Pie,
    Cell,
    XAxis,
    YAxis,
    Tooltip,
    Legend
} from 'recharts';
import CustomSelect from '@/Components/CustomSelect';

export default function Dashboard({ stats, charts, recentBookings, lowStockItems, activeShift, liveUpdates = [], upcomingCheckins = [], upcomingCheckouts = [], recentExpenses = [] }) {
    const [revenuePeriod, setRevenuePeriod] = useState('today');
    const [upcomingTab, setUpcomingTab] = useState('checkins');

    const roomStats = stats?.rooms || { total: 0, occupied: 0, vacant: 0, cleaning: 0, out_of_order: 0 };

    // Math indicators
    const currentRev = stats?.revenue_periods?.[revenuePeriod] || stats?.revenue || { total: 0, room: 0, product: 0, label: "Income Breakdown" };
    const updates = liveUpdates || [];

    const lodgingUpdates = updates.filter(u => ['check_in', 'overdue_checkout', 'checkout'].includes(u.type));
    const housekeepingUpdates = updates.filter(u => u.type === 'cleaning');
    const maintenanceUpdates = updates.filter(u => u.type === 'maintenance');

    const cards = [
        {
            title: "Gross Income",
            value: `₱${(currentRev.total || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Rooms: ₱${(currentRev.room || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })} | Products: ₱${(currentRev.product || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`,
            icon: Coins,
            baseColor: "emerald",
        },
        {
            title: "Total Expenses",
            value: `₱${(currentRev.expenses || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Operational and physical expenses`,
            icon: AlertTriangle,
            baseColor: "rose",
        },
        {
            title: "Net Income",
            value: `₱${(currentRev.net_income || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Gross Income - Expenses`,
            icon: TrendingUp,
            baseColor: "brand",
        }
    ];

    const quickActions = [
        {
            name: "New Guest Check-In",
            desc: "Register a walk-in guest",
            icon: Plus,
            href: route('checkin.index'),
            requiresShift: true,
            color: "bg-brand-500/20 border-brand-500/30 text-brand-300 hover:bg-brand-500/30"
        },
        {
            name: "Room Status Grid",
            desc: "Manage housekeeping & cleaning",
            icon: LayoutGrid,
            href: route('rooms.index'),
            requiresShift: false,
            color: "bg-indigo-500/20 border-indigo-500/30 text-indigo-300 hover:bg-indigo-500/30"
        },
        {
            name: "Guest Directory",
            desc: "View guest stay history",
            icon: Users2,
            href: route('guests.index'),
            requiresShift: false,
            color: "bg-emerald-500/20 border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/30"
        },
        {
            name: "Shift Remittances",
            desc: "Open/close register sessions",
            icon: Users2,
            href: route('shifts.index'),
            requiresShift: false,
            color: "bg-teal-500/20 border-teal-500/30 text-teal-300 hover:bg-teal-500/30"
        }
    ];

    const COLORS = ['#6366f1', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#3b82f6'];

    const CustomTooltip = ({ active, payload, label, isCurrency }) => {
        if (active && payload && payload.length) {
            return (
                <div className="bg-[#1e293b]/95 border border-[#334155] p-3 rounded-xl shadow-2xl backdrop-blur-md text-xs">
                    <p className="font-bold text-slate-300 font-outfit mb-1">{label}</p>
                    {payload.map((p, idx) => (
                        <p key={idx} className="font-medium" style={{ color: p.color || p.fill }}>
                            {p.name}: {isCurrency ? `₱${Number(p.value).toLocaleString()}` : `${p.value}${p.name.includes('Rate') ? '%' : ''}`}
                        </p>
                    ))}
                </div>
            );
        }
        return null;
    };

    return (
        <AuthenticatedLayout>
            <Head title="Admin Dashboard" />

            <div className="flex flex-col gap-10">

                {/* Header Welcome Title */}
                <div className="flex flex-col gap-3 md:flex-row md:justify-between md:items-center">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight bg-gradient-to-r from-slate-50 via-slate-100 to-brand-300 bg-clip-text text-transparent">
                            Statistics
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Monitor real-time room occupancy indices, ongoing session revenue totals, and critical stock levels.</p>
                    </div>

                    {/* Global Period CustomSelect Dropdown */}
                    <CustomSelect
                        value={revenuePeriod}
                        onChange={setRevenuePeriod}
                        containerClassName="sm:w-48 shadow-lg"
                        options={[
                            { key: 'today', label: 'TODAY' },
                            { key: 'last_7_days', label: '7 DAYS' },
                            { key: 'this_month', label: 'THIS MONTH' },
                            { key: 'this_year', label: 'THIS YEAR' },
                        ]}
                    />
                </div>

                {/* KPI Cards Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6">
                    {cards.map((card, idx) => (
                        <motion.div
                            key={card.title}
                            initial={{ opacity: 0, y: 15 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: idx * 0.1 }}
                            className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col justify-between"
                        >
                            <div>
                                <div className="flex items-center justify-between mb-3">
                                    <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{card.title}</span>
                                    <card.icon size={15} className={`text-${card.baseColor}-400`} />
                                </div>

                                <div className={`font-mono font-black text-xl tracking-tight text-${card.baseColor}-300`}>{card.value}</div>
                            </div>
                            <div className="text-[10px] text-slate-500 mt-2">{card.desc}</div>
                        </motion.div>
                    ))}
                </div>

                {/* Main Content Sections */}
                <div className="flex flex-col gap-10">

                    {/* Quick Actions (2/3 width) */}
                    <div className="xl:col-span-2 flex flex-col gap-4 justify-between">
                        <h2 className="text-lg font-outfit font-bold tracking-tight text-slate-200">Quick Actions</h2>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 flex-1">
                            {quickActions.map(action => {
                                const isLocked = action.requiresShift && !activeShift;
                                const linkHref = isLocked ? route('shifts.index') : action.href;

                                return (
                                    <Link
                                        key={action.name}
                                        href={linkHref}
                                        className={`p-4 rounded-xl border flex items-center gap-4 transition-all group ${action.color}`}
                                    >
                                        <div className="p-3 bg-[#0f172a]/50 rounded-lg group-hover:scale-105 transition-transform shrink-0">
                                            <action.icon size={20} />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="font-outfit font-extrabold text-sm flex items-center gap-1.5">
                                                {action.name}
                                                {isLocked && <Lock size={12} className="text-amber-400" />}
                                            </div>
                                            <div className="text-xs text-slate-400 mt-0.5 truncate">{action.desc}</div>
                                        </div>
                                        <ChevronRight size={16} className="text-slate-400 group-hover:translate-x-1 transition-transform ml-auto shrink-0" />
                                    </Link>
                                );
                            })}
                        </div>
                    </div>

                    {/* Section 1: Live Operational Console */}
                    <div className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">

                            {/* Stays Live Feed */}
                            <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-outfit font-bold text-slate-200 flex items-center gap-2 uppercase tracking-wide">
                                        Stays Feed
                                        <span className="relative flex h-2 w-2">
                                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                            <span className="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                                        </span>
                                    </h3>
                                    <span className="text-[9px] text-brand-400 font-mono font-bold tracking-widest uppercase flex items-center gap-1.5">
                                        LIVE
                                        {lodgingUpdates.length > 0 && (
                                            <span className="px-1.5 py-0.5 rounded text-[8px] font-black bg-brand-500/20 text-brand-300 border border-brand-500/30">
                                                {lodgingUpdates.length}
                                            </span>
                                        )}
                                    </span>
                                </div>

                                <div className="flex flex-col gap-3 max-h-[220px] overflow-y-auto pr-1">
                                    {lodgingUpdates.length > 0 ? (
                                        lodgingUpdates.map((update) => {
                                            let statusCls = "";
                                            let typeLabel = "";
                                            if (update.status === 'critical') {
                                                statusCls = "border-red-500/25 bg-red-950/15 text-red-400 hover:border-red-500/40";
                                                typeLabel = "CRITICAL";
                                            } else if (update.status === 'warning') {
                                                statusCls = "border-amber-500/25 bg-amber-950/15 text-amber-400 hover:border-amber-500/40";
                                                typeLabel = "WARNING";
                                            } else if (update.status === 'pending') {
                                                statusCls = "border-indigo-500/25 bg-indigo-950/15 text-indigo-400 hover:border-indigo-500/40";
                                                typeLabel = "PENDING";
                                            } else {
                                                statusCls = "border-[#334155] bg-[#0f172a]/30 text-slate-400 hover:border-brand-500/20";
                                                typeLabel = "INFO";
                                            }

                                            return (
                                                <Link
                                                    key={`${update.type}-${update.id}`}
                                                    href={update.link}
                                                    className={`p-3.5 rounded-xl border flex flex-col gap-1 transition-all hover:scale-[1.01] ${statusCls}`}
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-outfit font-extrabold text-slate-200 text-xs leading-snug">{update.title}</span>
                                                        <span className="text-[8px] font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-[#0f172a]/75 shrink-0">
                                                            {typeLabel}
                                                        </span>
                                                    </div>
                                                    <p className="text-[10px] text-slate-400 leading-normal">{update.description}</p>
                                                    {update.time && (
                                                        <span className="text-[9px] text-slate-500 font-mono mt-1 font-semibold">
                                                            {new Date(update.time).toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                    )}
                                                </Link>
                                            );
                                        })
                                    ) : (
                                        <div className="p-8 rounded-xl border border-dashed border-[#334155] text-center text-xs text-slate-500 flex flex-col gap-1 items-center justify-center py-8">
                                            <span className="font-bold text-slate-400 text-xs font-outfit uppercase">
                                                Stays All Clear
                                            </span>
                                            <span className="text-[10px] text-slate-500">
                                                No upcoming check-ins or departures today.
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Housekeeping Live Feed */}
                            <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-outfit font-bold text-slate-200 flex items-center gap-2 uppercase tracking-wide">
                                        Housekeeping Feed
                                        <span className="relative flex h-2 w-2">
                                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                            <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                        </span>
                                    </h3>
                                    <span className="text-[9px] text-emerald-400 font-mono font-bold tracking-widest uppercase flex items-center gap-1.5">
                                        LIVE
                                        {housekeepingUpdates.length > 0 && (
                                            <span className="px-1.5 py-0.5 rounded text-[8px] font-black bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                                {housekeepingUpdates.length}
                                            </span>
                                        )}
                                    </span>
                                </div>

                                <div className="flex flex-col gap-3 max-h-[220px] overflow-y-auto pr-1">
                                    {housekeepingUpdates.length > 0 ? (
                                        housekeepingUpdates.map((update) => {
                                            let statusCls = "";
                                            let typeLabel = "";
                                            if (update.status === 'critical') {
                                                statusCls = "border-red-500/25 bg-red-950/15 text-red-400 hover:border-red-500/40";
                                                typeLabel = "CRITICAL";
                                            } else if (update.status === 'warning') {
                                                statusCls = "border-amber-500/25 bg-amber-950/15 text-amber-400 hover:border-amber-500/40";
                                                typeLabel = "WARNING";
                                            } else if (update.status === 'pending') {
                                                statusCls = "border-indigo-500/25 bg-indigo-950/15 text-indigo-400 hover:border-indigo-500/40";
                                                typeLabel = "PENDING";
                                            } else {
                                                statusCls = "border-[#334155] bg-[#0f172a]/30 text-slate-400 hover:border-brand-500/20";
                                                typeLabel = "INFO";
                                            }

                                            return (
                                                <Link
                                                    key={`${update.type}-${update.id}`}
                                                    href={update.link}
                                                    className={`p-3.5 rounded-xl border flex flex-col gap-1 transition-all hover:scale-[1.01] ${statusCls}`}
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-outfit font-extrabold text-slate-200 text-xs leading-snug">{update.title}</span>
                                                        <span className="text-[8px] font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-[#0f172a]/75 shrink-0">
                                                            {typeLabel}
                                                        </span>
                                                    </div>
                                                    <p className="text-[10px] text-slate-400 leading-normal">{update.description}</p>
                                                    {update.time && (
                                                        <span className="text-[9px] text-slate-500 font-mono mt-1 font-semibold">
                                                            {new Date(update.time).toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                    )}
                                                </Link>
                                            );
                                        })
                                    ) : (
                                        <div className="p-8 rounded-xl border border-dashed border-[#334155] text-center text-xs text-slate-500 flex flex-col gap-1 items-center justify-center py-8">
                                            <span className="font-bold text-slate-400 text-xs font-outfit uppercase">
                                                Clean & Fresh
                                            </span>
                                            <span className="text-[10px] text-slate-500">
                                                All rooms are clean and vacant rooms prepared.
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Repairs Live Feed */}
                            <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-outfit font-bold text-slate-200 flex items-center gap-2 uppercase tracking-wide">
                                        Repairs Feed
                                        <span className="relative flex h-2 w-2">
                                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                            <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                                        </span>
                                    </h3>
                                    <span className="text-[9px] text-amber-400 font-mono font-bold tracking-widest uppercase flex items-center gap-1.5">
                                        LIVE
                                        {maintenanceUpdates.length > 0 && (
                                            <span className="px-1.5 py-0.5 rounded text-[8px] font-black bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                                {maintenanceUpdates.length}
                                            </span>
                                        )}
                                    </span>
                                </div>

                                <div className="flex flex-col gap-3 max-h-[220px] overflow-y-auto pr-1">
                                    {maintenanceUpdates.length > 0 ? (
                                        maintenanceUpdates.map((update) => {
                                            let statusCls = "";
                                            let typeLabel = "";
                                            if (update.status === 'critical') {
                                                statusCls = "border-red-500/25 bg-red-950/15 text-red-400 hover:border-red-500/40";
                                                typeLabel = "CRITICAL";
                                            } else if (update.status === 'warning') {
                                                statusCls = "border-amber-500/25 bg-amber-950/15 text-amber-400 hover:border-amber-500/40";
                                                typeLabel = "WARNING";
                                            } else if (update.status === 'pending') {
                                                statusCls = "border-indigo-500/25 bg-indigo-950/15 text-indigo-400 hover:border-indigo-500/40";
                                                typeLabel = "PENDING";
                                            } else {
                                                statusCls = "border-[#334155] bg-[#0f172a]/30 text-slate-400 hover:border-brand-500/20";
                                                typeLabel = "INFO";
                                            }

                                            return (
                                                <Link
                                                    key={`${update.type}-${update.id}`}
                                                    href={update.link}
                                                    className={`p-3.5 rounded-xl border flex flex-col gap-1 transition-all hover:scale-[1.01] ${statusCls}`}
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-outfit font-extrabold text-slate-200 text-xs leading-snug">{update.title}</span>
                                                        <span className="text-[8px] font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-[#0f172a]/75 shrink-0">
                                                            {typeLabel}
                                                        </span>
                                                    </div>
                                                    <p className="text-[10px] text-slate-400 leading-normal">{update.description}</p>
                                                    {update.time && (
                                                        <span className="text-[9px] text-slate-500 font-mono mt-1 font-semibold">
                                                            {new Date(update.time).toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                    )}
                                                </Link>
                                            );
                                        })
                                    ) : (
                                        <div className="p-8 rounded-xl border border-dashed border-[#334155] text-center text-xs text-slate-500 flex flex-col gap-1 items-center justify-center py-8">
                                            <span className="font-bold text-slate-400 text-xs font-outfit uppercase">
                                                Repairs Resolved
                                            </span>
                                            <span className="text-[10px] text-slate-500">
                                                No active repairs or maintenance tickets.
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>

                        </div>
                    </div>

                    {/* Section: Upcoming Actions & Recent Expenses */}
                    <div className="grid grid-cols-1 xl:grid-cols-3 gap-8 items-stretch">

                        {/* Upcoming Actions (2/3 width) */}
                        <div className="xl:col-span-2 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-[#334155] pb-4">
                                <h3 className="font-outfit font-bold text-slate-200 text-sm uppercase tracking-wider">Upcoming arrivals & departures (Next 24h)</h3>

                                <div className="flex bg-[#0f172a] p-0.5 rounded-lg border border-[#334155] text-[10px] font-black uppercase shadow-inner">
                                    <button
                                        type="button"
                                        onClick={() => setUpcomingTab('checkins')}
                                        className={`px-3 py-1.5 rounded transition-all ${upcomingTab === 'checkins'
                                            ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/60'
                                            : 'text-slate-400 hover:text-slate-205'
                                            }`}
                                    >
                                        Check-Ins ({upcomingCheckins.length})
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setUpcomingTab('checkouts')}
                                        className={`px-3 py-1.5 rounded transition-all ${upcomingTab === 'checkouts'
                                            ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/60'
                                            : 'text-slate-400 hover:text-slate-205'
                                            }`}
                                    >
                                        Check-Outs ({upcomingCheckouts.length})
                                    </button>
                                </div>
                            </div>

                            <div className="overflow-x-auto min-h-[220px]">
                                {upcomingTab === 'checkins' ? (
                                    <table className="w-full text-xs table-fixed">
                                        <thead>
                                            <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-1/4">Room</th>
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-1/3">Guest</th>
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left hidden sm:table-cell w-1/4">Arrival Time</th>
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right w-1/6">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {upcomingCheckins.length > 0 ? (
                                                upcomingCheckins.map((b) => (
                                                    <tr key={b.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors group">
                                                        <td className="px-4 py-3 font-bold font-outfit text-slate-200">
                                                            Room {b.room?.room_number || 'N/A'}
                                                            <div className="text-[10px] text-slate-400 font-normal">{b.room?.type?.type_name || ''}</div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300">
                                                            <div className="font-semibold">{b.guest_name}</div>
                                                            <div className="text-[10px] text-slate-400 font-mono">{b.booking_ref}</div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300 hidden sm:table-cell font-mono">
                                                            {new Date(b.check_in).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link
                                                                href={route('checkin.index')}
                                                                className="inline-flex items-center gap-1 px-2.5 py-1 bg-brand-500/10 hover:bg-brand-500/25 border border-brand-500/30 hover:border-brand-500/50 text-brand-350 text-[10px] font-bold rounded-lg transition-all"
                                                            >
                                                                Check-In
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="4" className="px-4 py-8 text-center text-slate-500">
                                                        No upcoming check-ins in the next 24 hours.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                ) : (
                                    <table className="w-full text-xs table-fixed">
                                        <thead>
                                            <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-1/4">Room</th>
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-1/3">Guest</th>
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left hidden sm:table-cell w-1/4">Expected Checkout</th>
                                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right w-1/6">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {upcomingCheckouts.length > 0 ? (
                                                upcomingCheckouts.map((b) => (
                                                    <tr key={b.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors group">
                                                        <td className="px-4 py-3 font-bold font-outfit text-slate-200">
                                                            Room {b.room?.room_number || 'N/A'}
                                                            <div className="text-[10px] text-slate-400 font-normal">{b.room?.type?.type_name || ''}</div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300">
                                                            <div className="font-semibold">{b.guest_name}</div>
                                                            <div className="text-[10px] text-slate-400 font-mono">{b.booking_ref}</div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300 hidden sm:table-cell font-mono">
                                                            {new Date(b.expected_check_out).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link
                                                                href={route('reservations.index') + '?status=active'}
                                                                className="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-500/10 hover:bg-amber-500/25 border border-amber-500/30 hover:border-amber-500/50 text-amber-350 text-[10px] font-bold rounded-lg transition-all"
                                                            >
                                                                Checkout
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="4" className="px-4 py-8 text-center text-slate-500">
                                                        No upcoming check-outs in the next 24 hours.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        </div>

                        {/* Recent Expenses (1/3 width) */}
                        <div className="xl:col-span-1 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                            <div className="flex justify-between items-center border-b border-[#334155] pb-4">
                                <h3 className="font-outfit font-bold text-slate-200 text-sm uppercase tracking-wider">Recent Expenses</h3>
                                <Link
                                    href={route('expenses.index')}
                                    className="text-[10px] font-bold text-brand-400 hover:text-brand-300 uppercase tracking-widest flex items-center gap-1"
                                >
                                    Manage <ChevronRight size={12} />
                                </Link>
                            </div>

                            <div className="overflow-x-auto min-h-[220px]">
                                <table className="w-full text-xs table-fixed">
                                    <thead>
                                        <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                            <th className="px-3 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-1/4">Date</th>
                                            <th className="px-3 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-1/2">Details</th>
                                            <th className="px-3 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right w-1/4">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentExpenses.length > 0 ? (
                                            recentExpenses.map((exp) => (
                                                <tr key={exp.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors group">
                                                    <td className="px-3 py-3 font-medium text-slate-350">
                                                        {new Date(exp.expense_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                                    </td>
                                                    <td className="px-3 py-3 text-slate-300 truncate">
                                                        <div className="font-semibold truncate">{exp.notes || 'No description'}</div>
                                                        <div className="text-[10px] text-slate-400 truncate">By {exp.user?.full_name || 'System'}</div>
                                                    </td>
                                                    <td className="px-3 py-3 text-right font-mono font-bold text-red-400">
                                                        ₱{Number(exp.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="3" className="px-3 py-8 text-center text-slate-500">
                                                    No recent expenses.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
