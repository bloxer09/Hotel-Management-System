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
    TrendingUp
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

export default function Dashboard({ stats, charts, recentBookings, lowStockItems, activeShift, liveUpdates = [] }) {
    const [revenuePeriod, setRevenuePeriod] = useState('today');

    const roomStats = stats?.rooms || { total: 0, occupied: 0, vacant: 0, cleaning: 0, out_of_order: 0 };

    // Math indicators
    const occupancyRate = roomStats.total > 0
        ? Math.round((roomStats.occupied / roomStats.total) * 100)
        : 0;

    // Sparkline data for RevPAR (daily revenue divided by rooms)
    const revparSparklineData = (charts?.dailyRevenue || []).map(d => ({
        date: d.date,
        revpar: roomStats.total > 0 ? Math.round((d.total / roomStats.total) * 10) / 10 : 0
    }));

    const currentRev = stats?.revenue_periods?.[revenuePeriod] || stats?.revenue || { total: 0, room: 0, product: 0, label: "Income Breakdown" };
    const updates = liveUpdates || [];

    const cards = [
        {
            title: "Occupancy Rate",
            value: `${occupancyRate}%`,
            desc: `${roomStats.occupied} of ${roomStats.total} Rooms Occupied`,
            icon: Bed,
            baseColor: "brand",
        },
        {
            title: currentRev.label || "Income Breakdown",
            value: `₱${(currentRev.total || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Rooms: ₱${(currentRev.room || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })} | Products: ₱${(currentRev.product || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`,
            icon: Coins,
            baseColor: "emerald",
            isRevenue: true,
        },
        {
            title: "RevPAR (30 Days)",
            value: `₱${(stats?.revpar || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Avg Room Yield: ₱${(stats?.revpar || 0).toLocaleString()}`,
            icon: TrendingUp,
            baseColor: "amber",
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

            <div className="flex flex-col gap-8">

                {/* Header Welcome Title */}
                <div className="flex flex-col gap-1.5 md:flex-row md:justify-between md:items-center">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight bg-gradient-to-r from-slate-50 via-slate-100 to-brand-300 bg-clip-text text-transparent">
                            Statistics
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Monitor real-time room occupancy indices, ongoing session revenue totals, and critical stock levels.</p>
                    </div>
                </div>

                {/* KPI Cards Grid */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
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

                                {card.isRevenue && (
                                    <div className="flex gap-1 bg-[#0f172a] p-0.5 rounded-lg border border-[#334155] mb-3.5 w-fit">
                                        {[
                                            { key: 'today', label: 'Today' },
                                            { key: 'last_7_days', label: '7D' },
                                            { key: 'this_month', label: 'Month' },
                                            { key: 'this_year', label: 'Year' },
                                        ].map(tab => (
                                            <button
                                                key={tab.key}
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setRevenuePeriod(tab.key);
                                                }}
                                                className={`px-2.5 py-1 rounded text-[9px] font-extrabold uppercase transition-all ${
                                                    revenuePeriod === tab.key
                                                        ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/60'
                                                        : 'text-slate-450 hover:text-slate-200'
                                                }`}
                                            >
                                                {tab.label}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <div className={`font-mono font-black text-xl tracking-tight text-${card.baseColor}-300`}>{card.value}</div>
                            </div>
                            <div className="text-[10px] text-slate-500 mt-2">{card.desc}</div>
                        </motion.div>
                    ))}
                </div>

                {/* Main Content Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    {/* Left & Middle Column (2/3) */}
                    <div className="lg:col-span-2 flex flex-col gap-8">

                        {/* Charts Area */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            {/* Occupancy Trend Card */}
                            <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <h3 className="font-outfit font-bold text-slate-200 text-sm tracking-wide uppercase">30-Day Occupancy Trend</h3>
                                <div className="h-64">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={charts?.dailyOccupancy || []} margin={{ left: -20, right: 10, top: 10, bottom: 0 }}>
                                            <defs>
                                                <linearGradient id="occTrend" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor="#6366f1" stopOpacity={0.25} />
                                                    <stop offset="95%" stopColor="#6366f1" stopOpacity={0} />
                                                </linearGradient>
                                            </defs>
                                            <XAxis dataKey="date" stroke="#475569" fontSize={10} tickLine={false} />
                                            <YAxis stroke="#475569" fontSize={10} tickLine={false} domain={[0, 100]} />
                                            <Tooltip content={<CustomTooltip isCurrency={false} />} />
                                            <Area type="monotone" name="Occupancy Rate" dataKey="occupancy_rate" stroke="#6366f1" strokeWidth={2} fillOpacity={1} fill="url(#occTrend)" />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>

                            {/* Revenue Breakdown Card */}
                            <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <h3 className="font-outfit font-bold text-slate-200 text-sm tracking-wide uppercase">30-Day Revenue Streams</h3>
                                <div className="h-64">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={charts?.dailyRevenue || []} margin={{ left: -20, right: 10, top: 10, bottom: 0 }}>
                                            <XAxis dataKey="date" stroke="#475569" fontSize={10} tickLine={false} />
                                            <YAxis stroke="#475569" fontSize={10} tickLine={false} />
                                            <Tooltip content={<CustomTooltip isCurrency={true} />} />
                                            <Bar name="Room related Income" dataKey="room" stackId="a" fill="#6366f1" />
                                            <Bar name="Inventory & Products" dataKey="product" stackId="a" fill="#10b981" />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        </div>

                        {/* Room Type Revenue Share & Quick Actions */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {/* Room Type Revenue Share (Pie) */}
                            <div className="md:col-span-1 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <h3 className="font-outfit font-bold text-slate-200 text-xs tracking-wider uppercase">Revenue Share</h3>
                                <div className="h-44 relative flex items-center justify-center">
                                    {charts?.roomTypeRevenue && charts.roomTypeRevenue.length > 0 ? (
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie
                                                    data={charts.roomTypeRevenue}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={50}
                                                    outerRadius={70}
                                                    paddingAngle={3}
                                                    dataKey="value"
                                                >
                                                    {charts.roomTypeRevenue.map((entry, index) => (
                                                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                                    ))}
                                                </Pie>
                                                <Tooltip formatter={(value) => `₱${Number(value).toLocaleString()}`} />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    ) : (
                                        <span className="text-slate-500 text-xs">No data</span>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-x-3 gap-y-1.5 justify-center">
                                    {(charts?.roomTypeRevenue || []).map((entry, index) => (
                                        <div key={entry.name} className="flex items-center gap-1">
                                            <div className="w-2 h-2 rounded-full" style={{ backgroundColor: COLORS[index % COLORS.length] }} />
                                            <span className="text-[10px] text-slate-400 font-medium">{entry.name}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Quick Actions (takes remaining 2 cols) */}
                            <div className="md:col-span-2 flex flex-col gap-4 justify-between">
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
                        </div>

                        {/* Recent Bookings Table */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                            <div className="flex justify-between items-center mb-6">
                                <h2 className="text-lg font-outfit font-bold text-slate-200">Recent Check-In Stay Records</h2>
                                <Link
                                    href={route('guests.index')}
                                    className="text-xs font-bold text-brand-400 hover:text-brand-300 flex items-center gap-1"
                                >
                                    View Guest Directory <ChevronRight size={14} />
                                </Link>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse text-sm">
                                    <thead>
                                        <tr className="border-b border-[#334155] text-xs font-semibold text-slate-400 uppercase">
                                            <th className="pb-3">Room</th>
                                            <th className="pb-3">Guest Profile</th>
                                            <th className="pb-3">Stay Type</th>
                                            <th className="pb-3">Amount</th>
                                            <th className="pb-3 text-right">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#334155]/60">
                                        {recentBookings.length > 0 ? (
                                            recentBookings.map((b) => (
                                                <tr key={b.id} className="hover:bg-[#0f172a]/20 transition-colors">
                                                    <td className="py-3.5 font-bold font-outfit text-slate-200">
                                                        Room {b.room?.room_number || 'N/A'}
                                                    </td>
                                                    <td className="py-3.5 font-medium text-slate-300">{b.guest_name}</td>
                                                    <td className="py-3.5">
                                                        <span className="text-xs bg-[#334155] text-slate-300 px-2 py-0.5 rounded capitalize font-mono">
                                                            {b.booking_type === 'overnight' ? 'Overnight' : `${b.short_time_hours}h Hourly`}
                                                        </span>
                                                    </td>
                                                    <td className="py-3.5 font-mono font-bold text-brand-300">
                                                        ₱{b.total_amount.toLocaleString()}
                                                    </td>
                                                    <td className="py-3.5 text-right">
                                                        <span className={`inline-flex items-center text-[10px] uppercase font-bold font-outfit tracking-wider px-2 py-0.5 rounded-full ${b.status === 'active'
                                                            ? 'bg-emerald-950 text-emerald-400 border border-emerald-500/20'
                                                            : 'bg-slate-900 text-slate-400 border border-slate-700/20'
                                                            }`}>
                                                            {b.status}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="5" className="py-6 text-center text-slate-500">
                                                    No recent booking stay logs found.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                    {/* Right Column (1/3) */}
                    <div className="flex flex-col gap-8">

                        {/* Operational Alerts Feed */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-outfit font-bold text-slate-250 flex items-center gap-2 uppercase tracking-wide">
                                    Operational Alerts
                                    <span className="relative flex h-2.5 w-2.5">
                                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                                        <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-500"></span>
                                    </span>
                                </h2>
                                <span className="text-[9px] text-slate-500 font-mono font-bold tracking-widest uppercase">LIVE FEED</span>
                            </div>

                            <div className="flex flex-col gap-3 max-h-[380px] overflow-y-auto pr-1">
                                {updates.length > 0 ? (
                                    updates.map((update) => {
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
                                            statusCls = "border-[#334155] bg-[#0f172a]/30 text-slate-400 hover:border-indigo-550/20";
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
                                    <div className="p-8 rounded-xl border border-dashed border-[#334155] text-center text-xs text-slate-500">
                                        No active operational alerts today.
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Occupancy Status Breakdowns */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-6">
                            <h2 className="text-lg font-outfit font-bold text-slate-200">Rooms</h2>

                            <div className="flex flex-col gap-4">
                                {/* Vacant Progress */}
                                <div className="space-y-1.5">
                                    <div className="flex justify-between text-xs font-semibold text-slate-400">
                                        <span className="text-emerald-400 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-emerald-500 shrink-0" />
                                            Vacant / Available
                                        </span>
                                        <span>{roomStats.vacant} / {roomStats.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-emerald-500 transition-all duration-500"
                                            style={{ width: `${(roomStats.vacant / roomStats.total) * 100 || 0}%` }}
                                        />
                                    </div>
                                </div>

                                {/* Occupied Progress */}
                                <div className="space-y-1.5">
                                    <div className="flex justify-between text-xs font-semibold text-slate-400">
                                        <span className="text-rose-400 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-rose-500 shrink-0" />
                                            Occupied
                                        </span>
                                        <span>{roomStats.occupied} / {roomStats.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-rose-500 transition-all duration-500"
                                            style={{ width: `${(roomStats.occupied / roomStats.total) * 100 || 0}%` }}
                                        />
                                    </div>
                                </div>

                                {/* Cleaning Progress */}
                                <div className="space-y-1.5">
                                    <div className="flex justify-between text-xs font-semibold text-slate-400">
                                        <span className="text-amber-400 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-amber-500 shrink-0" />
                                            Cleaning Required
                                        </span>
                                        <span>{roomStats.cleaning} / {roomStats.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-amber-500 transition-all duration-500"
                                            style={{ width: `${(roomStats.cleaning / roomStats.total) * 100 || 0}%` }}
                                        />
                                    </div>
                                </div>

                                {/* Out of Order Progress */}
                                <div className="space-y-1.5">
                                    <div className="flex justify-between text-xs font-semibold text-slate-400">
                                        <span className="text-slate-400 flex items-center gap-1.5">
                                            <span className="w-2 h-2 rounded-full bg-slate-500 shrink-0" />
                                            Out of Order
                                        </span>
                                        <span>{roomStats.out_of_order} / {roomStats.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-slate-500 transition-all duration-500"
                                            style={{ width: `${(roomStats.out_of_order / roomStats.total) * 100 || 0}%` }}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Low Stock Items Widgets */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                            <h2 className="text-lg font-outfit font-bold text-slate-200">Critical Stock Banners</h2>

                            <div className="flex flex-col gap-3">
                                {lowStockItems.length > 0 ? (
                                    lowStockItems.map((item) => (
                                        <div
                                            key={item.id}
                                            className="p-3.5 rounded-xl bg-red-950/20 border border-red-500/20 flex items-center justify-between gap-3 text-xs"
                                        >
                                            <div className="flex flex-col gap-0.5">
                                                <span className="font-outfit font-bold text-slate-200">{item.item_name}</span>
                                                <span className="text-[10px] text-slate-400 font-mono capitalize">{item.category} unit</span>
                                            </div>
                                            <div className="flex flex-col items-end gap-0.5 shrink-0">
                                                <span className="font-mono text-red-400 font-bold">Stock: {item.current_stock} {item.unit}</span>
                                                <span className="text-[9px] text-slate-400">Min limit: {item.minimum_stock}</span>
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="p-6 rounded-xl border border-dashed border-[#334155] text-center text-xs text-slate-500">
                                        All inventory stocks are at healthy quantities.
                                    </div>
                                )}
                            </div>
                        </div>

                    </div>

                </div>

            </div>
        </AuthenticatedLayout>
    );
}
