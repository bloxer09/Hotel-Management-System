import React from 'react';
import { Eye, Edit, UserCheck, XCircle, CheckCircle, Clock } from 'lucide-react';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';
import Badge from '@/Components/UI/Badge';
import Button from '@/Components/UI/Button';
import { formatPHP } from '@/Utils/currency';
import { formatHotelDateTime } from '@/Utils/datetime';

export default function ReservationTable({
    reservations,
    sortBy,
    sortDir,
    onSort,
    onViewStay,
    onOpenActionModal,
    onCheckIn,
    onCancel,
}) {
    if (!reservations || !reservations.data || reservations.data.length === 0) {
        return (
            <div className="bg-slate-900 border border-slate-800 rounded-2xl p-12 text-center">
                <Clock className="w-12 h-12 text-slate-600 mx-auto mb-3" />
                <h3 className="text-base font-semibold text-slate-200">No Reservations Found</h3>
                <p className="text-sm text-slate-500 mt-1">There are no bookings matching your current filter criteria.</p>
            </div>
        );
    }

    return (
        <div className="bg-slate-900 border border-slate-800 rounded-2xl shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm text-slate-300">
                    <thead className="bg-slate-950 border-b border-slate-800 text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        <tr>
                            <SortableHeader label="ID / Ref" sortKey="id" currentSort={sortBy} currentDir={sortDir} onSort={onSort} />
                            <SortableHeader label="Guest Name" sortKey="guest_name" currentSort={sortBy} currentDir={sortDir} onSort={onSort} />
                            <th className="px-4 py-3">Room</th>
                            <SortableHeader label="Check In" sortKey="check_in_time" currentSort={sortBy} currentDir={sortDir} onSort={onSort} />
                            <SortableHeader label="Expected Check Out" sortKey="expected_check_out" currentSort={sortBy} currentDir={sortDir} onSort={onSort} />
                            <SortableHeader label="Total Amount" sortKey="amount" currentSort={sortBy} currentDir={sortDir} onSort={onSort} />
                            <SortableHeader label="Status" sortKey="status" currentSort={sortBy} currentDir={sortDir} onSort={onSort} />
                            <th className="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/60">
                        {reservations.data.map((res) => (
                            <tr key={res.id} className="hover:bg-slate-800/40 transition-colors">
                                <td className="px-4 py-3.5 font-mono text-xs font-semibold text-slate-200">
                                    {res.booking_ref || `#${res.id}`}
                                </td>
                                <td className="px-4 py-3.5 font-semibold text-slate-100">
                                    {res.guest_name}
                                </td>
                                <td className="px-4 py-3.5 text-slate-300">
                                    {res.room ? (
                                        <span className="font-semibold text-brand-400">
                                            Room {res.room.room_number} <span className="text-xs text-slate-500">({res.room.type?.type_name})</span>
                                        </span>
                                    ) : (
                                        <span className="text-slate-500 italic">Unassigned</span>
                                    )}
                                </td>
                                <td className="px-4 py-3.5 text-xs text-slate-400">
                                    {formatHotelDateTime(res.check_in)}
                                </td>
                                <td className="px-4 py-3.5 text-xs text-slate-400">
                                    {formatHotelDateTime(res.expected_check_out)}
                                </td>
                                <td className="px-4 py-3.5 font-semibold text-slate-100">
                                    {formatPHP(res.total_amount)}
                                </td>
                                <td className="px-4 py-3.5">
                                    <Badge variant={res.status}>
                                        {res.status.replace('_', ' ').toUpperCase()}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3.5 text-right">
                                    <div className="flex items-center justify-end gap-1.5">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onViewStay(res.id)}
                                            icon={Eye}
                                        >
                                            View
                                        </Button>
                                        {res.status === 'reserved' && (
                                            <>
                                                <Button
                                                    variant="emerald"
                                                    size="sm"
                                                    onClick={() => onCheckIn(res)}
                                                    icon={UserCheck}
                                                >
                                                    Check-In
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => onCancel(res)}
                                                    className="text-rose-400 hover:text-rose-300"
                                                    icon={XCircle}
                                                >
                                                    Cancel
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {reservations.links && (
                <div className="p-4 border-t border-slate-800">
                    <Pagination links={reservations.links} />
                </div>
            )}
        </div>
    );
}
