import React, { useRef, Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { X, Printer, Building, User, Crown } from 'lucide-react';
import { usePage } from '@inertiajs/react';

export default function ReceiptModal({ isOpen, booking, onClose }) {
    const { app_name } = usePage().props;
    const [logoError, setLogoError] = React.useState(false);

    if (!booking) return null;

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(val || 0);
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '—';
        return new Date(dateStr).toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true
        });
    };

    const balanceDue = Math.max(0, (booking.total_amount || 0) - (booking.amount_paid || 0));
    const isHourly = booking.booking_type === 'short_time' || booking.booking_type === 'hourly';
    const displayStayType = isHourly ? 'Short Time (Hourly)' : 'Overnight Stay';

    const transactions = booking.transactions || [];
    const orNumbers = transactions.filter(t => t.formatted_or_number).map(t => t.formatted_or_number);
    const orDisplay = orNumbers.length > 0 ? orNumbers.join(', ') : '';

    const charges = [
        { label: 'Base Room Charge', amount: booking.base_amount },
    ];
    if (Number(booking.peak_surcharge) > 0) charges.push({ label: 'Peak Date Surcharge', amount: booking.peak_surcharge });
    if (Number(booking.extension_fee) > 0) charges.push({ label: 'Extension Stay Fee', amount: booking.extension_fee });
    if (Number(booking.late_checkout_fee) > 0) {
        charges.push({ label: `Late Check-Out Fee ${booking.late_hours ? `(${booking.late_hours} hr/s)` : ''}`, amount: booking.late_checkout_fee });
    }

    const printReceipt = () => {
        window.print();
    };

    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-[99999]">
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
                    <div className="fixed inset-0 bg-[#070b13]/80 backdrop-blur-sm print:hidden" />
                </TransitionChild>

                <div className="fixed inset-0 flex items-center justify-center p-4 print:p-0 overflow-y-auto">
                    <style dangerouslySetInnerHTML={{
                        __html: `
                        @media print {
                            @page { size: auto; margin: 0; }
                            body * { visibility: hidden; }
                            #printable-receipt, #printable-receipt * { visibility: visible; }
                            #printable-receipt { position: absolute; left: 0; top: 0; width: 100%; margin: 0; padding: 20px; background: white; color: black; box-shadow: none; border: none; }
                            .print-hide { display: none !important; }
                            .print-text-black { color: black !important; }
                            .print-border-black { border-color: black !important; }
                            .print-bg-transparent { background: transparent !important; }
                        }
                    `}} />

                    <TransitionChild
                        as={Fragment}
                        enter="ease-out duration-300"
                        enterFrom="opacity-0 scale-95"
                        enterTo="opacity-100 scale-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100 scale-100"
                        leaveTo="opacity-0 scale-95"
                    >
                        <DialogPanel className="bg-[#1e293b] border border-[#334155] rounded-3xl w-full max-w-4xl overflow-hidden shadow-2xl flex flex-col max-h-[90vh] relative z-10 print:shadow-none print:border-none print:max-w-full print:max-h-none print:bg-white print:rounded-none">
                            {/* Header Controls (Hidden on Print) */}
                            <div className="flex justify-between items-center px-6 py-4 border-b border-[#334155] bg-slate-900 shrink-0 print-hide">
                                <h3 className="font-outfit font-extrabold text-lg text-slate-100 flex items-center gap-2">
                                    <Printer size={18} className="text-brand-400" /> Guest Receipt
                                </h3>
                                <div className="flex items-center gap-3">
                                    <button onClick={printReceipt} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 font-bold text-xs text-slate-50 transition-all shadow-lg shadow-brand-600/30">
                                        <Printer size={14} /> Print
                                    </button>
                                    <button onClick={onClose} className="p-2 rounded-xl bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors">
                                        <X size={16} />
                                    </button>
                                </div>
                            </div>

                            {/* Printable Area */}
                            <div id="printable-receipt" className="flex-1 overflow-y-auto p-6 md:p-10 print:p-4 bg-slate-900 print:bg-white">
                                <div className="max-w-3xl mx-auto">
                                    {/* Brand Header */}
                                    <div className="flex flex-col md:flex-row justify-between items-start md:items-stretch gap-6 border-b border-slate-800 print-border-black pb-8 mb-8">
                                        <div className="flex items-center gap-4">
                                            <div className="h-16 w-16 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center overflow-hidden shrink-0 print-border-black">
                                                {!logoError ? (
                                                    <img src="/images/logo.jpg" alt="Logo" className="h-full w-full object-cover" onError={() => setLogoError(true)} />
                                                ) : (
                                                    <Building className="h-9 w-9 text-brand-400 print-text-black" />
                                                )}
                                            </div>
                                            <div>
                                                <h2 className="font-outfit font-black text-2xl tracking-wide text-slate-100 print-text-black">
                                                    {app_name || 'UPTOWN PENSION HOUSE'}
                                                </h2>
                                            </div>
                                        </div>
                                        <div className="md:text-right">
                                            <span className="text-[10px] uppercase font-bold tracking-widest bg-brand-500/10 border border-brand-500/30 text-brand-400 px-3 py-1 rounded-full print-text-black print-border-black">
                                                Guest Receipt
                                            </span>
                                            <div className="mt-4">
                                                <div className="font-mono font-bold text-lg text-brand-300 print-text-black">
                                                    {orDisplay || `RCP-${booking.booking_ref.replace(/[^A-Z0-9]/gi, '').toUpperCase()}`}
                                                </div>
                                                <div className="text-[11px] text-slate-400 font-medium print-text-black mt-1">
                                                    Reference: <span className="font-semibold text-slate-200 print-text-black">{booking.booking_ref}</span>
                                                </div>
                                                <div className="text-[11px] text-slate-400 font-medium print-text-black">
                                                    Issued: {new Date().toLocaleString()}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Guest & Stay Meta */}
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8 bg-slate-950/40 p-6 rounded-2xl border border-slate-800/80 print-bg-transparent print-border-black print:rounded-none">
                                        <div>
                                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold border-b border-slate-800/50 print-border-black pb-2 mb-3 print-text-black">Guest Info</h3>
                                            <div className="flex gap-2">
                                                <User className="h-4 w-4 text-slate-500 shrink-0 mt-0.5 print-text-black" />
                                                <div>
                                                    <div className="text-sm font-bold text-slate-200 print-text-black flex items-center gap-1.5">
                                                        {booking.guest_name}
                                                        {booking.guest_profile?.is_vip && <Crown size={12} className="text-amber-400 print-text-black" />}
                                                    </div>
                                                    <div className="text-[11px] text-slate-400 font-semibold mt-0.5 print-text-black">Contact: {booking.guest_contact || '—'}</div>
                                                    <div className="text-[11px] text-slate-400 font-medium print-text-black">Address: {booking.guest_profile?.address || '—'}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold border-b border-slate-800/50 print-border-black pb-2 mb-3 print-text-black">Stay Details</h3>
                                            <div className="space-y-1.5 text-xs">
                                                <div className="grid grid-cols-3"><span className="text-slate-500 font-bold print-text-black">Room</span><span className="col-span-2 text-slate-200 print-text-black font-extrabold">Room {booking.room?.room_number} — {booking.room?.type?.type_name}</span></div>
                                                <div className="grid grid-cols-3"><span className="text-slate-500 font-bold print-text-black">Type</span><span className="col-span-2 text-slate-300 print-text-black font-semibold">{displayStayType} {booking.short_time_hours ? `(${booking.short_time_hours} hrs)` : ''}</span></div>
                                                <div className="grid grid-cols-3"><span className="text-slate-500 font-bold print-text-black">Check-In</span><span className="col-span-2 text-slate-300 print-text-black font-medium">{formatDate(booking.check_in)}</span></div>
                                                <div className="grid grid-cols-3"><span className="text-slate-500 font-bold print-text-black">Check-Out</span><span className="col-span-2 text-slate-300 print-text-black font-medium">{formatDate(booking.check_out || booking.expected_check_out)}</span></div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Billing & Charges */}
                                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8 items-start">
                                        {/* Room Charges */}
                                        <div>
                                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-3 print-text-black">Room Charges</h3>
                                            <table className="w-full text-left text-xs border border-slate-800 print-border-black border-collapse">
                                                <thead>
                                                    <tr className="bg-slate-950 border-b border-slate-800 text-slate-400 font-bold print-text-black print-bg-transparent print-border-black">
                                                        <th className="px-4 py-3">Description</th>
                                                        <th className="px-4 py-3 text-right">Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-800 print-border-black">
                                                    {charges.map((c, i) => (
                                                        <tr key={i}><td className="px-4 py-3 text-slate-300 print-text-black font-medium">{c.label}</td><td className="px-4 py-3 text-right font-bold text-slate-100 print-text-black">{formatCurrency(c.amount)}</td></tr>
                                                    ))}
                                                    {Number(booking.discount_amount) > 0 && (
                                                        <tr><td className="px-4 py-3 text-rose-400 print-text-black font-semibold">Discount</td><td className="px-4 py-3 text-right font-extrabold text-rose-400 print-text-black">- {formatCurrency(booking.discount_amount)}</td></tr>
                                                    )}
                                                    <tr className="font-bold border-t border-slate-800 print-border-black">
                                                        <td className="px-4 py-4 text-brand-300 print-text-black uppercase text-xs">Total Room</td>
                                                        <td className="px-4 py-4 text-right text-brand-300 print-text-black text-sm">{formatCurrency(booking.total_amount)}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        {/* Minibar Charges */}
                                        <div>
                                            <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-3 print-text-black">Minibar & Inventory</h3>
                                            <table className="w-full text-left text-xs border border-slate-800 print-border-black border-collapse">
                                                <thead>
                                                    <tr className="bg-slate-950 border-b border-slate-800 text-slate-400 font-bold print-text-black print-bg-transparent print-border-black">
                                                        <th className="px-4 py-3">Item</th>
                                                        <th className="px-4 py-3 text-right">Subtotal</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-800 print-border-black">
                                                    {booking.inventory_usages && booking.inventory_usages.length > 0 ? (
                                                        booking.inventory_usages.map((u, i) => (
                                                            <tr key={i}><td className="px-4 py-3 text-slate-300 print-text-black">{u.item?.item_name} x {u.quantity}</td><td className="px-4 py-3 text-right font-bold text-slate-100 print-text-black">{formatCurrency(u.total_cost || (u.quantity * (u.item?.selling_price || 0)))}</td></tr>
                                                        ))
                                                    ) : (
                                                        <tr><td colSpan="2" className="px-4 py-4 text-slate-500 italic text-center print-text-black">No inventory consumed</td></tr>
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    {/* Summary Totals */}
                                    <div className="flex flex-col items-end gap-2 border-t border-slate-800 pt-6 mt-4 print-border-black">
                                        <div className="w-full max-w-xs space-y-2 text-sm">
                                            <div className="flex justify-between font-bold text-slate-200 print-text-black">
                                                <span>Grand Total:</span>
                                                <span>
                                                    {formatCurrency(Number(booking.total_amount) + Number(booking.inventory_usages?.reduce((acc, u) => acc + Number(u.total_cost || (u.quantity * (u.item?.selling_price || 0))), 0) || 0))}
                                                </span>
                                            </div>
                                            <div className="flex justify-between font-bold text-emerald-400 print-text-black">
                                                <span>Amount Paid:</span>
                                                <span>{formatCurrency(booking.amount_paid)}</span>
                                            </div>
                                            <div className="flex justify-between font-black text-slate-100 text-lg border-t border-slate-800 pt-2 print-border-black print-text-black">
                                                <span>Balance Due:</span>
                                                <span className={balanceDue > 0 ? 'text-rose-400 print-text-black' : 'text-emerald-400 print-text-black'}>{formatCurrency(balanceDue)}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
