import React from 'react';
import { Search, Calendar, Plus } from 'lucide-react';

const FILTER_TABS = [
    { key: 'all', label: 'All Bookings', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: 'reserved', label: 'Pending', color: 'text-indigo-400', dot: 'bg-indigo-400' },
    { key: 'active', label: 'Checked In', color: 'text-emerald-400', dot: 'bg-emerald-400' },
    { key: 'checked_out', label: 'Completed', color: 'text-slate-400', dot: 'bg-slate-400' },
    { key: 'cancelled', label: 'Cancelled', color: 'text-rose-400', dot: 'bg-rose-400' },
    { key: 'no_show', label: 'No Show', color: 'text-amber-500', dot: 'bg-amber-500' },
];

export default function ReservationFilterBar({
    currentFilter,
    onFilterChange,
    searchTerm,
    onSearchChange,
    showGroupsOnly,
    onToggleGroupsOnly,
    bookingView,
    onViewChange,
    onOpenNewBookingModal,
}) {
    return (
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div className="flex items-center gap-2 overflow-x-auto pb-2 md:pb-0 scrollbar-none">
                {FILTER_TABS.map((tab) => {
                    const isActive = currentFilter === tab.key;
                    return (
                        <button
                            key={tab.key}
                            onClick={() => onFilterChange(tab.key)}
                            className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors whitespace-nowrap ${
                                isActive
                                    ? 'bg-slate-800 text-slate-100 border border-slate-700 shadow-sm'
                                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
                            }`}
                        >
                            <span className={`w-2 h-2 rounded-full ${tab.dot}`} />
                            {tab.label}
                        </button>
                    );
                })}
            </div>

            <div className="flex items-center gap-3">
                <div className="relative flex-1 md:w-64">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    <input
                        type="text"
                        value={searchTerm}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Search reservations..."
                        className="w-full pl-9 pr-3 py-1.5 bg-slate-900/80 border border-slate-800 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-brand-500 transition-colors"
                    />
                </div>

                <div className="flex items-center bg-slate-900 border border-slate-800 rounded-lg p-1">
                    <button
                        onClick={() => onViewChange('list')}
                        className={`px-3 py-1 text-xs font-medium rounded-md transition-colors ${
                            bookingView === 'list'
                                ? 'bg-slate-800 text-slate-100 shadow-sm'
                                : 'text-slate-400 hover:text-slate-200'
                        }`}
                    >
                        List
                    </button>
                    <button
                        onClick={() => onViewChange('calendar')}
                        className={`flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-md transition-colors ${
                            bookingView === 'calendar'
                                ? 'bg-slate-800 text-slate-100 shadow-sm'
                                : 'text-slate-400 hover:text-slate-200'
                        }`}
                    >
                        <Calendar className="w-3.5 h-3.5" />
                        Calendar
                    </button>
                </div>

                <button
                    onClick={onOpenNewBookingModal}
                    className="flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-sm font-semibold rounded-lg shadow-sm transition-colors"
                >
                    <Plus className="w-4 h-4" />
                    New Booking
                </button>
            </div>
        </div>
    );
}
