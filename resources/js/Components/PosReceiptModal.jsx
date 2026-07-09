import React, { useRef, Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { X, Printer, Building, User } from 'lucide-react';
import { usePage } from '@inertiajs/react';

export default function PosReceiptModal({ isOpen, transaction, onClose }) {
    const { app_name } = usePage().props;
    const [logoError, setLogoError] = React.useState(false);

    if (!transaction) return null;

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

    const isSplit = transaction.payment_method === 'split';

    // Attempt to extract consumer name if it exists in the description
    let consumerName = 'Walk-in Customer';
    if (transaction.description && transaction.description.includes('Consumer:')) {
        const match = transaction.description.match(/Consumer:\s*(.*?)(?:\)|$)/);
        if (match && match[1]) {
            consumerName = match[1].trim();
        }
    } else if (transaction.booking_id) {
        consumerName = `Charged to Room Stay`;
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
                            #printable-pos-receipt, #printable-pos-receipt * { visibility: visible; }
                            #printable-pos-receipt { position: absolute; left: 0; top: 0; width: 100%; margin: 0; padding: 20px; background: white; color: black; box-shadow: none; border: none; }
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
                        <DialogPanel className="bg-[#1e293b] border border-[#334155] rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl flex flex-col max-h-[90vh] relative z-10 print:shadow-none print:border-none print:max-w-full print:max-h-none print:bg-white print:rounded-none">
                            {/* Header Controls (Hidden on Print) */}
                            <div className="flex justify-between items-center px-6 py-4 border-b border-[#334155] bg-slate-900 shrink-0 print-hide">
                                <h3 className="font-outfit font-extrabold text-lg text-slate-100 flex items-center gap-2">
                                    <Printer size={18} className="text-brand-400" /> POS Receipt
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
                            <div id="printable-pos-receipt" className="flex-1 overflow-y-auto p-6 md:p-10 print:p-4 bg-slate-900 print:bg-white">
                                <div className="max-w-xl mx-auto">
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
                                                Official Receipt
                                            </span>
                                            <div className="mt-4">
                                                <div className="font-mono font-bold text-lg text-brand-300 print-text-black">
                                                    {transaction.or_number ? `OR-${transaction.or_number}` : `TXN-${transaction.id}`}
                                                </div>
                                                <div className="text-[11px] text-slate-400 font-medium print-text-black mt-1">
                                                    Issued: {formatDate(transaction.created_at)}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Info */}
                                    <div className="mb-6 flex gap-2 items-center bg-slate-950/40 p-4 rounded-xl border border-slate-800/80 print-bg-transparent print-border-black print:rounded-none">
                                        <User className="h-4 w-4 text-slate-500 shrink-0 print-text-black" />
                                        <div>
                                            <div className="text-sm font-bold text-slate-200 print-text-black">{consumerName}</div>
                                            {transaction.booking_id && (
                                                <div className="text-[10px] text-slate-400 font-medium print-text-black mt-0.5">Linked to Stay ID: {transaction.booking_id}</div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Items List */}
                                    <div className="mb-8">
                                        <h3 className="text-xs uppercase tracking-widest text-slate-500 font-bold mb-3 print-text-black">Purchased Items</h3>
                                        <table className="w-full text-left text-xs border border-slate-800 print-border-black border-collapse">
                                            <thead>
                                                <tr className="bg-slate-950 border-b border-slate-800 text-slate-400 font-bold print-text-black print-bg-transparent print-border-black">
                                                    <th className="px-4 py-3">Description</th>
                                                    <th className="px-4 py-3 text-center">Qty</th>
                                                    <th className="px-4 py-3 text-right">Price</th>
                                                    <th className="px-4 py-3 text-right">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-800 print-border-black">
                                                {transaction.inventory_usages && transaction.inventory_usages.length > 0 ? (
                                                    transaction.inventory_usages.map((u, i) => (
                                                        <tr key={i}>
                                                            <td className="px-4 py-3 text-slate-300 print-text-black">{u.item?.item_name || 'Item'}</td>
                                                            <td className="px-4 py-3 text-center text-slate-400 print-text-black">{u.quantity}</td>
                                                            <td className="px-4 py-3 text-right text-slate-400 print-text-black">{formatCurrency(u.unit_price)}</td>
                                                            <td className="px-4 py-3 text-right font-bold text-slate-100 print-text-black">{formatCurrency(u.total_price)}</td>
                                                        </tr>
                                                    ))
                                                ) : (
                                                    <tr>
                                                        <td colSpan="4" className="px-4 py-4 text-slate-500 italic text-center print-text-black">
                                                            {transaction.description || 'Items details not available.'}
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>

                                    {/* Summary Totals */}
                                    <div className="flex flex-col items-end gap-2 border-t border-slate-800 pt-6 mt-4 print-border-black text-sm">
                                        <div className="w-full max-w-xs space-y-2">
                                            <div className="flex justify-between font-black text-slate-100 text-lg print-text-black mb-2">
                                                <span>Grand Total:</span>
                                                <span>{formatCurrency(transaction.amount)}</span>
                                            </div>
                                            <div className="flex justify-between font-semibold text-slate-400 print-text-black text-xs uppercase pt-2 border-t border-slate-800 print-border-black">
                                                <span>Payment Mode:</span>
                                                <span>{transaction.payment_method?.replace('_', ' ')}</span>
                                            </div>

                                            {transaction.cash_amount > 0 && (
                                                <div className="flex justify-between font-medium text-slate-300 print-text-black">
                                                    <span>Cash Given:</span>
                                                    <span>{formatCurrency(transaction.cash_amount)}</span>
                                                </div>
                                            )}
                                            {transaction.gcash_amount > 0 && (
                                                <div className="flex justify-between font-medium text-slate-300 print-text-black">
                                                    <span>GCash:</span>
                                                    <span>{formatCurrency(transaction.gcash_amount)} {transaction.gcash_ref ? `(Ref: ${transaction.gcash_ref})` : ''}</span>
                                                </div>
                                            )}
                                            {transaction.bank_amount > 0 && (
                                                <div className="flex justify-between font-medium text-slate-300 print-text-black">
                                                    <span>Bank Transfer:</span>
                                                    <span>{formatCurrency(transaction.bank_amount)} {transaction.bank_ref ? `(Ref: ${transaction.bank_ref})` : ''}</span>
                                                </div>
                                            )}

                                            {['cash', 'split'].includes(transaction.payment_method) && (
                                                <div className="flex justify-between font-bold text-slate-300 print-text-black pt-1">
                                                    <span>Change:</span>
                                                    <span>
                                                        {formatCurrency(Math.max(0, transaction.cash_amount - (isSplit ? (transaction.amount - transaction.gcash_amount - transaction.bank_amount) : transaction.amount)))}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Footer Signature */}
                                    <div className="mt-12 text-center text-xs text-slate-500 print-text-black">
                                        <p>Processed by: {transaction.processed_by?.name || 'Cashier'}</p>
                                        <p className="mt-1">Thank you for your purchase!</p>
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
