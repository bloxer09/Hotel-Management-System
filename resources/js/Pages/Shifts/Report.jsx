import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { 
    Printer, 
    ChevronLeft, 
    Clock, 
    Coins, 
    ArrowLeftRight, 
    ClipboardCheck, 
    PackageOpen,
    AlertCircle,
    UserCheck,
    CalendarDays,
    Hotel
} from 'lucide-react';
import { motion } from 'framer-motion';

export default function Report({ shift, report }) {
    const { app_name } = usePage().props;
    
    const triggerPrint = () => {
        window.print();
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Shift Closing Report - #${shift.id}`} />

            <div className="flex flex-col gap-8 print:p-0 print:gap-4 print:text-black">
                
                {/* Header Action Nav (Hidden in Print) */}
                <div className="flex justify-between items-center print:hidden">
                    <Link
                        href={route('shifts.index')}
                        className="text-xs font-bold text-slate-400 hover:text-slate-100 flex items-center gap-1"
                    >
                        <ChevronLeft size={16} /> Back to Shifts
                    </Link>
                    
                    <button
                        onClick={triggerPrint}
                        className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 text-xs font-extrabold font-outfit tracking-wide flex items-center gap-2 shadow-lg shadow-brand-600/10 active:scale-95 transition-all"
                    >
                        <Printer size={16} />
                        Print Closing Remittance
                    </button>
                </div>

                {/* Printable Certificate wrapper */}
                <div className="p-6 md:p-8 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl print:bg-white print:border-none print:shadow-none print:p-4">
                    
                    {/* Invoice header */}
                    <div className="border-b border-[#334155] pb-6 mb-6 print:border-slate-300">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div className="flex items-center gap-3">
                                <span className="p-2.5 bg-[#0f172a] text-brand-400 rounded-xl shrink-0 print:bg-slate-100 print:text-slate-700 flex items-center justify-center">
                                    <Hotel size={20} />
                                </span>
                                <div>
                                    <h1 className="text-xl font-outfit font-extrabold tracking-wide uppercase text-slate-100 print:text-black">
                                        {app_name || 'Uptown Pension House'} SHIFT REPORT
                                    </h1>
                                    <p className="text-xs text-slate-400 font-medium mt-0.5 print:text-slate-600">Register Handover Statement & Cash Drawer Audit</p>
                                </div>
                            </div>
                            <div className="text-right shrink-0">
                                <span className="text-[10px] bg-[#334155] text-slate-300 px-2 py-1 rounded font-mono uppercase font-bold print:bg-slate-100 print:text-slate-800">
                                    SESSION #{shift.id}
                                </span>
                                <p className="text-xs text-slate-400 mt-1.5 font-semibold print:text-slate-600">{new Date(report.end).toLocaleDateString()}</p>
                            </div>
                        </div>
                    </div>

                    {/* Metadata Grid */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 bg-[#0f172a]/45 border border-[#334155] p-5 rounded-2xl mb-8 print:bg-slate-50 print:border-slate-200 print:text-slate-800">
                        <div className="flex flex-col gap-0.5 text-xs">
                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1">
                                <UserCheck size={12} /> Cashier Staff
                            </span>
                            <span className="font-outfit font-bold mt-1 text-slate-200 print:text-black">{shift.user?.name}</span>
                        </div>
                        <div className="flex flex-col gap-0.5 text-xs">
                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1">
                                <CalendarDays size={12} /> Shift Code
                            </span>
                            <span className="font-outfit font-bold mt-1 text-slate-200 capitalize print:text-black">{shift.shift_code} Register</span>
                        </div>
                        <div className="flex flex-col gap-0.5 text-xs">
                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1">
                                <Clock size={12} /> Log-In Hour
                            </span>
                            <span className="font-mono mt-1 text-slate-300 print:text-black">{new Date(report.start).toLocaleTimeString()}</span>
                        </div>
                        <div className="flex flex-col gap-0.5 text-xs">
                            <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider flex items-center gap-1">
                                <Clock size={12} /> Log-Out Hour
                            </span>
                            <span className="font-mono mt-1 text-slate-300 print:text-black">
                                {shift.ended_at ? new Date(report.end).toLocaleTimeString() : 'Active timer'}
                            </span>
                        </div>
                    </div>

                    {/* Financial Reconciliation Audit */}
                    <div className="mb-8">
                        <h2 className="text-sm font-outfit font-bold text-slate-200 uppercase tracking-wider mb-4 print:text-black">Financial Reconciliation Audit</h2>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                            
                            <div className="p-4 rounded-xl bg-[#0f172a]/30 border border-[#334155] text-xs flex flex-col gap-2 print:bg-slate-50 print:border-slate-200">
                                <span className="text-slate-400 font-medium">Register Starting Capital</span>
                                <span className="text-2xl font-mono font-bold text-slate-200 print:text-black">₱{shift.opening_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                <span className="text-[10px] text-slate-500">Base drawer cash reserves at logon</span>
                            </div>

                            <div className="p-4 rounded-xl bg-[#0f172a]/30 border border-[#334155] text-xs flex flex-col gap-2 print:bg-slate-50 print:border-slate-200">
                                <span className="text-slate-400 font-medium">Expected Drawer Cash</span>
                                <span className="text-2xl font-mono font-bold text-brand-300 print:text-black">₱{report.expectedDrawerCash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                <span className="text-[10px] text-slate-500">Starting Capital + Cash collections (₱{report.sales.cash.toLocaleString()})</span>
                            </div>

                            <div className="p-4 rounded-xl bg-[#0f172a]/30 border border-[#334155] text-xs flex flex-col gap-2 print:bg-slate-50 print:border-slate-200">
                                <span className="text-slate-400 font-medium">Actual Closing Count</span>
                                <span className="text-2xl font-mono font-bold text-slate-200 print:text-black">
                                    {shift.ended_at ? `₱${shift.closing_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}` : 'Register open'}
                                </span>
                                {shift.ended_at && (
                                    <div className="flex items-center gap-1 mt-0.5">
                                        <span className={`text-[10px] uppercase font-bold ${report.cashVariance === 0 ? 'text-emerald-400' : report.cashVariance > 0 ? 'text-blue-400' : 'text-rose-400'}`}>
                                            Variance: ₱{report.cashVariance.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </span>
                                    </div>
                                )}
                            </div>

                        </div>
                    </div>

                    {/* Sales Collections Breakdown */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                        
                        {/* Summary left */}
                        <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                            <h3 className="text-xs font-semibold text-slate-300 uppercase tracking-wider mb-4 print:text-black">Collection Channels Summary</h3>
                            
                            <div className="space-y-3.5 text-xs text-slate-400 leading-normal">
                                <div className="flex justify-between font-medium">
                                    <span>Total Collected Sales</span>
                                    <span className="font-mono text-slate-200 font-bold print:text-black">₱{report.sales.total_collected.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="flex justify-between font-medium">
                                    <span className="flex items-center gap-1.5">Cash Remittances</span>
                                    <span className="font-mono text-slate-200 font-bold print:text-black">₱{report.sales.cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="flex justify-between font-medium">
                                    <span className="flex items-center gap-1.5">GCash Electronic Receipts</span>
                                    <span className="font-mono text-slate-200 font-bold print:text-black">₱{report.sales.gcash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                
                                <div className="h-px bg-[#334155] my-2 print:bg-slate-300" />

                                <div className="flex justify-between font-medium">
                                    <span>Stay Check-In Fees</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.checkin_sales.toLocaleString()}</span>
                                </div>
                                <div className="flex justify-between font-medium">
                                    <span>Extension Overtime Fees</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.extension_sales.toLocaleString()}</span>
                                </div>
                                <div className="flex justify-between font-medium">
                                    <span>Checkout Surcharge & Stock Fees</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.checkout_sales.toLocaleString()}</span>
                                </div>
                            </div>
                        </div>

                        {/* Room inventory items closed */}
                        <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                            <h3 className="text-xs font-semibold text-slate-300 uppercase tracking-wider mb-4 print:text-black">Minibar & Room Supplies Sold</h3>
                            
                            <div className="overflow-y-auto max-h-48 space-y-3">
                                {report.inventory_items.length > 0 ? (
                                    report.inventory_items.map((item, idx) => (
                                        <div key={idx} className="flex justify-between items-center text-xs">
                                            <div className="flex flex-col gap-0.5">
                                                <span className="font-outfit font-bold text-slate-200 print:text-black">{item.item_name}</span>
                                                <span className="text-[10px] text-slate-500 font-semibold font-mono">Qty: {Number(item.qty).toLocaleString()} sold</span>
                                            </div>
                                            <span className="font-mono font-bold text-brand-300 print:text-black">₱{Number(item.total).toLocaleString()}</span>
                                        </div>
                                    ))
                                ) : (
                                    <div className="py-6 text-center text-xs text-slate-500">
                                        No minibar / pantry item sales recorded.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Remittance logs table */}
                    <div className="mb-8">
                        <h2 className="text-sm font-outfit font-bold text-slate-200 uppercase tracking-wider mb-4 print:text-black">Audited Shift Transactions List</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse text-xs print:text-slate-800 table-fixed">
                                <thead>
                                    <tr className="border-b border-[#334155] pb-2 text-[10px] font-semibold text-slate-400 uppercase print:border-slate-300 print:text-slate-600">
                                        <th className="pb-2">Time</th>
                                        <th className="pb-2">Type</th>
                                        <th className="pb-2">Booking Ref</th>
                                        <th className="pb-2">Details</th>
                                        <th className="pb-2">Method</th>
                                        <th className="pb-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#334155]/60 print:divide-slate-200 text-slate-300 print:text-black">
                                    {report.transactions.length > 0 ? (
                                        report.transactions.map((t) => (
                                            <tr key={t.id} className="hover:bg-[#0f172a]/20 transition-colors">
                                                <td className="py-2.5 font-mono text-[10px]">
                                                    {new Date(t.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                </td>
                                                <td className="py-2.5 capitalize font-bold font-outfit">
                                                    {t.transaction_type.replace('_', ' ')}
                                                </td>
                                                <td className="py-2.5 font-mono text-[10px] text-slate-400 print:text-slate-700">
                                                    {t.booking?.booking_ref || '-'}
                                                </td>
                                                <td className="py-2.5 max-w-xs truncate text-[11px] text-slate-400 print:text-slate-600">
                                                    {t.description}
                                                </td>
                                                <td className="py-2.5 font-mono text-[10px] uppercase font-bold">
                                                    {t.payment_method}
                                                </td>
                                                <td className="py-2.5 text-right font-mono font-bold text-brand-300 print:text-black">
                                                    ₱{t.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="py-4 text-center text-slate-500">
                                                No transactions processed during this shift.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Closing signature layout */}
                    <div className="border-t border-[#334155]/80 pt-8 mt-8 flex flex-col sm:flex-row justify-between items-end gap-6 text-xs text-slate-400 print:text-slate-700 print:border-slate-300 print:mt-16">
                        <div className="flex flex-col gap-1">
                            <span className="font-bold text-slate-300 print:text-black">Notes / Handover remarks:</span>
                            <p className="italic leading-normal text-slate-400 max-w-md print:text-slate-600">
                                {shift.notes || 'No handover remarks registered.'}
                            </p>
                        </div>
                        
                        <div className="flex flex-col gap-4 text-center items-center shrink-0">
                            <div className="w-48 border-b border-slate-500 pb-1 font-mono font-bold text-slate-200 print:text-black print:border-black">
                                {shift.user?.name}
                            </div>
                            <span className="text-[10px] uppercase font-semibold text-slate-500">Cashier / Staff Signature</span>
                        </div>
                    </div>

                </div>

            </div>
        </AuthenticatedLayout>
    );
}
