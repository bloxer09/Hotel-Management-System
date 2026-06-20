import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage, router } from '@inertiajs/react';
import axios from 'axios';
import {
    UserCheck,
    Coins,
    Calendar,
    Search,
    Star,
    BedDouble,
    CheckCircle,
    Crown,
    TrendingUp,
    Plus,
    ChevronLeft,
    ChevronRight,
    X,
    Clock,
    LogOut,
    Ban,
    Eye,
    Edit
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import StayDetailsModal from '@/Components/StayDetailsModal';
import ImagePreviewModal from '@/Components/ImagePreviewModal';

const STATUS_TABS = [
    { key: 'all', label: 'All Stays', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: 'active', label: 'Checked In', color: 'text-emerald-400', dot: 'bg-emerald-400' },
    { key: 'checked_out', label: 'Checked Out', color: 'text-slate-400', dot: 'bg-slate-400' },
    { key: 'cancelled', label: 'Cancelled', color: 'text-red-400', dot: 'bg-red-400' },
];

export default function Index({ vacantRooms, roomTypes, prefilledGuest, promoCodes = [], bookings, currentFilter }) {
    const { auth } = usePage().props;
    const [selectedBookingIdForModal, setSelectedBookingIdForModal] = useState(null);
    const [isStayModalOpen, setIsStayModalOpen] = useState(false);
    const flash = usePage().props.flash || {};
    const isAdmin = auth?.user?.role === 'admin';

    // ── Modal state ──
    const [showModal, setShowModal] = useState(false);
    const [isImageModalOpen, setIsImageModalOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState(null);

    // ── Search state ──
    const [searchQuery, setSearchQuery] = useState('');

    // ── Guest autocomplete ──
    const [guestSearch, setGuestSearch] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [isVip, setIsVip] = useState(false);
    const [vipNotes, setVipNotes] = useState('');
    const [selectedGuestStays, setSelectedGuestStays] = useState(0);

    // ── Promo state ──
    const [promoInput, setPromoInput] = useState('');
    const [promoError, setPromoError] = useState('');

    // ── Live calc state ──
    const [calc, setCalc] = useState({
        base_amount: 0, peak_surcharge: 0, discount_amount: 0,
        total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null
    });

    const queryParams = new URLSearchParams(window.location.search);
    const initialRoomId = queryParams.get('room_id') || '';

    const { data, setData, post, processing, errors, reset } = useForm({
        room_id: initialRoomId,
        guest_name: '', guest_contact: '', guest_id_type: 'Driver License',
        guest_id_number: '', id_image: null, guest_email: '', guest_address: '', num_guests: 1,
        booking_type: 'overnight', num_nights: 1, short_time_hours: 3,
        discount_type: 'none', discount_amount: 0, promo_code: '',
        payment_method: 'cash', cash_amount: 0.00, gcash_amount: 0.00,
        gcash_ref: '', reference_number: '', notes: ''
    });

    // ── Edit Stay Modal States ──
    const [showEditModal, setShowEditModal] = useState(false);
    const [editingBooking, setEditingBooking] = useState(null);
    const [editCalc, setEditCalc] = useState({
        base_amount: 0, peak_surcharge: 0, discount_amount: 0,
        total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null
    });

    const editForm = useForm({
        room_id: '',
        guest_name: '', guest_contact: '', guest_id_type: 'Driver License',
        guest_id_number: '', id_image: null, guest_email: '', guest_address: '', num_guests: 1,
        booking_type: 'overnight', num_nights: 1, short_time_hours: 3,
        discount_type: 'none', discount_amount: 0, promo_code: '',
        payment_method: 'cash', cash_amount: 0.00, gcash_amount: 0.00,
        gcash_ref: '', reference_number: '', notes: ''
    });

    // Live price calc for Edit Form
    useEffect(() => {
        if (editForm.data.room_id) {
            axios.post(route('checkin.calculate'), {
                room_id: editForm.data.room_id, booking_type: editForm.data.booking_type,
                num_nights: editForm.data.num_nights, short_time_hours: editForm.data.short_time_hours,
                discount_type: editForm.data.discount_type, discount_amount: editForm.data.discount_amount,
                promo_code: editForm.data.promo_code
            }).then(res => {
                setEditCalc(res.data);
                editForm.setData(prev => {
                    const total = res.data.total_amount;
                    if (prev.payment_method === 'cash') return { ...prev, cash_amount: total, gcash_amount: 0 };
                    if (prev.payment_method === 'gcash') return { ...prev, gcash_amount: total, cash_amount: 0 };
                    if (prev.payment_method === 'split') return { ...prev, cash_amount: Math.round(total / 2), gcash_amount: total - Math.round(total / 2) };
                    return { ...prev, cash_amount: 0, gcash_amount: 0 };
                });
            }).catch(() => { });
        }
    }, [editForm.data.room_id, editForm.data.booking_type, editForm.data.num_nights, editForm.data.short_time_hours, editForm.data.discount_type, editForm.data.discount_amount, editForm.data.promo_code]);

    const selectEditGuest = (guest) => {
        editForm.setData(prev => ({
            ...prev,
            guest_name: guest.full_name,
            guest_contact: guest.contact_number || '',
            guest_id_type: guest.id_type || 'Driver License',
            guest_id_number: guest.id_number || '',
            guest_email: guest.email || '',
            guest_address: guest.address || ''
        }));
        setIsVip(guest.is_vip);
        setVipNotes(guest.vip_notes || '');
        setSelectedGuestStays(guest.total_stays || 0);
        setGuestSearch('');
        setSuggestions([]);
    };

    const handleApplyEditPromo = (codeOverride) => {
        const code = codeOverride || promoInput;
        if (!code.trim()) { setPromoError('Please enter a promo code.'); return; }
        if (!editForm.data.room_id) { setPromoError('Please select a room first.'); return; }
        axios.post(route('promo_codes.validate'), { code })
            .then(res => {
                if (res.data.valid) {
                    editForm.setData(prev => ({ ...prev, promo_code: res.data.code, discount_type: 'promo', discount_amount: res.data.discount_value }));
                    setPromoError('');
                } else {
                    setPromoError(res.data.message || 'Invalid promo code.');
                }
            })
            .catch(err => setPromoError(err.response?.data?.error || 'Failed to validate promo code.'));
    };

    const handleEditCashInput = (e) => {
        const cash = Math.min(editCalc.total_amount, Math.max(0, Number(e.target.value) || 0));
        editForm.setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: Math.max(0, editCalc.total_amount - cash) }));
    };
    const handleEditGCashInput = (e) => {
        const gcash = Math.min(editCalc.total_amount, Math.max(0, Number(e.target.value) || 0));
        editForm.setData(prev => ({ ...prev, gcash_amount: gcash, cash_amount: Math.max(0, editCalc.total_amount - gcash) }));
    };

    const handleEditFormSubmit = (e) => {
        e.preventDefault();
        editForm.transform((data) => ({
            ...data,
            _method: 'PUT',
        })).post(route('bookings.update', editingBooking.id), {
            onSuccess: () => {
                setShowEditModal(false);
                setEditingBooking(null);
            }
        });
    };

    const openEditModal = (booking) => {
        setEditingBooking(booking);
        editForm.setData({
            room_id: booking.room_id,
            guest_name: booking.guest_name,
            guest_contact: booking.guest_contact || '',
            guest_id_type: booking.guest_id_type || 'Driver License',
            guest_id_number: booking.guest_id_number || '',
            guest_email: booking.guest_profile?.email || '',
            guest_address: booking.guest_profile?.address || '',
            num_guests: booking.num_guests || 1,
            booking_type: booking.booking_type || 'overnight',
            num_nights: booking.booking_type === 'overnight' ? (booking.num_nights || 1) : 1,
            short_time_hours: booking.booking_type !== 'overnight' ? (booking.short_time_hours || 3) : 3,
            discount_type: booking.discount_type || 'none',
            discount_amount: booking.discount_amount || 0,
            promo_code: booking.promo_code || '',
            payment_method: booking.payment_method || 'cash',
            cash_amount: booking.cash_amount || 0.00,
            gcash_amount: booking.gcash_amount || 0.00,
            gcash_ref: booking.gcash_ref || '',
            reference_number: booking.gcash_ref || '',
            notes: booking.notes || ''
        });
        setEditCalc({
            base_amount: booking.base_amount || 0,
            peak_surcharge: booking.peak_surcharge || 0,
            discount_amount: booking.discount_amount || 0,
            total_amount: booking.total_amount || 0,
            expected_check_out: booking.expected_check_out || '',
            is_peak: booking.is_peak || false,
            peak_label: null
        });
        setIsVip(booking.guest_profile?.is_vip || false);
        setVipNotes(booking.guest_profile?.vip_notes || '');
        setSelectedGuestStays(booking.guest_profile?.total_stays || 0);
        setShowEditModal(true);
    };

    const closeEditModal = () => {
        setShowEditModal(false);
        setEditingBooking(null);
    };

    // Guest autocomplete fetch
    useEffect(() => {
        if (guestSearch.length >= 2) {
            axios.get(route('guests.search', { q: guestSearch }))
                .then(res => setSuggestions(res.data))
                .catch(() => { });
        } else {
            setSuggestions([]);
        }
    }, [guestSearch]);

    // Prefill guest from prop or sessionStorage
    useEffect(() => {
        let guestToPrefill = prefilledGuest;
        if (!guestToPrefill) {
            const stored = sessionStorage.getItem('quickCheckinGuest');
            if (stored) {
                try { guestToPrefill = JSON.parse(stored); } catch (e) { }
            }
        }
        if (guestToPrefill) {
            setData(prev => ({
                ...prev,
                guest_name: guestToPrefill.full_name || '',
                guest_contact: guestToPrefill.contact_number || '',
                guest_id_type: guestToPrefill.id_type || 'Driver License',
                guest_id_number: guestToPrefill.id_number || '',
                guest_email: guestToPrefill.email || '',
                guest_address: guestToPrefill.address || ''
            }));
            setIsVip(!!guestToPrefill.is_vip);
            setVipNotes(guestToPrefill.vip_notes || '');
            setSelectedGuestStays(guestToPrefill.total_stays || 0);
            sessionStorage.removeItem('quickCheckinGuest');
            setShowModal(true);
        }
    }, [prefilledGuest]);

    const selectGuest = (guest) => {
        setData(prev => ({
            ...prev,
            guest_name: guest.full_name,
            guest_contact: guest.contact_number || '',
            guest_id_type: guest.id_type || 'Driver License',
            guest_id_number: guest.id_number || '',
            guest_email: guest.email || '',
            guest_address: guest.address || ''
        }));
        setIsVip(guest.is_vip);
        setVipNotes(guest.vip_notes || '');
        setSelectedGuestStays(guest.total_stays || 0);
        setGuestSearch('');
        setSuggestions([]);
    };

    const handleApplyPromo = (codeOverride) => {
        const code = codeOverride || promoInput;
        if (!code.trim()) { setPromoError('Please enter a promo code.'); return; }
        if (!data.room_id) { setPromoError('Please select a room first.'); return; }
        axios.post(route('promo_codes.validate'), { code })
            .then(res => {
                if (res.data.valid) {
                    setData(prev => ({ ...prev, promo_code: res.data.code, discount_type: 'promo', discount_amount: res.data.discount_value }));
                    setPromoError('');
                } else {
                    setPromoError(res.data.message || 'Invalid promo code.');
                }
            })
            .catch(err => setPromoError(err.response?.data?.error || 'Failed to validate promo code.'));
    };

    // Live price calc
    useEffect(() => {
        if (data.room_id) {
            axios.post(route('checkin.calculate'), {
                room_id: data.room_id, booking_type: data.booking_type,
                num_nights: data.num_nights, short_time_hours: data.short_time_hours,
                discount_type: data.discount_type, discount_amount: data.discount_amount,
                promo_code: data.promo_code
            }).then(res => {
                setCalc(res.data);
                setData(prev => {
                    const total = res.data.total_amount;
                    if (prev.payment_method === 'cash') return { ...prev, cash_amount: total, gcash_amount: 0 };
                    if (prev.payment_method === 'gcash') return { ...prev, gcash_amount: total, cash_amount: 0 };
                    if (prev.payment_method === 'split') return { ...prev, cash_amount: Math.round(total / 2), gcash_amount: total - Math.round(total / 2) };
                    return { ...prev, cash_amount: 0, gcash_amount: 0 };
                });
            }).catch(() => { });
        }
    }, [data.room_id, data.booking_type, data.num_nights, data.short_time_hours, data.discount_type, data.discount_amount, data.promo_code]);

    const handleCashInput = (e) => {
        const cash = Math.min(calc.total_amount, Math.max(0, Number(e.target.value) || 0));
        setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: Math.max(0, calc.total_amount - cash) }));
    };
    const handleGCashInput = (e) => {
        const gcash = Math.min(calc.total_amount, Math.max(0, Number(e.target.value) || 0));
        setData(prev => ({ ...prev, gcash_amount: gcash, cash_amount: Math.max(0, calc.total_amount - gcash) }));
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        post(route('checkin.store'), {
            onSuccess: () => {
                closeModal();
            }
        });
    };

    const openModal = () => {
        reset();
        setCalc({ base_amount: 0, peak_surcharge: 0, discount_amount: 0, total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null });
        setIsVip(false); setVipNotes(''); setSelectedGuestStays(0);
        setGuestSearch(''); setSuggestions([]); setPromoInput(''); setPromoError('');
        setShowModal(true);
    };
    const closeModal = () => setShowModal(false);

    // Filter list locally by search
    const items = bookings?.data || [];
    const filtered = searchQuery.trim()
        ? items.filter(b =>
            b.guest_name?.toLowerCase().includes(searchQuery.toLowerCase()) ||
            b.booking_ref?.toLowerCase().includes(searchQuery.toLowerCase()) ||
            b.room?.room_number?.toString().includes(searchQuery) ||
            b.guest_contact?.includes(searchQuery)
        )
        : items;

    const paginationLinks = bookings?.links || [];
    const paginationMeta = bookings?.meta || { current_page: bookings?.current_page, last_page: bookings?.last_page, from: bookings?.from, to: bookings?.to, total: bookings?.total };

    const handleFilterChange = (status) => {
        router.get(route('checkin.index'), { status }, { preserveState: true, replace: true });
    };

    const handlePageChange = (url) => {
        if (url) router.get(url, {}, { preserveState: true, replace: true });
    };

    const activeTab = STATUS_TABS.find(t => t.key === currentFilter) || STATUS_TABS.find(t => t.key === 'active');

    const inputCls = "w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs";

    return (
        <AuthenticatedLayout>
            <Head title="Check In" />

            {/* Flash message */}
            <AnimatePresence>
                {flash.success && (
                    <motion.div
                        initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -20 }}
                        className="mb-4 p-4 rounded-xl bg-emerald-950/40 border border-emerald-500/30 text-emerald-300 text-sm font-medium flex items-center gap-2"
                    >
                        <CheckCircle size={16} /> {flash.success}
                    </motion.div>
                )}
            </AnimatePresence>

            <div className="flex flex-col gap-6">

                {/* Page Header */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">Check In</h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Register walk-in guests and view all active stays.</p>
                    </div>
                    <button
                        onClick={openModal}
                        className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-brand-600/20 active:scale-95 shrink-0"
                    >
                        <Plus size={16} /> New Check-In
                    </button>
                </div>

                {/* Filter Tabs + Search */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155]">
                        {STATUS_TABS.map(tab => (
                            <button
                                key={tab.key}
                                onClick={() => handleFilterChange(tab.key)}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${currentFilter === tab.key
                                        ? 'bg-[#0f172a] text-slate-100 shadow'
                                        : 'text-slate-400 hover:text-slate-200'
                                    }`}
                            >
                                <span className={`w-1.5 h-1.5 rounded-full ${tab.dot} ${currentFilter === tab.key ? 'opacity-100' : 'opacity-40'}`} />
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    <div className="relative w-full sm:w-64">
                        <Search className="absolute left-3 top-2.5 text-slate-500" size={14} />
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                            placeholder="Search guest, ref, room..."
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-200 pl-9 pr-4 py-2.5 text-xs focus:outline-none focus:border-brand-500"
                        />
                    </div>
                </div>

                {/* Stay List Table */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Ref</th>
                                    <th className="px-4 py-3 text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Guest</th>
                                    <th className="px-4 py-3 text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Room</th>
                                    <th className="px-4 py-3 text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Check-In</th>
                                    <th className="px-4 py-3 text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Expected Out</th>
                                    <th className="px-4 py-3 text-left text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Type</th>
                                    <th className="px-4 py-3 text-right text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Total</th>
                                    <th className="px-4 py-3 text-center text-[10px] font-semibold text-slate-400 uppercase tracking-wider"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-12 text-center text-slate-500">
                                            {searchQuery
                                                ? `No results for "${searchQuery}"`
                                                : `No ${activeTab.label.toLowerCase()} found.`}
                                        </td>
                                    </tr>
                                ) : filtered.map((booking, i) => (
                                    <motion.tr
                                        key={booking.id}
                                        initial={{ opacity: 0, y: 6 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors"
                                    >
                                        <td className="px-4 py-3 font-mono text-brand-400 font-bold">{booking.booking_ref}</td>
                                        <td className="px-4 py-3">
                                            <div className="font-semibold text-slate-200">{booking.guest_name}</div>
                                            {booking.guest_contact && <div className="text-slate-500 text-[10px]">{booking.guest_contact}</div>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-bold text-slate-300">Room {booking.room?.room_number}</span>
                                            {booking.room?.type && <div className="text-slate-500 text-[10px]">{booking.room.type.type_name}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-slate-300 font-mono">
                                            {booking.check_in ? new Date(booking.check_in).toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '-'}
                                        </td>
                                        <td className="px-4 py-3 text-slate-300 font-mono">
                                            {booking.expected_check_out ? new Date(booking.expected_check_out).toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-black uppercase ${booking.booking_type === 'overnight'
                                                    ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20'
                                                    : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                                                }`}>
                                                {booking.booking_type === 'overnight' ? <BedDouble size={9} /> : <Clock size={9} />}
                                                {booking.booking_type === 'overnight' ? 'Overnight' : `${booking.short_time_hours}h`}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex flex-col items-end gap-0.5">
                                                <span className="font-mono text-emerald-400 font-bold">₱{Number(booking.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                {Number(booking.amount_paid) < Number(booking.total_amount) && (
                                                    <div className="flex flex-col items-end gap-0.5 mt-0.5">
                                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                            Partially Paid
                                                        </span>
                                                        <span className="font-mono text-[9px] text-rose-400 font-semibold">
                                                            Bal: ₱{Number(booking.total_amount - booking.amount_paid).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-1.5">
                                                {booking.status === 'active' && (
                                                    <button
                                                        onClick={() => openEditModal(booking)}
                                                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-[#0f172a] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 text-slate-400 hover:text-amber-400 transition-all text-[10px] font-bold"
                                                    >
                                                        <Edit size={11} /> Edit
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() => {
                                                        setSelectedBookingIdForModal(booking.id);
                                                        setIsStayModalOpen(true);
                                                    }}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-[#0f172a] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 text-slate-400 hover:text-brand-400 transition-all text-[10px] font-bold cursor-pointer"
                                                >
                                                    <Eye size={11} /> View
                                                </button>
                                            </div>
                                        </td>
                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {bookings && bookings.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[#334155] flex items-center justify-between bg-[#0f172a]/40">
                            <span className="text-[10px] text-slate-500">
                                Showing {bookings.from}–{bookings.to} of {bookings.total} records
                            </span>
                            <div className="flex items-center gap-1">
                                <button
                                    onClick={() => handlePageChange(bookings.prev_page_url)}
                                    disabled={!bookings.prev_page_url}
                                    className="p-1.5 rounded-lg border border-[#334155] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                                >
                                    <ChevronLeft size={14} />
                                </button>
                                <span className="px-3 py-1 text-[10px] text-slate-300 font-bold font-mono">
                                    {bookings.current_page} / {bookings.last_page}
                                </span>
                                <button
                                    onClick={() => handlePageChange(bookings.next_page_url)}
                                    disabled={!bookings.next_page_url}
                                    className="p-1.5 rounded-lg border border-[#334155] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                                >
                                    <ChevronRight size={14} />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Check-In Modal ── */}
            <AnimatePresence>
                {showModal && (
                    <motion.div
                        initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-6 px-4"
                        onClick={(e) => { if (e.target === e.currentTarget) closeModal(); }}
                    >
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }}
                            transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-4xl bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden"
                        >
                            {/* Modal Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><UserCheck size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">New Check-In</h2>
                                        <p className="text-[10px] text-slate-400">Register a walk-in guest and process payment</p>
                                    </div>
                                </div>
                                <button onClick={closeModal} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            {/* Modal Body */}
                            <form onSubmit={handleFormSubmit} className="p-6">
                                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                    {/* Left — Guest + Stay + Payment */}
                                    <div className="lg:col-span-2 flex flex-col gap-5">

                                        {/* Section 1: Guest Profile */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><UserCheck size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Guest Details</h3>
                                            </div>

                                            {/* Autocomplete */}
                                            <div className="relative">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5 block">Search Returning Guest</label>
                                                <div className="relative">
                                                    <Search className="absolute left-3 top-2.5 text-slate-500" size={14} />
                                                    <input type="text" value={guestSearch} onChange={e => setGuestSearch(e.target.value)}
                                                        placeholder="Type name to autocomplete..."
                                                        className={`${inputCls} pl-9`} />
                                                </div>
                                                {suggestions.length > 0 && (
                                                    <ul className="absolute left-0 right-0 mt-1 bg-[#1e293b] border border-[#334155] rounded-xl shadow-2xl z-[99] text-xs overflow-hidden">
                                                        {suggestions.map(g => (
                                                            <li key={g.id} onClick={() => selectGuest(g)}
                                                                className="px-4 py-2.5 hover:bg-[#334155] cursor-pointer flex justify-between items-center text-slate-200">
                                                                <span className="font-bold">{g.full_name}</span>
                                                                <div className="flex items-center gap-2">
                                                                    {g.is_vip && <span className="inline-flex items-center gap-1 text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-bold uppercase"><Crown size={9} /> VIP</span>}
                                                                    {g.total_stays >= 3 && <span className="text-[9px] bg-purple-950 border border-purple-600/30 text-purple-400 px-1.5 py-0.5 rounded font-bold">⭐ {g.total_stays} stays</span>}
                                                                    <span className="text-slate-400">{g.contact_number}</span>
                                                                </div>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>

                                            {/* VIP Banner */}
                                            <AnimatePresence>
                                                {isVip && (
                                                    <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}
                                                        className="p-3 rounded-xl bg-amber-950/20 border border-amber-500/30 flex gap-2 text-xs text-amber-300">
                                                        <Star className="h-4 w-4 text-amber-400 shrink-0 fill-amber-400 mt-0.5" />
                                                        <div><span className="font-outfit font-extrabold uppercase tracking-wide block">VIP GUEST</span>
                                                            <p className="mt-0.5 text-amber-400/90">{vipNotes || 'No notes.'}</p></div>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>

                                            {/* Loyalty Banner */}
                                            <AnimatePresence>
                                                {selectedGuestStays >= 3 && data.discount_type !== 'loyalty' && (
                                                    <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}
                                                        className="p-3 rounded-xl bg-purple-950/20 border border-purple-500/30 flex gap-2 text-xs text-purple-300 items-center justify-between">
                                                        <div className="flex gap-2">
                                                            <span>✨</span>
                                                            <div><span className="font-outfit font-extrabold uppercase text-purple-400 block">LOYALTY DISCOUNT</span>
                                                                <p className="text-purple-400/90">{selectedGuestStays} stays — qualifies for 10% off</p></div>
                                                        </div>
                                                        <button type="button" onClick={() => setData('discount_type', 'loyalty')}
                                                            className="px-2.5 py-1 bg-purple-600 hover:bg-purple-500 text-purple-100 rounded-lg text-[9px] font-black uppercase shrink-0 transition-all">
                                                            Apply 10% Off
                                                        </button>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>

                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Full Name *</label>
                                                    <input type="text" value={data.guest_name} onChange={e => setData('guest_name', e.target.value)} required className={`${inputCls} font-bold`} />
                                                    {errors.guest_name && <span className="text-[10px] text-red-400">{errors.guest_name}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Contact</label>
                                                    <input type="text" value={data.guest_contact} onChange={e => setData('guest_contact', e.target.value)} className={inputCls} />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Type</label>
                                                    <input 
                                                        type="text" 
                                                        list="id_types_list" 
                                                        value={data.guest_id_type} 
                                                        onChange={e => setData('guest_id_type', e.target.value)} 
                                                        className={inputCls} 
                                                        placeholder="Select or type..."
                                                    />
                                                    <datalist id="id_types_list">
                                                        <option value="Driver License" />
                                                        <option value="Passport" />
                                                        <option value="UMID / National ID" />
                                                        <option value="SSS / GSIS" />
                                                        <option value="Senior Citizen ID" />
                                                    </datalist>
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Number</label>
                                                    <input type="text" value={data.guest_id_number} onChange={e => setData('guest_id_number', e.target.value)} className={inputCls} />
                                                </div>
                                                <div className="flex flex-col gap-1 col-span-2">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Attach ID / Document</label>
                                                    <input type="file" accept="image/*" onChange={e => setData('id_image', e.target.files[0])} className={`${inputCls} file:mr-4 file:py-1.5 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-brand-500/10 file:text-brand-400 hover:file:bg-brand-500/20 text-slate-300 text-sm p-1`} />
                                                    {data.id_image && (
                                                        <div className="mt-2 cursor-pointer transition-transform hover:scale-105" onClick={() => { setPreviewImage(URL.createObjectURL(data.id_image)); setIsImageModalOpen(true); }}>
                                                            <img src={URL.createObjectURL(data.id_image)} alt="ID Preview" className="h-32 object-contain rounded-lg border border-[#334155]/60 bg-[#0f172a]" />
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Section 2: Booking Details */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Calendar size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Booking Details</h3>
                                            </div>
                                            <div className="grid grid-cols-3 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Room *</label>
                                                    <select value={data.room_id} onChange={e => setData('room_id', e.target.value)} required className={`${inputCls} font-bold`}>
                                                        <option value="">Choose Room</option>
                                                        {vacantRooms.map(r => <option key={r.id} value={r.id}>Room {r.room_number} ({r.type?.type_name})</option>)}
                                                    </select>
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Type</label>
                                                    <select value={data.booking_type} onChange={e => setData('booking_type', e.target.value)} className={`${inputCls} font-bold`}>
                                                        <option value="overnight">Overnight</option>
                                                        <option value="short_time">Short-time (Hourly)</option>
                                                    </select>
                                                </div>
                                                {data.booking_type === 'overnight' ? (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Nights</label>
                                                        <input type="number" min="1" value={data.num_nights} onChange={e => setData('num_nights', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Hours Tier</label>
                                                        <select value={data.short_time_hours} onChange={e => setData('short_time_hours', Number(e.target.value))} className={`${inputCls} font-mono font-bold`}>
                                                            <option value={3}>3 Hours</option><option value={6}>6 Hours</option>
                                                            <option value={12}>12 Hours</option><option value={24}>24 Hours</option>
                                                        </select>
                                                    </div>
                                                )}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Guests</label>
                                                    <input type="number" min="1" value={data.num_guests} onChange={e => setData('num_guests', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discount</label>
                                                    <select value={data.discount_type} onChange={e => setData('discount_type', e.target.value)} className={`${inputCls} font-semibold`}>
                                                        <option value="none">No Discount</option>
                                                        <option value="loyalty">Loyalty (10%)</option>
                                                        <option value="senior">Senior Citizen (20%)</option>
                                                        <option value="pwd">PWD (20%)</option>
                                                        {isAdmin && <>
                                                            <option value="promo">Promo Override</option>
                                                            <option value="staff">Staff Override</option>
                                                            <option value="complimentary">Complimentary</option>
                                                        </>}
                                                    </select>
                                                </div>
                                                {['promo', 'staff'].includes(data.discount_type) && !data.promo_code && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discount Amount (₱)</label>
                                                        <input type="number" step="0.01" min="0" value={data.discount_amount} onChange={e => setData('discount_amount', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                            </div>

                                            {/* Promo codes */}
                                            <div className="flex flex-col gap-1">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Promo Code</label>
                                                {promoCodes.length > 0 && (
                                                    <div className="flex flex-wrap gap-1.5 mb-1">
                                                        {promoCodes.map(pc => (
                                                            <button key={pc.code} type="button"
                                                                onClick={() => { setPromoInput(pc.code); handleApplyPromo(pc.code); }}
                                                                className="px-2 py-1 rounded bg-[#0f172a] border border-[#334155] hover:border-brand-500 text-slate-300 font-mono text-[9px] font-bold uppercase transition-all">
                                                                {pc.code} ({pc.discount_type === 'percentage' ? `${pc.discount_value}%` : `₱${pc.discount_value}`})
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                                <div className="flex gap-2">
                                                    <input type="text" value={promoInput} onChange={e => setPromoInput(e.target.value.toUpperCase())}
                                                        placeholder="ENTER CODE" className={`${inputCls} font-mono font-bold uppercase`} />
                                                    <button type="button" onClick={() => handleApplyPromo()} className="px-3 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold shrink-0 transition-colors">Apply</button>
                                                </div>
                                                {promoError && <p className="text-red-400 text-[10px]">{promoError}</p>}
                                                {data.promo_code && <p className="text-emerald-400 text-[10px] font-semibold">✓ Promo "{data.promo_code}" Applied!</p>}
                                            </div>
                                        </div>

                                        {/* Section 3: Payment */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Payment Details</h3>
                                            </div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Payment Method</label>
                                                    <select value={data.payment_method} onChange={e => setData('payment_method', e.target.value)} className={`${inputCls} font-bold`}>
                                                        <option value="cash">Cash</option>
                                                        <option value="gcash">GCash</option>
                                                        <option value="card">Card</option>
                                                        <option value="bank_transfer">Bank Transfer</option>
                                                        <option value="split">Split (Cash + GCash)</option>
                                                    </select>
                                                </div>
                                                {['gcash', 'split'].includes(data.payment_method) && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash 13-Digit Ref #</label>
                                                        <input type="text" value={data.gcash_ref} onChange={e => setData('gcash_ref', e.target.value)} required
                                                            placeholder="2083920..." className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                                {['card', 'bank_transfer'].includes(data.payment_method) && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">{data.payment_method === 'card' ? 'Approval Code' : 'Bank Ref'}</label>
                                                        <input type="text" value={data.reference_number} onChange={e => setData('reference_number', e.target.value)} required
                                                            placeholder={data.payment_method === 'card' ? 'Auth Code...' : 'BDO-9821...'} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                            </div>
                                            {data.payment_method === 'split' && (
                                                <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] grid grid-cols-2 gap-3">
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cash (₱)</label>
                                                        <input type="number" min="0" max={calc.total_amount} step="any" value={data.cash_amount} onChange={handleCashInput} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash (₱)</label>
                                                        <input type="number" min="0" max={calc.total_amount} step="any" value={data.gcash_amount} onChange={handleGCashInput} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Right — Bill Summary */}
                                    <div className="flex flex-col gap-4">
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4 relative overflow-hidden">
                                            {calc.is_peak && (
                                                <div className="absolute top-0 left-0 right-0 py-1 bg-amber-500 text-slate-950 text-[9px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1">
                                                    <TrendingUp size={9} /> Peak: {calc.peak_label}
                                                </div>
                                            )}
                                            <div className={`flex items-center gap-2.5 border-b border-[#334155] pb-3 ${calc.is_peak ? 'pt-5' : 'pt-1'}`}>
                                                <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="font-outfit font-extrabold text-slate-200 text-sm uppercase tracking-wide">Billing</h3>
                                            </div>
                                            {data.room_id ? (
                                                <div className="flex flex-col gap-3 text-xs">
                                                    <div className="flex justify-between">
                                                        <span className="text-slate-400">Base charges:</span>
                                                        <span className="font-mono text-slate-200 font-bold">₱{calc.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    {calc.peak_surcharge > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400">Peak surcharge:</span>
                                                            <span className="font-mono text-amber-400 font-bold">+ ₱{calc.peak_surcharge.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    {calc.discount_amount > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400 capitalize">{data.discount_type} discount:</span>
                                                            <span className="font-mono text-emerald-400 font-bold">- ₱{calc.discount_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    <div className="h-px bg-[#334155]" />
                                                    <div className="flex justify-between items-baseline">
                                                        <span className="font-outfit font-extrabold text-slate-100 uppercase">Total:</span>
                                                        <span className="font-mono text-xl font-black text-emerald-400">₱{calc.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="flex flex-col gap-1 bg-[#0f172a]/65 p-3 rounded-xl border border-[#334155]">
                                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1"><Calendar size={10} /> Expected Out</span>
                                                        <span className="text-xs text-slate-300 font-bold font-mono">
                                                            {calc.expected_check_out ? new Date(calc.expected_check_out).toLocaleString() : '-'}
                                                        </span>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="py-8 text-center text-xs text-slate-500">Select room to load bill summary.</div>
                                            )}

                                            <button
                                                type="submit"
                                                disabled={processing || !data.room_id}
                                                className="w-full flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:bg-[#334155] disabled:text-slate-500 text-white font-outfit font-extrabold text-sm tracking-wide shadow-lg active:scale-95 transition-all"
                                            >
                                                <CheckCircle size={16} />
                                                {processing ? 'Processing...' : 'Check-In Guest'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* ── Edit Check-In Modal ── */}
            <AnimatePresence>
                {showEditModal && editingBooking && (
                    <motion.div
                        initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-6 px-4"
                        onClick={(e) => { if (e.target === e.currentTarget) closeEditModal(); }}
                    >
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }}
                            transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-4xl bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden"
                        >
                            {/* Modal Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Edit size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">Edit Stay details</h2>
                                        <p className="text-[10px] text-slate-400">Modify details for Ref: {editingBooking.booking_ref}</p>
                                    </div>
                                </div>
                                <button onClick={closeEditModal} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            {/* Modal Body */}
                            <form onSubmit={handleEditFormSubmit} className="p-6">
                                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                    {/* Left — Guest + Stay + Payment */}
                                    <div className="lg:col-span-2 flex flex-col gap-5">

                                        {/* Section 1: Guest Profile */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><UserCheck size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Guest Details</h3>
                                            </div>

                                            {/* Autocomplete */}
                                            <div className="relative">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5 block">Search Returning Guest</label>
                                                <div className="relative">
                                                    <Search className="absolute left-3 top-2.5 text-slate-500" size={14} />
                                                    <input type="text" value={guestSearch} onChange={e => setGuestSearch(e.target.value)}
                                                        placeholder="Type name to autocomplete..."
                                                        className={`${inputCls} pl-9`} />
                                                </div>
                                                {suggestions.length > 0 && (
                                                    <ul className="absolute left-0 right-0 mt-1 bg-[#1e293b] border border-[#334155] rounded-xl shadow-2xl z-[99] text-xs overflow-hidden">
                                                        {suggestions.map(g => (
                                                            <li key={g.id} onClick={() => selectEditGuest(g)}
                                                                className="px-4 py-2.5 hover:bg-[#334155] cursor-pointer flex justify-between items-center text-slate-200">
                                                                <span className="font-bold">{g.full_name}</span>
                                                                <div className="flex items-center gap-2">
                                                                    {g.is_vip && <span className="inline-flex items-center gap-1 text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-bold uppercase"><Crown size={9} /> VIP</span>}
                                                                    {g.total_stays >= 3 && <span className="text-[9px] bg-purple-950 border border-purple-600/30 text-purple-400 px-1.5 py-0.5 rounded font-bold">⭐ {g.total_stays} stays</span>}
                                                                    <span className="text-slate-400">{g.contact_number}</span>
                                                                </div>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>

                                            {/* VIP Banner */}
                                            <AnimatePresence>
                                                {isVip && (
                                                    <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}
                                                        className="p-3 rounded-xl bg-amber-950/20 border border-amber-500/30 flex gap-2 text-xs text-amber-300">
                                                        <Star className="h-4 w-4 text-amber-400 shrink-0 fill-amber-400 mt-0.5" />
                                                        <div><span className="font-outfit font-extrabold uppercase tracking-wide block">VIP GUEST</span>
                                                            <p className="mt-0.5 text-amber-400/90">{vipNotes || 'No notes.'}</p></div>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>

                                            {/* Loyalty Banner */}
                                            <AnimatePresence>
                                                {selectedGuestStays >= 3 && editForm.data.discount_type !== 'loyalty' && (
                                                    <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }}
                                                        className="p-3 rounded-xl bg-purple-950/20 border border-purple-500/30 flex gap-2 text-xs text-purple-300 items-center justify-between">
                                                        <div className="flex gap-2">
                                                            <span>✨</span>
                                                            <div><span className="font-outfit font-extrabold uppercase text-purple-400 block">LOYALTY DISCOUNT</span>
                                                                <p className="text-purple-400/90">{selectedGuestStays} stays — qualifies for 10% off</p></div>
                                                        </div>
                                                        <button type="button" onClick={() => editForm.setData('discount_type', 'loyalty')}
                                                            className="px-2.5 py-1 bg-purple-600 hover:bg-purple-500 text-purple-100 rounded-lg text-[9px] font-black uppercase shrink-0 transition-all">
                                                            Apply 10% Off
                                                        </button>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>

                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Full Name *</label>
                                                    <input type="text" value={editForm.data.guest_name} onChange={e => editForm.setData('guest_name', e.target.value)} required className={`${inputCls} font-bold`} />
                                                    {editForm.errors.guest_name && <span className="text-[10px] text-red-400">{editForm.errors.guest_name}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Contact</label>
                                                    <input type="text" value={editForm.data.guest_contact} onChange={e => editForm.setData('guest_contact', e.target.value)} className={inputCls} />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Type</label>
                                                    <input 
                                                        type="text" 
                                                        list="edit_id_types_list" 
                                                        value={editForm.data.guest_id_type} 
                                                        onChange={e => editForm.setData('guest_id_type', e.target.value)} 
                                                        className={inputCls} 
                                                        placeholder="Select or type..."
                                                    />
                                                    <datalist id="edit_id_types_list">
                                                        <option value="Driver License" />
                                                        <option value="Passport" />
                                                        <option value="UMID / National ID" />
                                                        <option value="SSS / GSIS" />
                                                        <option value="Senior Citizen ID" />
                                                    </datalist>
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Number</label>
                                                    <input type="text" value={editForm.data.guest_id_number} onChange={e => editForm.setData('guest_id_number', e.target.value)} className={inputCls} />
                                                </div>
                                                <div className="flex flex-col gap-1 col-span-2">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Update ID / Document</label>
                                                    <input type="file" accept="image/*" onChange={e => editForm.setData('id_image', e.target.files[0])} className={`${inputCls} file:mr-4 file:py-1.5 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-brand-500/10 file:text-brand-400 hover:file:bg-brand-500/20 text-slate-300 text-sm p-1`} />
                                                    {editForm.data.id_image ? (
                                                        <div className="mt-2 cursor-pointer transition-transform hover:scale-105" onClick={() => { setPreviewImage(URL.createObjectURL(editForm.data.id_image)); setIsImageModalOpen(true); }}>
                                                            <img src={URL.createObjectURL(editForm.data.id_image)} alt="New ID Preview" className="h-32 object-contain rounded-lg border border-[#334155]/60 bg-[#0f172a]" />
                                                        </div>
                                                    ) : editingBooking?.guest_id_image_path ? (
                                                        <div className="mt-2 cursor-pointer transition-transform hover:scale-105" onClick={() => { setPreviewImage(`/storage/${editingBooking.guest_id_image_path}`); setIsImageModalOpen(true); }}>
                                                            <img src={`/storage/${editingBooking.guest_id_image_path}`} alt="Current ID Preview" className="h-32 object-contain rounded-lg border border-[#334155]/60 bg-[#0f172a]" />
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Section 2: Booking Details */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Calendar size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Booking Details</h3>
                                            </div>
                                            <div className="grid grid-cols-3 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Room *</label>
                                                    <select value={editForm.data.room_id} onChange={e => editForm.setData('room_id', e.target.value)} required className={`${inputCls} font-bold`}>
                                                        <option value="">Choose Room</option>
                                                        {editingBooking?.room && !vacantRooms.some(r => r.id === editingBooking.room.id) && (
                                                            <option value={editingBooking.room.id}>Room {editingBooking.room.room_number} ({editingBooking.room.type?.type_name}) [Current]</option>
                                                        )}
                                                        {vacantRooms.map(r => <option key={r.id} value={r.id}>Room {r.room_number} ({r.type?.type_name})</option>)}
                                                    </select>
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Type</label>
                                                    <select value={editForm.data.booking_type} onChange={e => editForm.setData('booking_type', e.target.value)} className={`${inputCls} font-bold`}>
                                                        <option value="overnight">Overnight</option>
                                                        <option value="short_time">Short-time (Hourly)</option>
                                                    </select>
                                                </div>
                                                {editForm.data.booking_type === 'overnight' ? (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Nights</label>
                                                        <input type="number" min="1" value={editForm.data.num_nights} onChange={e => editForm.setData('num_nights', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Hours Tier</label>
                                                        <select value={editForm.data.short_time_hours} onChange={e => editForm.setData('short_time_hours', Number(e.target.value))} className={`${inputCls} font-mono font-bold`}>
                                                            <option value={3}>3 Hours</option><option value={6}>6 Hours</option>
                                                            <option value={12}>12 Hours</option><option value={24}>24 Hours</option>
                                                        </select>
                                                    </div>
                                                )}
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Guests</label>
                                                    <input type="number" min="1" value={editForm.data.num_guests} onChange={e => editForm.setData('num_guests', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discount</label>
                                                    <select value={editForm.data.discount_type} onChange={e => editForm.setData('discount_type', e.target.value)} className={`${inputCls} font-semibold`}>
                                                        <option value="none">No Discount</option>
                                                        <option value="loyalty">Loyalty (10%)</option>
                                                        <option value="senior">Senior Citizen (20%)</option>
                                                        <option value="pwd">PWD (20%)</option>
                                                        {isAdmin && <>
                                                            <option value="promo">Promo Override</option>
                                                            <option value="staff">Staff Override</option>
                                                            <option value="complimentary">Complimentary</option>
                                                        </>}
                                                    </select>
                                                </div>
                                                {['promo', 'staff'].includes(editForm.data.discount_type) && !editForm.data.promo_code && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discount Amount (₱)</label>
                                                        <input type="number" step="0.01" min="0" value={editForm.data.discount_amount} onChange={e => editForm.setData('discount_amount', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                            </div>

                                            {/* Promo codes */}
                                            <div className="flex flex-col gap-1">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Promo Code</label>
                                                {promoCodes.length > 0 && (
                                                    <div className="flex flex-wrap gap-1.5 mb-1">
                                                        {promoCodes.map(pc => (
                                                            <button key={pc.code} type="button"
                                                                onClick={() => { setPromoInput(pc.code); handleApplyEditPromo(pc.code); }}
                                                                className="px-2 py-1 rounded bg-[#0f172a] border border-[#334155] hover:border-brand-500 text-slate-300 font-mono text-[9px] font-bold uppercase transition-all">
                                                                {pc.code} ({pc.discount_type === 'percentage' ? `${pc.discount_value}%` : `₱${pc.discount_value}`})
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                                <div className="flex gap-2">
                                                    <input type="text" value={promoInput} onChange={e => setPromoInput(e.target.value.toUpperCase())}
                                                        placeholder="ENTER CODE" className={`${inputCls} font-mono font-bold uppercase`} />
                                                    <button type="button" onClick={() => handleApplyEditPromo()} className="px-3 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold shrink-0 transition-colors">Apply</button>
                                                </div>
                                                {promoError && <p className="text-red-400 text-[10px]">{promoError}</p>}
                                                {editForm.data.promo_code && <p className="text-emerald-400 text-[10px] font-semibold">✓ Promo "{editForm.data.promo_code}" Applied!</p>}
                                            </div>
                                        </div>

                                        {/* Section 3: Payment */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Payment Details</h3>
                                            </div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Payment Method</label>
                                                    <select value={editForm.data.payment_method} onChange={e => editForm.setData('payment_method', e.target.value)} className={`${inputCls} font-bold`}>
                                                        <option value="cash">Cash</option>
                                                        <option value="gcash">GCash</option>
                                                        <option value="card">Card</option>
                                                        <option value="bank_transfer">Bank Transfer</option>
                                                        <option value="split">Split (Cash + GCash)</option>
                                                    </select>
                                                </div>
                                                {['gcash', 'split'].includes(editForm.data.payment_method) && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash 13-Digit Ref #</label>
                                                        <input type="text" value={editForm.data.gcash_ref} onChange={e => editForm.setData('gcash_ref', e.target.value)} required
                                                            placeholder="2083920..." className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                                {['card', 'bank_transfer'].includes(editForm.data.payment_method) && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">{editForm.data.payment_method === 'card' ? 'Approval Code' : 'Bank Ref'}</label>
                                                        <input type="text" value={editForm.data.reference_number} onChange={e => editForm.setData('reference_number', e.target.value)} required
                                                            placeholder={editForm.data.payment_method === 'card' ? 'Auth Code...' : 'BDO-9821...'} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                            </div>
                                            {editForm.data.payment_method === 'split' && (
                                                <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] grid grid-cols-2 gap-3">
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cash (₱)</label>
                                                        <input type="number" min="0" max={editCalc.total_amount} step="any" value={editForm.data.cash_amount} onChange={handleEditCashInput} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash (₱)</label>
                                                        <input type="number" min="0" max={editCalc.total_amount} step="any" value={editForm.data.gcash_amount} onChange={handleEditGCashInput} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Right — Bill Summary */}
                                    <div className="flex flex-col gap-4">
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4 relative overflow-hidden">
                                            {editCalc.is_peak && (
                                                <div className="absolute top-0 left-0 right-0 py-1 bg-amber-500 text-slate-950 text-[9px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1">
                                                    <TrendingUp size={9} /> Peak: {editCalc.peak_label}
                                                </div>
                                            )}
                                            <div className={`flex items-center gap-2.5 border-b border-[#334155] pb-3 ${editCalc.is_peak ? 'pt-5' : 'pt-1'}`}>
                                                <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="font-outfit font-extrabold text-slate-200 text-sm uppercase tracking-wide">Billing</h3>
                                            </div>
                                            {editForm.data.room_id ? (
                                                <div className="flex flex-col gap-3 text-xs">
                                                    <div className="flex justify-between">
                                                        <span className="text-slate-400">Base charges:</span>
                                                        <span className="font-mono text-slate-200 font-bold">₱{editCalc.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    {editCalc.peak_surcharge > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400">Peak surcharge:</span>
                                                            <span className="font-mono text-amber-400 font-bold">+ ₱{editCalc.peak_surcharge.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    {editCalc.discount_amount > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400 capitalize">{editForm.data.discount_type} discount:</span>
                                                            <span className="font-mono text-emerald-400 font-bold">- ₱{editCalc.discount_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    <div className="h-px bg-[#334155]" />
                                                    <div className="flex justify-between items-baseline">
                                                        <span className="font-outfit font-extrabold text-slate-100 uppercase">Total:</span>
                                                        <span className="font-mono text-xl font-black text-emerald-400">₱{editCalc.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="flex flex-col gap-1 bg-[#0f172a]/65 p-3 rounded-xl border border-[#334155]">
                                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1"><Calendar size={10} /> Expected Out</span>
                                                        <span className="text-xs text-slate-300 font-bold font-mono">
                                                            {editCalc.expected_check_out ? new Date(editCalc.expected_check_out).toLocaleString() : '-'}
                                                        </span>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="py-8 text-center text-xs text-slate-500">Select room to load bill summary.</div>
                                            )}

                                            <button
                                                type="submit"
                                                disabled={editForm.processing || !editForm.data.room_id}
                                                className="w-full flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 disabled:bg-[#334155] disabled:text-slate-500 text-white font-outfit font-extrabold text-sm tracking-wide shadow-lg active:scale-95 transition-all"
                                            >
                                                <CheckCircle size={16} />
                                                {editForm.processing ? 'Saving...' : 'Save Updates'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            <AnimatePresence>
                {isStayModalOpen && (
                    <StayDetailsModal
                        isOpen={isStayModalOpen}
                        bookingId={selectedBookingIdForModal}
                        onClose={() => {
                            setIsStayModalOpen(false);
                            setSelectedBookingIdForModal(null);
                        }}
                        viewMode="checkin"
                    />
                )}
            </AnimatePresence>

            <ImagePreviewModal 
                isOpen={isImageModalOpen}
                imageUrl={previewImage}
                onClose={() => { setIsImageModalOpen(false); setPreviewImage(null); }}
            />
        </AuthenticatedLayout>
    );
}
