import React, { useState, useEffect, Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { useForm, router, usePage, Link } from '@inertiajs/react';
import { 
    Calendar, Clock, Coins, User, Plus, DollarSign, Timer, 
    PowerOff, Printer, FileText, AlertTriangle, X, ClipboardCheck, 
    Shuffle, TrendingUp, RefreshCw, MessageSquare
} from 'lucide-react';
import { AnimatePresence, motion } from 'framer-motion';
import ConfirmModal from '@/Components/ConfirmModal';
import GroupSettleModal from '@/Components/GroupSettleModal';
import ImagePreviewModal from '@/Components/ImagePreviewModal';
import ReceiptModal from '@/Components/ReceiptModal';
import axios from 'axios';

export default function StayDetailsModal({ isOpen, bookingId, onClose, viewMode = 'checkin' }) {
    if (!isOpen || !bookingId) return null;

    const { auth } = usePage().props;
    const currentUser = auth.user;

    const [loading, setLoading] = useState(true);
    const [isImageModalOpen, setIsImageModalOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState(null);
    const [showReceiptModal, setShowReceiptModal] = useState(false);
    const [confirmGroupCheckout, setConfirmGroupCheckout] = useState(false);
    const [booking, setBooking] = useState(null);
    const [inventoryUsages, setInventoryUsages] = useState([]);
    const [vacantRooms, setVacantRooms] = useState([]);
    const [calculations, setCalculations] = useState({});
    const [activeSubModal, setActiveSubModal] = useState(null); // 'extend', 'checkout', 'cancel', 'move', 'pos_receipt'

    // Form: Extend stay
    const extendForm = useForm({
        hours: '',
        days: '',
        payment_method: 'cash',
        cash_amount: 0.00,
        gcash_amount: 0.00,
        gcash_ref: ''
    });

    // Form: Checkout
    const checkoutForm = useForm({
        payment_method: 'cash',
        cash_amount: 0.00,
        gcash_amount: 0.00,
        gcash_ref: '',
        notes: '',
        waive_late_fee: false
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

    // Fetch Details on Open
    const loadDetails = async () => {
        setLoading(true);
        try {
            const res = await axios.get(route('bookings.show', { booking: bookingId, json: 1 }));
            setBooking(res.data.booking);
            setInventoryUsages(res.data.inventoryUsages || []);
            setVacantRooms(res.data.vacantRooms || []);
            setCalculations(res.data.calculations || {});
            
            // Set checkout defaults
            checkoutForm.setData({
                payment_method: 'cash',
                cash_amount: res.data.calculations?.additional_due || 0.00,
                gcash_amount: 0.00,
                gcash_ref: '',
                notes: '',
                waive_late_fee: false
            });
        } catch (err) {
            console.error("Failed to load stay details:", err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadDetails();
    }, [bookingId]);

    // Handle Submissions
    const handleExtendSubmit = (e) => {
        e.preventDefault();
        extendForm.post(route('bookings.extend', bookingId), {
            onSuccess: () => {
                setActiveSubModal(null);
                extendForm.reset();
                loadDetails();
            }
        });
    };

    const handleCheckoutSubmit = (e) => {
        e.preventDefault();
        checkoutForm.post(route('bookings.checkout', bookingId), {
            onSuccess: () => {
                setActiveSubModal(null);
                onClose();
                router.reload();
            }
        });
    };

    const handleCancelSubmit = (e) => {
        e.preventDefault();
        cancelForm.post(route('bookings.cancel', bookingId), {
            onSuccess: () => {
                setActiveSubModal(null);
                cancelForm.reset();
                onClose();
                router.reload();
            }
        });
    };

    const handleMoveSubmit = (e) => {
        e.preventDefault();
        moveForm.post(route('bookings.move', bookingId), {
            onSuccess: () => {
                setActiveSubModal(null);
                moveForm.reset();
                loadDetails();
            }
        });
    };

    const getExtensionFeeEstimate = () => {
        if (!booking || !booking.room?.type) return 0;
        const type = booking.room.type;
        if (extendForm.data.hours) {
            return Math.round(Number(type.hourly_rate || 0) * Number(extendForm.data.hours));
        }
        if (extendForm.data.days) {
            return Math.round(Number(type.base_rate || 0) * Number(extendForm.data.days));
        }
        return 0;
    };

    const handleExtendCashInput = (e, total) => {
        const cash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        extendForm.setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: total - cash }));
    };

    const handleExtendGCashInput = (e, total) => {
        const gcash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        extendForm.setData(prev => ({ ...prev, cash_amount: total - gcash, gcash_amount: gcash }));
    };

    const handleCheckoutCashInput = (e, total) => {
        const cash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        checkoutForm.setData(prev => ({ ...prev, cash_amount: cash, gcash_amount: total - cash }));
    };

    const handleCheckoutGCashInput = (e, total) => {
        const gcash = Math.min(total, Math.max(0, Number(e.target.value) || 0));
        checkoutForm.setData(prev => ({ ...prev, cash_amount: total - gcash, gcash_amount: gcash }));
    };

    return (
        <>
            <Transition show={isOpen} as={Fragment}>
                <Dialog onClose={onClose} className="relative z-[1000]">
                    {/* Backdrop transition */}
                    <TransitionChild
                        as={Fragment}
                        enter="ease-out duration-300"
                        enterFrom="opacity-0"
                        enterTo="opacity-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    >
                        <div className="fixed inset-0 bg-[#070b13]/80 backdrop-blur-sm" />
                    </TransitionChild>

                    {/* Dialog Panel wrapper */}
                    <div className="fixed inset-0 flex items-center justify-center p-4">
                        <TransitionChild
                            as={Fragment}
                            enter="ease-out duration-300"
                            enterFrom="opacity-0 scale-95"
                            enterTo="opacity-100 scale-100"
                            leave="ease-in duration-200"
                            leaveFrom="opacity-100 scale-100"
                            leaveTo="opacity-0 scale-95"
                        >
                            <DialogPanel className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden text-xs text-slate-100 relative z-10">
                    
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-[#334155] px-6 py-4 bg-[#0f172a]/40 shrink-0">
                        {loading ? (
                            <div className="flex items-center gap-2">
                                <RefreshCw size={16} className="animate-spin text-brand-400" />
                                <span className="font-outfit font-black text-slate-200">Loading stay details...</span>
                            </div>
                        ) : (
                            <div className="flex items-center gap-3 justify-between w-full pr-4">
                                <div className="flex items-center gap-3">
                                    <h2 className="text-xl font-outfit font-black text-slate-100">Booking: {booking.booking_ref}</h2>
                                    {booking.status === 'active' && (
                                        <span className="px-2 py-0.5 bg-emerald-950 text-emerald-400 border border-emerald-500/20 text-[9px] font-black uppercase rounded-full">Active Stay</span>
                                    )}
                                    {booking.status === 'checked_out' && (
                                        <span className="px-2 py-0.5 bg-blue-950 text-blue-400 border border-blue-500/20 text-[9px] font-black uppercase rounded-full">Checked Out</span>
                                    )}
                                    {booking.status === 'cancelled' && (
                                        <span className="px-2 py-0.5 bg-rose-950 text-rose-450 border border-rose-500/20 text-[9px] font-black uppercase rounded-full">Cancelled</span>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    {booking.status !== 'cancelled' && (
                                        <button
                                            onClick={() => setShowReceiptModal(true)}
                                            className="flex items-center gap-1.5 px-3 py-1.5 bg-[#334155]/60 hover:bg-[#0f172a] border border-[#475569]/30 text-slate-250 hover:text-slate-50 text-[10px] font-bold rounded-xl transition-all"
                                        >
                                            <Printer size={12} /> Print Receipt
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                        <button 
                            onClick={onClose}
                            className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors"
                        >
                            <X size={18} />
                        </button>
                    </div>

                    {loading ? (
                        <div className="flex-1 py-24 flex flex-col items-center justify-center gap-3">
                            <RefreshCw size={36} className="animate-spin text-brand-500" />
                            <span className="font-mono text-slate-400 text-xs uppercase tracking-widest">Querying Stay Ledger...</span>
                        </div>
                    ) : (
                        <div className="flex-1 overflow-y-auto p-6 space-y-6">
                            
                            {/* Main Split Grid */}
                            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                                
                                {/* Left Side (Guest Details, Minibar Ledger, Payments Timeline) */}
                                <div className="lg:col-span-2 space-y-6">
                                    
                                    {/* Card: Guest details */}
                                    <div className="p-5 rounded-xl bg-[#0f172a]/20 border border-[#334155]/60 flex flex-col gap-4 shadow">
                                        <h3 className="text-sm font-outfit font-black text-slate-200 border-b border-[#334155]/50 pb-2">Occupant Credentials</h3>
                                        
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block">Occupant Guest</span>
                                                <span className="font-outfit font-black text-sm text-slate-100 block mt-1">{booking.guest_name}</span>
                                                <span className="text-slate-450 block mt-0.5">{booking.guest_contact || 'No contact phone number'}</span>
                                                <span className="text-[9px] text-slate-500 font-mono block mt-1 uppercase">
                                                    {booking.guest_id_type || 'ID type'}: {booking.guest_id_number || 'N/A'}
                                                </span>
                                                {booking.guest_id_image_path && (
                                                    <div className="mt-3">
                                                        <div className="cursor-pointer" onClick={() => { setPreviewImage(`/storage/${booking.guest_id_image_path}`); setIsImageModalOpen(true); }}>
                                                            <img src={`/storage/${booking.guest_id_image_path}`} alt="Guest ID Document" className="w-full h-32 object-contain bg-[#0f172a] border border-[#334155]/60 rounded-xl hover:opacity-80 transition-opacity" />
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                            <div className="p-3 bg-[#0f172a] border border-[#334155]/60 rounded-xl flex flex-col gap-1.5">
                                                <div className="flex justify-between">
                                                    <span className="text-slate-500 font-bold">Assigned Room:</span>
                                                    <span className="font-black text-brand-400">Room {booking.room?.room_number}</span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-slate-500 font-bold">Room Class:</span>
                                                    <span className="capitalize">{booking.room?.type?.type_name}</span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-slate-500 font-bold">Stay Type:</span>
                                                    <span className="capitalize font-mono text-[9px] bg-[#334155] px-1.5 py-0.5 rounded text-slate-300">
                                                        {booking.booking_type === 'overnight' ? 'Overnight' : `${booking.short_time_hours}h hourly`}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-[#0f172a]/30 border border-[#334155]/60 p-3 rounded-xl">
                                            <div className="flex flex-col gap-1">
                                                <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Checked In</span>
                                                <span className="font-mono text-slate-300 font-bold">{new Date(booking.check_in).toLocaleString()}</span>
                                            </div>
                                            <div className="flex flex-col gap-1 border-t sm:border-t-0 sm:border-l border-[#334155]/60 pt-2 sm:pt-0 sm:pl-3">
                                                <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Expected Checkout</span>
                                                <span className="font-mono text-slate-300 font-bold">{new Date(booking.expected_check_out).toLocaleString()}</span>
                                            </div>
                                            <div className="flex flex-col gap-1 border-t sm:border-t-0 sm:border-l border-[#334155]/60 pt-2 sm:pt-0 sm:pl-3">
                                                <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Actual Checkout</span>
                                                <span className="font-mono text-slate-300 font-bold">
                                                    {booking.check_out ? new Date(booking.check_out).toLocaleString() : 'Active stay'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card: Minibar orders */}
                                    <div className="p-5 rounded-xl bg-[#0f172a]/20 border border-[#334155]/60 shadow">
                                        <div className="flex justify-between items-center mb-3 border-b border-[#334155]/50 pb-2">
                                            <h3 className="text-sm font-outfit font-black text-slate-200">Consumed Minibar & Orders</h3>
                                        </div>

                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left border-collapse text-[11px]">
                                                <thead>
                                                    <tr className="border-b border-[#334155]/80 text-[9px] font-semibold text-slate-400 uppercase tracking-wider pb-1.5">
                                                        <th className="pb-1">Order Item</th>
                                                        <th className="pb-1">Quantity</th>
                                                        <th className="pb-1">Unit Cost</th>
                                                        <th className="pb-1">Subtotal</th>
                                                        <th className="pb-1 text-right">Notes</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-[#334155]/40 text-slate-300">
                                                    {inventoryUsages.length > 0 ? (
                                                        inventoryUsages.map((usage) => (
                                                            <tr key={usage.id} className="hover:bg-[#0f172a]/10">
                                                                <td className="py-2 font-bold text-slate-200">{usage.item?.item_name || 'Item'}</td>
                                                                <td className="py-2 font-mono">{usage.quantity} {usage.item?.unit}</td>
                                                                <td className="py-2 font-mono">₱{usage.unit_price}</td>
                                                                <td className="py-2 font-mono font-bold text-brand-400">₱{usage.total_price.toLocaleString()}</td>
                                                                <td className="py-2 text-right text-slate-500 italic max-w-xs truncate">{usage.notes || 'None'}</td>
                                                            </tr>
                                                        ))
                                                    ) : (
                                                        <tr>
                                                            <td colSpan="5" className="py-4 text-center text-slate-550 italic">
                                                                No pantry replenishment or service orders logged under this stay.
                                                            </td>
                                                        </tr>
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    {/* Card: Payment History timeline */}
                                    <div className="p-5 rounded-xl bg-[#0f172a]/20 border border-[#334155]/60 shadow">
                                        <h3 className="text-sm font-outfit font-black text-slate-200 border-b border-[#334155]/50 pb-2 mb-3">Audited Transactions Logs</h3>
                                        
                                        <div className="space-y-3">
                                            {booking.transactions && booking.transactions.length > 0 ? (
                                                booking.transactions.map((txn, idx) => (
                                                    <div key={txn.id} className="p-3.5 rounded-xl bg-[#0f172a]/45 border border-[#334155]/80 flex gap-3">
                                                        <div className="p-2 bg-[#334155]/30 border border-[#334155] rounded-xl shrink-0 h-8 w-8 flex items-center justify-center font-bold text-brand-400 text-[10px]">
                                                            {idx + 1}
                                                        </div>
                                                        <div className="flex-1 min-w-0 leading-normal">
                                                            <div className="flex justify-between font-outfit font-black text-slate-200">
                                                                <span className="capitalize">{txn.transaction_type.replace('_', ' ')} Statement</span>
                                                                <span className="font-mono text-emerald-450 font-black">₱{txn.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                            </div>
                                                            <p className="text-slate-450 mt-0.5 text-[10px] leading-relaxed">{txn.description}</p>
                                                            <div className="flex justify-between items-center mt-2 pt-1.5 border-t border-[#334155]/30 text-[9px] text-slate-500 font-medium">
                                                                <span>Audited By: {txn.processed_by?.name || 'Grand System'}</span>
                                                                <span className="font-mono">{new Date(txn.created_at).toLocaleString()}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="py-4 text-center text-slate-550 italic">
                                                    No financial records logged.
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Right Side (Billing Summary, Actions) */}
                                <div className="space-y-6">
                                    
                                    {/* Card: Billing Summary */}
                                    <div className="p-5 rounded-xl bg-[#0f172a]/20 border border-[#334155]/60 flex flex-col gap-4 relative overflow-hidden shadow">
                                        {booking.is_peak && (
                                            <div className="absolute top-0 left-0 right-0 py-1 bg-amber-500 text-slate-950 text-[9px] uppercase font-black tracking-widest text-center flex items-center justify-center gap-1 shadow-sm">
                                                <TrendingUp size={9} /> Peak Rates Applied
                                            </div>
                                        )}
                                        
                                        <h3 className="font-outfit font-black text-slate-200 text-sm uppercase tracking-wide border-b border-[#334155]/50 pb-2 pt-1 mt-1">
                                            Billing Invoice Summary
                                        </h3>

                                        <div className="flex flex-col gap-3 text-[11px]">
                                            <div className="flex justify-between">
                                                <span className="text-slate-400">Base Room charges:</span>
                                                <span className="font-mono text-slate-200 font-bold">₱{booking.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                            </div>
                                            {booking.peak_surcharge > 0 && (
                                                <div className="flex justify-between">
                                                    <span className="text-slate-400">Peak surcharge:</span>
                                                    <span className="font-mono text-amber-400 font-bold">+ ₱{booking.peak_surcharge.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            )}
                                            {booking.extra_pax_charges > 0 && (
                                                <div className="flex justify-between">
                                                    <span className="text-slate-400">Extra pax charges:</span>
                                                    <span className="font-mono text-amber-400 font-bold">+ ₱{Number(booking.extra_pax_charges).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            )}
                                            {booking.discount_amount > 0 && (
                                                <div className="flex justify-between">
                                                    <span className="text-slate-400 capitalize">{booking.discount_type} discount:</span>
                                                    <span className="font-mono text-emerald-450 font-bold">- ₱{booking.discount_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            )}
                                            {booking.extension_fee > 0 && (
                                                <div className="flex justify-between">
                                                    <span className="text-slate-400">Extension fees paid:</span>
                                                    <span className="font-mono text-slate-200 font-bold">₱{booking.extension_fee.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            )}

                                            <div className="h-px bg-[#334155]/50 my-1" />

                                            <div className="flex justify-between">
                                                <span className="text-slate-400 font-medium">Deposit / Initial Payments:</span>
                                                <span className="font-mono text-emerald-400 font-bold">₱{booking.amount_paid.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                            </div>

                                            {/* Active Checkout Balances - ONLY rendered if in active check-in operational viewMode */}
                                            {viewMode === 'checkin' && booking.status === 'active' && (
                                                <div className="p-3.5 rounded-xl bg-[#0f172a] border border-[#334155]/80 flex flex-col gap-2 mt-1 shadow-inner">
                                                    <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider">Unsettled checkout fees</span>
                                                    
                                                    {calculations.late_fee > 0 && (
                                                        <div className="flex justify-between text-rose-450">
                                                            <span>Overdue overtime ({calculations.late_hours}h):</span>
                                                            <span className="font-mono font-bold">+ ₱{calculations.late_fee.toLocaleString()}</span>
                                                        </div>
                                                    )}
                                                    {calculations.unpaid_inventory > 0 && (
                                                        <div className="flex justify-between text-brand-300">
                                                            <span>Minibar unpaid usages:</span>
                                                            <span className="font-mono font-bold">+ ₱{calculations.unpaid_inventory.toLocaleString()}</span>
                                                        </div>
                                                    )}
                                                    {calculations.additional_due === 0 && (
                                                        <span className="text-emerald-450 font-extrabold text-[10px]">No balances due. Clear to checkout.</span>
                                                    )}

                                                    <div className="h-px bg-[#334155]/40 my-1" />

                                                    <div className="flex justify-between items-baseline font-bold font-outfit text-slate-200">
                                                        <span>Outstanding Due:</span>
                                                        <span className="font-mono text-base text-emerald-450">₱{calculations.additional_due.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                    </div>
                                                </div>
                                            )}
                                        </div>

                                        {/* Operations actions triggers - ONLY rendered if in active check-in operational viewMode */}
                                        {viewMode === 'checkin' && booking.status === 'active' && (
                                            <div className="flex flex-col gap-2.5 pt-2">
                                                <button
                                                    onClick={() => {
                                                        setActiveSubModal('checkout');
                                                        checkoutForm.setData({
                                                            payment_method: 'cash',
                                                            cash_amount: calculations.additional_due,
                                                            gcash_amount: 0.00,
                                                            gcash_ref: '',
                                                            notes: ''
                                                        });
                                                    }}
                                                    className="w-full flex items-center justify-center gap-1.5 px-4 py-3 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-slate-50 text-[11px] font-black uppercase tracking-wider shadow cursor-pointer transition-all active:scale-95"
                                                >
                                                    <ClipboardCheck size={14} /> Process Checkout
                                                </button>

                                                {booking.group_ref && (
                                                    <button
                                                        onClick={() => setConfirmGroupCheckout(true)}
                                                        className="w-full flex items-center justify-center gap-1.5 px-4 py-3 bg-indigo-600 hover:bg-indigo-500 rounded-xl text-slate-50 text-[11px] font-black uppercase tracking-wider shadow cursor-pointer transition-all active:scale-95"
                                                    >
                                                        <ClipboardCheck size={14} /> Process Group Checkout
                                                    </button>
                                                )}
                                                
                                                <button
                                                    onClick={() => setActiveSubModal('extend')}
                                                    className="w-full flex items-center justify-center gap-1.5 px-4 py-2 bg-[#0f172a] hover:bg-[#334155] border border-[#334155] rounded-xl text-slate-350 hover:text-slate-100 text-[10px] font-bold uppercase transition-colors"
                                                >
                                                    <Timer size={12} /> Extend Stay
                                                </button>

                                                {['admin', 'front_desk'].includes(currentUser.role) && (
                                                    <button
                                                        onClick={() => {
                                                            setActiveSubModal('move');
                                                            moveForm.setData({
                                                                new_room_id: '',
                                                                reason: ''
                                                            });
                                                        }}
                                                        className="w-full flex items-center justify-center gap-1.5 px-4 py-2 bg-brand-950/20 hover:bg-brand-950/30 border border-brand-900/30 rounded-xl text-brand-400 hover:text-brand-300 text-[10px] font-bold uppercase transition-all"
                                                    >
                                                        <Shuffle size={12} /> Reassign Room
                                                    </button>
                                                )}

                                                <button
                                                    onClick={() => setActiveSubModal('cancel')}
                                                    className="w-full flex items-center justify-center gap-1.5 px-4 py-1.5 bg-red-950/20 hover:bg-red-950/30 border border-red-900/30 rounded-xl text-red-400 hover:text-red-300 text-[10px] font-bold uppercase transition-all"
                                                >
                                                    <PowerOff size={11} /> Cancel Stay
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </Dialog>
            </Transition>

            {/* --- Modals Portal --- */}
            <AnimatePresence>
                {/* 1. Modal: Extend stays */}
                {activeSubModal === 'extend' && (
                    <div className="fixed inset-0 bg-[#070b13]/90 z-[99999] flex items-center justify-center p-4">
                        <motion.div 
                            initial={{ opacity: 0, scale: 0.95 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.95 }}
                            className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl p-6 flex flex-col gap-5 text-slate-100"
                        >
                            <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                                <h3 className="font-outfit font-extrabold text-base text-slate-200">Extend Stay Duration</h3>
                                <button onClick={() => setActiveSubModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                    <X size={14} />
                                </button>
                            </div>

                            <form onSubmit={handleExtendSubmit} className="space-y-4 text-xs">
                                <div className="grid grid-cols-2 gap-4">
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

                                {getExtensionFeeEstimate() > 0 && (
                                    <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex justify-between items-center shadow-inner">
                                        <span className="font-bold text-slate-400">Extension Cost Estimate:</span>
                                        <span className="font-mono text-emerald-450 font-black text-base">₱{getExtensionFeeEstimate().toLocaleString()}</span>
                                    </div>
                                )}

                                {getExtensionFeeEstimate() > 0 && (
                                    <div className="space-y-4 pt-2 border-t border-[#334155]/40">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="flex flex-col gap-1.5">
                                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Payment Method</label>
                                                <select 
                                                    value={extendForm.data.payment_method}
                                                    onChange={e => extendForm.setData('payment_method', e.target.value)}
                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold text-xs"
                                                >
                                                    <option value="cash">Cash</option>
                                                    <option value="gcash">GCash</option>
                                                    <option value="split">Split (Cash + GCash)</option>
                                                </select>
                                            </div>

                                            {['gcash', 'split'].includes(extendForm.data.payment_method) && (
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash 13-Digit Ref</label>
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
                                                <div className="grid grid-cols-2 gap-3 text-xs">
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
                                    className="w-full py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-black uppercase tracking-wider shadow cursor-pointer transition-all active:scale-95"
                                >
                                    {extendForm.processing ? 'Extending...' : 'Submit Extension'}
                                </button>
                            </form>
                        </motion.div>
                    </div>
                )}

                {/* 2. Modal: Process Checkout */}
                {activeSubModal === 'checkout' && (() => {
                    const actualAdditionalDue = checkoutForm.data.waive_late_fee 
                        ? Math.max(0, (calculations.additional_due || 0) - (calculations.late_fee || 0)) 
                        : (calculations.additional_due || 0);

                    return (
                        <div className="fixed inset-0 bg-[#070b13]/90 z-[99999] flex items-center justify-center p-4">
                            <motion.div 
                                initial={{ opacity: 0, scale: 0.95 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.95 }}
                                className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl p-6 flex flex-col gap-5 text-slate-100"
                            >
                                <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                                    <h3 className="font-outfit font-extrabold text-base text-slate-200">Process Checkout</h3>
                                    <button onClick={() => setActiveSubModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                        <X size={14} />
                                    </button>
                                </div>

                                <form onSubmit={handleCheckoutSubmit} className="space-y-4 text-xs">
                                    
                                    <div className="p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex justify-between items-center text-xs shadow-inner">
                                        <span className="font-bold text-slate-400">Additional Settlement Due:</span>
                                        <span className="font-mono text-emerald-400 font-bold text-lg">₱{actualAdditionalDue.toLocaleString()}</span>
                                    </div>

                                    {calculations.late_fee > 0 && (
                                        <div className="flex items-center gap-2 p-3 bg-[#0f172a]/40 border border-[#334155]/40 rounded-xl">
                                            <input 
                                                type="checkbox"
                                                id="waive_late_fee"
                                                checked={checkoutForm.data.waive_late_fee}
                                                onChange={e => {
                                                    const checked = e.target.checked;
                                                    const lateFee = calculations.late_fee || 0;
                                                    const baseAdditional = calculations.additional_due || 0;
                                                    const newDue = checked ? Math.max(0, baseAdditional - lateFee) : baseAdditional;
                                                    
                                                    checkoutForm.setData(prev => ({
                                                        ...prev,
                                                        waive_late_fee: checked,
                                                        cash_amount: checkoutForm.data.payment_method === 'split' ? Math.min(checkoutForm.data.cash_amount, newDue) : newDue,
                                                        gcash_amount: checkoutForm.data.payment_method === 'split' ? Math.max(0, newDue - Math.min(checkoutForm.data.cash_amount, newDue)) : 0
                                                    }));
                                                }}
                                                className="rounded bg-[#1e293b] border-[#334155] text-brand-500 focus:ring-brand-500 focus:ring-offset-0 focus:outline-none cursor-pointer"
                                            />
                                            <label htmlFor="waive_late_fee" className="font-outfit font-bold text-slate-300 select-none cursor-pointer">
                                                Waive Late Check-Out Fee (₱{calculations.late_fee.toLocaleString()})
                                            </label>
                                        </div>
                                    )}

                                    {actualAdditionalDue > 0 && (
                                        <div className="space-y-4 pt-2">
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Settlement Channel</label>
                                                    <select 
                                                        value={checkoutForm.data.payment_method}
                                                        onChange={e => {
                                                            const channel = e.target.value;
                                                            checkoutForm.setData(prev => ({
                                                                ...prev,
                                                                payment_method: channel,
                                                                cash_amount: channel === 'split' ? (actualAdditionalDue / 2) : actualAdditionalDue,
                                                                gcash_amount: channel === 'split' ? (actualAdditionalDue / 2) : 0
                                                            }));
                                                        }}
                                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold text-xs"
                                                    >
                                                        <option value="cash">Cash</option>
                                                        <option value="gcash">GCash</option>
                                                        <option value="split">Split (Cash + GCash)</option>
                                                    </select>
                                                </div>

                                                {['gcash', 'split'].includes(checkoutForm.data.payment_method) && (
                                                    <div className="flex flex-col gap-1.5">
                                                        <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash 13-Digit Ref</label>
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
                                                    <div className="grid grid-cols-2 gap-3 text-xs">
                                                        <div className="flex flex-col gap-1.5">
                                                            <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Cash Amount Received</label>
                                                            <input 
                                                                type="number"
                                                                min="0"
                                                                max={actualAdditionalDue}
                                                                step="any"
                                                                value={checkoutForm.data.cash_amount}
                                                                onChange={e => handleCheckoutCashInput(e, actualAdditionalDue)}
                                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                            />
                                                        </div>
                                                        <div className="flex flex-col gap-1.5">
                                                            <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash Amount Received</label>
                                                            <input 
                                                                type="number"
                                                                min="0"
                                                                max={actualAdditionalDue}
                                                                step="any"
                                                                value={checkoutForm.data.gcash_amount}
                                                                onChange={e => handleCheckoutGCashInput(e, actualAdditionalDue)}
                                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono font-bold"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="flex flex-col gap-1.5">
                                        <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Checkout Remarks / Notes</label>
                                        <textarea 
                                            value={checkoutForm.data.notes}
                                            onChange={e => checkoutForm.setData('notes', e.target.value)}
                                            placeholder="Add checkout details..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 resize-none h-16 focus:outline-none focus:border-brand-500"
                                        />
                                    </div>

                                    <button
                                        type="submit"
                                        disabled={checkoutForm.processing}
                                        className="w-full py-3 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-slate-50 font-outfit font-black uppercase tracking-wider shadow cursor-pointer transition-all active:scale-95"
                                    >
                                        {checkoutForm.processing ? 'Auditing Checkout...' : 'Confirm Checkout & Clear Room'}
                                    </button>
                                </form>
                            </motion.div>
                        </div>
                    );
                })()}

                {/* 3. Modal: Reassign Room */}
                {activeSubModal === 'move' && (
                    <div className="fixed inset-0 bg-[#070b13]/90 z-[99999] flex items-center justify-center p-4">
                        <motion.div 
                            initial={{ opacity: 0, scale: 0.95 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.95 }}
                            className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md overflow-hidden shadow-2xl p-6 flex flex-col gap-5 text-slate-100"
                        >
                            <div className="flex justify-between items-center border-b border-[#334155] pb-3">
                                <h3 className="font-outfit font-extrabold text-base text-slate-200">Reassign Guest Room</h3>
                                <button onClick={() => setActiveSubModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                    <X size={14} />
                                </button>
                            </div>

                            <form onSubmit={handleMoveSubmit} className="space-y-4 text-xs">
                                
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Select Available Room</label>
                                    <select 
                                        value={moveForm.data.new_room_id}
                                        onChange={e => moveForm.setData('new_room_id', e.target.value)}
                                        required
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold text-xs"
                                    >
                                        <option value="">-- Select Vacant Room --</option>
                                        {vacantRooms.map(room => (
                                            <option key={room.id} value={room.id}>
                                                Room {room.room_number} ({room.type?.type_name})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Reason for Transfer</label>
                                    <input 
                                        type="text" 
                                        value={moveForm.data.reason}
                                        onChange={e => moveForm.setData('reason', e.target.value)}
                                        placeholder="e.g. Toilet leak, AC not cooling..."
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500"
                                    />
                                </div>

                                <button
                                    type="submit"
                                    disabled={moveForm.processing || !moveForm.data.new_room_id}
                                    className="w-full py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-black uppercase tracking-wider shadow cursor-pointer transition-all active:scale-95"
                                >
                                    {moveForm.processing ? 'Reassigning...' : 'Perform Room Reassignment'}
                                </button>
                            </form>
                        </motion.div>
                    </div>
                )}

                {/* 4. Modal: Cancel Booking Stay */}
                {activeSubModal === 'cancel' && (
                    <div className="fixed inset-0 bg-[#070b13]/90 z-[99999] flex items-center justify-center p-4">
                        <motion.div 
                            initial={{ opacity: 0, scale: 0.95 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.95 }}
                            className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md overflow-hidden shadow-2xl p-6 flex flex-col gap-5 text-slate-100"
                        >
                            <div className="flex justify-between items-center border-b border-red-500/30 pb-3">
                                <h3 className="font-outfit font-extrabold text-base text-red-400">Cancel Booking Stay</h3>
                                <button onClick={() => setActiveSubModal(null)} className="p-1 rounded bg-[#0f172a] border border-[#334155] text-slate-400">
                                    <X size={14} />
                                </button>
                            </div>

                            <form onSubmit={handleCancelSubmit} className="space-y-4 text-xs">
                                
                                <div className="flex items-center gap-3 p-3 rounded-xl bg-red-950/20 border border-red-500/30 text-red-300">
                                    <AlertTriangle size={18} className="shrink-0 animate-pulse text-red-400" />
                                    <span><strong>Warning:</strong> Cancellation reverts room status to Vacant and restores inventory counts. This action is auditable.</span>
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Reason for Cancellation</label>
                                    <input 
                                        type="text" 
                                        value={cancelForm.data.reason}
                                        onChange={e => cancelForm.setData('reason', e.target.value)}
                                        required
                                        placeholder="e.g. Guest change of plans, personal emergency..."
                                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 font-bold"
                                    />
                                </div>

                                <button
                                    type="submit"
                                    disabled={cancelForm.processing || !cancelForm.data.reason}
                                    className="w-full py-3 bg-red-600 hover:bg-red-500 rounded-xl text-slate-50 font-outfit font-black uppercase tracking-wider shadow cursor-pointer transition-all active:scale-95"
                                >
                                    {cancelForm.processing ? 'Cancelling...' : 'Confirm Stay Cancellation'}
                                </button>
                            </form>
                        </motion.div>
                    </div>
                )}

                {/* Removed pos_receipt modal */}
            </AnimatePresence>

            <ImagePreviewModal 
                isOpen={isImageModalOpen}
                imageUrl={previewImage}
                onClose={() => { setIsImageModalOpen(false); setPreviewImage(null); }}
            />

            <ReceiptModal
                isOpen={showReceiptModal}
                booking={booking}
                onClose={() => setShowReceiptModal(false)}
            />
            {/* Group Settle Modal */}
            <GroupSettleModal
                isOpen={confirmGroupCheckout}
                groupRef={booking?.group_ref}
                onClose={() => setConfirmGroupCheckout(false)}
                onSuccess={() => {
                    setConfirmGroupCheckout(false);
                    onClose();
                }}
            />
        </>
    );
}
