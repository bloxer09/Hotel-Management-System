import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    UserCheck,
    Coins,
    Calendar,
    Search,
    Star,
    BedDouble,
    AlertCircle,
    CheckCircle,
    Crown,
    TrendingUp,
    ArrowLeft
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function Create({ rooms = [], roomTypes = [], prefilledGuest, promoCodes = [] }) {
    const { auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';

    // Default check-in tomorrow at 2:00 PM
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(14, 0, 0, 0);
    // Format to YYYY-MM-DDThh:mm for datetime-local input
    const pad = (num) => String(num).padStart(2, '0');
    const defaultCheckInStr = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T${pad(tomorrow.getHours())}:${pad(tomorrow.getMinutes())}`;

    // Autocomplete guest state
    const [searchQuery, setSearchQuery] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [isVip, setIsVip] = useState(false);
    const [vipNotes, setVipNotes] = useState('');
    const [selectedGuestStays, setSelectedGuestStays] = useState(0);

    // Promo validation states
    const [promoInput, setPromoInput] = useState('');
    const [promoError, setPromoError] = useState('');

    // Real-time calculations & conflict check states
    const [calc, setCalc] = useState({
        base_amount: 0,
        peak_surcharge: 0,
        discount_amount: 0,
        total_amount: 0,
        expected_check_out: '',
        is_peak: false,
        peak_label: null,
        conflict: null // holds conflict info if there's double-booking
    });

    const { data, setData, post, processing, errors } = useForm({
        room_id: '',
        check_in: defaultCheckInStr,
        guest_name: '',
        guest_contact: '',
        guest_id_type: 'Driver License',
        guest_id_number: '',
        guest_email: '',
        guest_address: '',
        num_guests: 1,
        booking_type: 'overnight',
        num_nights: 1,
        short_time_hours: 3,
        discount_type: 'none',
        discount_amount: 0,
        promo_code: '',
        payment_method: 'cash',
        cash_amount: 0.00,
        gcash_amount: 0.00,
        gcash_ref: '',
        reference_number: '',
        notes: ''
    });

    // 1. Fetch Guest Autocomplete
    useEffect(() => {
        if (searchQuery.length >= 2) {
            axios.get(route('guests.search', { q: searchQuery }))
                .then(res => setSuggestions(res.data))
                .catch(err => console.error(err));
        } else {
            setSuggestions([]);
        }
    }, [searchQuery]);

    // 1.5. Prefill Guest Details from Prop or SessionStorage
    useEffect(() => {
        let guestToPrefill = prefilledGuest;

        if (!guestToPrefill) {
            const stored = sessionStorage.getItem('quickCheckinGuest');
            if (stored) {
                try {
                    guestToPrefill = JSON.parse(stored);
                } catch (e) {
                    console.error("Failed to parse quickCheckinGuest from sessionStorage", e);
                }
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
            setIsVip(guestToPrefill.is_vip ? true : false);
            setVipNotes(guestToPrefill.vip_notes || '');
            setSelectedGuestStays(guestToPrefill.total_stays || 0);

            // Clean up
            sessionStorage.removeItem('quickCheckinGuest');
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
        setSearchQuery('');
        setSuggestions([]);
    };

    // Apply Promo Code
    const handleApplyPromo = () => {
        if (!promoInput.trim()) {
            setPromoError('Please enter a promo code.');
            return;
        }
        if (!data.room_id) {
            setPromoError('Please select a room first.');
            return;
        }
        axios.post(route('promo_codes.validate'), { code: promoInput })
            .then(res => {
                if (res.data.valid) {
                    setData(prev => ({
                        ...prev,
                        promo_code: res.data.code,
                        discount_type: 'promo',
                        discount_amount: res.data.discount_value
                    }));
                    setPromoError('');
                } else {
                    setPromoError(res.data.message || 'Invalid promo code.');
                }
            })
            .catch(err => {
                setPromoError(err.response?.data?.error || 'Failed to validate promo code.');
            });
    };

    // Dynamic price estimation and overlap verification
    useEffect(() => {
        if (data.room_id && data.check_in) {
            axios.post(route('reservations.calculate'), {
                room_id: data.room_id,
                check_in: data.check_in,
                booking_type: data.booking_type,
                num_nights: data.num_nights,
                short_time_hours: data.short_time_hours,
                discount_type: data.discount_type,
                discount_amount: data.discount_amount,
                promo_code: data.promo_code
            })
                .then(res => {
                    setCalc(res.data);

                    // Adjust payment defaults
                    setData(prev => {
                        const total = res.data.total_amount;
                        if (prev.payment_method === 'cash') {
                            return { ...prev, cash_amount: total, gcash_amount: 0 };
                        } else if (prev.payment_method === 'gcash') {
                            return { ...prev, gcash_amount: total, cash_amount: 0 };
                        } else if (prev.payment_method === 'split') {
                            return { ...prev, cash_amount: Math.round(total / 2), gcash_amount: total - Math.round(total / 2) };
                        } else {
                            return { ...prev, cash_amount: 0, gcash_amount: 0 };
                        }
                    });
                })
                .catch(err => console.error(err));
        }
    }, [data.room_id, data.check_in, data.booking_type, data.num_nights, data.short_time_hours, data.discount_type, data.discount_amount, data.promo_code]);

    const handleCashInput = (e) => {
        const cash = Math.min(calc.total_amount, Math.max(0, Number(e.target.value) || 0));
        const gcash = Math.max(0, calc.total_amount - cash);
        setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: gcash }));
    };

    const handleGCashInput = (e) => {
        const gcash = Math.min(calc.total_amount, Math.max(0, Number(e.target.value) || 0));
        const cash = Math.max(0, calc.total_amount - gcash);
        setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: gcash }));
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        if (calc.conflict) {
            alert("Cannot submit. There is a double-booking conflict for this room during this period.");
            return;
        }
        post(route('reservations.store'));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Create Reservation" />

            <div className="flex flex-col gap-8">

                {/* Back button & Header */}
                <div className="flex flex-col gap-2">
                    <Link
                        href={route('reservations.index')}
                        className="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-slate-200 transition-colors font-bold uppercase tracking-wider font-outfit self-start"
                    >
                        <ArrowLeft size={14} /> Back to Reservations
                    </Link>

                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100 mt-1">
                            New Reservation
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Book future stays, handle deposit allocations, and automatically crosscheck stay overlaps.</p>
                    </div>
                </div>

                <form onSubmit={handleFormSubmit} className="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    {/* Left Forms column */}
                    <div className="lg:col-span-2 flex flex-col gap-8">

                        {/* Guest Details */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5">
                            <div className="flex items-center gap-3.5 mb-2">
                                <div className="p-2.5 bg-indigo-500/10 text-indigo-400 rounded-xl">
                                    <UserCheck size={20} />
                                </div>
                                <h2 className="text-lg font-outfit font-bold text-slate-200">Guest Details</h2>
                            </div>

                            {/* Autocomplete Search */}
                            <div className="relative">
                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-2 block">Search Returning Guest</label>
                                <div className="relative">
                                    <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                                    <input
                                        type="text"
                                        value={searchQuery}
                                        onChange={e => setSearchQuery(e.target.value)}
                                        placeholder="Type guest name to autocomplete details..."
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-sm"
                                    />
                                </div>

                                {suggestions.length > 0 && (
                                    <ul className="absolute left-0 right-0 mt-2 bg-[#1e293b] border border-[#334155] rounded-xl overflow-hidden shadow-2xl z-[99] text-xs">
                                        {suggestions.map(g => (
                                            <li
                                                key={g.id}
                                                onClick={() => selectGuest(g)}
                                                className="px-4 py-3 hover:bg-[#334155] cursor-pointer flex justify-between items-center text-slate-200"
                                            >
                                                <span className="font-bold">{g.full_name}</span>
                                                <div className="flex items-center gap-2">
                                                    {g.is_vip && (
                                                        <span className="inline-flex items-center gap-1 text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-bold uppercase shrink-0">
                                                            <Crown size={9} /> VIP
                                                        </span>
                                                    )}
                                                    {g.total_stays >= 3 && (
                                                        <span className="inline-flex items-center gap-1 text-[9px] bg-purple-950 border border-purple-600/30 text-purple-400 px-1.5 py-0.5 rounded font-bold uppercase shrink-0">
                                                            ⭐ Frequent ({g.total_stays} stays)
                                                        </span>
                                                    )}
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
                                    <motion.div
                                        initial={{ opacity: 0, height: 0 }}
                                        animate={{ opacity: 1, height: 'auto' }}
                                        exit={{ opacity: 0, height: 0 }}
                                        className="p-4 rounded-xl bg-amber-950/20 border border-amber-500/30 flex gap-3 text-xs text-amber-300"
                                    >
                                        <Star className="h-5 w-5 text-amber-400 shrink-0 fill-amber-400 mt-0.5" />
                                        <div className="flex-1">
                                            <span className="font-outfit font-extrabold uppercase tracking-wide block">VIP GUEST PROFILE DETECTED</span>
                                            <p className="mt-1 leading-normal text-amber-400/90 font-medium">VIP Notes: {vipNotes || 'No notes specified.'}</p>
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>

                            {/* Loyalty Discount Offer */}
                            <AnimatePresence>
                                {selectedGuestStays >= 3 && data.discount_type !== 'loyalty' && (
                                    <motion.div
                                        initial={{ opacity: 0, height: 0 }}
                                        animate={{ opacity: 1, height: 'auto' }}
                                        exit={{ opacity: 0, height: 0 }}
                                        className="p-4 rounded-xl bg-purple-950/20 border border-purple-500/30 flex gap-3 text-xs text-purple-300 items-center justify-between"
                                    >
                                        <div className="flex gap-2">
                                            <span className="text-sm">✨</span>
                                            <div className="flex-1">
                                                <span className="font-outfit font-extrabold uppercase tracking-wide block text-purple-400">LOYALTY DISCOUNT SUGGESTED</span>
                                                <p className="mt-0.5 leading-normal text-purple-400/90 font-medium">Guest completed {selectedGuestStays} stays. Apply 10% loyalty discount?</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setData('discount_type', 'loyalty')}
                                            className="px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-purple-100 rounded-lg text-[9px] font-black uppercase shrink-0 transition-all border border-purple-500/30"
                                        >
                                            Apply 10% Off
                                        </button>
                                    </motion.div>
                                )}
                            </AnimatePresence>

                            {/* Inputs fields */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Full Name</label>
                                    <input
                                        type="text"
                                        value={data.guest_name}
                                        onChange={e => setData('guest_name', e.target.value)}
                                        required
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-bold"
                                    />
                                    {errors.guest_name && <span className="text-[10px] text-red-400 mt-1">{errors.guest_name}</span>}
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Contact Number</label>
                                    <input
                                        type="text"
                                        value={data.guest_contact}
                                        onChange={e => setData('guest_contact', e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs"
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Card Type Presented</label>
                                    <select
                                        value={data.guest_id_type}
                                        onChange={e => setData('guest_id_type', e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs"
                                    >
                                        <option value="Driver License">Driver License</option>
                                        <option value="Passport">Passport</option>
                                        <option value="UMID">UMID / National ID</option>
                                        <option value="SSS / GSIS">SSS / GSIS</option>
                                        <option value="Senior Citizen ID">Senior / PWD ID</option>
                                    </select>
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Number</label>
                                    <input
                                        type="text"
                                        value={data.guest_id_number}
                                        onChange={e => setData('guest_id_number', e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Stay Details */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5">
                            <div className="flex items-center gap-3.5 mb-2">
                                <div className="p-2.5 bg-indigo-500/10 text-indigo-400 rounded-xl">
                                    <Calendar size={20} />
                                </div>
                                <h2 className="text-lg font-outfit font-bold text-slate-200">Reservation Dates & Room</h2>
                            </div>

                            {/* Check-In Date selector */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Check-In Date & Time</label>
                                    <input
                                        type="datetime-local"
                                        value={data.check_in}
                                        onChange={e => setData('check_in', e.target.value)}
                                        required
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                    />
                                    {errors.check_in && <span className="text-[10px] text-red-400 mt-1">{errors.check_in}</span>}
                                </div>

                                {/* Room Selector */}
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Room Selection</label>
                                    <select
                                        value={data.room_id}
                                        onChange={e => setData('room_id', e.target.value)}
                                        required
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-bold font-outfit"
                                    >
                                        <option value="">Select Room</option>
                                        {rooms.map(r => (
                                            <option key={r.id} value={r.id}>
                                                Room {r.room_number} ({r.type?.type_name}) - Current status: {r.status}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.room_id && <span className="text-[10px] text-red-400 mt-1">{errors.room_id}</span>}
                                </div>
                            </div>

                            {/* Booking type & duration */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Booking Type</label>
                                    <select
                                        value={data.booking_type}
                                        onChange={e => setData('booking_type', e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-bold"
                                    >
                                        <option value="overnight">Overnight Stay</option>
                                        <option value="short_time">Short time (Hourly)</option>
                                    </select>
                                </div>

                                {data.booking_type === 'overnight' ? (
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Number of Nights</label>
                                        <input
                                            type="number"
                                            min="1"
                                            value={data.num_nights}
                                            onChange={e => setData('num_nights', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                        />
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Short-Time Hours Tier</label>
                                        <select
                                            value={data.short_time_hours}
                                            onChange={e => setData('short_time_hours', Number(e.target.value))}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                        >
                                            <option value={3}>3 Hours</option>
                                            <option value={6}>6 Hours</option>
                                            <option value={12}>12 Hours</option>
                                            <option value={24}>24 Hours</option>
                                        </select>
                                    </div>
                                )}
                            </div>

                            {/* Double Booking Overlap Conflict Alert Banner */}
                            <AnimatePresence>
                                {calc.conflict && (
                                    <motion.div
                                        initial={{ opacity: 0, y: -8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        exit={{ opacity: 0, y: -8 }}
                                        className="p-5 rounded-2xl bg-rose-950/40 border border-rose-500/50 flex gap-3 text-xs text-rose-300 items-start"
                                    >
                                        <AlertCircle className="h-5 w-5 text-rose-400 shrink-0 mt-0.5" />
                                        <div className="flex-1">
                                            <span className="font-outfit font-black uppercase tracking-wider block text-rose-400">⚠️ DOUBLE-BOOKING CONFLICT DETECTED</span>
                                            <p className="mt-1.5 leading-relaxed text-rose-200">
                                                This room is already reserved/occupied by <strong className="text-slate-50 font-black">{calc.conflict.guest_name}</strong> during this range.
                                            </p>
                                            <div className="mt-2 text-[10px] bg-rose-950/80 border border-rose-500/20 p-3 rounded-xl font-mono leading-normal space-y-1">
                                                <div>Conflict Ref: {calc.conflict.booking_ref} ({calc.conflict.status})</div>
                                                <div>Scheduled Check-In: {calc.conflict.check_in}</div>
                                                <div>Scheduled Check-Out: {calc.conflict.expected_check_out}</div>
                                            </div>
                                            <p className="mt-2 text-rose-400/90 font-medium">Please choose another room or stay duration to resolve the conflict.</p>
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>

                            {/* Discounts selector */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discounts</label>
                                    <select
                                        value={data.discount_type}
                                        onChange={e => setData('discount_type', e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-semibold"
                                    >
                                        <option value="none">No Discounts</option>
                                        <option value="loyalty">Loyalty Discount (10%)</option>
                                        <option value="senior">Senior Citizen Discount (20%)</option>
                                        <option value="pwd">PWD Special Discount (20%)</option>
                                        {isAdmin && (
                                            <>
                                                <option value="promo">Promo Override</option>
                                                <option value="staff">Staff Override</option>
                                                <option value="complimentary">Complimentary stay (100% Free)</option>
                                            </>
                                        )}
                                    </select>
                                </div>

                                {['promo', 'staff'].includes(data.discount_type) && !data.promo_code && (
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Custom Discount (₱)</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={data.discount_amount}
                                            onChange={e => setData('discount_amount', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                        />
                                    </div>
                                )}

                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Promo Code</label>
                                    {promoCodes && promoCodes.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5 mb-1.5">
                                            {promoCodes.map(pc => {
                                                const label = pc.discount_type === 'percentage' ? `${pc.code} (${pc.discount_value}%)` : `${pc.code} (₱${pc.discount_value})`;
                                                return (
                                                    <button
                                                        key={pc.code}
                                                        type="button"
                                                        onClick={() => {
                                                            setPromoInput(pc.code);
                                                            if (!data.room_id) {
                                                                setPromoError('Select a room first.');
                                                                return;
                                                            }
                                                            axios.post(route('promo_codes.validate'), { code: pc.code })
                                                                .then(res => {
                                                                    if (res.data.valid) {
                                                                        setData(prev => ({
                                                                            ...prev,
                                                                            promo_code: res.data.code,
                                                                            discount_type: 'promo',
                                                                            discount_amount: res.data.discount_value
                                                                        }));
                                                                        setPromoError('');
                                                                    } else {
                                                                        setPromoError(res.data.message || 'Invalid code.');
                                                                    }
                                                                })
                                                                .catch(err => {
                                                                    setPromoError(err.response?.data?.error || 'Failed validation.');
                                                                });
                                                        }}
                                                        className="px-2 py-1 rounded bg-[#0f172a] border border-[#334155] hover:border-brand-500 hover:bg-brand-950/20 text-slate-300 font-mono text-[9px] font-bold uppercase transition-all duration-200"
                                                    >
                                                        {label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}

                                    <div className="flex gap-2">
                                        <input
                                            type="text"
                                            value={promoInput}
                                            onChange={e => setPromoInput(e.target.value.toUpperCase())}
                                            placeholder="ENTER CODE"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold uppercase"
                                        />
                                        <button
                                            type="button"
                                            onClick={handleApplyPromo}
                                            className="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-slate-100 rounded-xl text-xs font-bold shrink-0 transition-colors"
                                        >
                                            Apply
                                        </button>
                                    </div>
                                    {promoError && <p className="text-red-400 text-[10px] mt-1">{promoError}</p>}
                                    {data.promo_code && <p className="text-emerald-400 text-[10px] font-semibold mt-1">✓ Promo "{data.promo_code}" Applied!</p>}
                                </div>
                            </div>
                        </div>

                        {/* Payments */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5">
                            <div className="flex items-center gap-3.5 mb-2">
                                <div className="p-2.5 bg-[#1e293b] text-indigo-400 rounded-xl border border-[#334155]">
                                    <Coins size={20} />
                                </div>
                                <h2 className="text-lg font-outfit font-bold text-slate-200">Reservation Deposit Payment</h2>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Payment Method</label>
                                    <select
                                        value={data.payment_method}
                                        onChange={e => setData('payment_method', e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-bold"
                                    >
                                        <option value="cash">Cash</option>
                                        <option value="gcash">GCash</option>
                                        <option value="card">Card</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="split">Split Payment (Cash + GCash)</option>
                                    </select>
                                </div>

                                {['gcash', 'split'].includes(data.payment_method) && (
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash 13-Digit Reference Number</label>
                                        <input
                                            type="text"
                                            value={data.gcash_ref}
                                            onChange={e => setData('gcash_ref', e.target.value)}
                                            required
                                            placeholder="Ref #: 2083920..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                        />
                                        {errors.gcash_ref && <span className="text-[10px] text-red-400">{errors.gcash_ref}</span>}
                                    </div>
                                )}

                                {data.payment_method === 'card' && (
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Card Reference Number / Approval Code</label>
                                        <input
                                            type="text"
                                            value={data.reference_number}
                                            onChange={e => setData('reference_number', e.target.value)}
                                            required
                                            placeholder="e.g. Auth Code: 123456..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                        />
                                    </div>
                                )}

                                {data.payment_method === 'bank_transfer' && (
                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Bank Transfer Reference</label>
                                        <input
                                            type="text"
                                            value={data.reference_number}
                                            onChange={e => setData('reference_number', e.target.value)}
                                            required
                                            placeholder="e.g. Bank Reference: BDO-9821..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                        />
                                    </div>
                                )}
                            </div>

                            {data.payment_method === 'split' && (
                                <div className="p-5 rounded-2xl bg-[#0f172a]/60 border border-[#334155] flex flex-col gap-4">
                                    <div className="flex items-center justify-between text-xs font-semibold text-slate-400">
                                        <span>Specify payment split</span>
                                        <span className="font-mono text-slate-300">Total Due: ₱{calc.total_amount.toLocaleString()}</span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="flex flex-col gap-1.5">
                                            <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cash Amount (₱)</label>
                                            <input
                                                type="number"
                                                min="0"
                                                max={calc.total_amount}
                                                step="any"
                                                value={data.cash_amount}
                                                onChange={handleCashInput}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                            />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash Amount (₱)</label>
                                            <input
                                                type="number"
                                                min="0"
                                                max={calc.total_amount}
                                                step="any"
                                                value={data.gcash_amount}
                                                onChange={handleGCashInput}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-mono font-bold"
                                            />
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Right column: invoice and submit */}
                    <div className="flex flex-col gap-6">

                        {/* Invoice billing summary */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5 relative overflow-hidden">
                            {calc.is_peak && (
                                <div className="absolute top-0 left-0 right-0 py-1.5 bg-amber-500 text-slate-950 text-[10px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1 shadow-sm">
                                    <TrendingUp size={10} className="text-slate-950 shrink-0" />
                                    <span>Peak Demand Surcharge: {calc.peak_label}</span>
                                </div>
                            )}

                            <div className="flex items-center gap-3.5 border-b border-[#334155] pb-3 pt-2">
                                <div className="p-2.5 bg-emerald-500/10 text-emerald-400 rounded-xl">
                                    <Coins size={20} />
                                </div>
                                <h3 className="font-outfit font-extrabold text-slate-200 text-base uppercase tracking-wide">
                                    Billing Breakdown
                                </h3>
                            </div>

                            {data.room_id ? (
                                <div className="flex flex-col gap-4 text-xs">
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium">Room Base charges:</span>
                                        <span className="font-mono text-slate-200 font-bold">₱{calc.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                    {calc.peak_surcharge > 0 && (
                                        <div className="flex justify-between">
                                            <span className="text-slate-400 font-medium">Holiday peak surcharge:</span>
                                            <span className="font-mono text-slate-200 font-bold text-amber-400">+ ₱{calc.peak_surcharge.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    )}
                                    {calc.discount_amount > 0 && (
                                        <div className="flex justify-between">
                                            <span className="text-slate-400 font-medium capitalize">{data.discount_type} discount:</span>
                                            <span className="font-mono text-emerald-400 font-bold">- ₱{calc.discount_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    )}

                                    <div className="h-px bg-[#334155] my-2" />

                                    <div className="flex justify-between items-baseline">
                                        <span className="font-outfit font-extrabold text-slate-100 text-sm uppercase">Total Due:</span>
                                        <span className="text-2xl font-mono text-emerald-400 font-black">
                                            ₱{calc.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </span>
                                    </div>

                                    {calc.expected_check_out && (
                                        <div className="bg-[#0f172a]/60 border border-[#334155]/60 rounded-xl p-3.5 mt-2 space-y-1.5 leading-normal">
                                            <div className="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Expected Stay Duration</div>
                                            <div className="text-xs text-slate-200 font-medium font-mono">
                                                IN: {new Date(data.check_in).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                                            </div>
                                            <div className="text-xs text-slate-300 font-semibold font-mono">
                                                OUT: {new Date(calc.expected_check_out).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="text-center py-6 text-xs text-slate-500 font-medium">
                                    Select a room and dates to calculate billing invoices.
                                </div>
                            )}
                        </div>

                        {/* Notes input */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-3.5">
                            <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">Internal Staff Notes</label>
                            <textarea
                                value={data.notes}
                                onChange={e => setData('notes', e.target.value)}
                                placeholder="Add comments regarding special requests, dietary concerns, checkin logs..."
                                rows="3"
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-3 focus:outline-none focus:border-brand-500 text-xs font-medium"
                            />
                        </div>

                        {/* Submit Button */}
                        <button
                            type="submit"
                            disabled={processing || !data.room_id || !!calc.conflict}
                            className="w-full py-4 rounded-2xl text-xs font-black uppercase tracking-wider text-white bg-brand-600 hover:bg-brand-500 disabled:bg-[#334155]/30 disabled:text-slate-500 border border-brand-500/30 hover:border-brand-400/40 transition-all shadow-xl shadow-brand-950/20 flex items-center justify-center gap-2 cursor-pointer"
                        >
                            {processing ? (
                                <span>Registering...</span>
                            ) : calc.conflict ? (
                                <span>⚠️ Overlap Conflict</span>
                            ) : (
                                <>
                                    <CheckCircle size={16} /> Complete Booking
                                </>
                            )}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
