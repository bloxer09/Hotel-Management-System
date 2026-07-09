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

                    {/* Handover Operations Snapshot */}
                    <div className="mb-8">
                        <h2 className="text-sm font-outfit font-bold text-slate-200 uppercase tracking-wider mb-4 print:text-black">Handover Operations Snapshot</h2>
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <div className="bg-[#0f172a]/20 border border-[#334155] p-4 rounded-xl flex flex-col gap-1 print:bg-slate-50 print:border-slate-200">
                                <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Rooms Checked In</span>
                                <span className="text-xl font-outfit font-black text-slate-200 mt-1 print:text-black">{report.checkins} rooms</span>
                            </div>
                            <div className="bg-[#0f172a]/20 border border-[#334155] p-4 rounded-xl flex flex-col gap-1 print:bg-slate-50 print:border-slate-200">
                                <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Rooms Checked Out</span>
                                <span className="text-xl font-outfit font-black text-slate-200 mt-1 print:text-black">{report.checkouts} rooms</span>
                            </div>
                            <div className="bg-[#0f172a]/20 border border-[#334155] p-4 rounded-xl flex flex-col gap-1 print:bg-slate-50 print:border-slate-200">
                                <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Active Stays (In-House)</span>
                                <span className="text-xl font-outfit font-black text-emerald-400 mt-1 print:text-black">{report.active_rooms_count} stays</span>
                            </div>
                            <div className="bg-[#0f172a]/20 border border-[#334155] p-4 rounded-xl flex flex-col gap-1 print:bg-slate-50 print:border-slate-200">
                                <span className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Pending Cleaning (Dirty)</span>
                                <span className="text-xl font-outfit font-black text-amber-400 mt-1 print:text-black">{report.cleaning_rooms_count} rooms</span>
                            </div>
                        </div>
                    </div>

                    {/* Financial Reconciliation Audit */}
                    <div className="mb-8">
                        <h2 className="text-sm font-outfit font-bold text-slate-200 uppercase tracking-wider mb-6 print:text-black">Financial Reconciliation Audit</h2>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            
                            {/* Rooms Drawer Audit */}
                            <div className="flex flex-col gap-4 bg-[#0f172a]/20 p-5 rounded-2xl border border-[#334155] print:bg-white print:border-slate-350 print:p-4">
                                <h3 className="text-xs font-bold text-slate-300 uppercase tracking-wider border-b border-[#334155] pb-2 print:text-black print:border-slate-300 flex items-center gap-2">
                                    <Hotel size={16} className="text-brand-400 print:text-black" /> Rooms Cash Drawer Audit
                                </h3>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs flex flex-col gap-1.5 print:bg-slate-50 print:border-slate-200">
                                        <span className="text-slate-400 font-medium print:text-slate-600">Starting Capital</span>
                                        <span className="text-lg font-mono font-bold text-slate-200 print:text-black">₱{shift.opening_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs flex flex-col gap-1.5 print:bg-slate-50 print:border-slate-200">
                                        <span className="text-slate-400 font-medium print:text-slate-600">Expected Drawer</span>
                                        <span className="text-lg font-mono font-bold text-brand-300 print:text-black">₱{report.expectedDrawerCash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                        <span className="text-[9px] text-slate-500 font-medium print:text-slate-600 leading-tight block mt-0.5">
                                            Rooms Cash: ₱{report.sales.rooms_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            {report.incomes_sum > 0 && ` | Incomes: +₱${report.incomes_sum.toLocaleString(undefined, { minimumFractionDigits: 2 })}`}
                                            {report.expenses_sum > 0 && ` | Expenses: -₱${report.expenses_sum.toLocaleString(undefined, { minimumFractionDigits: 2 })}`}
                                        </span>
                                    </div>
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs flex flex-col gap-1.5 print:bg-slate-50 print:border-slate-200">
                                        <span className="text-slate-400 font-medium print:text-slate-600">Actual Closing</span>
                                        <span className="text-lg font-mono font-bold text-slate-200 print:text-black">
                                            {shift.ended_at ? `₱${shift.closing_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}` : 'Open'}
                                        </span>
                                        {shift.ended_at && (
                                            <span className={`text-[9px] font-bold uppercase ${report.cashVariance === 0 ? 'text-emerald-400' : report.cashVariance > 0 ? 'text-blue-400' : 'text-rose-400'}`}>
                                                Variance: {report.cashVariance >= 0 ? '+' : ''}{report.cashVariance.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Minibar Drawer Audit */}
                            <div className="flex flex-col gap-4 bg-[#0f172a]/20 p-5 rounded-2xl border border-[#334155] print:bg-white print:border-slate-350 print:p-4">
                                <h3 className="text-xs font-bold text-slate-300 uppercase tracking-wider border-b border-[#334155] pb-2 print:text-black print:border-slate-300 flex items-center gap-2">
                                    <PackageOpen size={16} className="text-brand-400 print:text-black" /> Minibar Cash Drawer Audit
                                </h3>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs flex flex-col gap-1.5 print:bg-slate-50 print:border-slate-200">
                                        <span className="text-slate-400 font-medium print:text-slate-600">Starting Capital</span>
                                        <span className="text-lg font-mono font-bold text-slate-200 print:text-black">₱{shift.opening_cash_minibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs flex flex-col gap-1.5 print:bg-slate-50 print:border-slate-200">
                                        <span className="text-slate-400 font-medium print:text-slate-600">Expected Drawer</span>
                                        <span className="text-lg font-mono font-bold text-brand-300 print:text-black">₱{report.expectedDrawerCashMinibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                        <span className="text-[9px] text-slate-500">Collected: ₱{report.sales.minibar_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/30 border border-[#334155]/60 text-xs flex flex-col gap-1.5 print:bg-slate-50 print:border-slate-200">
                                        <span className="text-slate-400 font-medium print:text-slate-600">Actual Closing</span>
                                        <span className="text-lg font-mono font-bold text-slate-200 print:text-black">
                                            {shift.ended_at ? `₱${shift.closing_cash_minibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}` : 'Open'}
                                        </span>
                                        {shift.ended_at && (
                                            <span className={`text-[9px] font-bold uppercase ${report.cashVarianceMinibar === 0 ? 'text-emerald-400' : report.cashVarianceMinibar > 0 ? 'text-blue-400' : 'text-rose-400'}`}>
                                                Variance: {report.cashVarianceMinibar >= 0 ? '+' : ''}{report.cashVarianceMinibar.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    {/* Sales Collections Breakdown */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                        
                        {/* Summary left */}
                        <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                            <h3 className="text-xs font-semibold text-slate-300 uppercase tracking-wider mb-4 print:text-black">Collection Channels Summary</h3>
                            
                            <div className="space-y-3 text-xs text-slate-400 leading-normal">
                                <div className="flex justify-between font-medium">
                                    <span>Total Collected Sales</span>
                                    <span className="font-mono text-slate-200 font-bold print:text-black">₱{report.sales.total_collected.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="flex justify-between font-medium border-t border-[#334155]/30 pt-2 print:border-slate-300">
                                    <span className="flex items-center gap-1.5">Cash Remittances</span>
                                    <span className="font-mono text-slate-200 font-bold print:text-black">₱{report.sales.cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="pl-4 space-y-1 text-[10px] text-slate-500">
                                    <div className="flex justify-between">
                                        <span>• Rooms Cash Drawer</span>
                                        <span className="font-mono font-bold">₱{report.sales.rooms_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span>• Minibar Cash Drawer</span>
                                        <span className="font-mono font-bold">₱{report.sales.minibar_cash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                </div>
                                <div className="flex justify-between font-medium border-t border-[#334155]/30 pt-2 print:border-slate-300">
                                    <span className="flex items-center gap-1.5">GCash Electronic Receipts</span>
                                    <span className="font-mono text-slate-200 font-bold print:text-black">₱{report.sales.gcash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="pl-4 space-y-1 text-[10px] text-slate-500">
                                    <div className="flex justify-between">
                                        <span>• Rooms GCash</span>
                                        <span className="font-mono font-bold">₱{report.sales.rooms_gcash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span>• Minibar GCash</span>
                                        <span className="font-mono font-bold">₱{report.sales.minibar_gcash.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                    </div>
                                </div>
                                
                                <div className="h-px bg-[#334155] my-2 print:bg-slate-300" />

                                <div className="flex justify-between font-medium">
                                    <span>Stay Check-In Fees</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.checkin_sales.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="flex justify-between font-medium">
                                    <span>Extension Overtime Fees</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.extension_sales.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="flex justify-between font-medium">
                                    <span>Checkout Surcharge & Stock Fees</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.checkout_sales.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </div>
                                <div className="flex justify-between font-medium border-t border-[#334155]/30 pt-2 print:border-slate-300">
                                    <span>Minibar Direct Walk-in Sales</span>
                                    <span className="font-mono text-slate-300">₱{report.sales.possale_sales.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
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

                    {/* Expenses and Additional Incomes Breakdown */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                        
                        {/* Additional Incomes List */}
                        <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                            <h3 className="text-xs font-semibold text-slate-350 border-b border-[#334155]/30 pb-2 mb-4 print:text-black flex justify-between items-center">
                                <span>Additional Incomes Added</span>
                                <span className="text-emerald-400 font-bold font-mono">Total: +₱{report.incomes_sum.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                            </h3>
                            
                            <div className="overflow-y-auto max-h-48 space-y-3">
                                {report.incomes && report.incomes.length > 0 ? (
                                    report.incomes.map((item, idx) => (
                                        <div key={idx} className="flex justify-between items-center text-xs border-b border-[#334155]/15 pb-2">
                                            <div className="flex flex-col gap-0.5">
                                                <span className="font-outfit font-bold text-slate-200 print:text-black">
                                                    {item.notes || 'Miscellaneous Income'}
                                                </span>
                                                <span className="text-[10px] text-slate-500 font-semibold font-mono">
                                                    {new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                </span>
                                            </div>
                                            <span className="font-mono font-bold text-emerald-400 print:text-black">
                                                +₱{Number(item.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </span>
                                        </div>
                                    ))
                                ) : (
                                    <div className="py-6 text-center text-xs text-slate-500">
                                        No additional incomes recorded.
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Expenses List */}
                        <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                            <h3 className="text-xs font-semibold text-slate-350 border-b border-[#334155]/30 pb-2 mb-4 print:text-black flex justify-between items-center">
                                <span>Expenses Disbursed</span>
                                <span className="text-rose-400 font-bold font-mono">Total: -₱{report.expenses_sum.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                            </h3>
                            
                            <div className="overflow-y-auto max-h-48 space-y-3">
                                {report.expenses && report.expenses.length > 0 ? (
                                    report.expenses.map((item, idx) => (
                                        <div key={idx} className="flex justify-between items-center text-xs border-b border-[#334155]/15 pb-2">
                                            <div className="flex flex-col gap-0.5">
                                                <span className="font-outfit font-bold text-slate-200 print:text-black">
                                                    {item.notes || 'Miscellaneous Expense'}
                                                </span>
                                                <span className="text-[10px] text-slate-500 font-semibold font-mono">
                                                    {new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                </span>
                                            </div>
                                            <span className="font-mono font-bold text-rose-400 print:text-black">
                                                -₱{Number(item.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </span>
                                        </div>
                                    ))
                                ) : (
                                    <div className="py-6 text-center text-xs text-slate-500">
                                        No expenses recorded.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Shift Adjustments, Discounts & Waivers */}
                    <div className="mb-8">
                        <h2 className="text-sm font-outfit font-bold text-slate-200 uppercase tracking-wider mb-4 print:text-black">Shift Adjustments & Waivers Log</h2>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            
                            {/* Promo discounts */}
                            <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                                <h3 className="text-xs font-semibold text-slate-300 border-b border-[#334155]/30 pb-2 mb-4 print:text-black flex justify-between items-center">
                                    <span>Discounts & Promo Codes Applied</span>
                                    <span className="text-brand-300 font-bold font-mono">Total: ₱{report.discounts_sum.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </h3>
                                <div className="space-y-3 max-h-48 overflow-y-auto">
                                    {report.discounts && report.discounts.length > 0 ? (
                                        report.discounts.map((item, idx) => (
                                            <div key={idx} className="flex justify-between items-center text-xs border-b border-[#334155]/15 pb-2">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="font-outfit font-bold text-slate-200 print:text-black">{item.guest_name}</span>
                                                    <span className="text-[10px] text-slate-500 font-semibold font-mono">Ref: {item.booking_ref} | {item.discount_type.replace('_', ' ')}</span>
                                                </div>
                                                <span className="font-mono font-bold text-slate-300 print:text-black">-₱{Number(item.discount_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                            </div>
                                        ))
                                    ) : (
                                        <div className="py-6 text-center text-xs text-slate-500">
                                            No discount adjustments recorded.
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Waived late checkout fees */}
                            <div className="p-5 rounded-2xl bg-[#0f172a]/20 border border-[#334155]/60 print:bg-slate-50 print:border-slate-200">
                                <h3 className="text-xs font-semibold text-slate-355 border-b border-[#334155]/30 pb-2 mb-4 print:text-black flex justify-between items-center">
                                    <span>Waived Late Check-Out Fees</span>
                                    <span className="text-amber-400 font-bold font-mono">Waived: ₱{report.waived_late_fees_sum.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                                </h3>
                                <div className="space-y-3 max-h-48 overflow-y-auto">
                                    {report.waived_late_fees && report.waived_late_fees.length > 0 ? (
                                        report.waived_late_fees.map((item, idx) => (
                                            <div key={idx} className="flex justify-between items-center text-xs border-b border-[#334155]/15 pb-2">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="font-outfit font-bold text-slate-200 print:text-black">{item.guest_name}</span>
                                                    <span className="text-[10px] text-slate-500 font-semibold font-mono">Ref: {item.booking_ref} | Overstay: {item.late_hours}h</span>
                                                </div>
                                                <span className="font-mono font-bold text-amber-300 print:text-black">₱{Number(item.waived_fee).toLocaleString(undefined, { minimumFractionDigits: 2 })} [Waived]</span>
                                            </div>
                                        ))
                                    ) : (
                                        <div className="py-6 text-center text-xs text-slate-500">
                                            No late check-out fee waivers recorded.
                                        </div>
                                    )}
                                </div>
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
