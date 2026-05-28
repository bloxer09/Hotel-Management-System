import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { 
    Calendar, 
    ChevronLeft, 
    ChevronRight, 
    HelpCircle, 
    FileText, 
    User, 
    Wrench, 
    RefreshCw, 
    CheckCircle2, 
    BedDouble 
} from 'lucide-react';
import { motion } from 'framer-motion';

export default function Analytics({ month, dailyStats, roomsCount, rooms = [], selectedRoomId = null }) {
    // Parse year and month
    const [year, monthNum] = month.split('-').map(Number);

    // Get month name
    const monthDate = new Date(year, monthNum - 1, 1);
    const monthName = monthDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    // Calendar logic
    const firstDayIndex = new Date(year, monthNum - 1, 1).getDay(); // 0 = Sun, 6 = Sat
    const daysInMonth = dailyStats.length;

    // Selected room details
    const selectedRoom = rooms.find(r => Number(r.id) === Number(selectedRoomId));
    const isRoomFiltered = selectedRoom !== undefined && selectedRoom !== null;

    // Calculate month metrics
    const totalOccupiedRoomNights = dailyStats.reduce((sum, s) => sum + s.occupied, 0);
    const totalRoomNights = daysInMonth * roomsCount;
    const avgOccupancyRate = totalRoomNights > 0
        ? Math.round((totalOccupiedRoomNights / totalRoomNights) * 100)
        : 0;

    const highestOccupiedDay = [...dailyStats].sort((a, b) => b.occupied - a.occupied)[0];

    // Single room specific metrics
    const roomOccupiedNights = isRoomFiltered 
        ? dailyStats.filter(s => s.occupied > 0).length 
        : 0;
    const roomOccupancyRate = daysInMonth > 0 
        ? Math.round((roomOccupiedNights / daysInMonth) * 100) 
        : 0;

    // Navigation and filter changes
    const handleMonthChange = (e) => {
        router.get(route('reports.analytics'), { month: e.target.value, room_id: selectedRoomId || '' });
    };

    const handleRoomChange = (e) => {
        router.get(route('reports.analytics'), { month, room_id: e.target.value });
    };

    const navigateMonth = (direction) => {
        const nextMonthDate = new Date(year, monthNum - 1 + direction, 1);
        const nextMonthStr = nextMonthDate.toISOString().substring(0, 7);
        router.get(route('reports.analytics'), { month: nextMonthStr, room_id: selectedRoomId || '' });
    };

    // Weekdays headers
    const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // Assemble grid items
    const gridItems = [];

    // Add empty padding for first week days
    for (let i = 0; i < firstDayIndex; i++) {
        gridItems.push(<div key={`empty-${i}`} className="bg-[#1e293b]/10 border border-[#334155]/20 min-h-[105px] p-2" />);
    }

    // Add days
    dailyStats.forEach((stat) => {
        const isToday = new Date().toDateString() === new Date(stat.date).toDateString();
        
        let statusBlock = null;
        if (isRoomFiltered) {
            if (stat.occupied > 0) {
                statusBlock = (
                    <div className="h-11 mt-2 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-300 p-1.5 flex items-center gap-1.5 overflow-hidden">
                        <User size={12} className="shrink-0 text-rose-400" />
                        <div className="text-[9px] font-bold truncate leading-tight uppercase tracking-wider">
                            {stat.guest_name || 'Occupied'}
                        </div>
                    </div>
                );
            } else if (stat.reserved > 0) {
                statusBlock = (
                    <div className="h-11 mt-2 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-indigo-300 p-1.5 flex items-center gap-1.5 overflow-hidden">
                        <Calendar size={12} className="shrink-0 text-indigo-400" />
                        <div className="text-[9px] font-bold truncate leading-tight uppercase tracking-wider">
                            Res: {stat.guest_name || 'Reserved'}
                        </div>
                    </div>
                );
            } else if (stat.out_of_order > 0) {
                statusBlock = (
                    <div className="h-11 mt-2 rounded-lg bg-slate-500/10 border border-slate-500/20 text-slate-400 p-1.5 flex items-center gap-1.5 overflow-hidden">
                        <Wrench size={12} className="shrink-0 text-slate-500" />
                        <div className="text-[9px] font-bold truncate leading-tight uppercase tracking-wider">
                            {stat.ticket_title || 'OOO Logged'}
                        </div>
                    </div>
                );
            } else if (stat.cleaning > 0) {
                statusBlock = (
                    <div className="h-11 mt-2 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-300 p-1.5 flex items-center gap-1.5 overflow-hidden">
                        <RefreshCw size={12} className="shrink-0 text-amber-400 animate-spin" />
                        <div className="text-[9px] font-bold truncate leading-tight uppercase tracking-wider">
                            Cleaning
                        </div>
                    </div>
                );
            } else {
                statusBlock = (
                    <div className="h-11 mt-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 p-1.5 flex items-center gap-1.5 overflow-hidden">
                        <CheckCircle2 size={12} className="shrink-0 text-emerald-400" />
                        <div className="text-[9px] font-bold truncate leading-tight uppercase tracking-wider">
                            Available
                        </div>
                    </div>
                );
            }
        } else {
            statusBlock = (
                <div className="space-y-1 mt-2 shrink-0">
                    {/* Occupied */}
                    <div className="flex justify-between text-[10px] leading-tight font-medium text-rose-400">
                        <span className="flex items-center gap-1">
                            <span className="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />
                            Occ
                        </span>
                        <span className="font-mono font-bold">{stat.occupied}</span>
                    </div>

                    {/* Reserved */}
                    {stat.reserved > 0 && (
                        <div className="flex justify-between text-[10px] leading-tight font-medium text-indigo-400">
                            <span className="flex items-center gap-1">
                                <span className="w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0" />
                                Res
                            </span>
                            <span className="font-mono font-bold">{stat.reserved}</span>
                        </div>
                    )}

                    {/* Vacant */}
                    <div className="flex justify-between text-[10px] leading-tight font-medium text-emerald-400">
                        <span className="flex items-center gap-1">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" />
                            Vac
                        </span>
                        <span className="font-mono font-bold">{stat.vacant}</span>
                    </div>

                    {/* Cleaning */}
                    {stat.cleaning > 0 && (
                        <div className="flex justify-between text-[10px] leading-tight font-medium text-amber-400">
                            <span className="flex items-center gap-1">
                                <span className="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0" />
                                Cln
                            </span>
                            <span className="font-mono font-bold">{stat.cleaning}</span>
                        </div>
                    )}

                    {/* Out of Order */}
                    {stat.out_of_order > 0 && (
                        <div className="flex justify-between text-[10px] leading-tight font-medium text-slate-400">
                            <span className="flex items-center gap-1">
                                <span className="w-1.5 h-1.5 rounded-full bg-slate-500 shrink-0" />
                                OOO
                            </span>
                            <span className="font-mono font-bold">{stat.out_of_order}</span>
                        </div>
                    )}
                </div>
            );
        }

        gridItems.push(
            <div
                key={stat.date}
                className={`min-h-[105px] border border-[#334155]/60 p-2 flex flex-col justify-between transition-all hover:bg-[#1e293b]/40 rounded-xl ${
                    isToday ? 'bg-brand-500/10 border-brand-500 shadow-inner' : 'bg-[#1e293b]/30'
                }`}
            >
                <div className="flex justify-between items-start shrink-0">
                    <span className={`font-mono text-xs font-bold leading-none w-5 h-5 rounded-full flex items-center justify-center ${
                        isToday ? 'bg-brand-500 text-slate-50' : 'text-slate-400'
                    }`}>
                        {stat.day}
                    </span>
                    {isToday && <span className="text-[8px] bg-brand-500/20 text-brand-400 px-1.5 py-0.5 rounded font-bold uppercase">Today</span>}
                </div>

                {statusBlock}
            </div>
        );
    });

    return (
        <AuthenticatedLayout>
            <Head title="Room Availability Calendar" />

            <div className="flex flex-col gap-6">
                {/* Header title */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 shrink-0">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Room Availability Calendar
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">
                            {isRoomFiltered 
                                ? `Tracking availability schedule and reservations for Room ${selectedRoom.room_number}.`
                                : "Track monthly occupancy distributions and daily occupied, vacant, cleaning, or maintenance states."
                            }
                        </p>
                    </div>
                </div>

                {/* Adaptive KPI Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Monthly Avg Occupancy / Single Room occupancy rate */}
                    <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex items-center justify-between">
                        <div className="flex flex-col gap-0.5">
                            <span className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                {isRoomFiltered ? 'Room Monthly Occupancy' : 'Avg Month Occupancy'}
                            </span>
                            <span className="text-2xl font-outfit font-black text-slate-100 mt-1">
                                {isRoomFiltered ? `${roomOccupancyRate}%` : `${avgOccupancyRate}%`}
                            </span>
                        </div>
                        <div className="p-3 bg-brand-500/10 text-brand-400 rounded-xl flex items-center justify-center">
                            <Calendar size={18} />
                        </div>
                    </div>

                    {/* Peak occupancy day / Current Live Status */}
                    <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex items-center justify-between">
                        <div className="flex flex-col gap-0.5">
                            <span className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                {isRoomFiltered ? 'Current Live Status' : 'Peak Occupancy Day'}
                            </span>
                            <span className={`text-base font-outfit font-black mt-1 uppercase ${
                                isRoomFiltered 
                                    ? (selectedRoom.status === 'vacant' ? 'text-emerald-400' :
                                       selectedRoom.status === 'occupied' ? 'text-rose-400' :
                                       selectedRoom.status === 'cleaning' ? 'text-amber-400' : 'text-slate-400')
                                    : 'text-slate-100'
                            }`}>
                                {isRoomFiltered ? (
                                    selectedRoom.status
                                ) : (
                                    highestOccupiedDay ? `Day ${highestOccupiedDay.day} (${highestOccupiedDay.occupied} rooms)` : 'N/A'
                                )}
                            </span>
                        </div>
                        <div className={`p-3 rounded-xl flex items-center justify-center ${
                            isRoomFiltered 
                                ? (selectedRoom.status === 'vacant' ? 'bg-emerald-500/10 text-emerald-400' :
                                   selectedRoom.status === 'occupied' ? 'bg-rose-500/10 text-rose-400' :
                                   selectedRoom.status === 'cleaning' ? 'bg-amber-500/10 text-amber-400' : 'bg-slate-500/10 text-slate-400')
                                : 'bg-rose-500/10 text-rose-400'
                        }`}>
                            {isRoomFiltered ? <BedDouble size={18} /> : <FileText size={18} />}
                        </div>
                    </div>

                    {/* Total capacity / Nights Booked */}
                    <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex items-center justify-between">
                        <div className="flex flex-col gap-0.5">
                            <span className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                {isRoomFiltered ? 'Monthly Booked Nights' : 'Total Rooms Capacity'}
                            </span>
                            <span className="text-2xl font-outfit font-black text-slate-100 mt-1">
                                {isRoomFiltered ? `${roomOccupiedNights} Nights` : `${roomsCount} Rooms`}
                            </span>
                        </div>
                        <div className="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl flex items-center justify-center">
                            <HelpCircle size={18} />
                        </div>
                    </div>
                </div>

                {/* Calendar Card Grid */}
                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-6">
                    <div className="flex flex-col lg:flex-row justify-between lg:items-center gap-4 border-b border-[#334155] pb-4">
                        <h2 className="font-outfit font-extrabold text-slate-100 text-lg">
                            {monthName} Availability Grid
                        </h2>

                        <div className="flex items-center gap-3 flex-wrap">
                            {/* Selector Room Filter Dropdown */}
                            <div className="flex items-center gap-2 bg-[#0f172a]/60 border border-[#334155]/40 p-1.5 rounded-xl">
                                <span className="text-[10px] uppercase font-bold text-slate-500 pl-2">Filter Room:</span>
                                <select
                                    value={selectedRoomId || ''}
                                    onChange={handleRoomChange}
                                    className="bg-[#1e293b] border border-[#334155] rounded-lg text-xs text-slate-200 px-3 py-1.5 focus:outline-none focus:border-brand-500 font-medium"
                                >
                                    <option value="">All Rooms (Aggregated)</option>
                                    {rooms.map(r => (
                                        <option key={r.id} value={r.id}>
                                            Room {r.room_number} ({r.status})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Month Picker Controls */}
                            <div className="flex items-center gap-2 bg-[#0f172a]/60 border border-[#334155]/40 p-1.5 rounded-xl">
                                <button
                                    onClick={() => navigateMonth(-1)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-[#1e293b]/60"
                                >
                                    <ChevronLeft size={16} />
                                </button>

                                <input
                                    type="month"
                                    value={month}
                                    onChange={handleMonthChange}
                                    className="bg-[#1e293b] border border-[#334155] rounded-lg text-xs text-slate-100 px-3 py-1.5 focus:outline-none focus:border-brand-500 font-mono"
                                />

                                <button
                                    onClick={() => navigateMonth(1)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-[#1e293b]/60"
                                >
                                    <ChevronRight size={16} />
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col">
                        {/* Weekdays table headers */}
                        <div className="grid grid-cols-7 gap-1 text-center mb-1 shrink-0">
                            {weekdays.map(d => (
                                <div key={d} className="text-[10px] uppercase font-bold text-slate-500 py-2">
                                    {d}
                                </div>
                            ))}
                        </div>

                        {/* Calendar Month Grid Cells */}
                        <div className="grid grid-cols-7 gap-2 bg-[#0f172a]/20 p-2 rounded-2xl">
                            {gridItems}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
