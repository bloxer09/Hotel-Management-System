import React, { useState, useEffect, Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { X, ShieldAlert, Coins, BedDouble, CalendarDays, Receipt } from 'lucide-react';
import axios from 'axios';

export default function GroupSettleModal({ isOpen, groupRef, onClose, onSuccess }) {
    if (!isOpen || !groupRef) return null;

    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const { data: form, setData: setFormData, post, processing, errors } = useForm({
        waive_late_fee: false,
        payment_method: 'cash',
        cash_amount: 0.00,
        gcash_amount: 0.00,
        gcash_ref: '',
        bank_amount: 0.00,
        bank_ref: '',
    });

    useEffect(() => {
        setLoading(true);
        setError(null);
        axios.get(route('reservations.group_checkout_preview', groupRef))
            .then(res => {
                setData(res.data);
                setLoading(false);
                // Pre-populate amount paid to balance due initially
                setFormData(prev => ({
                    ...prev,
                    cash_amount: Number(res.data.totals.balance),
                    waive_late_fee: false
                }));
            })
            .catch(err => {
                setError(err.response?.data?.error || 'Failed to load group details.');
                setLoading(false);
            });
    }, [groupRef]);

    // Recalculate totals client-side when waiving late fees
    const getActiveTotals = () => {
        if (!data) return null;
        const waive = form.waive_late_fee;
        let newLate = 0.00;
        let newDue = 0.00;
        let newTotal = 0.00;

        const rooms = data.rooms.map(r => {
            const lateFee = waive ? 0.00 : Number(r.late_fee);
            const total = Number(r.base_amount) + Number(r.extension_fee) + Number(r.inventory_total) + lateFee;
            const bal = Math.max(0.00, total - Number(r.amount_paid));

            newLate += lateFee;
            newTotal += total;
            newDue += bal;

            return { ...r, late_fee: lateFee, total_amount: total, balance: bal };
        });

        return {
            rooms,
            totals: {
                base: data.totals.base,
                extension: data.totals.extension,
                minibar: data.totals.minibar,
                late: newLate,
                total: newTotal,
                paid: data.totals.paid,
                balance: newDue
            }
        };
    };

    const activeData = getActiveTotals();

    // Auto-update amount fields on change of payment method or waive toggle
    const handleWaiveChange = (e) => {
        const checked = e.target.checked;
        setFormData('waive_late_fee', checked);
        if (activeData) {
            // Recompute balance with new waive value
            const waive = checked;
            let newDue = 0.00;
            data.rooms.forEach(r => {
                const lateFee = waive ? 0.00 : Number(r.late_fee);
                const total = Number(r.base_amount) + Number(r.extension_fee) + Number(r.inventory_total) + lateFee;
                const bal = Math.max(0.00, total - Number(r.amount_paid));
                newDue += bal;
            });
            
            // Re-fill initial payment amount
            if (form.payment_method === 'cash') {
                setFormData(prev => ({ ...prev, waive_late_fee: checked, cash_amount: Number(newDue), gcash_amount: 0, bank_amount: 0 }));
            } else if (form.payment_method === 'gcash') {
                setFormData(prev => ({ ...prev, waive_late_fee: checked, cash_amount: 0, gcash_amount: Number(newDue), bank_amount: 0 }));
            } else if (form.payment_method === 'bank_transfer') {
                setFormData(prev => ({ ...prev, waive_late_fee: checked, cash_amount: 0, gcash_amount: 0, bank_amount: Number(newDue) }));
            } else {
                setFormData('waive_late_fee', checked);
            }
        }
    };

    const handleMethodChange = (e) => {
        const method = e.target.value;
        const balance = activeData?.totals?.balance || 0;
        setFormData(prev => {
            let updates = { payment_method: method };
            if (method === 'cash') {
                updates.cash_amount = Number(balance);
                updates.gcash_amount = 0;
                updates.bank_amount = 0;
            } else if (method === 'gcash') {
                updates.cash_amount = 0;
                updates.gcash_amount = Number(balance);
                updates.bank_amount = 0;
            } else if (method === 'bank_transfer') {
                updates.cash_amount = 0;
                updates.gcash_amount = 0;
                updates.bank_amount = Number(balance);
            } else { // split
                updates.cash_amount = 0;
                updates.gcash_amount = 0;
                updates.bank_amount = 0;
            }
            return { ...prev, ...updates };
        });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('reservations.group_checkout_settle', groupRef), {
            onSuccess: () => {
                onClose();
                if (onSuccess) onSuccess();
            }
        });
    };

    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-50">
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-[#070b13]/85 backdrop-blur-sm" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto flex items-center justify-center p-4">
                    <TransitionChild
                        as={Fragment}
                        enter="ease-out duration-300"
                        enterFrom="opacity-0 scale-95 y-4"
                        enterTo="opacity-100 scale-100 y-0"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100 scale-100 y-0"
                        leaveTo="opacity-0 scale-95 y-4"
                    >
                        <DialogPanel className="w-full max-w-4xl bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden text-xs">
                            {/* Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-indigo-500/10 text-indigo-400 rounded-xl">
                                        <Receipt size={18} />
                                    </div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">Group Settle & Check-Out</h2>
                                        <p className="text-[10px] text-slate-400">Review combined billing and settle outstanding balances for group: <span className="font-mono font-bold text-indigo-400">{groupRef}</span></p>
                                    </div>
                                </div>
                                <button onClick={onClose} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            {/* Body */}
                            {loading ? (
                                <div className="py-20 text-center text-slate-500 flex flex-col items-center justify-center gap-2">
                                    <div className="w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
                                    <span>Compiling group statistics...</span>
                                </div>
                            ) : error ? (
                                <div className="py-16 px-6 text-center">
                                    <div className="mx-auto w-12 h-12 bg-rose-500/10 text-rose-400 rounded-full flex items-center justify-center mb-3">
                                        <ShieldAlert size={24} />
                                    </div>
                                    <h4 className="text-sm font-bold text-slate-200 mb-1">Audit Blocker</h4>
                                    <p className="text-slate-400 text-[11px] mb-6 max-w-md mx-auto">{error}</p>
                                    <button type="button" onClick={onClose} className="px-4 py-2 bg-[#334155] hover:bg-slate-700 text-slate-200 font-bold rounded-xl transition-all">
                                        Dismiss
                                    </button>
                                </div>
                            ) : (
                                <form onSubmit={handleSubmit} className="p-6">
                                    {/* Billing table */}
                                    <div className="rounded-xl border border-[#334155]/60 overflow-hidden mb-6">
                                        <table className="w-full text-left border-collapse text-[11px]">
                                            <thead>
                                                <tr className="bg-[#0f172a]/60 border-b border-[#334155]/80 text-[10px] text-slate-400 uppercase font-semibold">
                                                    <th className="px-4 py-2.5">Room</th>
                                                    <th className="px-4 py-2.5">Guest</th>
                                                    <th className="px-4 py-2.5 text-right">Base Stays</th>
                                                    <th className="px-4 py-2.5 text-right">Extensions</th>
                                                    <th className="px-4 py-2.5 text-right">Late Fees</th>
                                                    <th className="px-4 py-2.5 text-right">Minibar</th>
                                                    <th className="px-4 py-2.5 text-right">Paid</th>
                                                    <th className="px-4 py-2.5 text-right text-brand-300">Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-[#334155]/40 text-slate-300">
                                                {activeData.rooms.map(r => (
                                                    <tr key={r.id} className="hover:bg-[#0f172a]/30">
                                                        <td className="px-4 py-2.5 font-bold text-slate-200">Room {r.room_number}</td>
                                                        <td className="px-4 py-2.5 font-medium">{r.guest_name}</td>
                                                        <td className="px-4 py-2.5 text-right font-mono">₱{r.base_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                                                        <td className="px-4 py-2.5 text-right font-mono">₱{r.extension_fee.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                                                        <td className="px-4 py-2.5 text-right font-mono text-amber-400">₱{r.late_fee.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                                                        <td className="px-4 py-2.5 text-right font-mono text-purple-400">₱{r.inventory_total.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                                                        <td className="px-4 py-2.5 text-right font-mono text-emerald-400">₱{r.amount_paid.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                                                        <td className="px-4 py-2.5 text-right font-mono font-bold text-slate-200">₱{r.balance.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                                        {/* Invoice breakdown summary */}
                                        <div className="lg:col-span-2 p-5 rounded-2xl bg-[#1e293b] border border-[#334155]/70 flex flex-col gap-3">
                                            <h4 className="text-xs font-bold text-slate-300 uppercase tracking-wider mb-2 flex items-center gap-2">
                                                <Receipt size={14} className="text-indigo-400" /> Consolidated Billing Breakdown
                                            </h4>
                                            
                                            <div className="grid grid-cols-2 gap-x-4 gap-y-2.5 text-slate-400">
                                                <div className="flex justify-between">
                                                    <span>Room Stays (Base):</span>
                                                    <span className="font-mono text-slate-200">₱{activeData.totals.base.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span>Late/Overstay Fees:</span>
                                                    <span className="font-mono text-amber-400">₱{activeData.totals.late.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span>Stay Extension Charges:</span>
                                                    <span className="font-mono text-slate-200">₱{activeData.totals.extension.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span>Minibar & Supplies Total:</span>
                                                    <span className="font-mono text-purple-400">₱{activeData.totals.minibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            </div>

                                            <div className="h-px bg-[#334155]/60 my-2" />

                                            <div className="grid grid-cols-2 gap-4 text-xs font-bold">
                                                <div className="flex justify-between text-slate-400">
                                                    <span>Invoice Grand Total:</span>
                                                    <span className="font-mono text-slate-200">₱{activeData.totals.total.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                                <div className="flex justify-between text-slate-400">
                                                    <span>Deposits / Already Paid:</span>
                                                    <span className="font-mono text-emerald-400">₱{activeData.totals.paid.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                                </div>
                                            </div>

                                            <div className="p-3 bg-[#0f172a]/55 border border-[#334155] rounded-xl flex justify-between items-center mt-2">
                                                <span className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                                    <Coins size={14} className="text-brand-400" /> Consolidated Group Balance Due
                                                </span>
                                                <span className="text-lg font-mono font-black text-brand-300">
                                                    ₱{activeData.totals.balance.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                            </div>

                                            {/* Waive Fees checkbox */}
                                            {Number(activeData.totals.late) > 0 && (
                                                <label className="flex items-center gap-2 p-3 bg-amber-500/5 border border-amber-500/20 rounded-xl cursor-pointer hover:bg-amber-500/10 transition-colors mt-2 text-amber-300 font-medium">
                                                    <input 
                                                        type="checkbox" 
                                                        checked={form.waive_late_fee} 
                                                        onChange={handleWaiveChange}
                                                        className="rounded bg-[#0f172a] border-[#334155] text-amber-500 focus:ring-amber-500/30" 
                                                    />
                                                    Waive Group Overstay / Late Check-Out Fees
                                                </label>
                                            )}
                                        </div>

                                        {/* Payments Form */}
                                        <div className="p-5 rounded-2xl bg-[#1e293b] border border-[#334155]/70 flex flex-col gap-4">
                                            <h4 className="text-xs font-bold text-slate-300 uppercase tracking-wider mb-1 flex items-center gap-2">
                                                <Coins size={14} className="text-emerald-400" /> Cashier Payment Drawer
                                            </h4>

                                            {activeData.totals.balance > 0 ? (
                                                <>
                                                    <div className="flex flex-col gap-1.5">
                                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Payment Method</label>
                                                        <select
                                                            value={form.payment_method}
                                                            onChange={handleMethodChange}
                                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 text-xs"
                                                        >
                                                            <option value="cash">Cash Settlement</option>
                                                            <option value="gcash">GCash Digital Wallet</option>
                                                            <option value="bank_transfer">Bank Transfer</option>
                                                            <option value="split">Split (Multi-Channel)</option>
                                                        </select>
                                                    </div>

                                                    {(form.payment_method === 'cash' || form.payment_method === 'split') && (
                                                        <div className="flex flex-col gap-1">
                                                            <label className="text-[10px] font-semibold text-slate-400 uppercase">Cash Tendered (₱)</label>
                                                            <input
                                                                type="number"
                                                                step="0.01"
                                                                value={form.cash_amount}
                                                                onChange={e => setFormData('cash_amount', Number(e.target.value))}
                                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono text-xs font-bold"
                                                            />
                                                        </div>
                                                    )}

                                                    {(form.payment_method === 'gcash' || form.payment_method === 'split') && (
                                                        <div className="grid grid-cols-2 gap-2">
                                                            <div className="flex flex-col gap-1">
                                                                <label className="text-[10px] font-semibold text-slate-400 uppercase">GCash (₱)</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    value={form.gcash_amount}
                                                                    onChange={e => setFormData('gcash_amount', Number(e.target.value))}
                                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono text-xs font-bold"
                                                                />
                                                            </div>
                                                            <div className="flex flex-col gap-1">
                                                                <label className="text-[10px] font-semibold text-slate-400 uppercase">GCash Ref #</label>
                                                                <input
                                                                    type="text"
                                                                    value={form.gcash_ref}
                                                                    onChange={e => setFormData('gcash_ref', e.target.value)}
                                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 text-xs"
                                                                />
                                                            </div>
                                                        </div>
                                                    )}

                                                    {(form.payment_method === 'bank_transfer' || form.payment_method === 'split') && (
                                                        <div className="grid grid-cols-2 gap-2">
                                                            <div className="flex flex-col gap-1">
                                                                <label className="text-[10px] font-semibold text-slate-400 uppercase">Bank Amount (₱)</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    value={form.bank_amount}
                                                                    onChange={e => setFormData('bank_amount', Number(e.target.value))}
                                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 font-mono text-xs font-bold"
                                                                />
                                                            </div>
                                                            <div className="flex flex-col gap-1">
                                                                <label className="text-[10px] font-semibold text-slate-400 uppercase">Bank Ref #</label>
                                                                <input
                                                                    type="text"
                                                                    value={form.bank_ref}
                                                                    onChange={e => setFormData('bank_ref', e.target.value)}
                                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 focus:outline-none focus:border-brand-500 text-xs"
                                                                />
                                                            </div>
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                <div className="py-6 text-center text-slate-400 font-bold bg-[#0f172a]/55 border border-[#334155] rounded-xl">
                                                    Group is fully paid. No additional payments required.
                                                </div>
                                            )}

                                            <button
                                                type="submit"
                                                disabled={processing}
                                                className={`w-full py-3 rounded-xl font-outfit font-black uppercase text-xs tracking-wider transition-all select-none ${
                                                    processing
                                                        ? 'bg-slate-600 text-slate-400 cursor-not-allowed'
                                                        : 'bg-brand-600 hover:bg-brand-500 text-white shadow-lg shadow-brand-600/20 active:scale-95'
                                                }`}
                                            >
                                                {processing ? 'Processing Settlement...' : activeData.totals.balance > 0 ? 'Settle & Check Out Group' : 'Confirm Group Check-Out'}
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            )}
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
