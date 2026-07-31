import React, { useState, useEffect, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Search, Calendar, Coins, UserCheck, XCircle, Plus, AlertTriangle,
    Crown, Star, TrendingUp, AlertCircle, CheckCircle, CheckCircle2, X, ChevronLeft,
    ChevronRight, Eye, Clock, BedDouble, Edit, RefreshCw, ChevronDown
} from 'lucide-react';
import { AnimatePresence, motion } from 'framer-motion';
import AlertModal from '@/Components/AlertModal';
import ConfirmModal from '@/Components/ConfirmModal';
import StayDetailsModal from '@/Components/StayDetailsModal';
import GroupSettleModal from '@/Components/GroupSettleModal';
import ImagePreviewModal from '@/Components/ImagePreviewModal';
import CustomSelect from '@/Components/CustomSelect';
import ActionModal from '@/Components/ActionModal';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';
const FILTER_TABS = [
    { key: 'all', label: 'All Bookings', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: 'reserved', label: 'Pending', color: 'text-indigo-400', dot: 'bg-indigo-400' },
    { key: 'active', label: 'Checked In', color: 'text-emerald-400', dot: 'bg-emerald-400' },
    { key: 'checked_out', label: 'Completed', color: 'text-slate-400', dot: 'bg-slate-400' },
    { key: 'cancelled', label: 'Cancelled', color: 'text-rose-400', dot: 'bg-rose-400' },
    { key: 'no_show', label: 'No Show', color: 'text-amber-500', dot: 'bg-amber-500' },
];

