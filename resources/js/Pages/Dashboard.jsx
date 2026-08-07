import React, { useState, useMemo } from 'react';
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
    ChevronDown,
    CalendarPlus,
    LogIn,
    LogOut,
    BedDouble,
    CreditCard,
    Wrench,
    CircleAlert
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

export default function Dashboard({ stats, charts, recentBookings, lowStockItems, activeShift, liveUpdates = [], upcomingCheckins = [], upcomingCheckouts = [], recentExpenses = [], todayArrivals = [] }) {
    const [revenuePeriod, setRevenuePeriod] = useState('today');
    const [upcomingTab, setUpcomingTab] = useState('checkins');
    const [chartTab, setChartTab] = useState('revenue'); // 'revenue' | 'payments' | 'occupancy'
    const [chartPeriod, setChartPeriod] = useState(30); // 7 | 14 | 30

    const filteredRevenueData = useMemo(() => charts?.dailyRevenue?.slice(-chartPeriod) || [], [charts, chartPeriod]);
    const filteredOccupancyData = useMemo(() => charts?.dailyOccupancy?.slice(-chartPeriod) || [], [charts, chartPeriod]);

    const roomStats = stats?.rooms || { total: 0, occupied: 0, vacant: 0, cleaning: 0, out_of_order: 0, sellable: 0, occupancy_rate: 0 };
    const operations = stats?.operations || { arrivals_today: 0, departures_today: 0, in_house: 0, rooms_to_clean: 0, available_tonight: 0 };

    // Math indicators
    const currentRev = stats?.revenue_periods?.[revenuePeriod] || stats?.revenue || { total: 0, room: 0, product: 0, label: "Income Breakdown" };
    const updates = liveUpdates || [];

    const lodgingUpdates = updates.filter(u => ['check_in', 'overdue_checkout', 'checkout'].includes(u.type));
    const housekeepingUpdates = updates.filter(u => u.type === 'cleaning');
    const maintenanceUpdates = updates.filter(u => u.type === 'maintenance');
    const actionStatusStyles = {
        critical: "border-red-500/25 bg-red-950/15 text-red-300 hover:border-red-500/45",
        warning: "border-amber-500/25 bg-amber-950/15 text-amber-300 hover:border-amber-500/45",
        pending: "border-indigo-500/25 bg-indigo-950/15 text-indigo-300 hover:border-indigo-500/45",
        info: "border-[#334155] bg-[#0f172a]/35 text-slate-300 hover:border-brand-500/35",
    };
    const actionStatusLabels = { critical: "Critical", warning: "Attention", pending: "Pending", info: "Info" };

    const operationalCards = [
        {
            title: "Arrivals Today",
            value: operations.arrivals_today || 0,
            desc: "Expected guest check-ins",
            icon: LogIn,
            accent: "text-indigo-300 bg-indigo-500/15 border-indigo-500/25",
            href: `${route('reservations.index')}?status=reserved&date_scope=arrivals_today`,
        },
        {
            title: "Departures Today",
            value: operations.departures_today || 0,
            desc: "Scheduled guest check-outs",
            icon: LogOut,
            accent: "text-amber-300 bg-amber-500/15 border-amber-500/25",
            href: `${route('checkin.index')}?status=active&date_scope=departures_today`,
        },
        {
            title: "In-House",
            value: operations.in_house || 0,
            desc: "Active guest stays now",
            icon: Users2,
            accent: "text-emerald-300 bg-emerald-500/15 border-emerald-500/25",
        },
        {
            title: "Occupancy",
            value: `${roomStats.occupancy_rate || 0}%`,
            desc: `${roomStats.occupied || 0} of ${(roomStats.sellable ?? roomStats.total) || 0} sellable rooms`,
            icon: BedDouble,
            accent: "text-brand-300 bg-brand-500/15 border-brand-500/25",
        },
        {
            title: "Rooms to Clean",
            value: operations.rooms_to_clean || 0,
            desc: "Housekeeping action needed",
            icon: ClipboardList,
            accent: "text-cyan-300 bg-cyan-500/15 border-cyan-500/25",
        },
    ];

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
            name: "New Reservation",
            desc: "Book a future stay",
            icon: CalendarPlus,
            href: route('reservations.index'),
            requiresShift: false,
            color: "bg-indigo-500/15 border-indigo-500/30 text-indigo-300 hover:bg-indigo-500/25"
        },
        {
            name: "Walk-in Check-In",
            desc: "Register a walk-in guest",
            icon: LogIn,
            href: route('checkin.index'),
            requiresShift: true,
            color: "bg-brand-500/20 border-brand-500/30 text-brand-300 hover:bg-brand-500/30"
        },
        {
            name: "Check-Out Guest",
            desc: "Settle an active stay",
            icon: LogOut,
            href: route('reservations.index') + '?status=active',
            requiresShift: true,
            color: "bg-amber-500/15 border-amber-500/30 text-amber-300 hover:bg-amber-500/25"
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
            name: "POS / Room Charge",
            desc: "Record a sale or room charge",
            icon: CreditCard,
            href: route('pos.index'),
            requiresShift: true,
            color: "bg-emerald-500/15 border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/25"
        },
        {
            name: activeShift ? "Manage Active Shift" : "Open Shift",
            desc: activeShift ? "Review or close your register" : "Start a register session",
            icon: activeShift ? ClipboardList : Plus,
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

            <div className="dashboard-page flex flex-col gap-8 lg:gap-10">

                {/* Header Welcome Title */}
                <div className="flex flex-col gap-3 md:flex-row md:justify-between md:items-center">
                    <div>
                        <h1 className="page-title text-3xl font-outfit font-extrabold tracking-tight bg-gradient-to-r from-slate-50 via-slate-100 to-brand-300 bg-clip-text text-transparent">
                            Front Desk Dashboard
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Start with today’s arrivals, departures, room readiness, and urgent hotel tasks.</p>
                    </div>

                    <div className="flex flex-col gap-1">
                        <span className="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Financial period</span>
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
                </div>

                {/* KPI Cards Grid */}
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
                    {operationalCards.map((card, idx) => {
                        const Card = card.href ? motion(Link) : motion.div;

                        return (
                        <Card
                            key={card.title}
                            {...(card.href ? { href: card.href, 'aria-label': `View ${card.title.toLowerCase()}` } : {})}
                            initial={{ opacity: 0, y: 15 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: idx * 0.1 }}
                            className={`dashboard-kpi p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col justify-between min-h-[126px]${card.href ? ' cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a]' : ''}`}
                        >
                            <div>
                                <div className="flex items-center justify-between mb-3">
                                    <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{card.title}</span>
                                    <span className={`p-2 rounded-lg border ${card.accent}`}>
                                        <card.icon size={16} />
                                    </span>
                                </div>

                                <div className="font-mono font-black text-2xl tracking-tight text-slate-100">{card.value}</div>
                            </div>
                            <div className="text-[10px] text-slate-500 mt-2">{card.desc}</div>
                        </Card>
                        );
                    })}
                </div>

                {/* Front-desk workspace: immediate actions and the single prioritized task queue. */}
                <div className="flex flex-col gap-6">
                    <section className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                            <div>
                                <h2 className="text-lg font-outfit font-bold tracking-tight text-slate-200">Quick Actions</h2>
                                <p className="text-xs text-slate-400 mt-1">Common front-desk tasks in one place.</p>
                            </div>
                            <Link href={route('guests.index')} className="text-[10px] font-bold text-brand-400 hover:text-brand-300 uppercase tracking-widest flex items-center gap-1">
                                Guest Directory <ChevronRight size={12} />
                            </Link>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                            {quickActions.map(action => {
                                const isLocked = action.requiresShift && !activeShift;
                                const linkHref = isLocked ? route('shifts.index') : action.href;

                                return (
                                    <Link key={action.name} href={linkHref} className={`p-4 rounded-xl border flex items-center gap-3 transition-all group ${action.color}`}>
                                        <div className="p-2.5 bg-[#0f172a]/50 rounded-lg group-hover:scale-105 transition-transform shrink-0">
                                            <action.icon size={19} />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="font-outfit font-extrabold text-sm flex items-center gap-1.5">
                                                {action.name}
                                                {isLocked && <Lock size={12} className="text-amber-400" />}
                                            </div>
                                            <div className="text-[11px] text-slate-400 mt-0.5 truncate">{isLocked ? "Open a shift to continue" : action.desc}</div>
                                        </div>
                                        <ChevronRight size={15} className="text-slate-400 group-hover:translate-x-1 transition-transform shrink-0" />
                                    </Link>
                                );
                            })}
                        </div>
                    </section>

                    <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
                        <section className="xl:col-span-2 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                            <div className="flex items-center justify-between gap-4 border-b border-[#334155] pb-4">
                                <div className="flex items-center gap-2">
                                    <CircleAlert size={18} className="text-brand-400" />
                                    <div>
                                        <h3 className="font-outfit font-bold text-slate-200 text-sm uppercase tracking-wider">Action Center</h3>
                                        <p className="text-[11px] text-slate-400 mt-0.5">Prioritized arrivals, departures, housekeeping, and repairs.</p>
                                    </div>
                                </div>
                                <span className="px-2 py-1 rounded-md text-[10px] font-black bg-brand-500/15 text-brand-300 border border-brand-500/25">
                                    {updates.length} OPEN
                                </span>
                            </div>

                            <div className="flex flex-col gap-2 max-h-[320px] overflow-y-auto pr-1">
                                {updates.length > 0 ? updates.slice(0, 8).map(update => (
                                    <Link key={`${update.type}-${update.id}`} href={update.link} className={`p-3.5 rounded-xl border flex items-start gap-3 transition-all ${actionStatusStyles[update.status] || actionStatusStyles.info}`}>
                                        <span className="mt-0.5 w-2 h-2 rounded-full bg-current shrink-0" />
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-start justify-between gap-3">
                                                <span className="font-outfit font-extrabold text-slate-100 text-xs leading-snug">{update.title}</span>
                                                <span className="text-[8px] font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-[#0f172a]/75 shrink-0">{actionStatusLabels[update.status] || "Info"}</span>
                                            </div>
                                            <p className="text-[10px] text-slate-400 leading-normal mt-0.5">{update.description}</p>
                                        </div>
                                    </Link>
                                )) : (
                                    <div className="p-8 rounded-xl border border-dashed border-[#334155] text-center flex flex-col gap-1 items-center justify-center">
                                        <CheckCircle2 size={20} className="text-emerald-400" />
                                        <span className="font-bold text-slate-300 text-xs font-outfit uppercase">All clear</span>
                                        <span className="text-[10px] text-slate-500">No priority front-desk, housekeeping, or repair tasks.</span>
                                    </div>
                                )}
                            </div>
                        </section>

                        <aside className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5">
                            <div>
                                <h3 className="font-outfit font-bold text-slate-200 text-sm uppercase tracking-wider">Room Status</h3>
                                <p className="text-[11px] text-slate-400 mt-1">Live room readiness overview.</p>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                {[
                                    { label: 'Vacant', value: roomStats.vacant || 0, color: 'text-emerald-300' },
                                    { label: 'Occupied', value: roomStats.occupied || 0, color: 'text-indigo-300' },
                                    { label: 'Cleaning', value: roomStats.cleaning || 0, color: 'text-cyan-300' },
                                    { label: 'Out of Order', value: roomStats.out_of_order || 0, color: 'text-rose-300' },
                                ].map(room => (
                                    <Link key={room.label} href={route('rooms.index')} className="p-3 rounded-xl border border-[#334155] bg-[#0f172a]/35 hover:border-brand-500/35 transition-colors">
                                        <span className="text-[10px] text-slate-400 uppercase tracking-wide">{room.label}</span>
                                        <div className={`font-mono font-black text-xl mt-1 ${room.color}`}>{room.value}</div>
                                    </Link>
                                ))}
                            </div>
                            <div className="pt-4 border-t border-[#334155]">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <span className="text-[10px] text-slate-400 uppercase tracking-wide">Today at a Glance</span>
                                        <div className="text-[11px] text-slate-300 font-semibold mt-1">{operations.available_tonight || 0} rooms available tonight</div>
                                    </div>
                                    <Link href={route('reservations.index') + '?view=calendar'} className="text-[10px] font-bold text-brand-400 hover:text-brand-300 uppercase tracking-widest flex items-center gap-1">
                                        Calendar <ChevronRight size={12} />
                                    </Link>
                                </div>
                                <div className="mt-3 flex flex-col gap-1.5">
                                    {todayArrivals.length > 0 ? todayArrivals.slice(0, 3).map(booking => (
                                        <Link key={booking.id} href={route('reservations.index') + '?view=calendar'} className="flex items-center justify-between gap-2 text-[10px] text-slate-400 hover:text-slate-200 transition-colors">
                                            <span className="truncate"><span className="font-mono text-indigo-300">{new Date(String(booking.check_in).replace(' ', 'T')).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span> · Room {booking.room?.room_number || '—'} · {booking.guest_name}</span>
                                        </Link>
                                    )) : <span className="text-[10px] text-slate-500">No booked arrivals today.</span>}
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>

                {/* Visual Analytics Section */}
                <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
                    {/* Charts Panel (2/3 width) */}
                    <div className="xl:col-span-2 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-6">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div>
                                <h3 className="font-outfit font-extrabold text-base text-slate-100 uppercase tracking-wider">Operational Trends</h3>
                                <p className="text-[11px] text-slate-450 mt-0.5">Visualize occupancy rates and financial collection channels over time.</p>
                            </div>
                            
                            {/* Chart Controls */}
                            <div className="flex flex-wrap gap-2 items-center">
                                {/* Tab Select */}
                                <div className="flex bg-[#0f172a] p-0.5 rounded-lg border border-[#334155] text-[10px] font-black uppercase shadow-inner">
                                    {[
                                        { key: 'revenue', label: 'Revenue' },
                                        { key: 'payments', label: 'Payments' },
                                        { key: 'occupancy', label: 'Occupancy' }
                                    ].map(tab => (
                                        <button
                                            key={tab.key}
                                            type="button"
                                            onClick={() => setChartTab(tab.key)}
                                            className={`px-3 py-1.5 rounded transition-all ${chartTab === tab.key
                                                ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/60'
                                                : 'text-slate-400 hover:text-slate-200'
                                                }`}
                                        >
                                            {tab.label}
                                        </button>
                                    ))}
                                </div>
                                
                                {/* Period Select */}
                                <div className="flex bg-[#0f172a] p-0.5 rounded-lg border border-[#334155] text-[10px] font-black uppercase shadow-inner">
                                    {[
                                        { key: 7, label: '7D' },
                                        { key: 14, label: '14D' },
                                        { key: 30, label: '30D' }
                                    ].map(per => (
                                        <button
                                            key={per.key}
                                            type="button"
                                            onClick={() => setChartPeriod(per.key)}
                                            className={`px-2.5 py-1.5 rounded transition-all ${chartPeriod === per.key
                                                ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/60'
                                                : 'text-slate-400 hover:text-slate-200'
                                                }`}
                                        >
                                            {per.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Chart Render */}
                        <div className="h-72 w-full">
                            <ResponsiveContainer minWidth={0} width="100%" height="100%">
                                {chartTab === 'revenue' ? (
                                    <AreaChart data={filteredRevenueData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                        <defs>
                                            <linearGradient id="colorRoom" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#6366f1" stopOpacity={0.2}/>
                                                <stop offset="95%" stopColor="#6366f1" stopOpacity={0}/>
                                            </linearGradient>
                                            <linearGradient id="colorProduct" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#10b981" stopOpacity={0.2}/>
                                                <stop offset="95%" stopColor="#10b981" stopOpacity={0}/>
                                            </linearGradient>
                                        </defs>
                                        <XAxis dataKey="date" stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} />
                                        <YAxis stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} tickFormatter={(v) => `₱${v}`} />
                                        <Tooltip content={<CustomTooltip isCurrency={true} />} />
                                        <Legend verticalAlign="top" height={36} iconType="circle" iconSize={8} wrapperStyle={{ fontSize: '11px', color: '#cbd5e1' }} />
                                        <Area type="monotone" name="Lodging Revenue" dataKey="room" stroke="#6366f1" strokeWidth={2} fillOpacity={1} fill="url(#colorRoom)" />
                                        <Area type="monotone" name="Product Revenue" dataKey="product" stroke="#10b981" strokeWidth={2} fillOpacity={1} fill="url(#colorProduct)" />
                                    </AreaChart>
                                ) : chartTab === 'payments' ? (
                                    <BarChart data={filteredRevenueData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                        <XAxis dataKey="date" stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} />
                                        <YAxis stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} tickFormatter={(v) => `₱${v}`} />
                                        <Tooltip content={<CustomTooltip isCurrency={true} />} />
                                        <Legend verticalAlign="top" height={36} iconType="circle" iconSize={8} wrapperStyle={{ fontSize: '11px', color: '#cbd5e1' }} />
                                        <Bar name="Cash" dataKey="cash" stackId="a" fill="#10b981" radius={[0, 0, 0, 0]} />
                                        <Bar name="GCash" dataKey="gcash" stackId="a" fill="#6366f1" radius={[0, 0, 0, 0]} />
                                        <Bar name="Bank Transfer" dataKey="bank_transfer" stackId="a" fill="#f59e0b" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                ) : (
                                    <AreaChart data={filteredOccupancyData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                        <defs>
                                            <linearGradient id="colorOcc" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#3b82f6" stopOpacity={0.2}/>
                                                <stop offset="95%" stopColor="#3b82f6" stopOpacity={0}/>
                                            </linearGradient>
                                        </defs>
                                        <XAxis dataKey="date" stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} />
                                        <YAxis stroke="#64748b" fontSize={10} tickLine={false} axisLine={false} tickFormatter={(v) => `${v}%`} />
                                        <Tooltip content={<CustomTooltip isCurrency={false} />} />
                                        <Legend verticalAlign="top" height={36} iconType="circle" iconSize={8} wrapperStyle={{ fontSize: '11px', color: '#cbd5e1' }} />
                                        <Area type="monotone" name="Occupancy Rate" dataKey="occupancy_rate" stroke="#3b82f6" strokeWidth={2} fillOpacity={1} fill="url(#colorOcc)" />
                                    </AreaChart>
                                )}
                            </ResponsiveContainer>
                        </div>
                    </div>

                    {/* Room Type Revenue Pie Chart (1/3 width) */}
                    <div className="xl:col-span-1 p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-6">
                        <div>
                            <h3 className="font-outfit font-extrabold text-base text-slate-100 uppercase tracking-wider">Revenue by Room Class</h3>
                            <p className="text-[11px] text-slate-450 mt-0.5">30-day billing contributions grouped by class.</p>
                        </div>

                        <div className="h-56 w-full relative flex items-center justify-center">
                            <ResponsiveContainer minWidth={0} width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={charts?.roomTypeRevenue || []}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={60}
                                        outerRadius={80}
                                        paddingAngle={4}
                                        dataKey="value"
                                    >
                                        {(charts?.roomTypeRevenue || []).map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                        ))}
                                    </Pie>
                                    <Tooltip formatter={(value) => `₱${Number(value).toLocaleString()}`} />
                                </PieChart>
                            </ResponsiveContainer>
                            
                            {/* Center absolute indicator */}
                            <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none mt-2">
                                <span className="text-[9px] uppercase font-bold text-slate-450 tracking-wider">Total Sales</span>
                                <span className="font-outfit font-black text-sm text-slate-200 mt-0.5">
                                    ₱{(charts?.roomTypeRevenue || []).reduce((sum, item) => sum + Number(item.value || 0), 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}
                                </span>
                            </div>
                        </div>

                        {/* Room Type Legend List */}
                        <div className="flex flex-col gap-2 overflow-y-auto max-h-[100px] scrollbar-thin">
                            {(charts?.roomTypeRevenue || []).map((entry, index) => {
                                const total = (charts?.roomTypeRevenue || []).reduce((sum, item) => sum + Number(item.value || 0), 0);
                                const pct = total > 0 ? ((entry.value / total) * 100).toFixed(1) : 0;
                                return (
                                    <div key={entry.name} className="flex items-center justify-between text-[11px]">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: COLORS[index % COLORS.length] }} />
                                            <span className="font-semibold text-slate-300 truncate">{entry.name}</span>
                                        </div>
                                        <span className="font-mono text-slate-400 shrink-0 font-bold ml-2">₱{Number(entry.value).toLocaleString()} ({pct}%)</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Main Content Sections */}
                <div className="flex flex-col gap-10">

                    {false && (
                    /* Legacy quick-action panel kept out of the render tree. */
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
                    )}

                    {false && (
                    <div className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">

                            {/* Stays Live Feed */}
                            <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-outfit font-bold text-slate-200 flex items-center gap-2 uppercase tracking-wide">
                                        Stays Feed
                                        <span className="relative flex h-2 w-2">
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

                    )}

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
                                    <table className="mobile-table-fit w-full text-xs table-fixed">
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
                                    <table className="mobile-table-fit w-full text-xs table-fixed">
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
                                <table className="mobile-table-fit w-full text-xs table-fixed">
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
