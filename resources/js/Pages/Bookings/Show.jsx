import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage } from '@inertiajs/react';
import {
    Calendar,
    Clock,
    Coins,
    User,
    Plus,
    DollarSign,
    Utensils,
    Timer,
    Slash,
    ChevronLeft,
    PowerOff,
    Sliders,
    Printer,
    FileText,
    History,
    AlertTriangle,
    ShieldAlert,
    X,
    ClipboardCheck,
    Shuffle,
    TrendingUp
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import ReceiptModal from '@/Components/ReceiptModal';
import CustomSelect from '@/Components/CustomSelect';
import { formatHotelDateTime } from '@/Utils/datetime';

export default function Show({ booking, vacantRooms = [], inventoryUsages, inventoryItems, calculations }) {
    const { auth } = usePage().props;
    const user = auth.user;

    const [activeModal, setActiveModal] = useState(null); // 'extend', 'add_item', 'checkout', 'cancel'
    const [showReceiptModal, setShowReceiptModal] = useState(false);

    // Form: Extend stay
    const extendForm = useForm({
        hours: '',
        days: '',
        payment_method: 'cash',
        cash_amount: 0.00,
        gcash_amount: 0.00,
        gcash_ref: ''
    });

    // Form: Add inventory item
    const itemForm = useForm({
        item_id: '',
        quantity: 1,
        notes: ''
    });

    // Form: Checkout
    const checkoutForm = useForm({
        payment_method: 'cash',
        cash_amount: calculations.additional_due || 0.00,
        gcash_amount: 0.00,
        gcash_ref: '',
        notes: '',
        waive_late_fee: false,
        extra_charge_amount: '',
        extra_charge_description: '',
        extra_charge_separate_payment: false,
        extra_charge_payment_method: 'gcash',
        extra_charge_payment_reference: ''
    });
    const checkoutExtraCharge = Number(checkoutForm.data.extra_charge_amount) || 0;
    const checkoutLateFee = checkoutForm.data.waive_late_fee ? 0 : Number(calculations.late_fee || 0);
    const checkoutInventoryDue = Number(calculations.unpaid_inventory || 0);
    const checkoutBaseSettlementDue = checkoutLateFee + checkoutInventoryDue;
    const checkoutMainSettlementDue = checkoutBaseSettlementDue
        + (checkoutForm.data.extra_charge_separate_payment ? 0 : checkoutExtraCharge);
    const checkoutLateRate = Number(calculations.late_hours || 0) > 0
        ? Number(calculations.late_fee || 0) / Number(calculations.late_hours)
        : 0;
    const formatCheckoutDateTime = (value) => formatHotelDateTime(value);

    const paymentForm = useForm({
        booking_id: booking.id,
        payer_name: booking.booker_name || booking.guest_name,
        payer_contact: booking.booker_contact || booking.guest_contact || '',
        payment_method_code: 'cash',
        reference_number: '',
        amount: Math.max(0, Number(booking.total_amount || 0) - Number(booking.amount_paid || 0)),
        payment_type: 'partial',
        cash_amount: 0,
        electronic_amount: 0,
        remarks: '',
    });

    // Form: Cancel stay
    const cancelForm = useForm({
        reason: ''
    });

    // Form: Reassign / Move Room
    const moveForm = useForm({
        new_room_id: '',
        reason: ''
    });

    // 1. Submit extensions
    const handleExtendSubmit = (e) => {
        e.preventDefault();
        extendForm.post(route('bookings.extend', booking.id), {
            onSuccess: () => {
                setActiveModal(null);
                extendForm.reset();
            }
        });
    };

    // Calculate extension fee on input
    const getExtensionFeeEstimate = () => {
        const hourlyRate = (float) => Number(float || 0);
        if (extendForm.data.hours) {
            return Math.round(hourlyRate(booking.room?.type?.hourly_rate) * Number(extendForm.data.hours));
        }
        if (extendForm.data.days) {
            return Math.round(hourlyRate(booking.room?.type?.base_rate) * Number(extendForm.data.days));
        }
        return 0;
    };

    // Handle Split inputs in extend modal directly
    const handleExtendCashInput = (e, total) => {
        const cash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        const gcash = Math.max(0, total - cash);
        extendForm.setData(prev => ({
            ...prev,
            cash_amount: cash,
            gcash_amount: gcash
        }));
    };

    const handleExtendGCashInput = (e, total) => {
        const gcash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        const cash = Math.max(0, total - gcash);
        extendForm.setData(prev => ({
            ...prev,
            cash_amount: cash,
            gcash_amount: gcash
        }));
    };

    // 2. Submit Minibar usage
    const handleAddItemSubmit = (e) => {
        e.preventDefault();
        itemForm.post(route('bookings.items', booking.id), {
            onSuccess: () => {
                setActiveModal(null);
                itemForm.reset();
            }
        });
    };

    // 3. Submit Checkout
    const handleCheckoutSubmit = (e) => {
        e.preventDefault();
        checkoutForm.post(route('bookings.checkout', booking.id), {
            onSuccess: () => {
                setActiveModal(null);
            }
        });
    };

    const handlePaymentSubmit = (e) => {
        e.preventDefault();
        const data = { ...paymentForm.data };
        if (data.payment_method_code === 'split') {
            data.components = [
                { payment_method_code: 'cash', amount: Number(data.cash_amount || 0) },
                { payment_method_code: 'gcash', amount: Number(data.electronic_amount || 0), reference_number: data.reference_number },
            ];
        }
        // Inertia's transform() does not return the form instance, so submit in
        // a separate statement instead of chaining transform().post().
        paymentForm.transform(() => data);
        paymentForm.post(route('payments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setActiveModal(null);
                paymentForm.reset('reference_number', 'remarks', 'cash_amount', 'electronic_amount');
            },
            onFinish: () => paymentForm.transform(values => values),
        });
    };

    // Handle Split inputs in checkout modal directly
    const handleCheckoutCashInput = (e, total) => {
        const cash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        const gcash = Math.max(0, total - cash);
        checkoutForm.setData(prev => ({
            ...prev,
            cash_amount: cash,
            gcash_amount: gcash
        }));
    };

    const handleCheckoutGCashInput = (e, total) => {
        const gcash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        const cash = Math.max(0, total - gcash);
        checkoutForm.setData(prev => ({
            ...prev,
            cash_amount: cash,
            gcash_amount: gcash
        }));
    };

    // 4. Submit Cancellation
    const handleCancelSubmit = (e) => {
        e.preventDefault();
        cancelForm.post(route('bookings.cancel', booking.id), {
            onSuccess: () => {
                setActiveModal(null);
                cancelForm.reset();
            }
        });
    };

    // 5. Submit Room Reassignment
    const handleMoveSubmit = (e) => {
        e.preventDefault();
        moveForm.post(route('bookings.move', booking.id), {
            onSuccess: () => {
                setActiveModal(null);
                moveForm.reset();
            }
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Booking - ${booking.booking_ref}`} />

            <div className="flex flex-col gap-8">

                {/* Header Back button */}
                <div className="flex justify-between items-center">
                    <Link
                        href={route('reservations.index')}
                        className="text-xs font-bold text-slate-400 hover:text-slate-100 flex items-center gap-1 transition-colors"
                    >
                        <ChevronLeft size={16} /> Back to Bookings
                    </Link>

                    <div className="flex items-center gap-3">
                        {booking.status !== 'cancelled' && (
                            <button
                                onClick={() => setShowReceiptModal(true)}
                                className="flex items-center gap-1.5 px-3 py-1.5 bg-[#334155]/60 hover:bg-brand-650 border border-[#475569]/30 text-slate-200 hover:text-slate-50 text-xs font-bold rounded-xl transition-all shadow-sm"
                            >
                                <Printer size={14} /> Print Receipt
                            </button>
                        )}
                        {booking.status === 'active' && (
                            <div className="flex items-center gap-2 px-3 py-1.5 bg-emerald-950 text-emerald-400 border border-emerald-500/20 text-xs font-semibold rounded-xl uppercase tracking-wider">
                                Active Stay
                            </div>
                        )}
                        {booking.status === 'checked_out' && (
                            <div className="flex items-center gap-2 px-3 py-1.5 bg-blue-950 text-blue-400 border border-blue-500/20 text-xs font-semibold rounded-xl uppercase tracking-wider">
                                Checked Out
                            </div>
                        )}
                    </div>
                </div>

                {/* Main Split Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    {/* Left Column (Stay specs, transaction timelines, minibar usages) */}
                    <div className="lg:col-span-2 flex flex-col gap-8">

                        {/* Guest Details */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5">
                            <h2 className="text-lg font-outfit font-bold text-slate-200 border-b border-[#334155] pb-3">Guest Details</h2>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 text-xs text-slate-300">
                                <div>
                                    <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider block">Occupant Guest</span>
                                    <span className="font-outfit font-extrabold text-base text-slate-200 block mt-1.5">{booking.guest_name}</span>
                                    <span className="text-slate-400 block mt-1">{booking.guest_contact || 'No contact phone'}</span>
                                    <span className="text-[10px] text-slate-500 font-mono block mt-1">{booking.guest_id_type}: {booking.guest_id_number || 'N/A'}</span>
                                </div>
                                <div className="p-4 bg-[#0f172a]/55 border border-[#334155] rounded-xl flex flex-col gap-2">
                                    <div className="flex justify-between">
                                        <span className="text-slate-500 font-bold">Assigned Room:</span>
                                        <span className="font-bold text-brand-300">Room {booking.room?.room_number}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500 font-bold">Room Class:</span>
                                        <span className="capitalize">{booking.room?.type?.type_name}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500 font-bold">Stay Type:</span>
                                        <span className="capitalize font-mono text-[10px] bg-[#334155] px-1.5 py-0.5 rounded text-slate-300">
                                            {booking.booking_type === 'overnight' ? 'Overnight' : `${booking.short_time_hours}h hourly`}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Dates details */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 bg-[#0f172a]/30 border border-[#334155] p-4 rounded-xl text-xs">
                                <div className="flex flex-col gap-1.5">
                                    <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Checked In Time</span>
                                    <span className="font-mono text-slate-300 font-bold">{formatHotelDateTime(booking.check_in)}</span>
                                </div>
                                <div className="flex flex-col gap-1.5 border-t sm:border-t-0 sm:border-l border-[#334155] pt-3 sm:pt-0 sm:pl-4">
                                    <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Expected Checkout Hour</span>
                                    <span className="font-mono text-slate-300 font-bold">{formatHotelDateTime(booking.expected_check_out)}</span>
                                </div>
                                <div className="flex flex-col gap-1.5 border-t sm:border-t-0 sm:border-l border-[#334155] pt-3 sm:pt-0 sm:pl-4">
                                    <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Actual Checkout Hour</span>
                                    <span className="font-mono text-slate-300 font-bold">
                                        {booking.check_out ? formatHotelDateTime(booking.check_out) : 'Active stay'}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Section 2: Minibar Usages */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                            <div className="flex justify-between items-center mb-5 border-b border-[#334155] pb-3">
                                <h2 className="text-lg font-outfit font-bold text-slate-200">Minibar & Room Orders</h2>
                                {booking.status === 'active' && (
                                    <button
                                        onClick={() => setActiveModal('add_item')}
                                        className="px-3.5 py-1.5 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] rounded-xl text-xs font-bold text-brand-400 hover:text-brand-300 flex items-center gap-1 transition-colors"
                                    >
                                        <Plus size={14} /> Add Minibar Item
                                    </button>
                                )}
                            </div>

                            <div className="overflow-x-auto text-xs">
                                <table className="w-full text-xs table-fixed">
                                    <thead>
                                        <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Order Item</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Quantity</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Unit Cost</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Subtotal</th>
                                            <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {inventoryUsages.length > 0 ? (
                                            inventoryUsages.map((usage) => (
                                                <tr key={usage.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">
                                                    <td className="px-4 py-3 font-bold font-outfit text-slate-200">
                                                        {usage.item?.item_name || 'Item'}
                                                    </td>
                                                    <td className="px-4 py-3 font-mono">{usage.quantity} {usage.item?.unit}</td>
                                                    <td className="px-4 py-3 font-mono">₱{usage.unit_price}</td>
                                                    <td className="px-4 py-3 font-mono font-bold text-brand-300">₱{usage.total_price.toLocaleString()}</td>
                                                    <td className="px-4 py-3 text-right text-slate-400 italic max-w-xs truncate">{usage.notes || 'None'}</td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="5" className="px-4 py-6 text-center text-slate-500">
                                                    No minibar or pantry service orders logged.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Payment Details */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                            <div className="mb-5 flex items-center justify-between border-b border-[#334155] pb-3">
                                <h2 className="text-lg font-outfit font-bold text-slate-200">Payment Details</h2>
                                {!['cancelled', 'no_show', 'checked_out'].includes(booking.status) && (
                                    <button type="button" onClick={() => setActiveModal('payment')}
                                        className="rounded-lg bg-brand-600 px-3 py-2 text-[10px] font-bold uppercase text-white hover:bg-brand-500">
                                        Record Payment
                                    </button>
                                )}
                            </div>

                            {booking.payments?.length > 0 && (
                                <div className="mb-4 grid gap-2">
                                    {booking.payments.map(payment => (
                                        <div key={payment.id} className="flex flex-col justify-between gap-2 rounded-xl border border-[#334155] bg-[#0f172a]/50 p-3 text-xs sm:flex-row sm:items-center">
                                            <div>
                                                <div className="font-mono font-bold text-brand-400">{payment.receipt_number}</div>
                                                <div className="capitalize text-slate-500">{payment.payment_type} · {String(payment.payment_method_code).replaceAll('_', ' ')}</div>
                                            </div>
                                            <div className="text-right">
                                                <div className="font-mono font-bold text-slate-200">₱{Number(payment.pivot?.allocated_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</div>
                                                <div className={`text-[10px] font-bold uppercase ${payment.status === 'verified' ? 'text-emerald-400' : 'text-amber-400'}`}>{payment.status}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div className="space-y-4">
                                {booking.transactions && booking.transactions.length > 0 ? (
                                    booking.transactions.map((txn, idx) => (
                                        <div key={txn.id} className="p-4 rounded-xl bg-[#0f172a]/25 border border-[#334155] text-xs flex gap-4">
                                            <div className="p-2.5 bg-[#334155]/25 border border-[#334155] rounded-xl shrink-0 h-10 w-10 flex items-center justify-center font-bold text-brand-300">
                                                {idx + 1}
                                            </div>
                                            <div className="flex-1 min-w-0 leading-normal">
                                                <div className="flex justify-between font-outfit font-extrabold text-sm text-slate-200">
                                                    <span className="capitalize">{txn.transaction_type.replace('_', ' ')} Statement</span>
                                                    <span className="font-mono text-emerald-400">₱{txn.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                                <p className="text-slate-400 mt-1">{txn.description}</p>
                                                <div className="flex justify-between items-center mt-2.5 pt-2 border-t border-[#334155]/30 text-[10px] text-slate-500 font-medium">
                                                    <span>Audited By: {txn.processed_by?.name || 'Grand System'}</span>
                                                    <span className="font-mono">{new Date(txn.created_at).toLocaleString()}</span>
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="py-6 text-center text-xs text-slate-500">
                                        No financial transaction events logged.
                                    </div>
                                )}
                            </div>
                        </div>

                    </div>

                    {/* Right Column (Billing specs, Actions triggers, Check-out slider) */}
                    <div className="flex flex-col gap-6">

                        {/* Summary bill calculations card */}
                        <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-5 relative overflow-hidden">
                            {booking.is_peak && (
                                <div className="absolute top-0 left-0 right-0 py-1.5 bg-amber-500 text-slate-950 text-[10px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1 shadow-sm">
                                    <TrendingUp size={10} className="text-slate-955 shrink-0" />
                                    <span>Peak Demand Rates Applied</span>
                                </div>
                            )}

                            <h3 className="font-outfit font-extrabold text-slate-200 text-base uppercase tracking-wide border-b border-[#334155] pb-3 pt-2">
                                Billing Summary
                            </h3>

                            <div className="flex flex-col gap-4 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-400 font-medium">Base Room charges:</span>
                                    <span className="font-mono text-slate-200 font-bold">₱{booking.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                {booking.peak_surcharge > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium">Holiday peak surcharge:</span>
                                        <span className="font-mono text-slate-200 font-bold text-amber-400">+ ₱{booking.peak_surcharge.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                )}
                                {booking.discount_amount > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium capitalize">{booking.discount_type} discount:</span>
                                        <span className="font-mono text-emerald-400 font-bold">- ₱{booking.discount_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                )}
                                {booking.extension_fee > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-slate-400 font-medium">Stay extension fees:</span>
                                        <span className="font-mono text-slate-200 font-bold">₱{booking.extension_fee.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                )}

                                <div className="h-px bg-[#334155] my-2" />

                                <div className="flex justify-between">
                                    <span className="text-slate-400 font-medium">Initial Paid Collections:</span>
                                    <span className="font-mono text-emerald-400 font-bold">₱{booking.amount_paid.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>

                                {/* Active checkout due calculations */}
                                {booking.status === 'active' && (
                                    <div className="p-4 rounded-xl bg-[#0f172a]/55 border border-[#334155] flex flex-col gap-2.5 mt-2">
                                        <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Outstanding checkout balances</span>

                                        {calculations.late_fee > 0 && (
                                            <div className="flex justify-between text-rose-400">
                                                <span>Overtime fee ({calculations.late_hours}h):</span>
                                                <span className="font-mono font-bold">+ ₱{calculations.late_fee.toLocaleString()}</span>
                                            </div>
                                        )}
                                        {calculations.unpaid_inventory > 0 && (
                                            <div className="flex justify-between text-brand-300">
                                                <span>Minibar Orders sum:</span>
                                                <span className="font-mono font-bold">+ ₱{calculations.unpaid_inventory.toLocaleString()}</span>
                                            </div>
                                        )}
                                        {calculations.additional_due === 0 && (
                                            <span className="text-emerald-400 font-bold">No outstanding checkout fees due.</span>
                                        )}

                                        <div className="h-px bg-[#334155] my-1" />

                                        <div className="flex justify-between items-baseline font-bold font-outfit text-slate-200">
                                            <span>Outstanding Due:</span>
                                            <span className="font-mono text-lg text-emerald-400">₱{calculations.additional_due.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Action triggers */}
                            {booking.status === 'active' && (
                                <div className="flex flex-col gap-3 pt-2">
                                    <button
                                        onClick={() => {
                                            setActiveModal('checkout');
                                            checkoutForm.setData({
                                                payment_method: 'cash',
                                                cash_amount: calculations.additional_due,
                                                gcash_amount: 0.00,
                                                gcash_ref: '',
                                                notes: '',
                                                waive_late_fee: false,
                                                extra_charge_amount: '',
                                                extra_charge_description: '',
                                                extra_charge_separate_payment: false,
                                                extra_charge_payment_method: 'gcash',
                                                extra_charge_payment_reference: ''
                                            });
                                        }}
                                        className="w-full flex items-center justify-center gap-1.5 px-5 py-3.5 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-slate-50 text-sm font-extrabold font-outfit tracking-wide shadow-lg shadow-emerald-600/10 active:scale-95 transition-all"
                                    >
                                        <ClipboardCheck size={16} /> Process Checkout
                                    </button>

                                    <button
                                        onClick={() => setActiveModal('extend')}
                                        className="w-full flex items-center justify-center gap-1.5 px-4 py-2.5 bg-[#1e293b] hover:bg-[#334155] border border-[#334155] rounded-xl text-slate-300 hover:text-slate-100 text-xs font-bold font-outfit transition-colors"
                                    >
                                        <Timer size={14} /> Extend Stay Duration
                                    </button>

                                    {['admin', 'front_desk'].includes(user.role) && (
                                        <button
                                            onClick={() => {
                                                setActiveModal('move');
                                                moveForm.setData({
                                                    new_room_id: '',
                                                    reason: ''
                                                });
                                            }}
                                            className="w-full flex items-center justify-center gap-1.5 px-4 py-2.5 bg-brand-950/20 hover:bg-brand-950/35 border border-brand-900/30 rounded-xl text-brand-400 hover:text-brand-300 text-xs font-bold font-outfit transition-all"
                                        >
                                            <Shuffle size={14} /> Reassign / Move Room
                                        </button>
                                    )}

                                    <button
                                        onClick={() => setActiveModal('cancel')}
                                        className="w-full flex items-center justify-center gap-1.5 px-4 py-2 bg-red-950/20 hover:bg-red-950/30 border border-red-900/30 rounded-xl text-red-400 hover:text-red-300 text-xs font-bold font-outfit transition-all"
                                    >
                                        <PowerOff size={12} /> Cancel Booking Stay
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                </div>

            </div>

            {/* --- Modals Portal --- */}

            {/* Modal: Extend stays */}
            {activeModal === 'extend' && (
                <div className="fixed inset-0 bg-[#070b13]/90 z-[9999] flex items-center justify-center p-4">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg max-h-[calc(100dvh-2rem)] overflow-x-hidden overflow-y-auto shadow-2xl p-4 sm:p-6 flex flex-col gap-5 text-slate-100"
                    >
                        <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                            <h3 className="font-outfit font-extrabold text-base text-slate-200">Extend Stay</h3>
                            <button onClick={() => setActiveModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                <X size={14} />
                            </button>
                        </div>

                        <form onSubmit={handleExtendSubmit} className="space-y-4 text-xs">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Extend By Hours</label>
                                    <input
                                        type="number"
                                        min="1"
                                        value={extendForm.data.hours}
                                        onChange={e => extendForm.setData(prev => ({ ...prev, hours: e.target.value, days: '' }))}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Or Extend By Nights</label>
                                    <input
                                        type="number"
                                        min="1"
                                        value={extendForm.data.days}
                                        onChange={e => extendForm.setData(prev => ({ ...prev, days: e.target.value, hours: '' }))}
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                    />
                                </div>
                            </div>

                            {/* Estimated extension cost */}
                            {getExtensionFeeEstimate() > 0 && (
                                <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex justify-between items-center">
                                    <span className="font-bold text-slate-400">Estimated Extension Cost:</span>
                                    <span className="font-mono text-emerald-400 font-bold text-base">₱{getExtensionFeeEstimate().toLocaleString()}</span>
                                </div>
                            )}

                            {/* Extension payments */}
                            {getExtensionFeeEstimate() > 0 && (
                                <div className="space-y-4 pt-2 border-t border-[#334155]/40">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div className="flex flex-col gap-1.5">
                                            <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Payment Method</label>
                                            <CustomSelect
                                                value={extendForm.data.payment_method}
                                                onChange={e => extendForm.setData('payment_method', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold text-xs"
                                            >
                                                <option value="cash">Cash</option>
                                                <option value="gcash">GCash</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="card">Card</option>
                                                <option value="maya">Maya</option>
                                                <option value="other_ewallet">Other E-wallet</option>
                                                <option value="other">Other</option>
                                                <option value="split">Split (Cash + GCash)</option>
                                            </CustomSelect>
                                        </div>

                                        {extendForm.data.payment_method !== 'cash' && (
                                            <div className="flex flex-col gap-1.5">
                                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Payment Reference</label>
                                                <input
                                                    type="text"
                                                    value={extendForm.data.gcash_ref}
                                                    onChange={e => extendForm.setData('gcash_ref', e.target.value)}
                                                    required
                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                />
                                            </div>
                                        )}
                                    </div>

                                    {extendForm.data.payment_method === 'split' && (
                                        <div className="p-4 bg-[#0f172a]/55 border border-[#334155] rounded-xl flex flex-col gap-3">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Cash Amount (₱)</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max={getExtensionFeeEstimate()}
                                                        step="any"
                                                        value={extendForm.data.cash_amount}
                                                        onChange={e => handleExtendCashInput(e, getExtensionFeeEstimate())}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash Amount (₱)</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max={getExtensionFeeEstimate()}
                                                        step="any"
                                                        value={extendForm.data.gcash_amount}
                                                        onChange={e => handleExtendGCashInput(e, getExtensionFeeEstimate())}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={extendForm.processing || getExtensionFeeEstimate() <= 0}
                                className="w-full py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-bold tracking-wide"
                            >
                                Extend Stay
                            </button>
                        </form>
                    </motion.div>
                </div>
            )}

            {/* Modal: Add minibar item */}
            {activeModal === 'add_item' && (
                <div className="fixed inset-0 bg-[#070b13]/90 z-[9999] flex items-center justify-center p-4">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md max-h-[calc(100dvh-2rem)] overflow-x-hidden overflow-y-auto shadow-2xl p-4 sm:p-6 flex flex-col gap-5 text-slate-100"
                    >
                        <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                            <h3 className="font-outfit font-extrabold text-base text-slate-200">Add Minibar / Room Orders</h3>
                            <button onClick={() => setActiveModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                <X size={14} />
                            </button>
                        </div>

                        <form onSubmit={handleAddItemSubmit} className="space-y-4 text-xs">

                            {/* Item selector */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Choose Inventory Item</label>
                                <CustomSelect
                                    value={itemForm.data.item_id}
                                    onChange={e => itemForm.setData('item_id', e.target.value)}
                                    required
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs font-bold"
                                >
                                    <option value="">-- Choose Stock Item --</option>
                                    {inventoryItems.map(item => (
                                        <option key={item.id} value={item.id}>
                                            {item.item_name} (Stock: {item.current_stock} {item.unit} | ₱{item.selling_price})
                                        </option>
                                    ))}
                                </CustomSelect>
                            </div>

                            {/* Qty count */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Quantity</label>
                                <input
                                    type="number"
                                    min="1"
                                    value={itemForm.data.quantity}
                                    onChange={e => itemForm.setData('quantity', e.target.value)}
                                    required
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                />
                            </div>

                            {/* Notes */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Service Remarks</label>
                                <input
                                    type="text"
                                    value={itemForm.data.notes}
                                    onChange={e => itemForm.setData('notes', e.target.value)}
                                    placeholder="Delivered to room, minibar replenishment..."
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500"
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={itemForm.processing || !itemForm.data.item_id}
                                className="w-full py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-bold tracking-wide"
                            >
                                SAVE AND ADD TO STAY BILL
                            </button>
                        </form>
                    </motion.div>
                </div>
            )}

            {/* Modal: Process Checkout */}
            {activeModal === 'payment' && (
                <div className="fixed inset-0 bg-[#070b13]/90 z-[9999] flex items-center justify-center p-4">
                    <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }}
                        className="w-full max-w-lg max-h-[calc(100dvh-2rem)] overflow-x-hidden overflow-y-auto rounded-2xl border border-[#334155] bg-[#1e293b] p-4 sm:p-6 text-slate-100 shadow-2xl">
                        <div className="mb-5 flex items-center justify-between border-b border-[#334155] pb-3">
                            <div>
                                <h3 className="font-outfit font-extrabold">Record Additional Payment</h3>
                                <p className="text-[10px] text-slate-500">Electronic payments remain pending until verified.</p>
                            </div>
                            <button type="button" onClick={() => setActiveModal(null)} className="rounded bg-[#0f172a] p-1 text-slate-400"><X size={14} /></button>
                        </div>
                        <form onSubmit={handlePaymentSubmit} className="grid gap-4 text-xs sm:grid-cols-2">
                            <label className="space-y-1 text-slate-400">Payer name
                                <input required value={paymentForm.data.payer_name} onChange={e => paymentForm.setData('payer_name', e.target.value)}
                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-slate-100" />
                            </label>
                            <label className="space-y-1 text-slate-400">Contact
                                <input value={paymentForm.data.payer_contact} onChange={e => paymentForm.setData('payer_contact', e.target.value)}
                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-slate-100" />
                            </label>
                            <label className="space-y-1 text-slate-400">Method
                                <select value={paymentForm.data.payment_method_code} onChange={e => paymentForm.setData('payment_method_code', e.target.value)}
                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-slate-100">
                                    <option value="cash">Cash</option><option value="gcash">GCash</option>
                                    <option value="bank_transfer">Bank transfer</option><option value="card">Card</option>
                                    <option value="maya">Maya</option><option value="other_ewallet">Other e-wallet</option>
                                    <option value="other">Other</option><option value="split">Split (Cash + GCash)</option>
                                </select>
                            </label>
                            <label className="space-y-1 text-slate-400">Payment type
                                <select value={paymentForm.data.payment_type} onChange={e => paymentForm.setData('payment_type', e.target.value)}
                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 text-slate-100">
                                    <option value="deposit">Deposit</option><option value="partial">Partial</option>
                                    <option value="full">Full</option><option value="final">Final settlement</option>
                                </select>
                            </label>
                            <label className="space-y-1 text-slate-400">Amount
                                <input required type="number" min="0.01" step="0.01" value={paymentForm.data.amount}
                                    onChange={e => paymentForm.setData('amount', e.target.value)}
                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 font-mono text-slate-100" />
                            </label>
                            {paymentForm.data.payment_method_code !== 'cash' && (
                                <label className="space-y-1 text-slate-400">Reference number
                                    <input required value={paymentForm.data.reference_number} onChange={e => paymentForm.setData('reference_number', e.target.value)}
                                        className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 font-mono text-slate-100" />
                                </label>
                            )}
                            {paymentForm.data.payment_method_code === 'split' && (
                                <>
                                    <label className="space-y-1 text-slate-400">Cash component
                                        <input required type="number" min="0.01" step="0.01" value={paymentForm.data.cash_amount}
                                            onChange={e => paymentForm.setData('cash_amount', e.target.value)}
                                            className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 font-mono text-slate-100" />
                                    </label>
                                    <label className="space-y-1 text-slate-400">GCash component
                                        <input required type="number" min="0.01" step="0.01" value={paymentForm.data.electronic_amount}
                                            onChange={e => paymentForm.setData('electronic_amount', e.target.value)}
                                            className="w-full rounded-xl border border-[#334155] bg-[#0f172a] px-3 py-2.5 font-mono text-slate-100" />
                                    </label>
                                </>
                            )}
                            <label className="space-y-1 text-slate-400 sm:col-span-2">Remarks
                                <textarea rows="2" value={paymentForm.data.remarks} onChange={e => paymentForm.setData('remarks', e.target.value)}
                                    className="w-full rounded-xl border border-[#334155] bg-[#0f172a] p-3 text-slate-100" />
                            </label>
                            <button type="submit" disabled={paymentForm.processing}
                                className="rounded-xl bg-brand-600 px-4 py-3 font-bold text-white hover:bg-brand-500 sm:col-span-2">
                                Record Payment
                            </button>
                        </form>
                    </motion.div>
                </div>
            )}

            {/* Modal: Process Checkout */}
            {activeModal === 'checkout' && (
                <div className="fixed inset-0 bg-[#070b13]/90 z-[9999] flex items-center justify-center p-4">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg max-h-[calc(100dvh-2rem)] overflow-x-hidden overflow-y-auto shadow-2xl p-4 sm:p-6 flex flex-col gap-5 text-slate-100"
                    >
                        <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                            <h3 className="font-outfit font-extrabold text-base text-slate-200">Guest Checkout</h3>
                            <button onClick={() => setActiveModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                <X size={14} />
                            </button>
                        </div>

                        <form onSubmit={handleCheckoutSubmit} className="space-y-4 text-xs">

                            <div className="flex flex-col gap-3 p-4 rounded-xl bg-[#0f172a]/40 border border-[#334155] mb-4">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Extra Charges (Optional: Stains, Damage, etc.)</label>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                    <div className="flex flex-col gap-1.5">
                                        <input
                                            type="text"
                                            placeholder="Description (e.g. Stained bedsheet)"
                                            value={checkoutForm.data.extra_charge_description}
                                            onChange={e => checkoutForm.setData('extra_charge_description', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-bold"
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <input
                                            type="number"
                                            min="0"
                                            step="any"
                                            placeholder="Amount (₱)"
                                            value={checkoutForm.data.extra_charge_amount}
                                            onChange={e => {
                                                const newAmount = e.target.value;
                                                const totalDue = checkoutBaseSettlementDue
                                                    + (checkoutForm.data.extra_charge_separate_payment ? 0 : (Number(newAmount) || 0));
                                                checkoutForm.setData(prev => ({
                                                    ...prev,
                                                    extra_charge_amount: newAmount,
                                                    cash_amount: prev.payment_method === 'cash' ? totalDue : (prev.payment_method === 'split' ? prev.cash_amount : 0),
                                                    gcash_amount: prev.payment_method === 'gcash' ? totalDue : (prev.payment_method === 'split' ? prev.gcash_amount : 0),
                                                }));
                                            }}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                        />
                                    </div>
                                </div>

                                {checkoutExtraCharge > 0 && (
                                    <div className="space-y-3 border-t border-[#334155] pt-3">
                                        <label className="flex items-center gap-2 text-slate-300 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={checkoutForm.data.extra_charge_separate_payment}
                                                onChange={e => {
                                                    const separate = e.target.checked;
                                                    const totalDue = checkoutBaseSettlementDue + (separate ? 0 : checkoutExtraCharge);
                                                    checkoutForm.setData(prev => ({
                                                        ...prev,
                                                        extra_charge_separate_payment: separate,
                                                        cash_amount: prev.payment_method === 'cash' ? totalDue : 0,
                                                        gcash_amount: prev.payment_method === 'gcash' ? totalDue : 0,
                                                    }));
                                                }}
                                                className="rounded border-[#334155] bg-[#0f172a] text-brand-500 focus:ring-brand-500"
                                            />
                                            Paid separately (record payment reference for this charge)
                                        </label>

                                        {checkoutForm.data.extra_charge_separate_payment && (
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Other Charge Payment</label>
                                                    <CustomSelect
                                                        value={checkoutForm.data.extra_charge_payment_method}
                                                        onChange={e => checkoutForm.setData('extra_charge_payment_method', e.target.value)}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-bold"
                                                    >
                                                        <option value="cash">Cash</option>
                                                        <option value="gcash">GCash</option>
                                                    </CustomSelect>
                                                </div>
                                                {checkoutForm.data.extra_charge_payment_method === 'gcash' && (
                                                    <div className="flex flex-col gap-1.5">
                                                        <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash Reference</label>
                                                        <input
                                                            type="text"
                                                            value={checkoutForm.data.extra_charge_payment_reference}
                                                            onChange={e => checkoutForm.setData('extra_charge_payment_reference', e.target.value)}
                                                            required
                                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            <section className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex flex-col gap-3" aria-label="Settlement breakdown">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h4 className="font-outfit font-extrabold text-slate-200 text-xs">Settlement Breakdown</h4>
                                        <p className="text-[10px] text-slate-500 mt-0.5">Review this with the guest before collecting payment.</p>
                                    </div>
                                    {calculations.late_hours > 0 && (
                                        <span className={`text-[9px] font-black uppercase tracking-wide px-2 py-1 rounded border ${checkoutForm.data.waive_late_fee ? 'text-emerald-300 bg-emerald-500/10 border-emerald-500/30' : 'text-rose-300 bg-rose-500/10 border-rose-500/30'}`}>
                                            {checkoutForm.data.waive_late_fee ? 'Late fee waived' : 'Late checkout'}
                                        </span>
                                    )}
                                </div>

                                <div className="flex flex-col gap-2 text-[11px]">
                                    {calculations.late_hours > 0 && (
                                        <div className="flex flex-col gap-1 rounded-lg bg-[#1e293b]/70 border border-[#334155]/70 px-3 py-2.5">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-semibold text-slate-200">Late checkout</p>
                                                    <p className="text-slate-400 mt-0.5">{calculations.late_hours} hour{calculations.late_hours === 1 ? '' : 's'} × ₱{checkoutLateRate.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                                                </div>
                                                <span className={`font-mono font-bold ${checkoutForm.data.waive_late_fee ? 'text-emerald-400 line-through decoration-emerald-500/70' : 'text-rose-400'}`}>₱{Number(calculations.late_fee || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                            </div>
                                            <p className="text-[10px] text-slate-500">Expected: {formatCheckoutDateTime(booking.expected_check_out)} · Calculated: {formatCheckoutDateTime(calculations.current_time)}</p>
                                            <p className="text-[10px] text-slate-500">The late fee is verified again when checkout is confirmed.</p>
                                        </div>
                                    )}

                                    {checkoutInventoryDue > 0 && (
                                        <div className="flex items-center justify-between gap-3 px-1">
                                            <span className="text-slate-400">Unpaid minibar / room orders</span>
                                            <span className="font-mono font-bold text-brand-300">₱{checkoutInventoryDue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                        </div>
                                    )}

                                    {checkoutExtraCharge > 0 && (
                                        <div className="flex items-center justify-between gap-3 px-1">
                                            <span className="text-slate-400 truncate">{checkoutForm.data.extra_charge_description || 'Additional charge'}{checkoutForm.data.extra_charge_separate_payment ? ' (paid separately)' : ''}</span>
                                            <span className="font-mono font-bold text-amber-300 shrink-0">₱{checkoutExtraCharge.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                        </div>
                                    )}

                                    {checkoutBaseSettlementDue === 0 && checkoutExtraCharge === 0 && (
                                        <span className="text-emerald-400 font-semibold">No outstanding checkout charges.</span>
                                    )}
                                </div>

                                {Number(calculations.late_fee || 0) > 0 && (
                                    <label className="flex items-center gap-2 rounded-lg bg-[#1e293b]/50 border border-[#334155]/70 px-3 py-2.5 text-slate-300 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={checkoutForm.data.waive_late_fee}
                                            onChange={e => {
                                                const waiveLateFee = e.target.checked;
                                                const baseDue = (waiveLateFee ? 0 : Number(calculations.late_fee || 0)) + checkoutInventoryDue;
                                                const totalDue = baseDue + (checkoutForm.data.extra_charge_separate_payment ? 0 : checkoutExtraCharge);
                                                checkoutForm.setData(prev => ({
                                                    ...prev,
                                                    waive_late_fee: waiveLateFee,
                                                    cash_amount: prev.payment_method === 'cash' ? totalDue : prev.cash_amount,
                                                    gcash_amount: prev.payment_method === 'gcash' ? totalDue : prev.gcash_amount,
                                                }));
                                            }}
                                            className="rounded border-[#334155] bg-[#0f172a] text-brand-500 focus:ring-brand-500"
                                        />
                                        <span>Waive late checkout fee <span className="font-mono font-bold text-slate-200">(₱{Number(calculations.late_fee || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })})</span></span>
                                    </label>
                                )}
                            </section>

                            <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex justify-between items-center text-xs">
                                <span className="font-bold text-slate-400">Total Settlement Due:</span>
                                <span className="font-mono text-emerald-400 font-bold text-lg">₱{checkoutMainSettlementDue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                            </div>

                            {checkoutMainSettlementDue > 0 && (
                                <div className="space-y-4 pt-2">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div className="flex flex-col gap-1.5">
                                            <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Settlement Channel</label>
                                            <CustomSelect
                                                value={checkoutForm.data.payment_method}
                                                onChange={e => {
                                                    const method = e.target.value;
                                                    const totalDue = checkoutMainSettlementDue;
                                                    checkoutForm.setData(prev => ({
                                                        ...prev,
                                                        payment_method: method,
                                                        cash_amount: method === 'cash' ? totalDue : 0,
                                                        gcash_amount: method === 'gcash' ? totalDue : 0,
                                                    }));
                                                }}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold text-xs"
                                            >
                                                <option value="cash">Cash</option>
                                                <option value="gcash">GCash</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="card">Card</option>
                                                <option value="maya">Maya</option>
                                                <option value="other_ewallet">Other E-wallet</option>
                                                <option value="other">Other</option>
                                                <option value="split">Split (Cash + GCash)</option>
                                            </CustomSelect>
                                        </div>

                                        {checkoutForm.data.payment_method !== 'cash' && (
                                            <div className="flex flex-col gap-1.5">
                                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Payment Reference</label>
                                                <input
                                                    type="text"
                                                    value={checkoutForm.data.gcash_ref}
                                                    onChange={e => checkoutForm.setData('gcash_ref', e.target.value)}
                                                    required
                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                />
                                            </div>
                                        )}
                                    </div>

                                    {checkoutForm.data.payment_method === 'split' && (
                                        <div className="p-4 bg-[#0f172a]/55 border border-[#334155] rounded-xl flex flex-col gap-3">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Cash Amount (₱)</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max={checkoutMainSettlementDue}
                                                        step="any"
                                                        value={checkoutForm.data.cash_amount}
                                                        onChange={e => handleCheckoutCashInput(e, checkoutMainSettlementDue)}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash Amount (₱)</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max={checkoutMainSettlementDue}
                                                        step="any"
                                                        value={checkoutForm.data.gcash_amount}
                                                        onChange={e => handleCheckoutGCashInput(e, checkoutMainSettlementDue)}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Service comments */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Checkout / Housekeeping Comments</label>
                                <textarea
                                    value={checkoutForm.data.notes}
                                    onChange={e => checkoutForm.setData('notes', e.target.value)}
                                    placeholder="Specify room service satisfaction, laundry requests, minibar remarks..."
                                    rows="2"
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-3 focus:outline-none"
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={checkoutForm.processing}
                                className="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-slate-50 font-outfit font-bold tracking-wide text-xs"
                            >
                                AUTHORIZE AND CLOSE STAY INVOICE
                            </button>
                        </form>
                    </motion.div>
                </div>
            )}

            {/* Modal: Reassign Room */}
            {activeModal === 'move' && (
                <div className="fixed inset-0 bg-[#070b13]/90 z-[9999] flex items-center justify-center p-4">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md max-h-[calc(100dvh-2rem)] overflow-x-hidden overflow-y-auto shadow-2xl p-4 sm:p-6 flex flex-col gap-5 text-slate-100"
                    >
                        <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                            <h3 className="font-outfit font-extrabold text-base text-slate-200 flex items-center gap-2">
                                <Shuffle size={16} className="text-brand-400" /> Reassign Room / Move Guest
                            </h3>
                            <button onClick={() => setActiveModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                <X size={14} />
                            </button>
                        </div>

                        <form onSubmit={handleMoveSubmit} className="space-y-4 text-xs">
                            <div className="p-3.5 bg-[#0f172a]/60 border border-[#334155] rounded-xl flex flex-col gap-1 text-[11px] leading-normal text-slate-400">
                                <span className="font-bold text-slate-300">Active Room: Room {booking.room?.room_number} ({booking.room?.type?.type_name})</span>
                                <span>Moving the guest will mark this room immediately as <strong className="text-yellow-400 font-bold">cleaning</strong> and occupy the new room. A ₱0.00 adjustment transaction will be logged.</span>
                            </div>

                            {/* Select Vacant Room */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Select Vacant Room</label>
                                <CustomSelect
                                    value={moveForm.data.new_room_id}
                                    onChange={e => moveForm.setData('new_room_id', e.target.value)}
                                    required
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold text-xs"
                                >
                                    <option value="">-- Choose Vacant Room --</option>
                                    {vacantRooms.map(r => (
                                        <option key={r.id} value={r.id}>
                                            Room {r.room_number} ({r.type?.type_name})
                                        </option>
                                    ))}
                                </CustomSelect>
                                {moveForm.errors.new_room_id && <span className="text-[10px] text-red-400 mt-1">{moveForm.errors.new_room_id}</span>}
                            </div>

                            {/* Reason for reassignment */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Reason for Transfer</label>
                                <textarea
                                    value={moveForm.data.reason}
                                    onChange={e => moveForm.setData('reason', e.target.value)}
                                    placeholder="e.g. AC malfunction, plumbing leak, guest request..."
                                    rows="2"
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-3 focus:outline-none"
                                />
                                {moveForm.errors.reason && <span className="text-[10px] text-red-400 mt-1">{moveForm.errors.reason}</span>}
                            </div>

                            <button
                                type="submit"
                                disabled={moveForm.processing || !moveForm.data.new_room_id}
                                className="w-full py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-bold tracking-wide"
                            >
                                CONFIRM ROOM REASSIGNMENT
                            </button>
                        </form>
                    </motion.div>
                </div>
            )}

            {/* Modal: Cancel stays */}
            {activeModal === 'cancel' && (
                <div className="fixed inset-0 bg-[#070b13]/90 z-[9999] flex items-center justify-center p-4">
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md max-h-[calc(100dvh-2rem)] overflow-x-hidden overflow-y-auto shadow-2xl p-4 sm:p-6 flex flex-col gap-5 text-slate-100"
                    >
                        <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                            <h3 className="font-outfit font-extrabold text-base text-red-400 flex items-center gap-1">
                                <ShieldAlert size={18} /> Cancel Booking Stay
                            </h3>
                            <button onClick={() => setActiveModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                <X size={14} />
                            </button>
                        </div>

                        <form onSubmit={handleCancelSubmit} className="space-y-4 text-xs">
                            <div className="p-3 bg-red-950/20 border border-red-500/20 text-red-300 rounded-xl leading-normal">
                                Warning: Stay cancellation will vacate Room {booking.room?.room_number} and return minibar items back to stock levels.
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Reason for Cancellation</label>
                                <textarea
                                    value={cancelForm.data.reason}
                                    onChange={e => cancelForm.setData('reason', e.target.value)}
                                    required
                                    placeholder="Specify reason: Guest cancellation, clerk booking error..."
                                    rows="3"
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 p-3 focus:outline-none"
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={cancelForm.processing || !cancelForm.data.reason}
                                className="w-full py-3 bg-red-600 hover:bg-red-500 rounded-xl text-slate-50 font-outfit font-bold tracking-wide"
                            >
                                CONFIRM STAY CANCELLATION
                            </button>
                        </form>
                    </motion.div>
                </div>
            )}

            {/* Modal: Live POS Receipt Preview */}
            <ReceiptModal
                isOpen={showReceiptModal}
                booking={booking}
                onClose={() => setShowReceiptModal(false)}
            />

        </AuthenticatedLayout>
    );
}