export default function Index({ reservations, groupBookings = {}, currentFilter, showGroupsOnly: propShowGroupsOnly = false, rooms = [], promoCodes = [], sortBy, sortDir, calendarBookings = [], calendarMonth, calendarView = false }) {
    const { auth } = usePage().props;
    const flash = usePage().props.flash || {};
    const isAdmin = auth?.user?.role === 'admin';

    const [showGroupsOnly, setShowGroupsOnly] = useState(propShowGroupsOnly);
    const [settleGroupRef, setSettleGroupRef] = useState(null);
    const [isGroupSettleOpen, setIsGroupSettleOpen] = useState(false);

    useEffect(() => {
        setShowGroupsOnly(propShowGroupsOnly);
    }, [propShowGroupsOnly]);

    useEffect(() => {
        setBookingView(calendarView ? 'calendar' : 'list');
    }, [calendarView]);

    // ── List state ──
    const [searchTerm, setSearchTerm] = useState('');
    const [viewStayId, setViewStayId] = useState(null);
    const [actionModalBooking, setActionModalBooking] = useState(null);
    const [bookingView, setBookingView] = useState(calendarView ? 'calendar' : 'list');


    // ── New Booking Modal ──
    const [showBookingModal, setShowBookingModal] = useState(false);
    const [showRoomSelectModal, setShowRoomSelectModal] = useState(false);
    const [roomFilter, setRoomFilter] = useState('all');
    const [availableRooms, setAvailableRooms] = useState(rooms);
    const [isLoadingRooms, setIsLoadingRooms] = useState(false);
    const [showConfirmBookingModal, setShowConfirmBookingModal] = useState(false);

    // ── Check-In Confirm Modal ──
    const [showCheckInModal, setShowCheckInModal] = useState(false);
    const [selectedCheckInRes, setSelectedCheckInRes] = useState(null);
    const [alertMessage, setAlertMessage] = useState(null);
    const [confirmAction, setConfirmAction] = useState(null);
    const settlementForm = useForm({
        payer_name: '',
        payer_contact: '',
        payment_method_code: 'cash',
        reference_number: '',
        cash_amount: 0,
        gcash_amount: 0,
        remarks: '',
    });

    // ── Cancel Modal ──
    const [showCancelModal, setShowCancelModal] = useState(false);
    const [selectedCancelRes, setSelectedCancelRes] = useState(null);
    const [cancelReason, setCancelReason] = useState('');
    const [cancelDisposition, setCancelDisposition] = useState('retain');
    const [cancelRefundAmount, setCancelRefundAmount] = useState('');

    const [isImageModalOpen, setIsImageModalOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState(null);

    // ── Booking wizard state ──
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(14, 0, 0, 0);
    const pad = (n) => String(n).padStart(2, '0');
    // Parses a server datetime string ("YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DDTHH:MM:SS")
    // as hotel-local wall-clock time, never converting through UTC.
    const parseLocalDatetime = (str) => {
        if (!str) return null;
        // Normalise: replace space separator with T, strip sub-seconds and timezone
        const normalized = str.toString().replace(' ', 'T').replace(/\.\d+$/, '').replace(/Z$/, '').replace(/[+-]\d{2}:\d{2}$/, '');
        const [datePart, timePart = '00:00'] = normalized.split('T');
        const [y, mo, d] = datePart.split('-').map(Number);
        const [h = 0, m = 0, s = 0] = (timePart || '00:00').split(':').map(Number);
        return new Date(y, mo - 1, d, h, m, s);
    };
    const formatLocalForInput = (str) => {
        const dt = parseLocalDatetime(str);
        if (!dt) return '';
        return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
    };
    const todayKey = `${new Date().getFullYear()}-${pad(new Date().getMonth() + 1)}-${pad(new Date().getDate())}`;
    const activeCalendarMonth = calendarMonth || todayKey.slice(0, 7);
    const [selectedCalendarDate, setSelectedCalendarDate] = useState(todayKey);

    useEffect(() => {
        setSelectedCalendarDate(activeCalendarMonth === todayKey.slice(0, 7) ? todayKey : `${activeCalendarMonth}-01`);
    }, [activeCalendarMonth]);

    const calendarBookingsByDate = useMemo(() => {
        const [year, month] = activeCalendarMonth.split('-').map(Number);
        const monthStart = new Date(year, month - 1, 1);
        const monthEnd = new Date(year, month, 0);

        return calendarBookings.reduce((dates, booking) => {
            const checkIn = parseLocalDatetime(booking.check_in);
            const expectedCheckOut = parseLocalDatetime(booking.expected_check_out);
            if (!checkIn || !expectedCheckOut) return dates;

            const checkInDay = new Date(checkIn.getFullYear(), checkIn.getMonth(), checkIn.getDate());
            const checkOutDay = new Date(expectedCheckOut.getFullYear(), expectedCheckOut.getMonth(), expectedCheckOut.getDate());
            const checkoutStartsDay = expectedCheckOut.getHours() === 0 && expectedCheckOut.getMinutes() === 0;
            const finalStayDay = checkoutStartsDay
                ? new Date(checkOutDay.getFullYear(), checkOutDay.getMonth(), checkOutDay.getDate() - 1)
                : checkOutDay;
            const cursor = new Date(Math.max(checkInDay.getTime(), monthStart.getTime()));
            const lastDay = new Date(Math.min(finalStayDay.getTime(), monthEnd.getTime()));

            for (let day = cursor; day <= lastDay; day.setDate(day.getDate() + 1)) {
                const key = `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`;
                const isArrival = day.getTime() === checkInDay.getTime();
                const isCheckout = day.getTime() === checkOutDay.getTime();
                const phase = isArrival && isCheckout ? 'same_day' : (isArrival ? 'arrival' : (isCheckout ? 'checkout' : 'in_house'));
                dates[key] = [...(dates[key] || []), { booking, phase }];
            }
            return dates;
        }, {});
    }, [calendarBookings, activeCalendarMonth]);

    const calendarDays = useMemo(() => {
        const [year, month] = activeCalendarMonth.split('-').map(Number);
        const firstDay = new Date(year, month - 1, 1);
        const totalDays = new Date(year, month, 0).getDate();
        return [
            ...Array.from({ length: firstDay.getDay() }, () => null),
            ...Array.from({ length: totalDays }, (_, index) => new Date(year, month - 1, index + 1)),
        ];
    }, [activeCalendarMonth]);

    const selectedDayBookings = calendarBookingsByDate[selectedCalendarDate] || [];
    const selectedDayBookedRoomIds = [...new Set(selectedDayBookings.map(({ booking }) => booking.room_id))];
    const selectedDayAvailableRooms = Math.max(0, rooms.length - selectedDayBookedRoomIds.length);
    const calendarLabel = new Date(`${activeCalendarMonth}-01T00:00:00`).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const selectedDayLabel = new Date(`${selectedCalendarDate}T00:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

    const switchBookingView = (view) => {
        if (view === bookingView) return;
        if (view === 'calendar') {
            router.get(route('reservations.index'), {
                status: currentFilter,
                show_groups_only: showGroupsOnly ? '1' : '0',
                view: 'calendar',
                calendar_month: activeCalendarMonth,
            }, { preserveScroll: true, replace: true });
            return;
        }
        router.get(route('reservations.index'), {
            status: currentFilter,
            show_groups_only: showGroupsOnly ? '1' : '0',
        }, { preserveScroll: true, replace: true });
    };

    const navigateCalendarMonth = (offset) => {
        const [year, month] = activeCalendarMonth.split('-').map(Number);
        const date = new Date(year, month - 1 + offset, 1);
        const nextMonth = `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
        router.get(route('reservations.index'), {
            status: currentFilter,
            show_groups_only: showGroupsOnly ? '1' : '0',
            view: 'calendar',
            calendar_month: nextMonth,
        }, { preserveScroll: true, replace: true });
    };

    const goToToday = () => {
        if (activeCalendarMonth === todayKey.slice(0, 7)) {
            setSelectedCalendarDate(todayKey);
            return;
        }
        router.get(route('reservations.index'), {
            status: currentFilter,
            show_groups_only: showGroupsOnly ? '1' : '0',
            view: 'calendar',
            calendar_month: todayKey.slice(0, 7),
        }, { preserveScroll: true, replace: true });
    };
    const defaultCheckIn = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T${pad(tomorrow.getHours())}:${pad(tomorrow.getMinutes())}`;

    const [guestSearch, setGuestSearch] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [isVip, setIsVip] = useState(false);
    const [vipNotes, setVipNotes] = useState('');
    const [selectedGuestStays, setSelectedGuestStays] = useState(0);
    const [promoInput, setPromoInput] = useState('');
    const [promoError, setPromoError] = useState('');
    const [calc, setCalc] = useState({
        base_amount: 0, peak_surcharge: 0, discount_amount: 0,
        total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null, conflict: null
    });

    const { data, setData, post, processing, errors, reset } = useForm({
        room_ids: [], check_in: defaultCheckIn,
        guest_name: '', guest_contact: '', booker_name: '', booker_contact: '', guest_id_type: 'Driver License',
        guest_id_number: '', id_image: null, guest_email: '', guest_address: '', extra_pax: {},
        booking_type: 'overnight', num_nights: 1, short_time_hours: 3,
        discount_type: 'none', discount_amount: 0, promo_code: '',
        booking_source: '',
        payment_ratio: 'full',
        payment_method: '', cash_received: '', cash_amount: 0.00, gcash_amount: 0.00,
        gcash_ref: '', reference_number: '', notes: ''
    });

    // ── Edit Stay Modal States ──
    const [showEditModal, setShowEditModal] = useState(false);
    const [editingBooking, setEditingBooking] = useState(null);
    const [editCalc, setEditCalc] = useState({
        base_amount: 0, peak_surcharge: 0, discount_amount: 0,
        total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null, conflict: null
    });

    const editForm = useForm({
        room_ids: [], check_in: defaultCheckIn,
        guest_name: '', guest_contact: '', guest_id_type: 'Driver License',
        guest_id_number: '', id_image: null, guest_email: '', guest_address: '', num_guests: 1,
        booking_type: 'overnight', num_nights: 1, short_time_hours: 3,
        discount_type: 'none', discount_amount: 0, promo_code: '',
        notes: ''
    });

    // Live pricing + conflict check for edit form
    useEffect(() => {
        if (editForm.data.room_id && editForm.data.check_in) {
            axios.post(route('reservations.calculate'), {
                room_ids: [editForm.data.room_id], check_in: editForm.data.check_in,
                booking_type: editForm.data.booking_type, num_nights: editForm.data.num_nights,
                short_time_hours: editForm.data.short_time_hours, discount_type: editForm.data.discount_type,
                num_guests: editForm.data.num_guests,
                discount_amount: editForm.data.discount_amount, promo_code: editForm.data.promo_code
            }).then(res => {
                setEditCalc(res.data);
            }).catch(() => { });
        }
    }, [editForm.data.room_id, editForm.data.check_in, editForm.data.booking_type, editForm.data.num_nights, editForm.data.short_time_hours, editForm.data.discount_type, editForm.data.discount_amount, editForm.data.promo_code, editForm.data.num_guests]);

    const selectEditGuest = (g) => {
        editForm.setData(prev => ({
            ...prev, guest_name: g.full_name, guest_contact: g.contact_number || '',
            guest_id_type: g.id_type || 'Driver License', guest_id_number: g.id_number || '',
            guest_email: g.email || '', guest_address: g.address || ''
        }));
        setIsVip(g.is_vip); setVipNotes(g.vip_notes || ''); setSelectedGuestStays(g.total_stays || 0);
        setGuestSearch(''); setSuggestions([]);
    };

    const handleApplyEditPromo = (codeOverride) => {
        const code = codeOverride || promoInput;
        if (!code.trim()) { setPromoError('Enter a promo code.'); return; }
        if (!editForm.data.room_id) { setPromoError('Select a room first.'); return; }
        axios.post(route('promo_codes.validate'), { code })
            .then(res => {
                if (res.data.valid) {
                    editForm.setData(prev => ({ ...prev, promo_code: res.data.code, discount_type: 'promo', discount_amount: res.data.discount_value }));
                    setPromoError('');
                } else setPromoError(res.data.message || 'Invalid promo code.');
            })
            .catch(err => setPromoError(err.response?.data?.error || 'Failed to validate.'));
    };

    const handleEditFormSubmit = (e) => {
        e.preventDefault();
        const conflict = editCalc.totals?.conflict ?? editCalc.conflict;
        if (conflict) { setAlertMessage('Double-booking conflict. Choose another room or date.'); return; }
        editForm.transform((data) => ({
            ...data,
            _method: 'PUT',
        }));

        editForm.post(route('bookings.update', editingBooking.id), {
            onSuccess: () => {
                setShowEditModal(false);
                setEditingBooking(null);
            }
        });
    };

    const openEditModal = (booking) => {
        setEditingBooking(booking);
        const formattedCheckIn = formatLocalForInput(booking.check_in);

        editForm.setData({
            room_id: booking.room_id,
            check_in: formattedCheckIn,
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
            notes: booking.notes || ''
        });
        setEditCalc({
            base_amount: booking.base_amount || 0,
            peak_surcharge: booking.peak_surcharge || 0,
            discount_amount: booking.discount_amount || 0,
            total_amount: booking.total_amount || 0,
            expected_check_out: booking.expected_check_out || '',
            is_peak: booking.is_peak || false,
            peak_label: null,
            conflict: null
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

    // ── Reschedule Modal States ──
    const [showRescheduleModal, setShowRescheduleModal] = useState(false);
    const [reschedulingBooking, setReschedulingBooking] = useState(null);
    const [rescheduleCalc, setRescheduleCalc] = useState({
        base_amount: 0, peak_surcharge: 0, discount_amount: 0,
        total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null, conflict: null
    });

    const rescheduleForm = useForm({
        room_id: '',
        check_in: '',
        booking_type: 'overnight',
        num_nights: 1,
        short_time_hours: 3
    });

    useEffect(() => {
        if (rescheduleForm.data.room_id && rescheduleForm.data.check_in) {
            axios.post(route('reservations.calculate'), {
                room_id: rescheduleForm.data.room_id,
                check_in: rescheduleForm.data.check_in,
                booking_type: rescheduleForm.data.booking_type,
                num_nights: rescheduleForm.data.num_nights,
                short_time_hours: rescheduleForm.data.short_time_hours,
                num_guests: reschedulingBooking?.num_guests || 1,
                discount_type: reschedulingBooking?.discount_type || 'none',
                discount_amount: reschedulingBooking?.discount_amount || 0,
                promo_code: reschedulingBooking?.promo_code || ''
            }).then(res => {
                setRescheduleCalc(res.data);
            }).catch(() => { });
        }
    }, [rescheduleForm.data.room_id, rescheduleForm.data.check_in, rescheduleForm.data.booking_type, rescheduleForm.data.num_nights, rescheduleForm.data.short_time_hours, reschedulingBooking, reschedulingBooking?.num_guests]);

    const handleRescheduleSubmit = (e) => {
        e.preventDefault();
        if (rescheduleCalc.conflict) {
            setAlertMessage('Double-booking conflict. Choose another room or date.');
            return;
        }
        rescheduleForm.post(route('reservations.reschedule', reschedulingBooking.id), {
            onSuccess: () => {
                setShowRescheduleModal(false);
                setReschedulingBooking(null);
                rescheduleForm.reset();
            }
        });
    };

    const openRescheduleModal = (booking) => {
        setReschedulingBooking(booking);
        const formattedCheckIn = formatLocalForInput(booking.check_in);
        rescheduleForm.setData({
            room_id: booking.room_id,
            check_in: formattedCheckIn,
            booking_type: booking.booking_type || 'overnight',
            num_nights: booking.booking_type === 'overnight' ? (booking.num_nights || 1) : 1,
            short_time_hours: booking.booking_type !== 'overnight' ? (booking.short_time_hours || 3) : 3
        });
        setRescheduleCalc({
            base_amount: booking.base_amount || 0,
            peak_surcharge: booking.peak_surcharge || 0,
            discount_amount: booking.discount_amount || 0,
            total_amount: booking.total_amount || 0,
            expected_check_out: booking.expected_check_out || '',
            is_peak: booking.is_peak || false,
            peak_label: null,
            conflict: null
        });
        setShowRescheduleModal(true);
    };

    const handleNoShow = (booking) => {
        setConfirmAction({
            title: 'Confirm No Show',
            message: `Are you sure you want to mark Reservation ${booking.booking_ref} for ${booking.guest_name} as No Show?`,
            onConfirm: () => {
                router.post(route('reservations.noshow', booking.id));
                setConfirmAction(null);
            }
        });
    };

    // Guest autocomplete
    useEffect(() => {
        if (guestSearch.length >= 2) {
            axios.get(route('guests.search', { q: guestSearch }))
                .then(res => setSuggestions(res.data)).catch(() => { });
        } else setSuggestions([]);
    }, [guestSearch]);

    const selectGuest = (g) => {
        setData(prev => ({
            ...prev, guest_name: g.full_name, guest_contact: g.contact_number || '',
            guest_id_type: g.id_type || 'Driver License', guest_id_number: g.id_number || '',
            guest_email: g.email || '', guest_address: g.address || ''
        }));
        setIsVip(g.is_vip); setVipNotes(g.vip_notes || ''); setSelectedGuestStays(g.total_stays || 0);
        setGuestSearch(''); setSuggestions([]);
    };

    const handleApplyPromo = (codeOverride) => {
        const code = codeOverride || promoInput;
        if (!code.trim()) { setPromoError('Enter a promo code.'); return; }
        if (!data.room_ids || data.room_ids.length === 0) { setPromoError('Select at least one room first.'); return; }
        axios.post(route('promo_codes.validate'), { code })
            .then(res => {
                if (res.data.valid) {
                    setData(prev => ({ ...prev, promo_code: res.data.code, discount_type: 'promo', discount_amount: res.data.discount_value }));
                    setPromoError('');
                } else setPromoError(res.data.message || 'Invalid promo code.');
            })
            .catch(err => setPromoError(err.response?.data?.error || 'Failed to validate.'));
    };

    useEffect(() => {
        if (data.room_ids && data.room_ids.length > 0 && data.check_in) {
            axios.post(route('reservations.calculate'), {
                room_ids: data.room_ids, check_in: data.check_in,
                booking_type: data.booking_type, num_nights: data.num_nights,
                short_time_hours: data.short_time_hours, discount_type: data.discount_type,
                extra_pax: data.extra_pax,
                discount_amount: data.discount_amount, promo_code: data.promo_code
            }).then(res => {
                setCalc(res.data);
                setData(prev => {
                    const total = res.data.totals.total_amount;
                    const due = prev.payment_ratio === 'half' ? Math.round(total / 2) : total;
                    if (prev.payment_method === 'cash') return { ...prev, cash_amount: due, gcash_amount: 0 };
                    if (prev.payment_method === 'gcash') return { ...prev, gcash_amount: due, cash_amount: 0 };
                    if (prev.payment_method === 'split') return { ...prev, cash_amount: Math.round(due / 2), gcash_amount: due - Math.round(due / 2) };
                    return { ...prev, cash_amount: 0, gcash_amount: 0 };
                });
            }).catch(() => { });
        }
    }, [JSON.stringify(data.room_ids), data.check_in, data.booking_type, data.num_nights, data.short_time_hours, data.discount_type, data.discount_amount, data.promo_code, data.payment_ratio, data.payment_method, JSON.stringify(data.extra_pax)]);

    useEffect(() => {
        if (!showBookingModal) {
            setData('cash_received', '');
        }
    }, [showBookingModal]);

    useEffect(() => {
        if (data.check_in && showBookingModal) {
            setIsLoadingRooms(true);
            axios.post(route('reservations.available_rooms'), {
                check_in: data.check_in,
                booking_type: data.booking_type,
                num_nights: data.num_nights,
                short_time_hours: data.short_time_hours,
            }).then(res => {
                setAvailableRooms(res.data.available_rooms);
                setIsLoadingRooms(false);
            }).catch(() => { setIsLoadingRooms(false); });
        }
    }, [data.check_in, data.booking_type, data.num_nights, data.short_time_hours, showBookingModal]);

    const handleCashInput = (e) => {
        const total = calc.totals ? calc.totals.total_amount : calc.total_amount;
        const due = data.payment_ratio === 'half' ? Math.round(total / 2) : total;
        const cash = Math.min(due, Math.max(0, Number(e.target.value) || 0));
        setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: Math.max(0, due - cash) }));
    };
    const handleGCashInput = (e) => {
        const total = calc.totals ? calc.totals.total_amount : calc.total_amount;
        const due = data.payment_ratio === 'half' ? Math.round(total / 2) : total;
        const gcash = Math.min(due, Math.max(0, Number(e.target.value) || 0));
        setData(prev => ({ ...prev, gcash_amount: gcash, cash_amount: Math.max(0, due - gcash) }));
    };

    const bookingTotal = Number(calc.totals?.total_amount || calc.total_amount || 0);
    const bookingAmountDue = data.payment_ratio === 'half'
        ? Math.round(bookingTotal / 2)
        : bookingTotal;
    const bookingChange = data.payment_method === 'cash'
        ? Math.max(0, Number(data.cash_received || 0) - bookingAmountDue)
        : 0;

    const handleBookingSourceChange = (source) => {
        setData(prev => ({
            ...prev,
            booking_source: source,
            payment_method: source === 'online' ? 'gcash' : 'cash',
            cash_received: '',
            cash_amount: source === 'walk_in' ? bookingAmountDue : 0,
            gcash_amount: source === 'online' ? bookingAmountDue : 0,
            gcash_ref: '',
            reference_number: '',
        }));
    };

    const handleBookingPaymentMethodChange = (method) => {
        setData(prev => ({
            ...prev,
            payment_method: method,
            cash_received: '',
            cash_amount: method === 'cash' ? bookingAmountDue : 0,
            gcash_amount: method === 'gcash' ? bookingAmountDue : 0,
            gcash_ref: '',
            reference_number: '',
        }));
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        if ((calc.totals || calc).conflict) { setAlertMessage('Double-booking conflict. Choose another room or date.'); return; }
        if (!data.booking_source) { setAlertMessage('Choose Walk-in or Online Booking first.'); return; }
        if (!data.payment_method) { setAlertMessage('Choose a payment method.'); return; }
        if (data.payment_method === 'cash' && Number(data.cash_received || 0) + 0.01 < bookingAmountDue) {
            setAlertMessage('Cash received must be at least the amount due today.');
            return;
        }
        if (data.payment_method !== 'cash' && !(data.gcash_ref || data.reference_number || '').trim()) {
            setAlertMessage('Enter the GCash or bank transaction reference.');
            return;
        }
        setShowConfirmBookingModal(true);
    };

    const executeFormSubmit = () => {
        post(route('reservations.store'), {
            onSuccess: () => {
                setShowConfirmBookingModal(false);
                setShowBookingModal(false);
            }
        });
    };

    const openBookingModal = () => {
        reset(); setCalc({ base_amount: 0, peak_surcharge: 0, discount_amount: 0, total_amount: 0, expected_check_out: '', is_peak: false, peak_label: null, conflict: null });
        setIsVip(false); setVipNotes(''); setSelectedGuestStays(0);
        setGuestSearch(''); setSuggestions([]); setPromoInput(''); setPromoError('');
        setShowBookingModal(true);
    };

    // Filter + pagination helpers
    const items = reservations?.data || [];
    const filtered = searchTerm.trim()
        ? items.filter(r =>
            r.booking_ref?.toLowerCase().includes(searchTerm.toLowerCase()) ||
            r.guest_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
            r.guest_contact?.includes(searchTerm) ||
            r.room?.room_number?.toString().includes(searchTerm)
        )
        : items;

    const handleFilterChange = (status) => {
        router.get(route('reservations.index'), {
            status,
            show_groups_only: showGroupsOnly ? '1' : '0'
        }, { preserveState: true, replace: true });
    };

    const handleToggleGroupsOnly = () => {
        const nextVal = !showGroupsOnly;
        setShowGroupsOnly(nextVal);
        router.get(route('reservations.index'), {
            status: currentFilter,
            show_groups_only: nextVal ? '1' : '0'
        }, { preserveState: true, replace: true });
    };
    const handlePageChange = (url) => {
        if (url) router.get(url, {}, { preserveState: true, replace: true });
    };

    const handleGroupCheckIn = (groupRef) => {
        if (confirm(`Are you sure you want to check in all rooms for Group ${groupRef}?`)) {
            router.post(route('reservations.group_checkin', groupRef));
        }
    };

    const handleGroupCheckOut = (groupRef) => {
        setSettleGroupRef(groupRef);
        setIsGroupSettleOpen(true);
    };

    const triggerCheckIn = (res) => {
        const balance = Math.max(0, Number(res.total_amount || 0) - Number(res.amount_paid || 0));
        setSelectedCheckInRes(res);
        settlementForm.setData({
            payer_name: res.booker_name || res.guest_name || '',
            payer_contact: res.booker_contact || res.guest_contact || '',
            payment_method_code: 'cash',
            reference_number: '',
            cash_amount: balance,
            gcash_amount: 0,
            remarks: `Arrival balance for ${res.booking_ref}`,
        });
        setShowCheckInModal(true);
    };

    const closeCheckInModal = () => {
        setShowCheckInModal(false);
        setSelectedCheckInRes(null);
        settlementForm.clearErrors();
    };

    const confirmCheckIn = () => {
        if (!selectedCheckInRes) return;
        const balance = Math.max(0, Number(selectedCheckInRes.total_amount || 0) - Number(selectedCheckInRes.amount_paid || 0));

        if (balance <= 0.01) {
            router.post(route('reservations.checkin', selectedCheckInRes.id), {}, {
                onSuccess: closeCheckInModal,
            });
            return;
        }

        const payload = {
            payer_name: settlementForm.data.payer_name,
            payer_contact: settlementForm.data.payer_contact,
            payment_method_code: settlementForm.data.payment_method_code,
            reference_number: settlementForm.data.reference_number,
            remarks: settlementForm.data.remarks,
        };
        if (settlementForm.data.payment_method_code === 'split') {
            payload.components = [
                {
                    payment_method_code: 'cash',
                    amount: Number(settlementForm.data.cash_amount || 0),
                },
                {
                    payment_method_code: 'gcash',
                    amount: Number(settlementForm.data.gcash_amount || 0),
                    reference_number: settlementForm.data.reference_number,
                },
            ];
        }

        // Inertia's transform() mutates the form transform callback but does not
        // return the form instance, so it cannot be chained with post().
        settlementForm.transform(() => payload);
        settlementForm.post(route('reservations.settle_checkin', selectedCheckInRes.id), {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!page.props.flash?.error) closeCheckInModal();
            },
            onFinish: () => settlementForm.transform(values => values),
        });
    };
    const selectedCheckInBalance = selectedCheckInRes
        ? Math.max(0, Number(selectedCheckInRes.total_amount || 0) - Number(selectedCheckInRes.amount_paid || 0))
        : 0;
    const selectedPendingPayment = Number(selectedCheckInRes?.pending_payment_amount || 0);

    const handleSettlementMethodChange = (method) => {
        settlementForm.setData(prev => ({
            ...prev,
            payment_method_code: method,
            reference_number: '',
            cash_amount: method === 'cash'
                ? selectedCheckInBalance
                : (method === 'split' ? Math.floor(selectedCheckInBalance / 2) : 0),
            gcash_amount: method === 'split'
                ? selectedCheckInBalance - Math.floor(selectedCheckInBalance / 2)
                : 0,
        }));
    };

    const handleSettlementCashChange = (value) => {
        const cash = Math.min(selectedCheckInBalance, Math.max(0, Number(value) || 0));
        settlementForm.setData(prev => ({
            ...prev,
            cash_amount: cash,
            gcash_amount: Math.max(0, selectedCheckInBalance - cash),
        }));
    };

    const handleSettlementGcashChange = (value) => {
        const gcash = Math.min(selectedCheckInBalance, Math.max(0, Number(value) || 0));
        settlementForm.setData(prev => ({
            ...prev,
            gcash_amount: gcash,
            cash_amount: Math.max(0, selectedCheckInBalance - gcash),
        }));
    };
    const triggerCancel = (res) => {
        setSelectedCancelRes(res);
        setCancelReason('');
        setCancelDisposition('retain');
        setCancelRefundAmount('');
        setShowCancelModal(true);
    };
    const confirmCancel = () => {
        if (!selectedCancelRes || !cancelReason.trim()) return;
        router.post(route('reservations.cancel', selectedCancelRes.id), {
            reason: cancelReason,
            payment_disposition: cancelDisposition,
            refund_amount: cancelDisposition === 'partial_refund' ? cancelRefundAmount : null,
        }, {
            onSuccess: () => {
                setShowCancelModal(false);
                setSelectedCancelRes(null);
                setCancelReason('');
                setCancelDisposition('retain');
                setCancelRefundAmount('');
            }
        });
    };

    const inputCls = "w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs";
    const activeTab = FILTER_TABS.find(t => t.key === currentFilter) || FILTER_TABS.find(t => t.key === 'reserved');

    return (
        <AuthenticatedLayout>
            <Head title="Bookings" />

            <AnimatePresence>
                {flash.success && (
                    <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -20 }}
                        className="mb-4 p-4 rounded-xl bg-emerald-950/40 border border-emerald-500/30 text-emerald-300 text-sm font-medium flex items-center gap-2">
                        <CheckCircle size={16} /> {flash.success}
                    </motion.div>
                )}
            </AnimatePresence>

            <div className="flex flex-col gap-6">

                {/* Header */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">Bookings</h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Register future reservations and manage guest arrivals.</p>
                    </div>
                    <div className="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                        <div className="flex bg-[#0f172a] p-1 rounded-xl border border-[#334155] text-[10px] font-black uppercase shadow-inner">
                            <button type="button" onClick={() => switchBookingView('list')} className={`px-3 py-1.5 rounded-lg transition-all ${bookingView === 'list' ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/70' : 'text-slate-500 hover:text-slate-300'}`}>
                                List
                            </button>
                            <button type="button" onClick={() => switchBookingView('calendar')} className={`px-3 py-1.5 rounded-lg transition-all flex items-center gap-1 ${bookingView === 'calendar' ? 'bg-[#1e293b] text-slate-100 shadow border border-[#334155]/70' : 'text-slate-500 hover:text-slate-300'}`}>
                                <Calendar size={13} /> Calendar
                            </button>
                        </div>
                        <button onClick={openBookingModal}
                            className="flex items-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-brand-600/20 active:scale-95 shrink-0 w-full sm:w-auto justify-center">
                            <Plus size={16} /> New Booking
                        </button>
                    </div>
                </div>

                {bookingView === 'list' && (
                <>
                {/* Tabs + Search */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    {/* Status CustomSelect Dropdown */}
                    <CustomSelect
                        value={currentFilter}
                        onChange={handleFilterChange}
                        containerClassName="sm:w-56"
                        options={FILTER_TABS}
                    />
                    <div className="flex items-center gap-2 w-full sm:w-auto">
                        <button
                            onClick={handleToggleGroupsOnly}
                            className={`px-3.5 py-2.5 rounded-xl font-outfit font-bold text-xs transition-all border flex items-center gap-1.5 active:scale-95 shrink-0 ${showGroupsOnly
                                ? 'bg-indigo-600 hover:bg-indigo-500 text-white border-indigo-500/30'
                                : 'bg-[#1e293b] border-[#334155] text-slate-400 hover:text-slate-200'
                                }`}
                        >
                            <span className={`w-1.5 h-1.5 rounded-full bg-indigo-400 ${showGroupsOnly ? 'opacity-100' : 'opacity-40'}`} />
                            Group Bookings
                        </button>
                        <div className="relative w-full sm:w-64">
                            <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                            <input type="text" value={searchTerm} onChange={e => setSearchTerm(e.target.value)}
                                placeholder="Search ref, guest, room..."
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs" />
                        </div>
                        <button onClick={() => router.reload({ only: ['reservations'] })} className="p-2.5 rounded-xl border border-[#334155] bg-[#1e293b] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 transition-all shrink-0 shadow-sm" title="Refresh Table">
                            <RefreshCw size={16} />
                        </button>
                    </div>
                </div>
                </>
                )}

                {/* Table */}
                {bookingView === 'list' && <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        {currentFilter === 'groups' ? (
                            <table className="w-full min-w-[1100px] text-xs table-fixed">
                                <thead>
                                    <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[150px]">Group Ref</th>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[180px]">Guest</th>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Rooms Included</th>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[180px]">Schedule</th>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-[120px]">Type</th>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right w-[150px]">Combined Billing</th>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-center w-[160px]">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {Object.keys(groupBookings).length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                                                No active group bookings found.
                                            </td>
                                        </tr>
                                    ) : (
                                        Object.entries(groupBookings).map(([groupRef, bookingsList], idx) => {
                                            const firstBooking = bookingsList[0] || {};
                                            const groupTotalAmount = bookingsList.reduce((sum, b) => sum + Number(b.total_amount || 0), 0);
                                            const groupAmountPaid = bookingsList.reduce((sum, b) => sum + Number(b.amount_paid || 0), 0);
                                            const hasReserved = bookingsList.some(b => b.status === 'reserved');
                                            const hasActive = bookingsList.some(b => b.status === 'active');

                                            return (
                                                <motion.tr
                                                    key={groupRef}
                                                    initial={{ opacity: 0, y: 6 }}
                                                    animate={{ opacity: 1, y: 0 }}
                                                    transition={{ delay: idx * 0.03 }}
                                                    className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors"
                                                >
                                                    <td className="px-4 py-3">
                                                        <span className="font-mono text-indigo-400 font-bold block">{groupRef}</span>
                                                        <span className="text-[10px] text-slate-500">Group Booking</span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="font-semibold text-slate-200">{firstBooking.guest_name || 'Guest'}</div>
                                                        {firstBooking.guest_contact && <div className="text-slate-500 text-[10px]">{firstBooking.guest_contact}</div>}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {bookingsList.map(b => (
                                                                <span key={b.id} className="inline-flex items-center gap-1.5 px-2 py-1 rounded text-[10px] font-bold bg-[#0f172a] border border-[#334155] text-slate-350">
                                                                    Room {b.room?.room_number}
                                                                    <span className={`w-1.5 h-1.5 rounded-full ${b.status === 'active' ? 'bg-emerald-400' :
                                                                        b.status === 'reserved' ? 'bg-indigo-400' :
                                                                            b.status === 'checked_out' ? 'bg-slate-400' : 'bg-red-400'
                                                                        }`} title={b.status} />
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-300 font-mono leading-normal">
                                                        {firstBooking.check_in ? (
                                                            <>
                                                                <div className="text-[10px] text-slate-400 font-sans">IN: <span className="font-mono font-bold text-slate-300">{parseLocalDatetime(firstBooking.check_in)?.toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })}</span></div>
                                                                <div className="text-[10px] text-slate-400 font-sans mt-0.5">OUT: <span className="font-mono font-bold text-slate-300">{parseLocalDatetime(firstBooking.expected_check_out)?.toLocaleString('en-US', { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })}</span></div>
                                                            </>
                                                        ) : '-'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-black uppercase bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                                                            {bookingsList.length} Rooms Group
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex flex-col items-end gap-0.5">
                                                            <span className="font-mono text-emerald-400 font-bold">₱{groupTotalAmount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            {groupAmountPaid < groupTotalAmount ? (
                                                                <div className="flex flex-col items-end gap-0.5 mt-0.5">
                                                                    <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                                        Partially Paid
                                                                    </span>
                                                                    <span className="font-mono text-[9px] text-rose-400 font-semibold">
                                                                        Bal: ₱{(groupTotalAmount - groupAmountPaid).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                                    </span>
                                                                </div>
                                                            ) : (
                                                                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                                    Paid
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 text-center">
                                                        <div className="flex justify-center items-center gap-2">
                                                            {hasReserved && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleGroupCheckIn(groupRef)}
                                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 hover:bg-brand-500 border border-brand-500/30 rounded-lg text-[10px] font-bold text-white transition-colors cursor-pointer"
                                                                >
                                                                    Check-In
                                                                </button>
                                                            )}
                                                            {hasActive && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleGroupCheckOut(groupRef)}
                                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-600 hover:bg-rose-500 border border-rose-500/30 rounded-lg text-[10px] font-bold text-white transition-colors cursor-pointer"
                                                                >
                                                                    Check-Out
                                                                </button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </motion.tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            <table className="w-full min-w-[900px] text-xs table-fixed">
                                <thead>
                                    <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                        <SortableHeader sortKey="id" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Ref / Room</SortableHeader>
                                        <SortableHeader sortKey="guest_name" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Guest</SortableHeader>
                                        <SortableHeader sortKey="check_in_time" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Schedule</SortableHeader>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Type</th>
                                        <SortableHeader sortKey="amount" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Amount</SortableHeader>
                                        <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filtered.length === 0 ? (
                                        <tr><td colSpan={6} className="px-4 py-12 text-center text-slate-500">
                                            {searchTerm ? `No results for "${searchTerm}"` : `No ${activeTab.label.toLowerCase()} bookings found.`}
                                        </td></tr>
                                    ) : filtered.map((b, i) => (
                                        <motion.tr key={b.id} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}
                                            className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">
                                            <td className="px-4 py-3">
                                                <span className="font-mono text-brand-400 font-bold block">{b.booking_ref}</span>
                                                {b.group_ref && <span className="text-[9px] bg-indigo-500/20 text-indigo-300 px-1.5 py-0.5 rounded font-bold uppercase block mt-1 w-fit">Group: {b.group_ref}</span>}
                                                <span className="text-slate-300 font-bold text-[11px]">Room {b.room?.room_number}</span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="font-semibold text-slate-200">{b.guest_name}</span>
                                                    {b.guest_profile?.is_vip && <span className="text-[8px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1 rounded font-bold">VIP</span>}
                                                </div>
                                                <span className="text-slate-500 text-[10px]">{b.guest_contact || '—'}</span>
                                            </td>
                                            <td className="px-4 py-3 leading-normal">
                                                <div className="flex items-center gap-1 text-indigo-400 font-bold text-[10px]">IN: <span className="font-mono text-slate-300">{parseLocalDatetime(b.check_in)?.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</span></div>
                                                <div className="flex items-center gap-1 text-slate-500 text-[10px]">OUT: <span className="font-mono text-slate-400">{parseLocalDatetime(b.expected_check_out)?.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</span></div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-black uppercase ${b.booking_type === 'overnight'
                                                    ? 'bg-brand-500/10 text-brand-400 border border-brand-500/20'
                                                    : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                                                    }`}>
                                                    <BedDouble size={9} />
                                                    {b.booking_type === 'overnight' ? 'Overnight' : `${b.short_time_hours}h`}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="font-mono text-emerald-400 font-bold">₱{Number(b.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    {Number(b.amount_paid) < Number(b.total_amount) ? (
                                                        <div className="flex flex-col gap-0.5 mt-0.5">
                                                            <span className="inline-flex items-center w-fit px-1.5 py-0.5 rounded text-[8px] font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                                Partially Paid
                                                            </span>
                                                            <span className="font-mono text-[9px] text-amber-400 font-semibold">
                                                                Paid: ₱{Number(b.amount_paid).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                            </span>
                                                            <span className="font-mono text-[9px] text-rose-400 font-semibold">
                                                                Bal: ₱{Number(b.total_amount - b.amount_paid).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <div className="text-slate-500 text-[10px] capitalize">{b.payment_method}</div>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <button onClick={() => setActionModalBooking(b)} className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] hover:bg-slate-800 border border-[#334155] rounded-lg text-[10px] font-bold text-slate-300 transition-colors">
                                                    Manage
                                                </button>
                                            </td>
                                        </motion.tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    {/* Pagination */}
                    {currentFilter !== 'groups' && reservations && reservations.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                            <span className="text-[10px] text-slate-500">
                                Showing {reservations.from}–{reservations.to} of {reservations.total} records
                            </span>
                            <Pagination links={reservations.links} />
                        </div>
                    )}
                </div>}

                {bookingView === 'calendar' && (
                    <section className="flex flex-col gap-5">
                        <div className="rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl overflow-hidden">
                            <div className="p-5 border-b border-[#334155] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                <div>
                                    <h2 className="font-outfit font-extrabold text-lg text-slate-100">Booking Calendar</h2>
                                    <p className="text-xs text-slate-400 mt-1">Every active booking blocks its room for the full stay, so front desk can check availability safely.</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button type="button" onClick={() => navigateCalendarMonth(-1)} className="p-2 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-300 hover:border-brand-500/40 hover:text-white transition-colors" aria-label="Previous month">
                                        <ChevronLeft size={17} />
                                    </button>
                                    <button type="button" onClick={goToToday} className="px-3 py-2 rounded-lg bg-[#0f172a] border border-[#334155] text-[10px] font-black uppercase text-brand-300 hover:border-brand-500/40 transition-colors">
                                        Today
                                    </button>
                                    <button type="button" onClick={() => navigateCalendarMonth(1)} className="p-2 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-300 hover:border-brand-500/40 hover:text-white transition-colors" aria-label="Next month">
                                        <ChevronRight size={17} />
                                    </button>
                                </div>
                            </div>

                            <div className="px-5 pt-5 flex items-center justify-between gap-3">
                                <h3 className="font-outfit font-bold text-base text-slate-200">{calendarLabel}</h3>
                                <div className="flex items-center gap-3 text-[10px] font-semibold text-slate-400">
                                    <span className="flex items-center gap-1"><i className="w-2 h-2 rounded-full bg-indigo-400" /> Reserved</span>
                                    <span className="flex items-center gap-1"><i className="w-2 h-2 rounded-full bg-emerald-400" /> Checked in</span>
                                    <span className="flex items-center gap-1"><i className="w-2 h-2 rounded-full bg-amber-400" /> Checkout</span>
                                    <span className="flex items-center gap-1"><i className="w-2 h-2 rounded-full bg-rose-400" /> In-house</span>
                                </div>
                            </div>

                            <div className="p-5 overflow-x-auto">
                                <div className="min-w-[720px]">
                                    <div className="grid grid-cols-7 mb-2">
                                        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(day => (
                                            <div key={day} className="px-2 text-[10px] font-black text-slate-500 uppercase tracking-wider">{day}</div>
                                        ))}
                                    </div>
                                    <div className="grid grid-cols-7 border-l border-t border-[#334155] rounded-xl overflow-hidden">
                                        {calendarDays.map((day, index) => {
                                            if (!day) return <div key={`blank-${index}`} className="min-h-[124px] bg-[#0f172a]/20 border-r border-b border-[#334155]" />;
                                            const dateKey = `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`;
                                            const dayBookings = calendarBookingsByDate[dateKey] || [];
                                            const bookedRoomCount = new Set(dayBookings.map(({ booking }) => booking.room_id)).size;
                                            const isSelected = selectedCalendarDate === dateKey;
                                            const isToday = todayKey === dateKey;
                                            return (
                                                <button type="button" key={dateKey} onClick={() => setSelectedCalendarDate(dateKey)} className={`min-h-[124px] p-2 text-left border-r border-b border-[#334155] transition-colors ${isSelected ? 'bg-brand-500/10 ring-1 ring-inset ring-brand-500/45' : 'bg-[#1e293b] hover:bg-[#0f172a]/45'}`}>
                                                    <div className="flex items-center justify-between mb-2">
                                                        <span className={`w-6 h-6 flex items-center justify-center rounded-full text-[11px] font-black ${isToday ? 'bg-brand-500 text-white' : 'text-slate-300'}`}>{day.getDate()}</span>
                                                        {dayBookings.length > 0 && <span className="text-[9px] font-black text-brand-300" title={`${bookedRoomCount} booked room${bookedRoomCount === 1 ? '' : 's'}`}>{bookedRoomCount}</span>}
                                                    </div>
                                                    <div className="flex flex-col gap-1">
                                                        {dayBookings.slice(0, 2).map(({ booking, phase }) => {
                                                            const time = phase === 'checkout'
                                                                ? parseLocalDatetime(booking.expected_check_out)
                                                                : parseLocalDatetime(booking.check_in);
                                                            const label = phase === 'arrival' ? 'IN' : (phase === 'checkout' ? 'OUT' : (phase === 'same_day' ? 'STAY' : 'IN-HOUSE'));
                                                            const tone = phase === 'arrival'
                                                                ? (booking.status === 'active' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-indigo-500/15 text-indigo-300')
                                                                : (phase === 'checkout' ? 'bg-amber-500/15 text-amber-300' : 'bg-rose-500/15 text-rose-300');
                                                            return <span key={`${booking.id}-${phase}`} className={`block truncate px-1.5 py-1 rounded text-[9px] font-bold ${tone}`}>
                                                                {label === 'IN-HOUSE' ? label : `${label} ${time?.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}`} · Rm {booking.room?.room_number || '—'}
                                                            </span>;
                                                        })}
                                                        {dayBookings.length > 2 && <span className="text-[9px] font-bold text-slate-500">+{dayBookings.length - 2} more · {bookedRoomCount} rooms blocked</span>}
                                                    </div>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                            <div className="p-5 border-b border-[#334155] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                                <div>
                                    <h3 className="font-outfit font-bold text-slate-100 text-base">{selectedDayLabel}</h3>
                                    <p className="text-xs text-slate-400 mt-1">{selectedDayBookedRoomIds.length} room{selectedDayBookedRoomIds.length === 1 ? '' : 's'} unavailable on this date · {selectedDayAvailableRooms} not booked.</p>
                                </div>
                                <span className="px-2 py-1 rounded-md text-[10px] font-black bg-rose-500/15 text-rose-300 border border-rose-500/25">{selectedDayBookedRoomIds.length} ROOMS BLOCKED</span>
                            </div>
                            <div className="p-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                                {selectedDayBookings.length > 0 ? selectedDayBookings.map(({ booking, phase }) => {
                                    const arrival = parseLocalDatetime(booking.check_in);
                                    const departure = parseLocalDatetime(booking.expected_check_out);
                                    const phaseLabel = phase === 'arrival' ? 'Arrival' : (phase === 'checkout' ? 'Checkout today' : (phase === 'same_day' ? 'Same-day stay' : 'In-house'));
                                    const phaseTone = phase === 'arrival'
                                        ? (booking.status === 'active' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-indigo-500/15 text-indigo-300')
                                        : (phase === 'checkout' ? 'bg-amber-500/15 text-amber-300' : 'bg-rose-500/15 text-rose-300');
                                    return (
                                        <button type="button" key={`${booking.id}-${phase}`} onClick={() => setViewStayId(booking.id)} className="p-4 rounded-xl bg-[#0f172a]/50 hover:bg-[#0f172a] border border-[#334155] hover:border-brand-500/40 text-left transition-colors">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className={`text-[9px] font-black uppercase px-1.5 py-0.5 rounded ${phaseTone}`}>{phaseLabel}</span>
                                                <span className="text-[10px] font-mono text-slate-400">Room {booking.room?.room_number || '—'}</span>
                                            </div>
                                            <div className="font-outfit font-bold text-sm text-slate-100 mt-3 truncate">{booking.guest_name}</div>
                                            <div className="text-[11px] text-slate-400 mt-1">Arrival: <span className="font-mono text-slate-300">{arrival?.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) || '—'}</span></div>
                                            <div className="text-[11px] text-slate-400 mt-0.5">Checkout: <span className="font-mono text-slate-300">{departure?.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) || '—'}</span></div>
                                            <div className="text-[10px] text-brand-400 font-bold mt-3">View booking details →</div>
                                        </button>
                                    );
                                }) : (
                                    <div className="md:col-span-2 xl:col-span-3 py-8 text-center text-sm text-slate-500">No rooms are blocked by a booking on this date.</div>
                                )}
                            </div>
                        </div>
                    </section>
                )}
            </div>

            {/* ── New Booking Modal ── */}
            <AnimatePresence>
                {showBookingModal && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-6 px-4"
                        onClick={e => { if (e.target === e.currentTarget) setShowBookingModal(false); }}>
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }} transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-4xl bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden">

                            {/* Modal Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Calendar size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">New Booking</h2>
                                        <p className="text-[10px] text-slate-400">Register a future reservation and collect deposit</p>
                                    </div>
                                </div>
                                <button onClick={() => setShowBookingModal(false)} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            {/* Modal Body */}
                            <form onSubmit={handleFormSubmit} className="p-6">
                                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                    {/* Left — Forms */}
                                    <div className="lg:col-span-2 flex flex-col gap-5">

                                        {/* Guest Details */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl"><UserCheck size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Guest Details</h3>
                                            </div>
                                            {/* Autocomplete */}
                                            <div className="relative">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5 block">Search Returning Guest</label>
                                                <div className="relative">
                                                    <Search className="absolute left-3 top-2.5 text-slate-500" size={14} />
                                                    <input type="text" value={guestSearch} onChange={e => setGuestSearch(e.target.value)}
                                                        placeholder="Type name to autocomplete..." className={`${inputCls} pl-9`} />
                                                </div>
                                                {suggestions.length > 0 && (
                                                    <ul className="absolute left-0 right-0 mt-1 bg-[#1e293b] border border-[#334155] rounded-xl shadow-2xl z-[99] text-xs overflow-hidden">
                                                        {suggestions.map(g => (
                                                            <li key={g.id} onClick={() => selectGuest(g)}
                                                                className="px-4 py-2.5 hover:bg-[#334155] cursor-pointer flex justify-between items-center text-slate-200">
                                                                <span className="font-bold">{g.full_name}</span>
                                                                <div className="flex items-center gap-2">
                                                                    {g.is_vip && <span className="text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-bold uppercase inline-flex items-center gap-1"><Crown size={9} /> VIP</span>}
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
                                                        <div className="flex gap-2"><span>✨</span>
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
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Person Who Booked / Payer</label>
                                                    <input type="text" value={data.booker_name} onChange={e => setData('booker_name', e.target.value)}
                                                        placeholder="Leave blank if same as guest" className={inputCls} />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Booker Contact</label>
                                                    <input type="text" value={data.booker_contact} onChange={e => setData('booker_contact', e.target.value)}
                                                        placeholder="Leave blank if same as guest" className={inputCls} />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">ID Type</label>
                                                    <input
                                                        type="text"
                                                        list="res_id_types_list"
                                                        value={data.guest_id_type}
                                                        onChange={e => setData('guest_id_type', e.target.value)}
                                                        className={inputCls}
                                                        placeholder="Select or type..."
                                                    />
                                                    <datalist id="res_id_types_list">
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
                                                <div className="flex flex-col gap-1 sm:col-span-2">
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

                                        {/* Reservation Details */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl"><Calendar size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Booking Details</h3>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Check-In Date & Time *</label>
                                                    <input type="datetime-local" value={data.check_in} onChange={e => setData('check_in', e.target.value)} required className={`${inputCls} font-mono font-bold`} />
                                                    {errors.check_in && <span className="text-[10px] text-red-400">{errors.check_in}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Rooms (Multiple) *</label>
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowRoomSelectModal(true)}
                                                        className={`w-full text-left flex items-center justify-between px-3 py-2 bg-[#0f172a]/60 border border-[#334155] hover:border-[#475569] rounded-xl transition-all ${inputCls.replace('px-3 py-2.5', '').replace('bg-[#0f172a]', '')} font-bold`}
                                                    >
                                                        <span className={data.room_ids.length ? 'text-slate-200' : 'text-slate-500'}>
                                                            {data.room_ids.length > 0
                                                                ? `${data.room_ids.length} Room${data.room_ids.length > 1 ? 's' : ''} Selected`
                                                                : 'Select Rooms...'}
                                                        </span>
                                                        <div className="w-5 h-5 rounded bg-[#1e293b] flex items-center justify-center border border-[#334155] text-slate-400">
                                                            <Plus size={12} />
                                                        </div>
                                                    </button>
                                                    {errors.room_ids && <span className="text-[10px] text-red-400">{errors.room_ids}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Type</label>
                                                    <CustomSelect value={data.booking_type} onChange={e => setData('booking_type', e.target.value)} className={`${inputCls} font-bold`} elevateWhenOpen>
                                                        <option value="overnight">Overnight</option>
                                                        <option value="short_time">Short-time (Hourly)</option>
                                                    </CustomSelect>
                                                </div>
                                                {data.booking_type === 'overnight' ? (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Nights</label>
                                                        <input type="number" min="1" value={data.num_nights} onChange={e => setData('num_nights', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Hours</label>
                                                        <CustomSelect value={data.short_time_hours} onChange={e => setData('short_time_hours', Number(e.target.value))} className={`${inputCls} font-mono font-bold`}>
                                                            <option value={3}>3 Hours</option><option value={6}>6 Hours</option>
                                                            <option value={12}>12 Hours</option><option value={24}>24 Hours</option>
                                                        </CustomSelect>
                                                    </div>
                                                )}

                                            </div>

                                            {/* Conflict Banner */}
                                            <AnimatePresence>
                                                {(calc.totals || calc).conflict && (
                                                    <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                                                        className="p-4 rounded-xl bg-rose-950/40 border border-rose-500/50 flex gap-3 text-xs text-rose-300 items-start">
                                                        <AlertCircle className="h-5 w-5 text-rose-400 shrink-0 mt-0.5" />
                                                        <div>
                                                            <span className="font-outfit font-black uppercase tracking-wider block text-rose-400">⚠️ DOUBLE-BOOKING CONFLICT</span>
                                                            <p className="mt-1 text-rose-200">Room is already reserved by <strong className="text-white">{(calc.totals || calc).conflict.guest_name}</strong> during this period.</p>
                                                            <div className="mt-2 text-[10px] bg-rose-950/80 border border-rose-500/20 p-2.5 rounded-xl font-mono space-y-1">
                                                                <div>Ref: {(calc.totals || calc).conflict.booking_ref} ({(calc.totals || calc).conflict.status})</div>
                                                                <div>IN: {(calc.totals || calc).conflict.check_in} | OUT: {(calc.totals || calc).conflict.expected_check_out}</div>
                                                            </div>
                                                        </div>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>

                                            {/* Discount */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discount</label>
                                                    <CustomSelect value={data.discount_type} onChange={e => setData('discount_type', e.target.value)} className={`${inputCls} font-semibold`}>
                                                        <option value="none">No Discount</option>
                                                        <option value="loyalty">Loyalty (10%)</option>
                                                        <option value="senior">Senior Citizen (20%)</option>
                                                        <option value="pwd">PWD (20%)</option>
                                                        {isAdmin && <>
                                                            <option value="promo">Promo Override</option>
                                                            <option value="staff">Staff Override</option>
                                                            <option value="complimentary">Complimentary</option>
                                                        </>}
                                                    </CustomSelect>
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
                                                {data.promo_code && <p className="text-emerald-400 text-[10px] font-semibold">✓ "{data.promo_code}" Applied!</p>}
                                            </div>
                                        </div>

                                        {/* Select the source before payment to prevent misclassified transactions. */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl"><Coins size={16} /></div>
                                                <div>
                                                    <h3 className="text-sm font-outfit font-bold text-slate-200">Booking & Payment</h3>
                                                    <p className="text-[10px] text-slate-500 mt-0.5">Select how the booking was received before recording payment.</p>
                                                </div>
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">1. Booking Source *</label>
                                                <div className="grid grid-cols-2 gap-2">
                                                    <button type="button" onClick={() => handleBookingSourceChange('walk_in')}
                                                        className={`rounded-xl border px-3 py-3 text-left transition-all ${data.booking_source === 'walk_in' ? 'border-emerald-500 bg-emerald-500/10 text-emerald-300' : 'border-[#334155] bg-[#0f172a] text-slate-400 hover:border-slate-500'}`}>
                                                        <span className="block text-xs font-extrabold">Walk-in</span>
                                                        <span className="mt-0.5 block text-[9px] opacity-80">Guest booked at the front desk</span>
                                                    </button>
                                                    <button type="button" onClick={() => handleBookingSourceChange('online')}
                                                        className={`rounded-xl border px-3 py-3 text-left transition-all ${data.booking_source === 'online' ? 'border-brand-500 bg-brand-500/10 text-brand-300' : 'border-[#334155] bg-[#0f172a] text-slate-400 hover:border-slate-500'}`}>
                                                        <span className="block text-xs font-extrabold">Online Booking</span>
                                                        <span className="mt-0.5 block text-[9px] opacity-80">GCash or bank transfer only</span>
                                                    </button>
                                                </div>
                                                {errors.booking_source && <p className="text-[10px] text-rose-400">{errors.booking_source}</p>}
                                            </div>
                                            {data.booking_source && (
                                            <>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">2. Amount to Collect</label>
                                                    <div className="grid grid-cols-2 gap-1 bg-[#0f172a] p-1 rounded-xl border border-[#334155]">
                                                        <button type="button" onClick={() => setData('payment_ratio', 'full')}
                                                            className={`py-1 px-2 rounded-lg text-[10px] font-bold transition-all ${data.payment_ratio === 'full' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}`}>
                                                            Full (100%)
                                                        </button>
                                                        <button type="button" onClick={() => setData('payment_ratio', 'half')}
                                                            className={`py-1 px-2 rounded-lg text-[10px] font-bold transition-all ${data.payment_ratio === 'half' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}`}>
                                                            50% Deposit
                                                        </button>
                                                    </div>
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">3. Payment Method *</label>
                                                    <CustomSelect value={data.payment_method} onChange={e => handleBookingPaymentMethodChange(e.target.value)} className={`${inputCls} font-bold`}>
                                                        {data.booking_source === 'walk_in' && <option value="cash">Cash</option>}
                                                        <option value="gcash">GCash</option>
                                                        <option value="bank_transfer">Bank Transfer</option>
                                                    </CustomSelect>
                                                    {errors.payment_method && <p className="text-[10px] text-rose-400">{errors.payment_method}</p>}
                                                </div>
                                                {data.payment_method === 'cash' && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-emerald-400">Cash Received (₱)</label>
                                                        <input type="number" step="0.01" min="0" value={data.cash_received} onChange={e => setData('cash_received', e.target.value)} required placeholder="0.00" className={`${inputCls} font-mono text-emerald-400 font-bold`} />
                                                        {errors.cash_received && <p className="text-[10px] text-rose-400">{errors.cash_received}</p>}
                                                    </div>
                                                )}
                                                {data.payment_method === 'cash' && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Change</label>
                                                        <div className={`${inputCls} flex items-center font-mono font-black text-emerald-400`}>
                                                            ₱{bookingChange.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                        </div>
                                                    </div>
                                                )}
                                                {data.payment_method === 'gcash' && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash Reference *</label>
                                                        <input type="text" value={data.gcash_ref} onChange={e => setData('gcash_ref', e.target.value)} required placeholder="Enter transaction reference" className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                                {data.payment_method === 'bank_transfer' && (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Bank Transaction Reference *</label>
                                                        <input type="text" value={data.reference_number} onChange={e => setData('reference_number', e.target.value)} required placeholder="Enter bank reference" className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                )}
                                                {data.payment_method !== 'cash' && (
                                                    <div className="sm:col-span-2 rounded-xl border border-amber-500/20 bg-amber-500/5 px-3 py-2.5 text-[10px] text-amber-300">
                                                        Digital payments remain pending until verified in Front Desk Payments.
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex flex-col gap-1">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Transaction Notes</label>
                                                <textarea value={data.transaction_notes || ''} onChange={e => setData('transaction_notes', e.target.value)} rows="2" placeholder="Optional payment or booking note" className={inputCls} />
                                            </div>
                                            {data.payment_method === 'split' && (
                                                <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cash (₱)</label>
                                                        <input type="number" min="0" max={data.payment_ratio === 'half' ? Math.round((calc.totals || calc).total_amount / 2) : (calc.totals || calc).total_amount} step="any" value={data.cash_amount} onChange={handleCashInput} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">GCash (₱)</label>
                                                        <input type="number" min="0" max={data.payment_ratio === 'half' ? Math.round((calc.totals || calc).total_amount / 2) : (calc.totals || calc).total_amount} step="any" value={data.gcash_amount} onChange={handleGCashInput} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                </div>
                                            )}
                                            </>
                                            )}
                                        </div>
                                    </div>

                                    {/* Right — Bill */}
                                    <div className="flex flex-col gap-4">
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4 relative overflow-hidden sticky top-4">
                                            {calc.is_peak && (
                                                <div className="absolute top-0 left-0 right-0 py-1 bg-amber-500 text-slate-950 text-[9px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1">
                                                    <TrendingUp size={9} /> Peak: {calc.peak_label}
                                                </div>
                                            )}
                                            <div className={`flex items-center gap-2.5 border-b border-[#334155] pb-3 ${calc.is_peak ? 'pt-5' : 'pt-1'}`}>
                                                <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="font-outfit font-extrabold text-slate-200 text-sm uppercase tracking-wide">Billing</h3>
                                            </div>
                                            {data.room_ids.length > 0 ? (
                                                <>
                                                    <div className="flex flex-col gap-3 text-xs max-h-60 overflow-y-auto custom-scrollbar pr-2 mb-2">
                                                        {data.room_ids.map(roomId => {
                                                            const room = rooms.find(v => String(v.id) === String(roomId));
                                                            if (!room) return null;
                                                            const activeCalc = calc.room_breakdown ? calc.room_breakdown[roomId] : calc.totals || calc;
                                                            return (
                                                                <div key={roomId} className="bg-[#0f172a] rounded-xl border border-[#334155] p-3 flex flex-col gap-2">
                                                                    <div className="flex justify-between items-start border-b border-[#334155] pb-2">
                                                                        <div>
                                                                            <div className="text-sm font-bold text-slate-200">{room.room_number} <span className="text-[10px] text-slate-400 font-normal uppercase tracking-wider ml-1">{room.type.name}</span></div>
                                                                            <div className="text-[10px] text-slate-500">Base Limit: {room.type.max_occupancy} pax</div>
                                                                        </div>
                                                                        <div className="text-right">
                                                                            <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Extra Pax</div>
                                                                            <div className="flex items-center justify-end gap-1.5">
                                                                                <button type="button" onClick={() => setData('extra_pax', { ...data.extra_pax, [roomId]: Math.max(0, (data.extra_pax[roomId] || 0) - 1) })} className="w-6 h-6 rounded bg-[#1e293b] border border-[#334155] text-slate-300 flex items-center justify-center hover:bg-brand-500 hover:text-white transition-colors">-</button>
                                                                                <span className="text-xs font-mono font-bold text-slate-200 w-4 text-center">{data.extra_pax[roomId] || 0}</span>
                                                                                <button type="button" onClick={() => setData('extra_pax', { ...data.extra_pax, [roomId]: (data.extra_pax[roomId] || 0) + 1 })} className="w-6 h-6 rounded bg-[#1e293b] border border-[#334155] text-slate-300 flex items-center justify-center hover:bg-brand-500 hover:text-white transition-colors">+</button>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div className="flex flex-col gap-1 pt-1">
                                                                        <div className="flex justify-between">
                                                                            <span className="text-slate-400 text-[10px]">Base Charge:</span>
                                                                            <span className="font-mono text-slate-300 font-bold text-[10px]">₱{(activeCalc?.base_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                                        </div>
                                                                        {(activeCalc?.peak_surcharge || 0) > 0 && (
                                                                            <div className="flex justify-between">
                                                                                <span className="text-amber-400/80 text-[10px]">Peak Surcharge:</span>
                                                                                <span className="font-mono text-amber-400 font-bold text-[10px]">+ ₱{(activeCalc.peak_surcharge || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                                            </div>
                                                                        )}
                                                                        {(activeCalc?.extra_pax_charges || 0) > 0 && (
                                                                            <div className="flex justify-between">
                                                                                <span className="text-amber-400/80 text-[10px]">Extra Pax Charge:</span>
                                                                                <span className="font-mono text-amber-400 font-bold text-[10px]">+ ₱{(activeCalc.extra_pax_charges || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                    <div className="flex justify-between items-baseline border-t border-[#334155] pt-2 mt-1">
                                                                        <span className="font-outfit font-extrabold text-slate-400 uppercase text-[9px] tracking-wider">Room Subtotal:</span>
                                                                        <span className="font-mono text-xs font-black text-emerald-400">₱{(activeCalc?.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>

                                                    <div className="bg-[#1e293b] p-4 rounded-xl border border-brand-500/20 shadow-lg flex flex-col gap-2 relative overflow-hidden">
                                                        <div className="absolute top-0 right-0 w-24 h-24 bg-brand-500/5 rounded-full blur-xl -mr-10 -mt-10" />
                                                        <div className="flex items-center gap-2 mb-1">
                                                            <h4 className="font-outfit font-black text-slate-100 text-xs uppercase tracking-wider">Global Totals</h4>
                                                        </div>
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400 text-[11px]">Total Base Charges:</span>
                                                            <span className="font-mono text-slate-200 font-bold text-[11px]">₱{(calc.totals?.base_amount || calc.base_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                        {(calc.totals?.peak_surcharge || calc.peak_surcharge || 0) > 0 && (
                                                            <div className="flex justify-between">
                                                                <span className="text-amber-400/80 text-[11px]">Total Peak Surcharge:</span>
                                                                <span className="font-mono text-amber-400 font-bold text-[11px]">+ ₱{(calc.totals?.peak_surcharge || calc.peak_surcharge || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            </div>
                                                        )}
                                                        {(calc.totals?.extra_pax_charges || calc.extra_pax_charges || 0) > 0 && (
                                                            <div className="flex justify-between">
                                                                <span className="text-amber-400/80 text-[11px]">Total Extra Pax Charges:</span>
                                                                <span className="font-mono text-amber-400 font-bold text-[11px]">+ ₱{(calc.totals?.extra_pax_charges || calc.extra_pax_charges || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            </div>
                                                        )}
                                                        {(calc.totals?.discount_amount || calc.discount_amount || 0) > 0 && (
                                                            <div className="flex justify-between">
                                                                <span className="text-emerald-400/80 text-[11px] capitalize">Total Discount ({data.discount_type}):</span>
                                                                <span className="font-mono text-emerald-400 font-bold text-[11px]">- ₱{(calc.totals?.discount_amount || calc.discount_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            </div>
                                                        )}
                                                        <div className="h-px bg-[#334155]" />
                                                        <div className="flex justify-between items-baseline mt-1">
                                                            <span className="font-outfit font-black text-slate-100 uppercase tracking-widest text-xs">Grand Total:</span>
                                                            <span className="font-mono text-xl font-black text-emerald-400 drop-shadow-md">₱{(calc.totals?.total_amount || calc.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>

                                                        {data.payment_ratio === 'half' ? (
                                                            <div className="flex justify-between items-baseline bg-brand-500/10 border border-brand-500/20 p-2.5 rounded-xl mt-1">
                                                                <span className="font-outfit font-extrabold text-brand-400 uppercase text-[10px]">Due Today (50%):</span>
                                                                <span className="font-mono text-base font-black text-brand-400">₱{Math.round((calc.totals?.total_amount || calc.total_amount || 0) / 2).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            </div>
                                                        ) : (
                                                            <div className="flex justify-between items-baseline bg-emerald-500/10 border border-emerald-500/20 p-2.5 rounded-xl mt-1">
                                                                <span className="font-outfit font-extrabold text-emerald-400 uppercase text-[10px]">Due Today (100%):</span>
                                                                <span className="font-mono text-base font-black text-emerald-400">₱{(calc.totals?.total_amount || calc.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            </div>
                                                        )}
                                                        {data.payment_method === 'cash' && (
                                                            <div className="flex justify-between items-baseline mt-2 pt-2 border-t border-[#334155]/60">
                                                                <span className="font-outfit font-black text-slate-100 uppercase tracking-widest text-xs">Change:</span>
                                                                <span className="font-mono text-base font-black text-emerald-400">
                                                                    ₱{bookingChange.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>

                                                    <div className="flex flex-col gap-1 bg-[#0f172a]/65 p-3 rounded-xl border border-[#334155] mt-1">
                                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1"><Calendar size={10} /> Expected Check-Out</span>
                                                        <span className="text-xs text-slate-300 font-bold font-mono">
                                                            {calc.expected_check_out || calc.totals?.expected_check_out ? new Date(calc.expected_check_out || calc.totals?.expected_check_out).toLocaleString() : '-'}
                                                        </span>
                                                    </div>
                                                </>
                                            ) : (
                                                <div className="py-8 text-center text-xs text-slate-500">Select rooms to load bill summary.</div>
                                            )}
                                            <button type="submit" disabled={processing || !data.room_ids || data.room_ids.length === 0 || !data.booking_source || !data.payment_method || !!(calc.totals || calc).conflict}
                                                className="w-full flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 disabled:bg-[#334155] disabled:text-slate-500 text-white font-outfit font-extrabold text-sm tracking-wide shadow-lg active:scale-95 transition-all">
                                                <CheckCircle size={16} />
                                                {processing ? 'Processing...' : calc.conflict ? 'Conflict — Cannot Book' : 'Complete Booking'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Check-In Confirm Modal */}
            <AnimatePresence>
                {showCheckInModal && selectedCheckInRes && (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                            onClick={closeCheckInModal} className="fixed inset-0 bg-[#070b13]/90" />
                        <motion.div initial={{ scale: 0.95, opacity: 0, y: 15 }} animate={{ scale: 1, opacity: 1, y: 0 }} exit={{ scale: 0.95, opacity: 0, y: 15 }}
                            transition={{ type: 'spring', damping: 25, stiffness: 350 }}
                            className="relative w-full max-w-lg max-h-[92vh] overflow-y-auto bg-[#1e293b] border border-[#334155] rounded-3xl p-6 shadow-2xl z-10">
                            <div className="flex flex-col items-center text-center gap-4">
                                <div className="p-3.5 bg-emerald-500/10 text-emerald-400 rounded-2xl"><UserCheck size={28} /></div>
                                <div>
                                    <h3 className="font-outfit font-black text-slate-100 text-lg uppercase tracking-wide">
                                        {selectedCheckInBalance > 0 ? 'Settle Balance & Check In' : 'Confirm Check-In'}
                                    </h3>
                                    <p className="text-xs text-slate-400 mt-1">
                                        <strong className="text-slate-200">{selectedCheckInRes.guest_name}</strong> · Room <strong className="text-slate-200">{selectedCheckInRes.room?.room_number}</strong>
                                    </p>
                                </div>
                                <div className="w-full bg-[#0f172a]/60 border border-[#334155]/60 rounded-2xl p-4 text-left text-xs space-y-2">
                                    <div className="flex justify-between"><span className="text-slate-400">Ref:</span><span className="font-mono text-slate-200 font-bold">{selectedCheckInRes.booking_ref}</span></div>
                                    <div className="flex justify-between"><span className="text-slate-400">Scheduled:</span><span className="font-mono text-slate-200">{parseLocalDatetime(selectedCheckInRes.check_in)?.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</span></div>
                                    <div className="flex justify-between"><span className="text-slate-400">Confirmed Booking Total:</span><span className="font-mono text-slate-200">₱{Number(selectedCheckInRes.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span></div>
                                    <div className="flex justify-between"><span className="text-slate-400">Previous Verified Advance:</span><span className="font-mono text-emerald-400 font-bold">₱{Number(selectedCheckInRes.amount_paid).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span></div>
                                    {selectedCheckInBalance > 0 && (
                                        <div className="flex justify-between border-t border-[#334155] pt-2 mt-2"><span className="text-rose-400 font-bold">Balance Due Today:</span><span className="font-mono text-rose-400 font-extrabold">₱{selectedCheckInBalance.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span></div>
                                    )}
                                </div>

                                {selectedPendingPayment > 0 && (
                                    <div className="p-3.5 bg-amber-950/20 border border-amber-500/30 text-amber-300 text-[11px] rounded-xl flex items-start gap-2.5 text-left w-full">
                                        <Clock className="h-4 w-4 text-amber-400 mt-0.5 shrink-0" />
                                        <div>
                                            <span className="font-bold text-amber-400 block font-outfit uppercase text-[10px] tracking-wide">Payment Pending Verification</span>
                                            A payment of ₱{selectedPendingPayment.toLocaleString(undefined, { minimumFractionDigits: 2 })} is already pending.
                                            Verify or reject it in Front Desk Payments before checking in.
                                            <Link href={route('reports.front_desk')} className="mt-2 block font-bold text-brand-400 hover:text-brand-300">Open verification queue →</Link>
                                        </div>
                                    </div>
                                )}

                                {selectedCheckInBalance > 0 && selectedPendingPayment <= 0 && (
                                    <div className="w-full rounded-2xl border border-[#334155] bg-[#0f172a]/40 p-4 text-left space-y-3">
                                        <div>
                                            <label className="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-400">Payment Method</label>
                                            <select value={settlementForm.data.payment_method_code} onChange={e => handleSettlementMethodChange(e.target.value)}
                                                className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-xs font-bold text-slate-100">
                                                <option value="cash">Cash — verify and check in now</option>
                                                <option value="gcash">GCash</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="card">Card</option>
                                                <option value="maya">Maya</option>
                                                <option value="other_ewallet">Other E-wallet</option>
                                                <option value="other">Other</option>
                                                <option value="split">Split — Cash + GCash</option>
                                            </select>
                                        </div>

                                        {settlementForm.data.payment_method_code !== 'cash' && (
                                            <div>
                                                <label className="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-400">Reference Number *</label>
                                                <input required value={settlementForm.data.reference_number}
                                                    onChange={e => settlementForm.setData('reference_number', e.target.value)}
                                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-xs font-mono text-slate-100"
                                                    placeholder="Enter transaction / approval reference" />
                                                {settlementForm.errors.reference_number && <div className="mt-1 text-[10px] text-rose-400">{settlementForm.errors.reference_number}</div>}
                                            </div>
                                        )}

                                        {settlementForm.data.payment_method_code === 'split' && (
                                            <div className="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label className="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-400">Cash</label>
                                                    <input type="number" min="0.01" step="0.01" value={settlementForm.data.cash_amount}
                                                        onChange={e => handleSettlementCashChange(e.target.value)}
                                                        className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-xs font-mono text-slate-100" />
                                                </div>
                                                <div>
                                                    <label className="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-400">GCash</label>
                                                    <input type="number" min="0.01" step="0.01" value={settlementForm.data.gcash_amount}
                                                        onChange={e => handleSettlementGcashChange(e.target.value)}
                                                        className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-xs font-mono text-slate-100" />
                                                </div>
                                                <div className="col-span-2 flex justify-between rounded-lg bg-[#1e293b] px-3 py-2 text-[10px]">
                                                    <span className="text-slate-400">Split total</span>
                                                    <span className="font-mono font-bold text-slate-200">₱{(Number(settlementForm.data.cash_amount || 0) + Number(settlementForm.data.gcash_amount || 0)).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            </div>
                                        )}

                                        {settlementForm.data.payment_method_code !== 'cash' && (
                                            <p className="text-[10px] text-amber-300">
                                                Electronic and split payments will be recorded as pending. Check-in remains blocked until verification.
                                            </p>
                                        )}
                                    </div>
                                )}

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full">
                                    <button type="button" onClick={closeCheckInModal} className="px-4 py-3 bg-[#0f172a] hover:bg-[#1e293b] border border-[#334155] rounded-xl text-xs font-black text-slate-400 uppercase transition-colors">Cancel</button>
                                    <button type="button" onClick={confirmCheckIn}
                                        disabled={settlementForm.processing || selectedPendingPayment > 0 || (settlementForm.data.payment_method_code !== 'cash' && selectedCheckInBalance > 0 && !settlementForm.data.reference_number.trim())}
                                        className="px-4 py-3 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-700 disabled:text-slate-500 disabled:cursor-not-allowed rounded-xl text-xs font-black text-white uppercase transition-colors">
                                        {settlementForm.processing
                                            ? 'Processing...'
                                            : selectedCheckInBalance <= 0
                                                ? 'Confirm Check-In'
                                                : settlementForm.data.payment_method_code === 'cash'
                                                    ? `Pay ₱${selectedCheckInBalance.toLocaleString()} & Check In`
                                                    : `Record ₱${selectedCheckInBalance.toLocaleString()} for Verification`}
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            {/* Cancel Reservation Modal */}
            <AnimatePresence>
                {showCancelModal && selectedCancelRes && (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                            onClick={() => setShowCancelModal(false)} className="fixed inset-0 bg-[#070b13]/90" />
                        <motion.div initial={{ scale: 0.95, opacity: 0, y: 15 }} animate={{ scale: 1, opacity: 1, y: 0 }} exit={{ scale: 0.95, opacity: 0, y: 15 }}
                            transition={{ type: 'spring', damping: 25, stiffness: 350 }}
                            className="relative w-full max-w-md bg-[#1e293b] border border-[#334155] rounded-3xl p-6 shadow-2xl z-10">
                            <div className="flex flex-col items-center text-center gap-4">
                                <div className="p-3.5 bg-rose-500/10 text-rose-400 rounded-2xl"><AlertTriangle size={28} /></div>
                                <div>
                                    <h3 className="font-outfit font-black text-slate-100 text-lg uppercase tracking-wide">Cancel Reservation</h3>
                                    <p className="text-xs text-slate-400 mt-1">Cancel booking for <strong className="text-slate-200">{selectedCancelRes.guest_name}</strong> in Room <strong className="text-slate-200">{selectedCancelRes.room?.room_number}</strong>?</p>
                                </div>
                                <div className="w-full text-left flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Reason for Cancellation</label>
                                    <textarea value={cancelReason} onChange={e => setCancelReason(e.target.value)} rows="3" required
                                        placeholder="Type reason here..." className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-3 focus:outline-none focus:border-brand-500 text-xs" />
                                </div>
                                <div className="w-full text-left flex flex-col gap-1.5">
                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Payment Disposition</label>
                                    <select value={cancelDisposition} onChange={e => setCancelDisposition(e.target.value)}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs">
                                        <option value="retain">Retain payment / deposit</option>
                                        <option value="full_refund">Full refund ({Number(selectedCancelRes.amount_paid || 0).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' })})</option>
                                        <option value="partial_refund">Partial refund</option>
                                    </select>
                                    <p className="text-[10px] text-slate-500">Refunds are separate ledger entries; the original payment is never deleted.</p>
                                </div>
                                {cancelDisposition === 'partial_refund' && (
                                    <div className="w-full text-left flex flex-col gap-1.5">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Refund Amount</label>
                                        <input type="number" min="0.01" step="0.01" max={selectedCancelRes.amount_paid || 0}
                                            value={cancelRefundAmount} onChange={e => setCancelRefundAmount(e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs" />
                                    </div>
                                )}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full">
                                    <button onClick={() => setShowCancelModal(false)} className="px-4 py-3 bg-[#0f172a] hover:bg-[#1e293b] border border-[#334155] rounded-xl text-xs font-black text-slate-400 uppercase transition-colors">Back</button>
                                    <button onClick={confirmCancel} disabled={!cancelReason.trim() || (cancelDisposition === 'partial_refund' && !Number(cancelRefundAmount))} className="px-4 py-3 bg-rose-600 hover:bg-rose-500 disabled:bg-rose-950/40 disabled:text-rose-400/50 rounded-xl text-xs font-black text-white uppercase transition-colors">Cancel Booking</button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            {/* ── Edit Reservation Modal ── */}
            <AnimatePresence>
                {showEditModal && editingBooking && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-6 px-4"
                        onClick={e => { if (e.target === e.currentTarget) closeEditModal(); }}>
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }} transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-4xl bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden">

                            {/* Modal Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Edit size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">Edit Booking details</h2>
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

                                    {/* Left — Forms */}
                                    <div className="lg:col-span-2 flex flex-col gap-5">

                                        {/* Guest Details */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl"><UserCheck size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Guest Details</h3>
                                            </div>
                                            {/* Autocomplete */}
                                            <div className="relative">
                                                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5 block">Search Returning Guest</label>
                                                <div className="relative">
                                                    <Search className="absolute left-3 top-2.5 text-slate-500" size={14} />
                                                    <input type="text" value={guestSearch} onChange={e => setGuestSearch(e.target.value)}
                                                        placeholder="Type name to autocomplete..." className={`${inputCls} pl-9`} />
                                                </div>
                                                {suggestions.length > 0 && (
                                                    <ul className="absolute left-0 right-0 mt-1 bg-[#1e293b] border border-[#334155] rounded-xl shadow-2xl z-[99] text-xs overflow-hidden">
                                                        {suggestions.map(g => (
                                                            <li key={g.id} onClick={() => selectEditGuest(g)}
                                                                className="px-4 py-2.5 hover:bg-[#334155] cursor-pointer flex justify-between items-center text-slate-200">
                                                                <span className="font-bold">{g.full_name}</span>
                                                                <div className="flex items-center gap-2">
                                                                    {g.is_vip && <span className="text-[9px] bg-amber-950 border border-amber-600/30 text-amber-400 px-1.5 py-0.5 rounded font-bold uppercase inline-flex items-center gap-1"><Crown size={9} /> VIP</span>}
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
                                                        <div className="flex gap-2"><span>✨</span>
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
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                                        list="edit_res_id_types_list"
                                                        value={editForm.data.guest_id_type}
                                                        onChange={e => editForm.setData('guest_id_type', e.target.value)}
                                                        className={inputCls}
                                                        placeholder="Select or type..."
                                                    />
                                                    <datalist id="edit_res_id_types_list">
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

                                        {/* Reservation Details */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl"><Calendar size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Booking Details</h3>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Check-In Date & Time *</label>
                                                    <input type="datetime-local" value={editForm.data.check_in} onChange={e => editForm.setData('check_in', e.target.value)} required className={`${inputCls} font-mono font-bold`} />
                                                    {editForm.errors.check_in && <span className="text-[10px] text-red-400">{editForm.errors.check_in}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Room *</label>
                                                    <CustomSelect value={editForm.data.room_id} onChange={e => editForm.setData('room_id', e.target.value)} required className={`${inputCls} font-bold`}>
                                                        <option value="">Select Room</option>
                                                        {editingBooking?.room && !rooms.some(r => r.id === editingBooking.room.id) && (
                                                            <option value={editingBooking.room.id}>Room {editingBooking.room.room_number} ({editingBooking.room.type?.type_name}) — {editingBooking.room.status} [Current]</option>
                                                        )}
                                                        {rooms.map(r => <option key={r.id} value={r.id}>Room {r.room_number} ({r.type?.type_name}) — {r.status}</option>)}
                                                    </CustomSelect>
                                                    {editForm.errors.room_id && <span className="text-[10px] text-red-400">{editForm.errors.room_id}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Type</label>
                                                    <CustomSelect value={editForm.data.booking_type} onChange={e => editForm.setData('booking_type', e.target.value)} className={`${inputCls} font-bold`} elevateWhenOpen>
                                                        <option value="overnight">Overnight</option>
                                                        <option value="short_time">Short-time (Hourly)</option>
                                                    </CustomSelect>
                                                </div>
                                                {editForm.data.booking_type === 'overnight' ? (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Nights</label>
                                                        <input type="number" min="1" value={editForm.data.num_nights} onChange={e => editForm.setData('num_nights', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Hours</label>
                                                        <CustomSelect value={editForm.data.short_time_hours} onChange={e => editForm.setData('short_time_hours', Number(e.target.value))} className={`${inputCls} font-mono font-bold`}>
                                                            <option value={3}>3 Hours</option><option value={6}>6 Hours</option>
                                                            <option value={12}>12 Hours</option><option value={24}>24 Hours</option>
                                                        </CustomSelect>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Conflict Banner */}
                                            <AnimatePresence>
                                                {(editCalc.totals?.conflict ?? editCalc.conflict) && (
                                                    <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                                                        className="p-4 rounded-xl bg-rose-950/40 border border-rose-500/50 flex gap-3 text-xs text-rose-300 items-start">
                                                        <AlertCircle className="h-5 w-5 text-rose-400 shrink-0 mt-0.5" />
                                                        <div>
                                                            <span className="font-outfit font-black uppercase tracking-wider block text-rose-400">⚠️ DOUBLE-BOOKING CONFLICT</span>
                                                            <p className="mt-1 text-rose-200">Room is already reserved by <strong className="text-white">{(editCalc.totals?.conflict ?? editCalc.conflict).guest_name}</strong> during this period.</p>
                                                            <div className="mt-2 text-[10px] bg-rose-950/80 border border-rose-500/20 p-2.5 rounded-xl font-mono space-y-1">
                                                                <div>Ref: {(editCalc.totals?.conflict ?? editCalc.conflict).booking_ref} ({(editCalc.totals?.conflict ?? editCalc.conflict).status})</div>
                                                                <div>IN: {(editCalc.totals?.conflict ?? editCalc.conflict).check_in} | OUT: {(editCalc.totals?.conflict ?? editCalc.conflict).expected_check_out}</div>
                                                            </div>
                                                        </div>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>

                                            {/* Discount */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Discount</label>
                                                    <CustomSelect value={editForm.data.discount_type} onChange={e => editForm.setData('discount_type', e.target.value)} className={`${inputCls} font-semibold`}>
                                                        <option value="none">No Discount</option>
                                                        <option value="loyalty">Loyalty (10%)</option>
                                                        <option value="senior">Senior Citizen (20%)</option>
                                                        <option value="pwd">PWD (20%)</option>
                                                        {isAdmin && <>
                                                            <option value="promo">Promo Override</option>
                                                            <option value="staff">Staff Override</option>
                                                            <option value="complimentary">Complimentary</option>
                                                        </>}
                                                    </CustomSelect>
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
                                                {editForm.data.promo_code && <p className="text-emerald-400 text-[10px] font-semibold">✓ "{editForm.data.promo_code}" Applied!</p>}
                                            </div>
                                        </div>

                                        <div className="p-4 rounded-2xl bg-indigo-500/5 border border-indigo-500/20 flex items-start gap-3 text-xs text-slate-400">
                                            <Coins size={17} className="text-indigo-400 shrink-0 mt-0.5" />
                                            <div>
                                                <span className="block font-bold text-indigo-300">Payment history is protected</span>
                                                Editing guest, room, schedule, or pricing details never creates a payment.
                                                Collect the exact remaining balance from <strong className="text-slate-300">Manage → Check In</strong>.
                                            </div>
                                        </div>
                                    </div>

                                    {/* Right — Bill */}
                                    <div className="flex flex-col gap-4">
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4 relative overflow-hidden sticky top-4">
                                            {(editCalc.totals?.is_peak ?? editCalc.is_peak) && (
                                                <div className="absolute top-0 left-0 right-0 py-1 bg-amber-500 text-slate-950 text-[9px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1">
                                                    <TrendingUp size={9} /> Peak: {editCalc.totals?.peak_label ?? editCalc.peak_label}
                                                </div>
                                            )}
                                            <div className={`flex items-center gap-2.5 border-b border-[#334155] pb-3 ${(editCalc.totals?.is_peak ?? editCalc.is_peak) ? 'pt-5' : 'pt-1'}`}>
                                                <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="font-outfit font-extrabold text-slate-200 text-sm uppercase tracking-wide">Billing</h3>
                                            </div>
                                            {editForm.data.room_id ? (
                                                <div className="flex flex-col gap-3 text-xs">
                                                    <div className="flex justify-between">
                                                        <span className="text-slate-400">Base charges:</span>
                                                        <span className="font-mono text-slate-200 font-bold">₱{(editCalc.totals?.base_amount ?? editCalc.base_amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    {(editCalc.totals?.peak_surcharge ?? editCalc.peak_surcharge ?? 0) > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400">Peak surcharge:</span>
                                                            <span className="font-mono text-amber-400 font-bold">+ ₱{(editCalc.totals?.peak_surcharge ?? editCalc.peak_surcharge ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    {(editCalc.totals?.discount_amount ?? editCalc.discount_amount ?? 0) > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400 capitalize">{editForm.data.discount_type} discount:</span>
                                                            <span className="font-mono text-emerald-400 font-bold">- ₱{(editCalc.totals?.discount_amount ?? editCalc.discount_amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    <div className="h-px bg-[#334155]" />
                                                    <div className="flex justify-between items-baseline">
                                                        <span className="font-outfit font-extrabold text-slate-100 uppercase text-[10px]">Total Stay Price:</span>
                                                        <span className="font-mono text-xs font-bold text-slate-300">₱{(editCalc.totals?.total_amount ?? editCalc.total_amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="rounded-xl border border-[#334155] bg-[#0f172a]/65 p-3">
                                                        <div className="flex justify-between text-[10px] uppercase text-slate-500">
                                                            <span>Verified payments retained</span>
                                                            <span className="font-mono text-emerald-400">₱{Number(editingBooking?.amount_paid || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                        <div className="mt-2 flex justify-between text-[10px] font-bold uppercase text-slate-300">
                                                            <span>Recalculated balance</span>
                                                            <span className="font-mono text-rose-400">₱{Math.max(0, Number(editCalc.totals?.total_amount ?? editCalc.total_amount ?? 0) - Number(editingBooking?.amount_paid || 0)).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-col gap-1 bg-[#0f172a]/65 p-3 rounded-xl border border-[#334155]">
                                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1"><Calendar size={10} /> Expected Out</span>
                                                        <span className="text-xs text-slate-300 font-bold font-mono">{(editCalc.totals?.expected_check_out ?? editCalc.expected_check_out) ? parseLocalDatetime(editCalc.totals?.expected_check_out ?? editCalc.expected_check_out)?.toLocaleString() : '-'}</span>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="py-8 text-center text-xs text-slate-500">Select room to load summary.</div>
                                            )}
                                            <button type="submit" disabled={editForm.processing || !editForm.data.room_id || !!(editCalc.totals?.conflict ?? editCalc.conflict)}
                                                className="w-full flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-brand-600 hover:bg-brand-500 disabled:bg-[#334155] disabled:text-slate-500 text-white font-outfit font-extrabold text-sm tracking-wide shadow-lg active:scale-95 transition-all">
                                                <CheckCircle size={16} />
                                                {editForm.processing ? 'Saving...' : editCalc.conflict ? 'Conflict — Cannot Book' : 'Save Updates'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* ── Reschedule Wizard Modal ── */}
            <AnimatePresence>
                {showRescheduleModal && reschedulingBooking && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-6 px-4"
                        onClick={e => { if (e.target === e.currentTarget) { setShowRescheduleModal(false); setReschedulingBooking(null); } }}>
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }} transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-4xl bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden">

                            {/* Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Calendar size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">Reschedule Booking</h2>
                                        <p className="text-[10px] text-slate-400">Reschedule Ref: {reschedulingBooking.booking_ref} ({reschedulingBooking.guest_name})</p>
                                    </div>
                                </div>
                                <button onClick={() => { setShowRescheduleModal(false); setReschedulingBooking(null); }} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            {/* Body */}
                            <form onSubmit={handleRescheduleSubmit} className="p-6">
                                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                    {/* Left — Options */}
                                    <div className="lg:col-span-2 flex flex-col gap-5">
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4">
                                            <div className="flex items-center gap-3 mb-1">
                                                <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl"><Calendar size={16} /></div>
                                                <h3 className="text-sm font-outfit font-bold text-slate-200">Reschedule Parameters</h3>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">New Check-In Date & Time *</label>
                                                    <input type="datetime-local" value={rescheduleForm.data.check_in} onChange={e => rescheduleForm.setData('check_in', e.target.value)} required className={`${inputCls} font-mono font-bold`} />
                                                    {rescheduleForm.errors.check_in && <span className="text-[10px] text-red-400">{rescheduleForm.errors.check_in}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Room *</label>
                                                    <CustomSelect value={rescheduleForm.data.room_id} onChange={e => rescheduleForm.setData('room_id', e.target.value)} required className={`${inputCls} font-bold`}>
                                                        <option value="">Select Room</option>
                                                        {rooms.map(r => <option key={r.id} value={r.id}>Room {r.room_number} ({r.type?.type_name}) — {r.status}</option>)}
                                                    </CustomSelect>
                                                    {rescheduleForm.errors.room_id && <span className="text-[10px] text-red-400">{rescheduleForm.errors.room_id}</span>}
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Type</label>
                                                    <CustomSelect value={rescheduleForm.data.booking_type} onChange={e => rescheduleForm.setData('booking_type', e.target.value)} className={`${inputCls} font-bold`} elevateWhenOpen>
                                                        <option value="overnight">Overnight</option>
                                                        <option value="short_time">Short-time (Hourly)</option>
                                                    </CustomSelect>
                                                </div>
                                                {rescheduleForm.data.booking_type === 'overnight' ? (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Nights</label>
                                                        <input type="number" min="1" value={rescheduleForm.data.num_nights} onChange={e => rescheduleForm.setData('num_nights', e.target.value)} className={`${inputCls} font-mono font-bold`} />
                                                    </div>
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Hours</label>
                                                        <CustomSelect value={rescheduleForm.data.short_time_hours} onChange={e => rescheduleForm.setData('short_time_hours', Number(e.target.value))} className={`${inputCls} font-mono font-bold`}>
                                                            <option value={3}>3 Hours</option><option value={6}>6 Hours</option>
                                                            <option value={12}>12 Hours</option><option value={24}>24 Hours</option>
                                                        </CustomSelect>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Overlap Banner */}
                                            <AnimatePresence>
                                                {rescheduleCalc.conflict && (
                                                    <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                                                        className="p-4 rounded-xl bg-rose-950/40 border border-rose-500/50 flex gap-3 text-xs text-rose-300 items-start">
                                                        <AlertCircle className="h-5 w-5 text-rose-400 shrink-0 mt-0.5" />
                                                        <div>
                                                            <span className="font-outfit font-black uppercase tracking-wider block text-rose-400">⚠️ DOUBLE-BOOKING CONFLICT</span>
                                                            <p className="mt-1 text-rose-200">Room is already reserved by <strong className="text-white">{rescheduleCalc.conflict.guest_name}</strong> during this period.</p>
                                                            <div className="mt-2 text-[10px] bg-rose-950/80 border border-rose-500/20 p-2.5 rounded-xl font-mono space-y-1">
                                                                <div>Ref: {rescheduleCalc.conflict.booking_ref} ({rescheduleCalc.conflict.status})</div>
                                                                <div>IN: {rescheduleCalc.conflict.check_in} | OUT: {rescheduleCalc.conflict.expected_check_out}</div>
                                                            </div>
                                                        </div>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>
                                        </div>
                                    </div>

                                    {/* Right — Pricing summary */}
                                    <div className="flex flex-col gap-4">
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155] flex flex-col gap-4 relative overflow-hidden sticky top-4">
                                            {rescheduleCalc.is_peak && (
                                                <div className="absolute top-0 left-0 right-0 py-1 bg-amber-500 text-slate-950 text-[9px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1">
                                                    <TrendingUp size={9} /> Peak: {rescheduleCalc.peak_label}
                                                </div>
                                            )}
                                            <div className={`flex items-center gap-2.5 border-b border-[#334155] pb-3 ${rescheduleCalc.is_peak ? 'pt-5' : 'pt-1'}`}>
                                                <div className="p-2 bg-emerald-500/10 text-emerald-400 rounded-xl"><Coins size={16} /></div>
                                                <h3 className="font-outfit font-extrabold text-slate-200 text-sm uppercase tracking-wide">Billing</h3>
                                            </div>
                                            {rescheduleForm.data.room_id ? (
                                                <div className="flex flex-col gap-3 text-xs">
                                                    <div className="flex justify-between">
                                                        <span className="text-slate-400">Base charges:</span>
                                                        <span className="font-mono text-slate-200 font-bold">₱{rescheduleCalc.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    {rescheduleCalc.peak_surcharge > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400">Peak surcharge:</span>
                                                            <span className="font-mono text-amber-400 font-bold">+ ₱{rescheduleCalc.peak_surcharge.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    {rescheduleCalc.discount_amount > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-slate-400 capitalize">{reschedulingBooking.discount_type} discount:</span>
                                                            <span className="font-mono text-emerald-400 font-bold">- ₱{rescheduleCalc.discount_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    )}
                                                    <div className="h-px bg-[#334155]" />
                                                    <div className="flex justify-between items-baseline">
                                                        <span className="font-outfit font-extrabold text-slate-100 uppercase text-[10px]">New Total Price:</span>
                                                        <span className="font-mono text-sm font-bold text-emerald-400">₱{rescheduleCalc.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    <div className="flex justify-between items-baseline">
                                                        <span className="text-slate-400 text-[10px]">Previously Paid:</span>
                                                        <span className="font-mono text-slate-300 font-bold">₱{Number(reschedulingBooking.amount_paid).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                    {Number(reschedulingBooking.amount_paid) < rescheduleCalc.total_amount ? (
                                                        <div className="flex justify-between items-baseline bg-rose-500/10 border border-rose-500/20 p-2.5 rounded-xl">
                                                            <span className="font-outfit font-extrabold text-rose-400 uppercase text-[9px]">Additional Due:</span>
                                                            <span className="font-mono text-sm font-black text-rose-400">₱{(rescheduleCalc.total_amount - Number(reschedulingBooking.amount_paid)).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                        </div>
                                                    ) : (
                                                        <div className="flex justify-between items-baseline bg-emerald-500/10 border border-emerald-500/20 p-2.5 rounded-xl">
                                                            <span className="font-outfit font-extrabold text-emerald-400 uppercase text-[9px]">Status:</span>
                                                            <span className="font-outfit text-xs font-black text-emerald-400 uppercase">Fully Paid</span>
                                                        </div>
                                                    )}
                                                    <div className="flex flex-col gap-1 bg-[#0f172a]/65 p-3 rounded-xl border border-[#334155]">
                                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1"><Calendar size={10} /> Expected Out</span>
                                                        <span className="text-xs text-slate-300 font-bold font-mono">{rescheduleCalc.expected_check_out ? parseLocalDatetime(rescheduleCalc.expected_check_out)?.toLocaleString() : '-'}</span>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="py-8 text-center text-xs text-slate-500">Select room to load summary.</div>
                                            )}
                                            <button type="submit" disabled={rescheduleForm.processing || !rescheduleForm.data.room_id || !!rescheduleCalc.conflict}
                                                className="w-full flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:bg-[#334155] disabled:text-slate-500 text-white font-outfit font-extrabold text-sm tracking-wide shadow-lg active:scale-95 transition-all">
                                                <CheckCircle size={16} />
                                                {rescheduleForm.processing ? 'Rescheduling...' : rescheduleCalc.conflict ? 'Conflict — Cannot Reschedule' : 'Confirm Reschedule'}
                                            </button>
                                        </div>
                                    </div>

                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Modal for Stay Details */}
            <StayDetailsModal
                isOpen={!!viewStayId}
                bookingId={viewStayId}
                onClose={() => setViewStayId(null)}
                viewMode="bookings"
            />

            <ImagePreviewModal
                isOpen={isImageModalOpen}
                imageUrl={previewImage}
                onClose={() => { setIsImageModalOpen(false); setPreviewImage(null); }}
            />

            <ActionModal
                isOpen={!!actionModalBooking}
                onClose={() => setActionModalBooking(null)}
                title={`Manage ${actionModalBooking?.booking_ref}`}
            >
                {actionModalBooking && (
                    <>
                        {actionModalBooking.status === 'reserved' && (
                            <>
                                <button onClick={() => { setActionModalBooking(null); triggerCheckIn(actionModalBooking); }} className="w-full flex items-center gap-2 px-4 py-3 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-xs font-bold text-white transition-colors">
                                    <UserCheck size={16} /> Check In
                                </button>
                                {actionModalBooking.group_ref && (
                                    <button onClick={() => {
                                        const groupRef = actionModalBooking.group_ref;
                                        setActionModalBooking(null);
                                        setConfirmAction({
                                            title: 'Group Check In',
                                            message: 'Are you sure you want to check in all grouped reservations?',
                                            onConfirm: () => {
                                                router.post(route('reservations.group_checkin', groupRef));
                                                setConfirmAction(null);
                                            }
                                        });
                                    }} className="w-full flex items-center gap-2 px-4 py-3 bg-indigo-600 hover:bg-indigo-500 rounded-xl text-xs font-bold text-white transition-colors">
                                        <UserCheck size={16} /> Group In
                                    </button>
                                )}
                                <button onClick={() => { setActionModalBooking(null); openEditModal(actionModalBooking); }} className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 rounded-xl text-xs font-bold text-amber-400 transition-colors">
                                    <Edit size={16} /> Edit
                                </button>
                                <button onClick={() => { setActionModalBooking(null); openRescheduleModal(actionModalBooking); }} className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-indigo-600/20 border border-[#334155] hover:border-indigo-500/40 rounded-xl text-xs font-bold text-indigo-400 transition-colors">
                                    <Calendar size={16} /> Reschedule
                                </button>
                                <button onClick={() => { setActionModalBooking(null); handleNoShow(actionModalBooking); }} className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-orange-600/20 border border-[#334155] hover:border-orange-500/40 rounded-xl text-xs font-bold text-orange-400 transition-colors">
                                    <Clock size={16} /> No Show
                                </button>
                                <button onClick={() => { setActionModalBooking(null); triggerCancel(actionModalBooking); }} className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-rose-900/30 border border-[#334155] hover:border-rose-500/40 rounded-xl text-xs font-bold text-rose-400 transition-colors">
                                    <XCircle size={16} /> Cancel
                                </button>
                            </>
                        )}
                        <button onClick={() => { setActionModalBooking(null); setViewStayId(actionModalBooking.id); }} className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 rounded-xl text-xs font-bold text-brand-400 transition-colors">
                            <Eye size={16} /> View Details
                        </button>
                    </>
                )}
            </ActionModal>

            {/* Room Multi-Select Modal */}
            <ActionModal
                isOpen={showRoomSelectModal}
                onClose={() => setShowRoomSelectModal(false)}
                title="Select Rooms"
            >
                {/* Filter Tabs */}
                <div className="flex gap-1 overflow-x-auto custom-scrollbar pb-2 mb-2 border-b border-[#334155]/50">
                    <button
                        onClick={() => setRoomFilter('all')}
                        className={`shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${roomFilter === 'all' ? 'bg-brand-600 text-white shadow' : 'bg-[#1e293b] text-slate-400 hover:text-slate-200 border border-[#334155]'}`}
                    >
                        All Rooms
                    </button>
                    <button
                        onClick={() => setRoomFilter('vacant')}
                        className={`shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${roomFilter === 'vacant' ? 'bg-emerald-600 text-white shadow' : 'bg-[#1e293b] text-slate-400 hover:text-slate-200 border border-[#334155]'}`}
                    >
                        Vacant Only
                    </button>
                    {[...new Set(availableRooms.map(r => r.floor))].filter(Boolean).sort((a, b) => a - b).map(f => (
                        <button
                            key={`floor-${f}`}
                            onClick={() => setRoomFilter(`floor-${f}`)}
                            className={`shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${roomFilter === `floor-${f}` ? 'bg-indigo-600 text-white shadow' : 'bg-[#1e293b] text-slate-400 hover:text-slate-200 border border-[#334155]'}`}
                        >
                            Floor {f}
                        </button>
                    ))}
                    {[...new Set(availableRooms.map(r => r.type?.type_name))].filter(Boolean).sort().map(type => (
                        <button
                            key={`type-${type}`}
                            onClick={() => setRoomFilter(`type-${type}`)}
                            className={`shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${roomFilter === `type-${type}` ? 'bg-amber-600 text-white shadow' : 'bg-[#1e293b] text-slate-400 hover:text-slate-200 border border-[#334155]'}`}
                        >
                            {type}
                        </button>
                    ))}
                </div>

                <div className="flex flex-col gap-2 max-h-72 overflow-y-auto pr-1 custom-scrollbar">
                    {isLoadingRooms && <div className="text-center p-4 text-xs text-brand-400 font-bold">Refreshing availability...</div>}
                    {!isLoadingRooms && availableRooms.filter(r => {
                        if (roomFilter === 'all') return true;
                        if (roomFilter === 'vacant') return r.status === 'vacant';
                        if (roomFilter.startsWith('floor-')) return r.floor?.toString() === roomFilter.replace('floor-', '');
                        if (roomFilter.startsWith('type-')) return r.type?.type_name === roomFilter.replace('type-', '');
                        return true;
                    }).map(r => (
                        <label key={r.id} className="flex items-center gap-3 p-3 rounded-xl bg-[#0f172a]/60 border border-[#334155] cursor-pointer hover:bg-[#1e293b]/60 transition-colors">
                            <input
                                type="checkbox"
                                className="rounded bg-[#1e293b] border-[#475569] text-brand-500 focus:ring-brand-500"
                                checked={data.room_ids.some(id => id.toString() === r.id.toString())}
                                onChange={(e) => {
                                    const val = r.id.toString();
                                    if (e.target.checked) {
                                        setData('room_ids', [...data.room_ids, val]);
                                    } else {
                                        setData('room_ids', data.room_ids.filter(id => id.toString() !== val));
                                    }
                                }}
                            />
                            <div className="flex flex-col">
                                <span className="font-outfit font-bold text-slate-200 text-sm flex items-center gap-2">
                                    Room {r.room_number}
                                    {r.status !== 'vacant' && (
                                        <span className={`text-[8px] uppercase px-1.5 py-0.5 rounded-full ${r.status === 'occupied' ? 'bg-rose-500/20 text-rose-400' :
                                            r.status === 'cleaning' ? 'bg-amber-500/20 text-amber-400' :
                                                'bg-slate-500/20 text-slate-400'
                                            }`}>{r.status}</span>
                                    )}
                                </span>
                                <span className="text-[10px] text-slate-400 font-medium">{r.type?.type_name}</span>
                            </div>
                        </label>
                    ))}
                    {availableRooms.length === 0 && !isLoadingRooms && (
                        <div className="text-center p-4 text-xs text-slate-500">No rooms available for the selected dates.</div>
                    )}
                </div>
                <div className="mt-4 pt-3 border-t border-[#334155] flex justify-between items-center">
                    <span className="text-xs font-bold text-slate-400">{data.room_ids.length} selected</span>
                    <button
                        type="button"
                        onClick={() => setShowRoomSelectModal(false)}
                        className="px-6 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-sm font-bold transition-all"
                    >
                        Done
                    </button>
                </div>
            </ActionModal>

            {/* MODAL: CONFIRM BOOKING */}
            <ConfirmModal
                isOpen={showConfirmBookingModal}
                onClose={() => setShowConfirmBookingModal(false)}
                onConfirm={executeFormSubmit}
                title="Confirm Booking?"
                message={`Are you sure you want to finalize this booking for ${data.guest_name || 'the guest'}?`}
                confirmText="Confirm Booking"
                confirmColor="brand"
            />

            <AlertModal
                isOpen={!!alertMessage}
                onClose={() => setAlertMessage(null)}
                title="Conflict Detected"
                message={alertMessage}
            />

            <ConfirmModal
                isOpen={!!confirmAction}
                onClose={() => setConfirmAction(null)}
                onConfirm={confirmAction?.onConfirm}
                title={confirmAction?.title}
                message={confirmAction?.message}
                confirmText="Proceed"
            />
            <GroupSettleModal
                isOpen={isGroupSettleOpen}
                groupRef={settleGroupRef}
                onClose={() => {
                    setIsGroupSettleOpen(false);
                    setSettleGroupRef(null);
                }}
            />
        </AuthenticatedLayout>
    );
}
