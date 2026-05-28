import React from 'react';
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

export default function Dashboard({ stats, charts, recentBookings, lowStockItems, activeShift }) {
    // Math indicators
    const occupancyRate = stats.rooms.total > 0
        ? Math.round((stats.rooms.occupied / stats.rooms.total) * 100)
        : 0;

    // Sparkline data for RevPAR (daily revenue divided by rooms)
    const revparSparklineData = (charts?.dailyRevenue || []).map(d => ({
        date: d.date,
        revpar: stats.rooms.total > 0 ? Math.round((d.total / stats.rooms.total) * 10) / 10 : 0
    }));

    const cards = [
        {
            title: "Occupancy Rate",
            value: `${occupancyRate}%`,
            desc: `${stats.rooms.occupied} of ${stats.rooms.total} Rooms Occupied`,
            icon: Bed,
            color: "from-brand-500 to-indigo-600 bg-brand-500/10 text-brand-400",
            sparkline: (
                <div className="h-10 w-24 shrink-0">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={charts?.dailyOccupancy || []}>
                            <defs>
                                <linearGradient id="colorOcc" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor="#6366f1" stopOpacity={0.4} />
                                    <stop offset="95%" stopColor="#6366f1" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <Area type="monotone" dataKey="occupancy_rate" stroke="#6366f1" strokeWidth={1.5} fillOpacity={1} fill="url(#colorOcc)" dot={false} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )
        },
        {
            title: "Today's Income",
            value: `₱${(stats.revenue.total).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Rooms: ₱${(stats.revenue.room || 0).toLocaleString(undefined, {minimumFractionDigits:2})} | Products: ₱${(stats.revenue.product || 0).toLocaleString(undefined, {minimumFractionDigits:2})}`,
            icon: Coins,
            color: "from-emerald-500 to-teal-600 bg-emerald-500/10 text-emerald-400",
            sparkline: (
                <div className="h-10 w-24 shrink-0">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={charts?.dailyRevenue || []}>
                            <defs>
                                <linearGradient id="colorRev" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor="#10b981" stopOpacity={0.4} />
                                    <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <Area type="monotone" dataKey="total" stroke="#10b981" strokeWidth={1.5} fillOpacity={1} fill="url(#colorRev)" dot={false} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )
        },
        {
            title: "RevPAR (30 Days)",
            value: `₱${(stats.revpar || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
            desc: `Avg Room Yield: ₱${(stats.revpar || 0).toLocaleString()}`,
            icon: TrendingUp,
            color: "from-amber-500 to-orange-600 bg-amber-500/10 text-amber-400",
            sparkline: (
                <div className="h-10 w-24 shrink-0">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={revparSparklineData}>
                            <defs>
                                <linearGradient id="colorRevpar" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor="#f59e0b" stopOpacity={0.4} />
                                    <stop offset="95%" stopColor="#f59e0b" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <Area type="monotone" dataKey="revpar" stroke="#f59e0b" strokeWidth={1.5} fillOpacity={1} fill="url(#colorRevpar)" dot={false} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )
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
            name: "Active Bookings Directory",
            desc: "Check-out or extend stays",
            icon: ClipboardList,
            href: route('bookings.index'),
            requiresShift: true,
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
                            className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex items-center justify-between gap-4 group hover:border-[#475569] transition-all duration-300"
                        >
                            <div className="flex-1 flex flex-col gap-1 min-w-0">
                                <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{card.title}</span>
                                <span className="text-2xl font-outfit font-extrabold text-slate-100 mt-1 truncate">{card.value}</span>
                                <span className="text-[11px] text-slate-400 mt-1 font-medium truncate">{card.desc}</span>
                            </div>
                            <div className="flex flex-col items-end gap-2 shrink-0">
                                <div className={`p-2.5 rounded-xl ${card.color.split(' ').slice(2).join(' ')}`}>
                                    <card.icon className="h-5 w-5" />
                                </div>
                                {card.sparkline}
                            </div>
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
                                    href={route('bookings.index')}
                                    className="text-xs font-bold text-brand-400 hover:text-brand-300 flex items-center gap-1"
                                >
                                    View All Stay History <ChevronRight size={14} />
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
                                        <span>{stats.rooms.vacant} / {stats.rooms.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-emerald-500 transition-all duration-500"
                                            style={{ width: `${(stats.rooms.vacant / stats.rooms.total) * 100 || 0}%` }}
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
                                        <span>{stats.rooms.occupied} / {stats.rooms.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-rose-500 transition-all duration-500"
                                            style={{ width: `${(stats.rooms.occupied / stats.rooms.total) * 100 || 0}%` }}
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
                                        <span>{stats.rooms.cleaning} / {stats.rooms.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-amber-500 transition-all duration-500"
                                            style={{ width: `${(stats.rooms.cleaning / stats.rooms.total) * 100 || 0}%` }}
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
                                        <span>{stats.rooms.out_of_order} / {stats.rooms.total}</span>
                                    </div>
                                    <div className="h-2 w-full bg-[#0f172a] rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-slate-500 transition-all duration-500"
                                            style={{ width: `${(stats.rooms.out_of_order / stats.rooms.total) * 100 || 0}%` }}
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
