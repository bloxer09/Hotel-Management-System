import React, { useState, useMemo, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage, router } from '@inertiajs/react';
import {
    Filter,
    Layers,
    Home,
    X,
    Settings2,
    User,
    ArrowUpRight,
    BedDouble,
    Plus,
    Edit,
    Trash2,
    CheckCheck,
    AlertTriangle,
    Clock,
    Bell,
    ChevronLeft,
    Calendar,
    RefreshCw,
    Shuffle,
    Brush,
    Sparkles,
    ArrowRight,
    Search
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import RoomAvailabilityModal from './RoomAvailabilityModal';
import AlertModal from '@/Components/AlertModal';
import ConfirmModal from '@/Components/ConfirmModal';

const STATUS_LABELS = { vacant: 'Vacant', occupied: 'Occupied', cleaning: 'Cleaning', out_of_order: 'Out of Order' };
const STATUS_COLORS = {
    vacant: 'bg-emerald-950/45 border-emerald-500/30 text-emerald-300 hover:border-emerald-500/60 shadow-emerald-950/20',
    occupied: 'bg-rose-950/45 border-rose-500/30 text-rose-300 hover:border-rose-500/60 shadow-rose-950/20',
    cleaning: 'bg-amber-950/45 border-amber-500/30 text-amber-300 hover:border-amber-500/60 shadow-amber-950/20',
    out_of_order: 'bg-slate-900 border-slate-700 text-slate-400 hover:border-slate-500 shadow-slate-950/20',
};

function formatMinsToReadable(totalMins) {
    if (totalMins < 60) {
        return `${totalMins}m`;
    }
    const hours = Math.floor(totalMins / 60);
    const mins = totalMins % 60;
    if (hours < 24) {
        return `${hours}h ${mins}m`;
    }
    const days = Math.floor(hours / 24);
    const remHours = hours % 24;
    return `${days}d ${remHours}h ${mins}m`;
}

function formatExpectedCheckout(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function getCheckoutAlertState(room) {
    if (room.status !== 'occupied' || !room.active_booking?.expected_check_out) return null;
    const now = Date.now();
    const expectedTs = new Date(room.active_booking.expected_check_out).getTime();
    const diffMs = expectedTs - now;
    if (diffMs < 0) {
        const minsOver = Math.max(1, Math.ceil(Math.abs(diffMs) / 60000));
        return { state: 'overdue', label: `Overdue ${formatMinsToReadable(minsOver)}` };
    }
    if (diffMs <= 5 * 60 * 1000) {
        const minsLeft = Math.max(1, Math.ceil(diffMs / 60000));
        return { state: 'upcoming', label: `${formatMinsToReadable(minsLeft)} left` };
    }
    return null;
}

export default function Board({ rooms, roomTypes, housekeepers = [] }) {
    const { auth } = usePage().props;
    const user = auth.user;
    const isAdmin = user.role === 'admin';
    const isHousekeeping = user.role === 'housekeeping';

    const [currentTime, setCurrentTime] = useState(Date.now());
    useEffect(() => {
        const interval = setInterval(() => setCurrentTime(Date.now()), 10000);
        return () => clearInterval(interval);
    }, []);

    const getCleaningDuration = (startedAt) => {
        if (!startedAt) return 0;
        const start = new Date(startedAt).getTime();
        const diff = currentTime - start;
        return Math.max(0, Math.floor(diff / 60000));
    };

    const formatElapsedMinutes = (totalMins) => {
        if (totalMins < 60) return `${totalMins}m`;
        const days = Math.floor(totalMins / 1440);
        const hours = Math.floor((totalMins % 1440) / 60);
        const mins = totalMins % 60;
        
        let parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (hours > 0 || days > 0) parts.push(`${hours}h`);
        parts.push(`${mins}m`);
        
        return parts.join(' ');
    };

    const getStayProgress = (room) => {
        if (room.status !== 'occupied' || !room.active_booking?.check_in || !room.active_booking?.expected_check_out) return null;
        const start = new Date(room.active_booking.check_in).getTime();
        const end = new Date(room.active_booking.expected_check_out).getTime();
        const total = end - start;
        if (total <= 0) return { pct: 100, minsLeft: 0 };
        const elapsed = currentTime - start;
        const pct = Math.min(100, Math.max(0, (elapsed / total) * 100));
        const minsLeft = Math.max(0, Math.ceil((end - currentTime) / 60000));
        return { pct, minsLeft };
    };

    // QOL Swap Room State
    const [isSwapOpen, setIsSwapOpen] = useState(false);
    const swapForm = useForm({ new_room_id: '', reason: 'AC stopped working, quick room swap' });

    // QOL Stay Extension State
    const [extPreset, setExtPreset] = useState(''); // '3h', '6h', '1n'
    const [extCalc, setExtCalc] = useState(null);
    const [extLoading, setExtLoading] = useState(false);
    const [alertMessage, setAlertMessage] = useState(null);
    const [confirmAction, setConfirmAction] = useState(null);
    
    const extForm = useForm({
        hours: '',
        days: '',
        payment_method: 'cash',
        cash_amount: 0,
        gcash_amount: 0,
        gcash_ref: '',
        reference_number: ''
    });

    // QOL Housekeeper selection form
    const hkForm = useForm({ assigned_housekeeper: '', status: 'cleaning', notes: '' });

    const [selectedRoom, setSelectedRoom] = useState(null);
    const [activeModal, setActiveModal] = useState(null); // 'add', 'edit', 'bulk_clean'
    const [statusFilter, setStatusFilter] = useState('all');
    const [floorFilter, setFloorFilter] = useState('all');
    const [typeFilter, setTypeFilter] = useState('all');
    const [roomSearch, setRoomSearch] = useState('');

    // Bulk clean selection
    const [bulkSelected, setBulkSelected] = useState([]);

    const [isAvailabilityOpen, setIsAvailabilityOpen] = useState(false);
    const [availabilityRoomId, setAvailabilityRoomId] = useState('');

    const statusForm = useForm({ status: '', notes: '' });
    const addForm = useForm({ room_number: '', room_type_id: '', floor: 1, notes: '', photo: null });
    const editForm = useForm({ room_type_id: '', floor: 1, notes: '', perm_ooo: false, status: 'vacant', photo: null, remove_photo: false, _method: 'PUT' });

    const handleRoomClick = (room) => {
        setSelectedRoom(room);
        statusForm.setData({ status: room.status === 'occupied' ? 'vacant' : room.status, notes: room.notes || '' });
        hkForm.setData({
            assigned_housekeeper: room.assigned_housekeeper || '',
            status: room.status,
            notes: room.notes || ''
        });
        setExtPreset('');
        setExtCalc(null);
        swapForm.setData({ new_room_id: '', reason: 'AC stopped working, quick room swap' });
        setIsSwapOpen(false);
    };

    // QOL Housekeeping Status & Cleaner Assignment Submit
    const handleHousekeepingSubmit = (e) => {
        e.preventDefault();
        hkForm.post(route('rooms.status', selectedRoom.id), {
            onSuccess: () => { setSelectedRoom(null); }
        });
    };

    // QOL Swap Room / Guest Transfer Submit
    const handleSwapSubmit = (e) => {
        e.preventDefault();
        if (!swapForm.data.new_room_id) {
            setAlertMessage('Please select a vacant room to swap.');
            return;
        }
        swapForm.post(route('bookings.move', selectedRoom.active_booking.id), {
            onSuccess: () => {
                setSelectedRoom(null);
                setIsSwapOpen(false);
            }
        });
    };

    // QOL Stay Extension Preset Preview fetch
    const handlePresetSelect = async (preset) => {
        setExtPreset(preset);
        let params = {};
        if (preset === '3h') {
            params = { hours: 3 };
            extForm.setData({ hours: 3, days: '', payment_method: 'cash', cash_amount: 0, gcash_amount: 0, gcash_ref: '', reference_number: '' });
        } else if (preset === '6h') {
            params = { hours: 6 };
            extForm.setData({ hours: 6, days: '', payment_method: 'cash', cash_amount: 0, gcash_amount: 0, gcash_ref: '', reference_number: '' });
        } else if (preset === '1n') {
            params = { days: 1 };
            extForm.setData({ days: 1, hours: '', payment_method: 'cash', cash_amount: 0, gcash_amount: 0, gcash_ref: '', reference_number: '' });
        }

        setExtLoading(true);
        try {
            const res = await axios.post(route('bookings.preview_extend', selectedRoom.active_booking.id), params);
            setExtCalc(res.data);
            
            // Set defaults on form data
            extForm.setData(prev => {
                const total = res.data.total_amount;
                return {
                    ...prev,
                    hours: preset === '3h' ? 3 : preset === '6h' ? 6 : '',
                    days: preset === '1n' ? 1 : '',
                    cash_amount: total,
                    gcash_amount: 0,
                    payment_method: 'cash'
                };
            });
        } catch (err) {
            console.error('Failed to preview extension:', err);
            setAlertMessage(err.response?.data?.error || 'Failed to estimate stay extension pricing.');
        } finally {
            setExtLoading(false);
        }
    };

    const handleExtPaymentMethodChange = (method, total) => {
        extForm.setData(prev => {
            if (method === 'cash') {
                return { ...prev, payment_method: method, cash_amount: total, gcash_amount: 0 };
            } else if (method === 'gcash') {
                return { ...prev, payment_method: method, gcash_amount: total, cash_amount: 0 };
            } else if (method === 'split') {
                return { ...prev, payment_method: method, cash_amount: Math.round(total / 2), gcash_amount: total - Math.round(total / 2) };
            } else {
                return { ...prev, payment_method: method, cash_amount: 0, gcash_amount: 0 };
            }
        });
    };

    const handleExtensionSubmit = (e) => {
        e.preventDefault();
        extForm.post(route('bookings.extend', selectedRoom.active_booking.id), {
            onSuccess: () => {
                setSelectedRoom(null);
                setExtPreset('');
                setExtCalc(null);
            }
        });
    };

    const handleAddSubmit = (e) => {
        e.preventDefault();
        addForm.post(route('rooms.store'), {
            onSuccess: () => { setActiveModal(null); addForm.reset(); }
        });
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        // POST to support file uploads with Laravel simulated spoofing
        editForm.post(route('rooms.update', selectedRoom.id), {
            onSuccess: () => { setActiveModal(null); setSelectedRoom(null); editForm.reset(); }
        });
    };

    const handleDelete = (room) => {
        setConfirmAction({
            title: 'Delete Room',
            message: `Delete Room ${room.room_number}? This cannot be undone.`,
            isDanger: true,
            onConfirm: () => {
                router.delete(route('rooms.destroy', room.id), {
                    onSuccess: () => { setActiveModal(null); setSelectedRoom(null); setConfirmAction(null); }
                });
            }
        });
    };

    const openEditModal = (room) => {
        setSelectedRoom(room);
        editForm.setData({
            room_type_id: room.room_type_id,
            floor: room.floor,
            notes: room.notes || '',
            perm_ooo: false,
            status: room.status === 'occupied' ? 'occupied' : room.status,
            photo: null,
            remove_photo: false,
            _method: 'PUT'
        });
        setActiveModal('edit');
    };

    const handleBulkClean = () => {
        if (bulkSelected.length === 0) return;
        router.post(route('rooms.bulkClean'), { room_ids: bulkSelected }, {
            onSuccess: () => { setActiveModal(null); setBulkSelected([]); }
        });
    };

    const cleaningRooms = rooms.filter(r => r.status === 'cleaning');

    const filteredRooms = useMemo(() => rooms.filter(room => {
        const matchesStatus = statusFilter === 'all' || room.status === statusFilter;
        const matchesFloor = floorFilter === 'all' || room.floor.toString() === floorFilter;
        const matchesType = typeFilter === 'all' || room.room_type_id.toString() === typeFilter;
        const matchesSearch = !roomSearch || 
            room.room_number.toLowerCase().includes(roomSearch.toLowerCase()) ||
            (room.active_booking?.guest_name && room.active_booking.guest_name.toLowerCase().includes(roomSearch.toLowerCase())) ||
            (room.type?.type_name && room.type.type_name.toLowerCase().includes(roomSearch.toLowerCase()));
        return matchesStatus && matchesFloor && matchesType && matchesSearch;
    }), [rooms, statusFilter, floorFilter, typeFilter, roomSearch]);

    const roomsByFloor = useMemo(() => {
        return filteredRooms.reduce((acc, room) => {
            (acc[room.floor] = acc[room.floor] || []).push(room);
            return acc;
        }, {});
    }, [filteredRooms]);

    const uniqueFloors = [...new Set(rooms.map(r => r.floor))].sort((a, b) => a - b);
    const countStatus = (s) => rooms.filter(r => r.status === s).length;

    return (
        <AuthenticatedLayout>
            <Head title="Rooms" />

            <div className="flex flex-col gap-8">

                {/* Title + Actions */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Rooms
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Monitor real-time room occupancy, manage status allocations, and orchestrate housekeeping updates.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                        {!isHousekeeping && cleaningRooms.length > 0 && (
                            <button
                                onClick={() => { setBulkSelected([]); setActiveModal('bulk_clean'); }}
                                className="flex-1 sm:flex-none justify-center flex items-center gap-2 px-4 py-2 bg-amber-600/20 border border-amber-500/40 hover:bg-amber-600/30 text-amber-300 rounded-xl text-xs font-bold transition-all"
                            >
                                <CheckCheck size={15} /> Bulk Clean
                                <span className="bg-amber-500 text-amber-950 font-black text-[10px] px-1.5 py-0.5 rounded-full">{cleaningRooms.length}</span>
                            </button>
                        )}
                        {isAdmin && (
                            <button
                                onClick={() => setActiveModal('add')}
                                className="flex-1 sm:flex-none justify-center flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition-all shadow-lg"
                            >
                                <Plus size={15} /> Add Room
                            </button>
                        )}
                        {user.role !== 'housekeeping' && (
                            <button
                                onClick={() => { setAvailabilityRoomId(''); setIsAvailabilityOpen(true); }}
                                className="flex-1 sm:flex-none justify-center flex items-center gap-2 px-4 py-2 bg-indigo-700/30 border border-indigo-600/40 hover:bg-indigo-600/40 text-indigo-300 rounded-xl text-xs font-bold transition-all"
                            >
                                <Calendar size={15} /> Availability
                            </button>
                        )}
                        {user.role !== 'housekeeping' && (
                            <Link
                                href={route('checkin.index')}
                                className="flex-1 sm:flex-none justify-center flex items-center gap-2 px-4 py-2 bg-emerald-700/30 border border-emerald-600/40 hover:bg-emerald-600/40 text-emerald-300 rounded-xl text-xs font-bold transition-all"
                            >
                                <ArrowUpRight size={15} /> Check-In
                            </Link>
                        )}
                    </div>
                </div>

                {/* Tabs + Search & Filters */}
                <div className="flex flex-col md:flex-row md:items-center gap-4 justify-between">
                    {/* Status Tabs */}
                    <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155] flex-wrap shrink-0">
                        {[
                            { key: 'all',          label: 'All Rooms',    dot: 'bg-brand-400',   count: rooms.length },
                            { key: 'vacant',       label: 'Vacant',       dot: 'bg-emerald-400', count: countStatus('vacant') },
                            { key: 'occupied',     label: 'Occupied',     dot: 'bg-rose-450',    count: countStatus('occupied') },
                            { key: 'cleaning',     label: 'Cleaning',     dot: 'bg-amber-400',   count: countStatus('cleaning') },
                            { key: 'out_of_order',  label: 'Out of Order', dot: 'bg-slate-400',   count: countStatus('out_of_order') },
                        ].map(tab => (
                            <button key={tab.key} onClick={() => setStatusFilter(tab.key)}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${
                                    statusFilter === tab.key ? 'bg-[#0f172a] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'
                                }`}>
                                <span className={`w-1.5 h-1.5 rounded-full ${tab.dot} ${statusFilter === tab.key ? 'opacity-100' : 'opacity-40'}`} />
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    {/* Search beside status tabs */}
                    <div className="relative w-full md:w-64">
                        <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                        <input
                            type="text"
                            value={roomSearch}
                            onChange={e => setRoomSearch(e.target.value)}
                            placeholder="Search room #, guest, type..."
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs placeholder:text-slate-500"
                        />
                        {roomSearch && (
                            <button onClick={() => setRoomSearch('')} className="absolute right-4 top-3.5 text-slate-500 hover:text-slate-300">
                                <X size={14} />
                            </button>
                        )}
                    </div>
                </div>

                {/* Visual Floor Selector Tabs */}
                <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155] w-full sm:w-fit shadow-md overflow-x-auto mobile-scroll-tabs">
                    <button
                        onClick={() => setFloorFilter('all')}
                        className={`flex-none flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all ${floorFilter === 'all' ? 'bg-[#0f172a] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'}`}
                    >
                        <span className={`w-1.5 h-1.5 rounded-full bg-brand-400 ${floorFilter === 'all' ? 'opacity-100' : 'opacity-40'}`} />
                        All Floors
                    </button>
                    {uniqueFloors.map(f => {
                        const floorRoomCount = rooms.filter(r => r.floor === f).length;
                        const colors = ['bg-indigo-400', 'bg-emerald-400', 'bg-sky-400', 'bg-amber-400', 'bg-rose-400', 'bg-purple-400'];
                        const dotColor = colors[(parseInt(f) || 0) % colors.length];
                        return (
                            <button
                                key={f}
                                onClick={() => setFloorFilter(f.toString())}
                                className={`flex-none flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-bold transition-all ${floorFilter === f.toString() ? 'bg-[#0f172a] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'}`}
                            >
                                <span className={`w-1.5 h-1.5 rounded-full ${dotColor} ${floorFilter === f.toString() ? 'opacity-100' : 'opacity-40'}`} />
                                Floor {f}
                            </button>
                        );
                    })}
                </div>

                {/* Room grid grouped by floor */}
                {Object.keys(roomsByFloor).sort((a, b) => +a - +b).map(floor => (
                    <div key={floor}>
                        <div className="flex items-center gap-3 mb-3">
                            <span className="text-xs font-extrabold text-slate-550 uppercase tracking-widest flex items-center gap-1">
                                <Layers size={13} /> Floor {floor}
                            </span>
                            <span className="text-xs text-slate-600">({roomsByFloor[floor].length} rooms)</span>
                            <div className="flex-1 h-px bg-[#334155]" />
                        </div>
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-4">
                            {roomsByFloor[floor].map(room => {
                                const alert = getCheckoutAlertState(room);
                                return (
                                    <motion.div
                                        key={room.id}
                                        whileHover={{ y: -3 }}
                                        onClick={() => handleRoomClick(room)}
                                        className={`p-4 rounded-2xl border shadow-lg cursor-pointer flex flex-col justify-between gap-3 transition-all duration-300 relative overflow-hidden ${STATUS_COLORS[room.status]} ${alert?.state === 'overdue' ? 'ring-2 ring-red-500/60' : alert?.state === 'upcoming' ? 'ring-2 ring-amber-400/50' : ''}`}
                                    >
                                        <div className="flex justify-center items-center w-full gap-2">
                                            <span className="text-[10px] font-mono capitalize opacity-70 truncate text-center">
                                                {room.type?.type_name}
                                            </span>
                                            {room.photo_url && (
                                                <img
                                                    src={room.photo_url}
                                                    alt={room.type?.type_name}
                                                    className="w-6 h-6 rounded-md object-cover border border-[#334155] shrink-0"
                                                    onError={(e) => { e.currentTarget.style.display = 'none'; }}
                                                />
                                            )}
                                        </div>

                                        {alert && (
                                            <div className={`flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full w-fit ${alert.state === 'overdue' ? 'bg-red-900/60 text-red-300 border border-red-700/30' : 'bg-amber-900/60 text-amber-300 border border-amber-700/30'}`}>
                                                <Bell size={9} />
                                                {alert.label}
                                            </div>
                                        )}

                                        <div className="text-center">
                                            <span className="text-2xl font-outfit font-black block tracking-tight">{room.room_number}</span>
                                            <span className="text-[10px] uppercase font-extrabold tracking-wider block mt-1 opacity-75">
                                                {room.status === 'cleaning' ? (
                                                    <span className="inline-flex items-center gap-1 text-amber-400 font-extrabold animate-pulse">
                                                        <Brush size={10} /> Cleaning
                                                    </span>
                                                ) : STATUS_LABELS[room.status]}
                                            </span>

                                            {room.status === 'cleaning' && (
                                                <div className="text-[9px] text-amber-300 font-mono mt-1 flex flex-col gap-0.5 leading-none">
                                                    <div>{formatElapsedMinutes(getCleaningDuration(room.cleaning_started_at))} elapsed</div>
                                                    {room.assigned_housekeeper && <div className="text-[8px] text-slate-400 truncate max-w-[100px] mx-auto mt-0.5 flex items-center justify-center gap-0.5"><User size={8} className="shrink-0" /> {room.assigned_housekeeper}</div>}
                                                </div>
                                            )}

                                            <button
                                                type="button"
                                                onClick={e => { e.stopPropagation(); setAvailabilityRoomId(room.id); setIsAvailabilityOpen(true); }}
                                                className="inline-flex items-center gap-1 text-[9px] font-extrabold text-brand-400 hover:text-brand-300 mt-2 bg-brand-500/10 border border-brand-500/20 px-2 py-0.5 rounded-md hover:bg-brand-500/20 transition-all"
                                                title="Check Room Availability Calendar"
                                            >
                                                <Calendar size={9} className="shrink-0 text-brand-400" />
                                                <span>Calendar</span>
                                            </button>
                                        </div>

                                        {room.status === 'occupied' && room.active_booking && (
                                            <div className="flex flex-col gap-1 w-full text-[9px] border-t border-[#334155]/60 pt-2 mt-1 bg-[#0f172a]/20 p-1.5 rounded-lg text-slate-350">
                                                <div className="flex justify-between items-center gap-1.5">
                                                    <span className="opacity-50">Guest:</span>
                                                    <span className="font-bold truncate max-w-[80px]" title={room.active_booking.guest_name}>{room.active_booking.guest_name}</span>
                                                </div>
                                                <div className="flex justify-between items-center gap-1.5">
                                                    <span className="opacity-50">Type:</span>
                                                    <span className="font-semibold uppercase text-brand-400">
                                                        {room.active_booking.booking_type === 'overnight' ? 'Overnight' : `${room.active_booking.short_time_hours} Hours`}
                                                    </span>
                                                </div>
                                                <div className="flex justify-between items-center gap-1.5">
                                                    <span className="opacity-50">Checkout:</span>
                                                    <span className="font-mono font-bold text-rose-350">{formatExpectedCheckout(room.active_booking.expected_check_out)}</span>
                                                </div>
                                            </div>
                                        )}
                                    </motion.div>
                                );
                            })}
                        </div>
                    </div>
                ))}

                {filteredRooms.length === 0 && (
                    <div className="py-16 text-center text-slate-500">
                        <BedDouble size={40} className="mx-auto mb-3 opacity-20" />
                        No rooms match the current filters.
                    </div>
                )}
            </div>

            {/* ── ROOM DETAIL MODAL ── */}
            <AnimatePresence>
                {selectedRoom && !activeModal && (
                    <>
                        <motion.div
                            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                            onClick={() => setSelectedRoom(null)}
                            className="fixed inset-0 bg-[#070b13]/90 z-[999]"
                        />
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 16 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 16 }}
                            transition={{ type: 'spring', damping: 26, stiffness: 320 }}
                            className="fixed inset-0 z-[1000] flex items-center justify-center p-4"
                        >
                            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden">

                                {/* Header */}
                                <div className="flex items-center justify-between border-b border-[#334155] px-6 py-4 shrink-0">
                                    <div className="flex items-center gap-3">
                                        <span className={`w-3 h-3 rounded-full shrink-0 ${selectedRoom.status === 'vacant' ? 'bg-emerald-400' :
                                            selectedRoom.status === 'occupied' ? 'bg-rose-400' :
                                                selectedRoom.status === 'cleaning' ? 'bg-amber-400' : 'bg-slate-500'
                                            }`} />
                                        <div>
                                            <h2 className="text-xl font-outfit font-black text-slate-100">Room {selectedRoom.room_number}</h2>
                                            <p className="text-xs text-slate-400">{selectedRoom.type?.type_name} - Floor {selectedRoom.floor} - <span className="capitalize">{selectedRoom.status.replace('_', ' ')}</span></p>
                                            <button
                                                type="button"
                                                onClick={() => { setAvailabilityRoomId(selectedRoom.id); setIsAvailabilityOpen(true); }}
                                                className="inline-flex items-center gap-1.5 mt-2.5 text-[11px] font-extrabold text-brand-400 hover:text-brand-300 transition-colors bg-brand-500/10 border border-brand-500/20 px-2 py-0.5 rounded-md hover:bg-brand-500/20"
                                                title="View Availability Calendar for this Room"
                                            >
                                                <Calendar size={11} className="text-brand-400" />
                                                <span>Check Room Calendar</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {isAdmin && (
                                            <>
                                                <button onClick={() => openEditModal(selectedRoom)} title="Edit Room"
                                                    className="p-2 rounded-lg bg-brand-600/20 border border-brand-500/30 text-brand-400 hover:bg-brand-600/30 transition-all">
                                                    <Edit size={15} />
                                                </button>
                                                <button onClick={() => handleDelete(selectedRoom)} title="Delete Room"
                                                    disabled={selectedRoom.status === 'occupied'}
                                                    className="p-2 rounded-lg bg-red-900/20 border border-red-700/30 text-red-400 hover:bg-red-800/30 disabled:opacity-30 disabled:cursor-not-allowed transition-all">
                                                    <Trash2 size={15} />
                                                </button>
                                            </>
                                        )}
                                        <button onClick={() => setSelectedRoom(null)}
                                            className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors">
                                            <X size={18} />
                                        </button>
                                    </div>
                                </div>

                                {/* Scrollable body */}
                                <div className="flex-1 overflow-y-auto p-6 space-y-4">
                                    {selectedRoom.photo_url && (
                                        <div className="w-full h-48 rounded-xl overflow-hidden border border-[#334155] relative shrink-0">
                                            <img src={selectedRoom.photo_url} alt={selectedRoom.type?.type_name} className="w-full h-full object-cover" />
                                        </div>
                                    )}

                                    {/* Housekeeping Panel if cleaning */}
                                    {selectedRoom.status === 'cleaning' && (
                                        <div className="p-4 rounded-xl bg-amber-950/20 border border-amber-500/20 text-xs flex flex-col gap-3">
                                            <h3 className="font-bold text-amber-400 uppercase tracking-wider flex items-center gap-1.5 text-xs">
                                                <Brush size={13} /> Housekeeping Management
                                            </h3>
                                            <form onSubmit={handleHousekeepingSubmit} className="space-y-3">
                                                <div>
                                                    <label className="text-[9px] uppercase font-bold text-slate-400 tracking-wider mb-1 block">Assigned Housekeeper</label>
                                                    <select
                                                        value={hkForm.data.assigned_housekeeper}
                                                        onChange={e => hkForm.setData('assigned_housekeeper', e.target.value)}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 text-xs focus:outline-none focus:border-brand-500 font-bold"
                                                    >
                                                        <option value="">-- Unassigned (Select Staff) --</option>
                                                        {housekeepers.map(hk => (
                                                            <option key={hk.id} value={hk.name}>{hk.name} ({hk.role})</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                
                                                <div className="flex gap-2 pt-1">
                                                    <button
                                                        type="submit"
                                                        onClick={() => hkForm.setData('status', 'cleaning')}
                                                        disabled={hkForm.processing}
                                                        className="flex-1 py-2 bg-slate-800 border border-slate-700/60 hover:bg-slate-700 rounded-xl text-slate-300 font-bold text-[10px] uppercase tracking-wider transition-all"
                                                    >
                                                        Update Assignee
                                                    </button>

                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setConfirmAction({
                                                                title: 'Mark Vacant',
                                                                message: `Mark Room ${selectedRoom.room_number} as Vacant and Ready?`,
                                                                onConfirm: () => {
                                                                    router.post(route('rooms.status', selectedRoom.id), { status: 'vacant', assigned_housekeeper: '', notes: 'Housekeeping complete' }, { preserveScroll: true, onSuccess: () => setSelectedRoom(null) });
                                                                    setConfirmAction(null);
                                                                }
                                                            });
                                                        }}
                                                        className="flex-1 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-white font-bold text-[10px] uppercase tracking-wider transition-all flex items-center justify-center gap-1 shadow-md shadow-emerald-950/20"
                                                    >
                                                        <CheckCheck size={11} /> Mark as Ready
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    )}

                                    {/* Occupied info */}
                                    {selectedRoom.status === 'occupied' && selectedRoom.active_booking && (
                                        <div className="p-4 rounded-xl bg-rose-950/20 border border-rose-500/20 text-xs flex flex-col gap-3">
                                            <h3 className="font-bold text-rose-400 uppercase tracking-wider flex items-center gap-1.5 text-xs">
                                                <User size={13} /> Active Occupant Details
                                            </h3>
                                            <div className="space-y-1.5">
                                                <div className="flex justify-between"><span className="text-slate-400">Guest:</span><span className="font-bold text-slate-200">{selectedRoom.active_booking.guest_name}</span></div>
                                                <div className="flex justify-between"><span className="text-slate-400">Ref:</span><span className="font-mono text-slate-300">{selectedRoom.active_booking.booking_ref}</span></div>
                                                {selectedRoom.active_booking.group_ref && <div className="flex justify-between"><span className="text-slate-400">Group Ref:</span><span className="font-mono text-indigo-400 font-bold">{selectedRoom.active_booking.group_ref}</span></div>}
                                                <div className="flex justify-between"><span className="text-slate-400">Check-In Type:</span><span className="font-bold text-brand-400 uppercase">{selectedRoom.active_booking.booking_type === 'overnight' ? 'Overnight' : `${selectedRoom.active_booking.short_time_hours} Hours`}</span></div>
                                                <div className="flex justify-between"><span className="text-slate-400">Check-In Time:</span><span className="font-mono text-slate-200">{new Date(selectedRoom.active_booking.check_in).toLocaleString()}</span></div>
                                                <div className="flex justify-between"><span className="text-slate-400">Checkout Time:</span><span className="font-mono text-rose-400 font-bold">{new Date(selectedRoom.active_booking.expected_check_out).toLocaleString()}</span></div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setIsSwapOpen(!isSwapOpen)}
                                                className={`flex-1 flex items-center justify-center gap-1 px-3 py-2 border rounded-xl font-bold text-[10px] uppercase tracking-wider transition-all ${isSwapOpen ? 'bg-indigo-650 border-indigo-500 text-slate-100 shadow-lg' : 'bg-[#0f172a]/55 border-[#334155] text-slate-400 hover:bg-[#334155]/40'}`}
                                            >
                                                <Shuffle size={11} /> Swap Room
                                            </button>
                                        </div>
                                    )}

                                    {/* Quick check-in if vacant */}
                                    {selectedRoom.status === 'vacant' && user.role !== 'housekeeping' && (
                                        <Link
                                            href={route('checkin.index', { room_id: selectedRoom.id })}
                                            className="flex items-center justify-center gap-2 px-4 py-3 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-white font-bold text-sm transition-all"
                                        >
                                            Check-In <ArrowUpRight size={15} />
                                        </Link>
                                    )}
                                </div>
                            </div>
                        </motion.div>
                    </>
                )}
            </AnimatePresence>

            {/* ── ADD ROOM MODAL ── */}
            <AnimatePresence>
                {activeModal === 'add' && isAdmin && (
                    <>
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.6 }} exit={{ opacity: 0 }}
                            onClick={() => setActiveModal(null)} className="fixed inset-0 bg-[#070b13]/90 z-[999]" />
                        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }}
                            className="fixed inset-0 z-[1000] flex items-center justify-center p-4">
                            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-md p-6">
                                <div className="flex items-center justify-between mb-5">
                                    <h2 className="font-outfit font-black text-lg text-slate-100 flex items-center gap-2">
                                        <Plus size={18} className="text-emerald-400" /> Add New Room
                                    </h2>
                                    <button
                                        type="button"
                                        onClick={() => { setActiveModal(null); setSelectedRoom(null); }}
                                        className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors"
                                    >
                                        <X size={18} />
                                    </button>
                                </div>
                                <form onSubmit={handleAddSubmit} className="space-y-4">
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className="text-xs font-semibold text-slate-400 mb-1 block">Room Number *</label>
                                            <input type="text" value={addForm.data.room_number}
                                                onChange={e => addForm.setData('room_number', e.target.value.toUpperCase())}
                                                placeholder="e.g. 101" maxLength={10} required
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 text-sm focus:outline-none focus:border-brand-500 uppercase" />
                                        </div>
                                        <div>
                                            <label className="text-xs font-semibold text-slate-400 mb-1 block">Floor *</label>
                                            <input type="number" value={addForm.data.floor}
                                                onChange={e => addForm.setData('floor', parseInt(e.target.value) || 1)}
                                                min={1} max={99} required
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 text-sm focus:outline-none focus:border-brand-500" />
                                        </div>
                                    </div>
                                    <div>
                                        <label className="text-xs font-semibold text-slate-400 mb-1 block">Room Type *</label>
                                        <select value={addForm.data.room_type_id}
                                            onChange={e => addForm.setData('room_type_id', e.target.value)} required
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 text-sm focus:outline-none focus:border-brand-500">
                                            <option value="">Select Room Type</option>
                                            {roomTypes.map(t => (
                                                <option key={t.id} value={t.id}>{t.type_name} - ₱{Number(t.base_rate).toLocaleString()}/night</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="flex justify-end gap-3 pt-2">
                                        <button type="submit" disabled={addForm.processing}
                                            className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-white font-bold text-sm transition-all disabled:opacity-50">
                                            Add
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </motion.div>
                    </>
                )}
            </AnimatePresence>

            {/* ── EDIT ROOM MODAL ── */}
            <AnimatePresence>
                {activeModal === 'edit' && selectedRoom && isAdmin && (
                    <>
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.6 }} exit={{ opacity: 0 }}
                            onClick={() => { setActiveModal(null); setSelectedRoom(null); }} className="fixed inset-0 bg-[#070b13]/90 z-[999]" />
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 16 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 16 }}
                            transition={{ type: 'spring', damping: 26, stiffness: 320 }}
                            className="fixed inset-0 z-[1000] flex items-center justify-center p-4"
                        >
                            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden">
                                <div className="flex items-center justify-between border-b border-[#334155] px-6 py-4 shrink-0">
                                    <h2 className="text-xl font-outfit font-black text-slate-100">Edit Room {selectedRoom.room_number}</h2>
                                    <button
                                        onClick={() => { setActiveModal(null); setSelectedRoom(null); }}
                                        className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors"
                                    >
                                        <X size={18} />
                                    </button>
                                </div>
                                <div className="flex-1 overflow-y-auto p-6 space-y-4">
                                    <form onSubmit={handleEditSubmit} className="space-y-4">
                                        <button type="submit" disabled={editForm.processing}
                                            className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-white font-bold text-sm transition-all disabled:opacity-50">
                                            Save Changes
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </motion.div>
                    </>
                )}
            </AnimatePresence>

            <RoomAvailabilityModal 
                isOpen={isAvailabilityOpen} 
                onClose={() => setIsAvailabilityOpen(false)} 
            />

            <AlertModal
                isOpen={!!alertMessage}
                onClose={() => setAlertMessage(null)}
                title="Notice"
                message={alertMessage}
            />

            <ConfirmModal
                isOpen={!!confirmAction}
                onClose={() => setConfirmAction(null)}
                onConfirm={confirmAction?.onConfirm}
                title={confirmAction?.title}
                message={confirmAction?.message}
                confirmText="Proceed"
                isDanger={confirmAction?.isDanger}
            />
        </AuthenticatedLayout>
    );
}
