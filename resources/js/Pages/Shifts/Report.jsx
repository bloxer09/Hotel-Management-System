import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import {
    Printer,
    ChevronLeft,
    Info,
    BookOpen,
    Coffee,
    Package,
    MinusCircle,
    PlusCircle,
    Wrench,
    AlertTriangle,
    CheckCircle,
    ChevronDown,
    Banknote,
    Trash2
} from 'lucide-react';
import CustomSelect from '@/Components/CustomSelect';

export default function Report({ shift, report }) {
    const { app_name } = usePage().props;
    const [activeTab, setActiveTab] = useState('overview');
    const [printMode, setPrintMode] = useState('all'); // 'all' (entire report) or 'active' (active tab only)
    const [printOrientation, setPrintOrientation] = useState('landscape');
    const cashMovementForm = useForm({
        movement_type: 'cashier_transfer',
        amount: '',
        description: '',
        moved_at: '',
    });

    const formatCurrency = (val) => {
        const num = Number(val);
        return isNaN(num)
            ? '₱0.00'
            : '₱' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: '2-digit' });
    };

    const formatTime = (dateStr) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };

    // --- Tab definitions with count badges ---
    const tabItems = [
        { id: 'overview', label: 'Overview & Cash', icon: Info, count: null },
        { id: 'bookings', label: 'Bookings Ledger', icon: BookOpen, count: report.bookings?.length || 0 },
        { id: 'daily-cash', label: 'Daily Cash Report', icon: Banknote, count: null },
        { id: 'minibar', label: 'Minibar & POS', icon: Coffee, count: (report.transactions?.filter(t => t.transaction_type === 'pos_sale').length || 0) + (report.inventory_usage_details?.filter(u => u.booking_id !== null).length || 0) },
        { id: 'inventory', label: 'Inventory Status', icon: Package, count: report.inventory_items?.length || 0 },
        { id: 'expenses', label: 'Expenses', icon: MinusCircle, count: report.expenses?.length || 0 },
        { id: 'income', label: 'Additional Income', icon: PlusCircle, count: report.incomes?.length || 0 },
        { id: 'maintenance', label: 'Maintenance', icon: Wrench, count: report.maintenance_tickets?.length || 0 },
    ];

    // --- Print handlers ---
    const handlePrintAll = () => {
        setPrintMode('all');
        setPrintOrientation('landscape');
        setTimeout(() => {
            window.print();
        }, 150);
    };

    const handlePrintActive = () => {
        setPrintMode('active');
        setPrintOrientation(activeTab === 'daily-cash' ? 'portrait' : 'landscape');
        setTimeout(() => {
            window.print();
        }, 150);
    };

    // Stay collections are verified receipts received during this shift.
    // Booking totals, cumulative paid amounts, and balances remain separate.
    const stayCollections = report.stay_collections || {};
    const cashBookingsTotal = Number(stayCollections.cash || 0);
    const gcashBookingsTotal = Number(stayCollections.gcash || 0);
    const otherBookingsTotal = ['bank_transfer', 'card', 'maya', 'other_ewallet', 'other']
        .reduce((sum, method) => sum + Number(stayCollections[method] || 0), 0);
    const staysTotalCollection = Number(stayCollections.total_received || 0);
    const staysRefunds = Number(stayCollections.refunds || 0);
    const staysNetCollection = Number(stayCollections.net_collections || 0);
    const dailyCash = report.daily_cash_report || {};
    const dailyVariance = dailyCash.variance === null || dailyCash.variance === undefined
        ? null
        : Number(dailyCash.variance);
    const dailyCashStatus = dailyVariance === null
        ? 'PENDING TALLY'
        : dailyVariance === 0 ? 'BALANCED' : dailyVariance > 0 ? 'SHORT' : 'OVER';
    const dailyCashStatusClass = dailyCashStatus === 'BALANCED'
        ? 'text-emerald-600 bg-emerald-50 border-emerald-300'
        : dailyCashStatus === 'SHORT'
            ? 'text-rose-600 bg-rose-50 border-rose-300'
            : dailyCashStatus === 'OVER'
                ? 'text-sky-700 bg-sky-50 border-sky-300'
                : 'text-slate-600 bg-slate-50 border-slate-300';
    const dailyCashDetails = [
        ...(dailyCash.expense_details || []).map(expense => ({
            id: `expense-${expense.id}`,
            time: expense.created_at || expense.expense_date,
            particulars: expense.notes || 'Expense / withdrawal',
            amount: Number(expense.amount || 0),
            kind: 'Expense',
        })),
        ...(dailyCash.cash_movements || []).map(movement => ({
            id: `movement-${movement.id}`,
            time: movement.moved_at,
            particulars: movement.description,
            amount: Number(movement.amount || 0),
            kind: movement.movement_type === 'cashier_transfer' ? 'Transfer to Cashier' : 'Withdrawal',
            movement,
        })),
    ].sort((a, b) => new Date(a.time) - new Date(b.time));
    const cashDenominations = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.25, 0.05, 0.01];
    const closingDenominations = shift.closing_denominations || {};

    const submitCashMovement = (event) => {
        event.preventDefault();
        cashMovementForm.post(route('shifts.cash_movements.store', shift.id), {
            preserveScroll: true,
            onSuccess: () => cashMovementForm.reset('amount', 'description', 'moved_at'),
        });
    };

    const deleteCashMovement = (movement) => {
        if (!window.confirm(`Remove this ${movement.movement_type === 'cashier_transfer' ? 'cashier transfer' : 'withdrawal'} record?`)) return;
        router.delete(route('shifts.cash_movements.destroy', [shift.id, movement.id]), { preserveScroll: true });
    };

    const formatPaymentMethods = (booking) => {
        const labels = {
            cash: 'Cash',
            gcash: 'GCash',
            bank_transfer: 'Bank',
            card: 'Card',
            maya: 'Maya',
            other_ewallet: 'E-wallet',
            other: 'Other',
        };
        const references = booking.shift_collection_references || {};
        const methods = Object.entries(booking.shift_collection_methods || {})
            .filter(([, amount]) => Number(amount) > 0)
            .map(([method]) => {
                const methodLabel = labels[method] || method;
                const methodReferences = Array.isArray(references[method])
                    ? references[method].filter(Boolean)
                    : [];

                return methodReferences.length > 0
                    ? `${methodLabel}\nRef: ${methodReferences.join(', ')}`
                    : methodLabel;
            });

        if (methods.length > 0) {
            return methods.join('\n+\n');
        }

        const fallbackMethod = labels[booking.payment_method] || booking.payment_method || '-';
        return booking.payment_method === 'gcash' && booking.gcash_ref
            ? `${fallbackMethod}\nRef: ${booking.gcash_ref}`
            : fallbackMethod;
    };

    const getBookingRoomRate = (booking) => {
        const baseAmount = Number(booking.base_amount || 0);
        const units = booking.booking_type === 'overnight'
            ? Math.max(1, Number(booking.num_nights || 1))
            : 1;
        const roomRate = booking.booking_type === 'overnight'
            ? baseAmount / units
            : baseAmount;
        return roomRate;
    };

    const formatPaymentStatus = (booking) => {
        if (booking.report_payment_status === 'pending_verification') {
            return `Pending ${formatCurrency(booking.pending_payment_amount)}`;
        }

        return {
            paid: 'Fully Paid',
            partial: 'Deposit / Partial',
            unpaid: 'Unpaid',
        }[booking.report_payment_status] || booking.report_payment_status || 'Unpaid';
    };

    // Helper component for printing headers on every page
    const PrintHeader = ({ title, pageNum }) => (
        <div className="hidden print:flex justify-between items-start border-b border-black pb-3 mb-4 w-full">
            <div className="flex items-center gap-3">
                <img
                    src="/images/logo.jpg"
                    alt="Hotel Logo"
                    className="w-10 h-10 object-contain filter grayscale"
                    onError={(e) => e.target.style.display = 'none'}
                />
                <div>
                    <h1 className="text-sm font-bold uppercase tracking-tight font-mono">
                        {app_name || 'UPTOWN PENSION HOUSE'}
                    </h1>
                    <p className="text-[10px] font-bold text-slate-700 uppercase font-mono">{title}</p>
                </div>
            </div>
            <div className="text-right text-[9px] font-mono leading-tight">
                <div><strong>Date:</strong> {new Date(report.end).toLocaleDateString()}</div>
                <div><strong>Shift:</strong> <span className="uppercase font-bold">{shift.shift_code}</span></div>
                <div><strong>Cashier:</strong> {shift.user?.name}</div>
                <div><strong>Prepared By:</strong> {shift.user?.name}</div>
                {printMode === 'all' && <div><strong>Sheet:</strong> {pageNum}</div>}
            </div>
        </div>
    );

    // Helper component for printing footers on every page
    const PrintFooter = ({ title }) => (
        <div className="hidden print:flex justify-between items-center border-t border-slate-400 mt-6 pt-2 w-full text-[8px] font-mono text-slate-500">
            <div>Uptown Pension House PMS &bull; {title}</div>
            <div>Printed: {new Date().toLocaleString()}</div>
        </div>
    );

    return (
        <AuthenticatedLayout>
            <style dangerouslySetInnerHTML={{
                __html: `
                @media print {
                    @page {
                        size: ${printOrientation === 'portrait' ? 'A4 portrait' : 'A4 landscape'};
                        margin: 10mm 10mm 10mm 10mm;
                    }
                    /* Reset application containers for multi-page print layout */
                    html, body, #app, [data-page], .h-screen, .overflow-hidden, .overflow-y-auto {
                        height: auto !important;
                        min-height: 0 !important;
                        overflow: visible !important;
                    }
                    /* Ensure tables can break pages naturally without block overflow clipping */
                    div.overflow-x-auto, div.overflow-y-auto {
                        overflow: visible !important;
                        display: block !important;
                    }
                    html, body {
                        background: #ffffff !important;
                        color: #1e293b !important;
                        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
                        font-size: 9px !important;
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                    .print-hidden {
                        display: none !important;
                    }
                    .print-page-break {
                        display: block !important;
                        page-break-after: always !important;
                        break-after: page !important;
                        clear: both;
                    }
                    .print-page-break:last-child {
                        page-break-after: avoid !important;
                        break-after: avoid !important;
                    }
                    table {
                        width: 100% !important;
                        border-collapse: collapse !important;
                        page-break-inside: auto;
                    }
                    tr {
                        page-break-inside: avoid !important;
                        page-break-after: auto;
                    }
                    thead {
                        display: table-header-group !important;
                    }
                    tfoot {
                        display: table-footer-group !important;
                    }
                    /* Soften high-contrast borders for a cleaner, professional look */
                    .border-black, .border-t, .border-b, .border-l, .border-r {
                        border-color: #cbd5e1 !important;
                    }
                    .border-b-black {
                        border-bottom-color: #cbd5e1 !important;
                    }
                    .border-t-black {
                        border-top-color: #cbd5e1 !important;
                    }
                }
                
                /* Digital Log Book Styles */
                .logbook-table th, .logbook-table td {
                    border: 1px solid #cbd5e1;
                    padding: 5px 8px;
                    font-size: 10px;
                }
                .logbook-table th {
                    background-color: #f1f5f9 !important;
                    color: #0f172a !important;
                    font-weight: bold;
                    text-align: center;
                }
                .print:logbook-table th {
                    background-color: #e2e8f0 !important;
                }
                
                /* Zebra and highlighted row styles */
                .highlight-row {
                    background-color: rgba(16, 185, 129, 0.04) !important;
                }
                @media print {
                    .highlight-row {
                        background-color: rgba(16, 185, 129, 0.06) !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                    }
                    .ledger-handwritten-circle {
                        border: 1px solid #94a3b8 !important;
                        background-color: #f8fafc !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                    }
                }
                
                /* Clean summary badge style for ledger totals */
                .ledger-handwritten-circle {
                    border: 1px solid #cbd5e1;
                    border-radius: 6px;
                    padding: 5px 10px;
                    display: inline-block;
                    font-weight: bold;
                    background-color: #f8fafc;
                    color: #0f172a;
                }
                
                .handwrite-line {
                    border-bottom: 1px solid #cbd5e1;
                    height: 18px;
                    margin-top: 3px;
                }

                .daily-cash-table th, .daily-cash-table td {
                    border: 1px solid #94a3b8;
                    padding: 5px 7px;
                    font-size: 10px;
                }
                .daily-cash-table th {
                    background: #e2e8f0 !important;
                    color: #0f172a !important;
                    font-weight: 700;
                }
                @media print {
                    .daily-cash-report-page {
                        width: 100%;
                        font-family: Arial, Helvetica, sans-serif !important;
                        font-size: 9px !important;
                    }
                    .daily-cash-table th, .daily-cash-table td {
                        padding: 4px 6px !important;
                        font-size: 8px !important;
                    }
                    .daily-cash-report-page .daily-cash-details td {
                        padding-top: 3px !important;
                        padding-bottom: 3px !important;
                    }
                }
            `}} />

            <Head title={`Shift Turnover Report - #${shift.id}`} />

            {/* ========================================================================= */}
            {/* SCREEN VIEW (Visible only on monitor)                                    */}
            {/* ========================================================================= */}
            <div className="print-hidden flex flex-col gap-6 p-4 max-w-[1350px] mx-auto bg-slate-900 text-slate-100 min-h-screen">

                {/* Print Controls Header */}
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-slate-800 p-4 rounded-xl border border-slate-700 shadow-lg">
                    <div>
                        <Link
                            href={route('shifts.index')}
                            className="text-xs font-bold text-slate-400 hover:text-slate-100 flex items-center gap-1 mb-1 transition-all"
                        >
                            <ChevronLeft size={14} /> Back to Shifts
                        </Link>
                        <h1 className="text-lg font-bold flex items-center gap-2">
                            <span>Shift Report #{shift.id}</span>
                            <span className="text-xs font-mono px-2 py-0.5 bg-slate-700 text-emerald-400 rounded-full uppercase">
                                {shift.shift_code} Shift
                            </span>
                        </h1>
                        <p className="text-xs text-slate-400">
                            Cashier: <strong className="text-slate-200">{shift.user?.name}</strong> &bull; Period: {new Date(shift.started_at).toLocaleString()} - {shift.ended_at ? new Date(shift.ended_at).toLocaleString() : 'Active'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            onClick={handlePrintActive}
                            className="px-3.5 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-50 text-xs font-bold flex items-center gap-1.5 transition-all shadow"
                        >
                            <Printer size={15} />
                            Export PDF / Print Tab
                        </button>
                        <button
                            onClick={handlePrintAll}
                            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-slate-50 text-xs font-bold flex items-center gap-1.5 transition-all shadow"
                        >
                            <Printer size={15} />
                            Export PDF / Print Complete Report
                        </button>
                    </div>
                </div>

                {/* Report Section CustomSelect Dropdown */}
                <CustomSelect
                    value={activeTab}
                    onChange={setActiveTab}
                    containerClassName="sm:w-64 mb-4 print:hidden"
                    options={tabItems.map(opt => ({
                        key: opt.id,
                        label: `${opt.label} ${opt.count !== null ? `(${opt.count})` : ''}`
                    }))}
                />

                {/* Active Tab Container */}
                <div className="bg-slate-800/55 rounded-2xl border border-slate-750 p-6 backdrop-blur shadow-md">

                    {/* Tab 1: OVERVIEW & CASH */}
                    {activeTab === 'overview' && (
                        <div className="flex flex-col gap-6">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div className="bg-slate-800 p-4 rounded-xl border border-slate-700/60">
                                    <span className="text-[10px] font-mono uppercase text-slate-400">Total Cash in Drawers</span>
                                    <div className="text-xl font-bold text-slate-50 mt-1">
                                        {formatCurrency((shift.closing_cash || 0) + (shift.closing_cash_minibar || 0))}
                                    </div>
                                    <div className="text-[10px] text-slate-400 mt-1">
                                        Rooms: {formatCurrency(shift.closing_cash || 0)} | Minibar: {formatCurrency(shift.closing_cash_minibar || 0)}
                                    </div>
                                </div>
                                <div className="bg-slate-800 p-4 rounded-xl border border-slate-700/60">
                                    <span className="text-[10px] font-mono uppercase text-slate-400">Room Stay Revenue</span>
                                    <div className="text-xl font-bold text-emerald-400 mt-1">
                                        {formatCurrency(report.room_revenue)}
                                    </div>
                                    <div className="text-[10px] text-slate-400 mt-1">
                                        Walk-ins: {report.walk_ins} &bull; Reserv: {report.reservations}
                                    </div>
                                </div>
                                <div className="bg-slate-800 p-4 rounded-xl border border-slate-700/60">
                                    <span className="text-[10px] font-mono uppercase text-slate-400">Minibar Sales Revenue</span>
                                    <div className="text-xl font-bold text-indigo-400 mt-1">
                                        {formatCurrency(report.minibar_revenue)}
                                    </div>
                                    <div className="text-[10px] text-slate-400 mt-1">
                                        POS Walk-ins: {report.pos_revenue}
                                    </div>
                                </div>
                                <div className="bg-slate-800 p-4 rounded-xl border border-slate-700/60">
                                    <span className="text-[10px] font-mono uppercase text-slate-400">Cash Variance</span>
                                    <div className={`text-xl font-bold mt-1 ${(report.cashVariance + report.cashVarianceMinibar) !== 0 ? 'text-red-400' : 'text-emerald-400'}`}>
                                        {shift.ended_at ? formatCurrency(report.cashVariance + report.cashVarianceMinibar) : 'N/A'}
                                    </div>
                                    <div className="text-[10px] text-slate-400 mt-1">
                                        Rooms: {report.cashVariance || 0} | Minibar: {report.cashVarianceMinibar || 0}
                                    </div>
                                </div>
                            </div>

                            {/* Operations Count Table */}
                            <div>
                                <h3 className="text-sm font-bold text-slate-200 uppercase mb-3 font-mono">1. Operations Summary</h3>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left border-collapse text-xs text-slate-200">
                                        <thead>
                                            <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                                <th className="p-2">Indicator</th>
                                                <th className="p-2 text-center">Value</th>
                                                <th className="p-2">Indicator</th>
                                                <th className="p-2 text-center">Value</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-700/50">
                                            <tr>
                                                <td className="p-2 font-semibold">Opening Drawer Cash</td>
                                                <td className="p-2 text-center font-mono font-bold">{formatCurrency(shift.opening_cash + shift.opening_cash_minibar)}</td>
                                                <td className="p-2 font-semibold">Rooms Checked In</td>
                                                <td className="p-2 text-center font-mono">{report.rooms_checked_in}</td>
                                            </tr>
                                            <tr>
                                                <td className="p-2 font-semibold">Closing Drawer Cash</td>
                                                <td className="p-2 text-center font-mono font-bold">{shift.ended_at ? formatCurrency(shift.closing_cash + shift.closing_cash_minibar) : 'OPEN'}</td>
                                                <td className="p-2 font-semibold">Rooms Checked Out</td>
                                                <td className="p-2 text-center font-mono">{report.rooms_checked_out}</td>
                                            </tr>
                                            <tr>
                                                <td className="p-2 font-semibold">Expected Drawer Cash</td>
                                                <td className="p-2 text-center font-mono font-bold">{formatCurrency(report.grand_cash_collection)}</td>
                                                <td className="p-2 font-semibold">Rooms Occupied Now</td>
                                                <td className="p-2 text-center font-mono">{report.rooms_occupied}</td>
                                            </tr>
                                            <tr>
                                                <td className="p-2 font-semibold">Active Guest Count</td>
                                                <td className="p-2 text-center font-mono font-bold">{report.total_guests} pax</td>
                                                <td className="p-2 font-semibold">Vacant Rooms Now</td>
                                                <td className="p-2 text-center font-mono">{report.vacant_rooms}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Detailed Cash Flow Reconciliation */}
                            <div>
                                <h3 className="text-sm font-bold text-slate-200 uppercase mb-3 font-mono">2. Financial Reconciliation Ledger</h3>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-center border-collapse text-xs text-slate-200">
                                        <thead>
                                            <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                                <th className="p-2 text-left">Drawer</th>
                                                <th className="p-2">Opening</th>
                                                <th className="p-2">Cash Collections</th>
                                                <th className="p-2">GCash Payments</th>
                                                <th className="p-2">Add. Income</th>
                                                <th className="p-2">Expenses Out</th>
                                                <th className="p-2">Expected Cash</th>
                                                <th className="p-2">Actual Cash</th>
                                                <th className="p-2 text-right">Variance</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-700/50 font-mono">
                                            <tr>
                                                <td className="p-2 text-left font-bold text-slate-300 font-sans">Rooms Drawer</td>
                                                <td className="p-2">{formatCurrency(shift.opening_cash)}</td>
                                                <td className="p-2 text-emerald-400">{formatCurrency(report.sales.rooms_cash)}</td>
                                                <td className="p-2">{formatCurrency(report.sales.rooms_gcash)}</td>
                                                <td className="p-2 text-emerald-400">{formatCurrency(report.incomes.filter(i => i.cash_drawer === 'room').reduce((sum, i) => sum + Number(i.amount), 0))}</td>
                                                <td className="p-2 text-red-400">-{formatCurrency(report.expenses.filter(e => e.cash_drawer === 'room').reduce((sum, e) => sum + Number(e.amount), 0))}</td>
                                                <td className="p-2 font-bold">{formatCurrency(report.expectedDrawerCash)}</td>
                                                <td className="p-2 font-bold">{shift.ended_at ? formatCurrency(shift.closing_cash) : 'OPEN'}</td>
                                                <td className={`p-2 text-right font-bold ${report.cashVariance !== 0 ? 'text-red-400' : 'text-emerald-400'}`}>
                                                    {shift.ended_at ? formatCurrency(report.cashVariance) : 'N/A'}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td className="p-2 text-left font-bold text-slate-300 font-sans">Minibar Drawer</td>
                                                <td className="p-2">{formatCurrency(shift.opening_cash_minibar)}</td>
                                                <td className="p-2 text-emerald-400">{formatCurrency(report.sales.minibar_cash)}</td>
                                                <td className="p-2">{formatCurrency(report.sales.minibar_gcash)}</td>
                                                <td className="p-2 text-emerald-400">{formatCurrency(report.incomes.filter(i => i.cash_drawer === 'minibar').reduce((sum, i) => sum + Number(i.amount), 0))}</td>
                                                <td className="p-2 text-red-400">-{formatCurrency(report.expenses.filter(e => e.cash_drawer === 'minibar').reduce((sum, e) => sum + Number(e.amount), 0))}</td>
                                                <td className="p-2 font-bold">{formatCurrency(report.expectedDrawerCashMinibar)}</td>
                                                <td className="p-2 font-bold">{shift.ended_at ? formatCurrency(shift.closing_cash_minibar) : 'OPEN'}</td>
                                                <td className={`p-2 text-right font-bold ${report.cashVarianceMinibar !== 0 ? 'text-red-400' : 'text-emerald-400'}`}>
                                                    {shift.ended_at ? formatCurrency(report.cashVarianceMinibar) : 'N/A'}
                                                </td>
                                            </tr>
                                            <tr className="bg-slate-750 font-bold font-sans">
                                                <td className="p-2 text-left font-bold">Grand Total</td>
                                                <td className="p-2 font-mono">{formatCurrency(shift.opening_cash + shift.opening_cash_minibar)}</td>
                                                <td className="p-2 font-mono text-emerald-400">{formatCurrency(report.sales.rooms_cash + report.sales.minibar_cash)}</td>
                                                <td className="p-2 font-mono">{formatCurrency(report.sales.rooms_gcash + report.sales.minibar_gcash)}</td>
                                                <td className="p-2 font-mono text-emerald-400">{formatCurrency(report.incomes_sum)}</td>
                                                <td className="p-2 font-mono text-red-400">-{formatCurrency(report.expenses_sum)}</td>
                                                <td className="p-2 font-mono">{formatCurrency(report.grand_cash_collection)}</td>
                                                <td className="p-2 font-mono">{shift.ended_at ? formatCurrency(shift.closing_cash + shift.closing_cash_minibar) : 'OPEN'}</td>
                                                <td className={`p-2 text-right font-mono ${report.cashVariance + report.cashVarianceMinibar !== 0 ? 'text-red-400' : 'text-emerald-400'}`}>
                                                    {shift.ended_at ? formatCurrency(report.cashVariance + report.cashVarianceMinibar) : 'N/A'}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div className="mt-3 bg-slate-800 p-3 rounded-lg border border-slate-700/50 text-xs">
                                    <strong>Endorsements & Handover Notes:</strong>
                                    <p className="text-slate-350 italic mt-1 leading-relaxed">
                                        {shift.notes || 'No handover remarks registered for this shift.'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tab 2: BOOKINGS LEDGER */}
                    {activeTab === 'bookings' && (
                        <div className="flex flex-col gap-4">
                            <h3 className="text-sm font-bold text-slate-200 uppercase font-mono flex items-center justify-between">
                                <span>Room Bookings Ledger (Log Book Format)</span>
                                <span className="text-xs text-slate-400 font-normal">Matches handwritten ledger design</span>
                            </h3>

                            <div className="overflow-x-auto rounded-lg border border-slate-700">
                                <table className="w-full text-left border-collapse logbook-table text-slate-100">
                                    <thead>
                                        <tr>
                                            <th>ROOM NO.</th>
                                            <th>DATE IN</th>
                                            <th>TIME IN</th>
                                            <th>DATE OUT</th>
                                            <th>TIME OUT</th>
                                            <th>HRS</th>
                                            <th>ROOM RATE</th>
                                            <th>PAID THIS SHIFT</th>
                                            <th>BALANCE DUE</th>
                                            <th>THIS SHIFT MOP</th>
                                            <th>GUEST NAME</th>
                                            <th>CONTACT NUMBER</th>
                                            <th>STATUS</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                         {report.bookings && report.bookings.length > 0 ? (
                                             report.bookings.map((booking) => {
                                                  const roomRate = getBookingRoomRate(booking);
                                                 return (
                                                    <tr key={booking.id} className="highlight-row text-xs font-mono">
                                                        <td className="text-center font-bold text-indigo-400">{booking.room?.room_number || '-'}</td>
                                                        <td className="text-center">{formatDate(booking.check_in)}</td>
                                                        <td className="text-center">{formatTime(booking.check_in)}</td>
                                                        <td className="text-center">{formatDate(booking.check_out || booking.expected_check_out)}</td>
                                                        <td className="text-center">{formatTime(booking.check_out || booking.expected_check_out)}</td>
                                                        <td className="text-center font-sans">
                                                            {booking.booking_type === 'overnight' ? `${booking.num_nights} nights` : `${booking.short_time_hours} hrs`}
                                                        </td>
                                                         <td className="text-right">
                                                              <div className="font-bold">{formatCurrency(roomRate)}</div>
                                                             <div className="text-[9px] text-slate-400">
                                                                 {booking.booking_type === 'overnight' ? 'per night' : `${booking.short_time_hours || 0}h rate`}
                                                             </div>
                                                         </td>
                                                         <td className="text-right font-bold text-emerald-400">{formatCurrency(booking.shift_collection_amount)}</td>
                                                        <td className={`text-right font-bold ${Number(booking.balance_amount) > 0 ? 'text-amber-300' : 'text-slate-400'}`}>{formatCurrency(booking.balance_amount)}</td>
                                                         <td className="text-center font-sans uppercase font-bold text-[10px] text-slate-300 whitespace-pre-line break-words">{formatPaymentMethods(booking)}</td>
                                                        <td className="font-bold text-slate-200">{booking.guest_name}</td>
                                                        <td>{booking.guest_contact || '-'}</td>
                                                        <td className={`text-[10px] font-sans font-bold text-center uppercase ${booking.report_payment_status === 'paid' ? 'text-emerald-400' : 'text-amber-300'}`}>
                                                            {formatPaymentStatus(booking)}
                                                        </td>
                                                    </tr>
                                                );
                                            })
                                        ) : (
                                            <tr>
                                                <td colSpan="13" className="text-center py-6 text-slate-500 font-sans">
                                                    No stay bookings checked in or checked out during this shift.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {/* Circle summary indicators at the bottom */}
                            <div className="flex flex-wrap gap-6 justify-end items-center mt-4">
                                <div className="ledger-handwritten-circle text-emerald-300 text-xs">
                                    Room Sales Cash: {formatCurrency(cashBookingsTotal)}
                                </div>
                                <div className="ledger-handwritten-circle text-indigo-300 text-xs">
                                    Room Sales GCash: {formatCurrency(gcashBookingsTotal)}
                                </div>
                                {otherBookingsTotal > 0 && (
                                    <div className="ledger-handwritten-circle text-slate-300 text-xs">
                                        Room Sales Other: {formatCurrency(otherBookingsTotal)}
                                    </div>
                                )}
                                <div className="ledger-handwritten-circle text-slate-100 text-sm border-emerald-400">
                                    Verified Room Sales: {formatCurrency(staysTotalCollection)}
                                </div>
                                {staysRefunds > 0 && (
                                    <>
                                        <div className="ledger-handwritten-circle text-rose-300 text-xs">
                                            Refunds: {formatCurrency(staysRefunds)}
                                        </div>
                                        <div className="ledger-handwritten-circle text-slate-100 text-sm">
                                            Net Collections: {formatCurrency(staysNetCollection)}
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Daily Cash Report */}
                    {activeTab === 'daily-cash' && (
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-bold text-slate-100 uppercase font-mono">Pension House Daily Cash Report</h3>
                                    <p className="mt-1 text-xs text-slate-400">Room drawer only. Room sales use verified cash payments; minibar/POS and e-wallet payments are excluded.</p>
                                </div>
                                <div className={`border rounded-lg px-3 py-2 text-xs font-bold ${dailyCashStatusClass}`}>
                                    {dailyCashStatus}{dailyVariance !== null ? ` • ${formatCurrency(Math.abs(dailyVariance))}` : ''}
                                </div>
                            </div>

                            <div className="overflow-x-auto rounded-lg border border-slate-700">
                                <table className="w-full text-sm daily-cash-table text-slate-100">
                                    <tbody>
                                        <tr><th className="text-left w-2/3">Cash on Hand (Opening Balance)</th><td className="text-right font-mono font-bold">{formatCurrency(shift.opening_cash)}</td></tr>
                                        <tr><th className="text-left">Add: Total Cash Check-In / Room Sales</th><td className="text-right font-mono font-bold text-emerald-400">{formatCurrency(dailyCash.room_sales_cash)}</td></tr>
                                        <tr><th className="text-left">Total Cash Available</th><td className="text-right font-mono font-bold">{formatCurrency(Number(shift.opening_cash || 0) + Number(dailyCash.room_sales_cash || 0))}</td></tr>
                                        <tr><th className="text-left">Less: Expenses / Withdrawals</th><td className="text-right font-mono font-bold text-rose-400">-{formatCurrency(Number(dailyCash.room_expenses || 0) + Number(dailyCash.withdrawals || 0))}</td></tr>
                                        <tr><th className="text-left">Less: Transfer to Cashier</th><td className="text-right font-mono font-bold text-rose-400">-{formatCurrency(dailyCash.cashier_transfers)}</td></tr>
                                        <tr><th className="text-left">Expected Cash in Drawer</th><td className="text-right font-mono font-bold text-amber-300">{formatCurrency(dailyCash.expected_cash)}</td></tr>
                                        <tr><th className="text-left">Actual Cash Tally</th><td className="text-right font-mono font-bold">{dailyCash.actual_cash === null ? 'Shift still open' : formatCurrency(dailyCash.actual_cash)}</td></tr>
                                    </tbody>
                                </table>
                            </div>

                            {report.can_manage_daily_cash && (
                                <form onSubmit={submitCashMovement} className="rounded-lg border border-slate-700 bg-slate-800 p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                                    <select value={cashMovementForm.data.movement_type} onChange={e => cashMovementForm.setData('movement_type', e.target.value)} className="rounded bg-slate-900 border border-slate-600 px-3 py-2 text-sm">
                                        <option value="cashier_transfer">Transfer to Cashier</option>
                                        <option value="withdrawal">Cash Withdrawal</option>
                                    </select>
                                    <input type="number" min="0.01" step="0.01" required value={cashMovementForm.data.amount} onChange={e => cashMovementForm.setData('amount', e.target.value)} placeholder="Amount" className="rounded bg-slate-900 border border-slate-600 px-3 py-2 text-sm" />
                                    <input required value={cashMovementForm.data.description} onChange={e => cashMovementForm.setData('description', e.target.value)} placeholder="Particulars / description" className="rounded bg-slate-900 border border-slate-600 px-3 py-2 text-sm" />
                                    <button disabled={cashMovementForm.processing} className="rounded bg-emerald-600 hover:bg-emerald-500 px-3 py-2 text-sm font-bold disabled:opacity-60">Record cash movement</button>
                                    {cashMovementForm.errors.amount && <p className="md:col-span-4 text-xs text-rose-400">{cashMovementForm.errors.amount}</p>}
                                    {cashMovementForm.errors.description && <p className="md:col-span-4 text-xs text-rose-400">{cashMovementForm.errors.description}</p>}
                                </form>
                            )}

                            <div className="overflow-x-auto rounded-lg border border-slate-700">
                                <table className="w-full text-left text-xs text-slate-100">
                                    <thead className="bg-slate-750 text-slate-300"><tr><th className="p-3">Time</th><th className="p-3">Particulars / Description</th><th className="p-3 text-right">Amount</th>{report.can_manage_daily_cash && <th className="p-3" />}</tr></thead>
                                    <tbody>{dailyCashDetails.length ? dailyCashDetails.map(detail => <tr key={detail.id} className="border-t border-slate-700"><td className="p-3">{formatTime(detail.time)}</td><td className="p-3"><span className="font-bold">{detail.kind}:</span> {detail.particulars}</td><td className="p-3 text-right font-mono text-rose-300">-{formatCurrency(detail.amount)}</td>{report.can_manage_daily_cash && <td className="p-2 text-right">{detail.movement && <button type="button" onClick={() => deleteCashMovement(detail.movement)} className="p-1 text-rose-400 hover:text-rose-300" title="Remove cash movement"><Trash2 size={14} /></button>}</td>}</tr>) : <tr><td colSpan={report.can_manage_daily_cash ? 4 : 3} className="p-5 text-center text-slate-500">No room drawer expenses, withdrawals, or cashier transfers recorded.</td></tr>}</tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Tab 3: MINIBAR & POS */}
                    {activeTab === 'minibar' && (
                        <div className="flex flex-col gap-6">
                            <div>
                                <h3 className="text-sm font-bold text-slate-200 uppercase mb-3 font-mono">1. Pantry / Minibar Walk-in POS Sales</h3>
                                <div className="overflow-x-auto rounded-lg border border-slate-700">
                                    <table className="w-full text-left border-collapse text-xs text-slate-200">
                                        <thead>
                                            <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                                <th className="p-2.5">Time</th>
                                                <th className="p-2.5">OR / Ref No</th>
                                                <th className="p-2.5">Method</th>
                                                <th className="p-2.5">Items Sold</th>
                                                <th className="p-2.5 text-right">Cash Amount</th>
                                                <th className="p-2.5 text-right">GCash Amount</th>
                                                <th className="p-2.5 text-right font-bold text-indigo-400">Total Billed</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-700/50">
                                            {report.transactions?.filter(t => t.transaction_type === 'pos_sale').length > 0 ? (
                                                report.transactions.filter(t => t.transaction_type === 'pos_sale').map((item) => (
                                                    <tr key={item.id} className="hover:bg-slate-800/40 font-mono">
                                                        <td className="p-2.5">{formatTime(item.created_at)}</td>
                                                        <td className="p-2.5 font-bold text-slate-300">{item.formatted_or_number || `POS-${item.id}`}</td>
                                                        <td className="p-2.5 uppercase font-sans text-[10px] font-bold">{item.payment_method || 'Cash'}</td>
                                                        <td className="p-2.5 font-sans">
                                                            {item.inventory_usages && item.inventory_usages.length > 0
                                                                ? item.inventory_usages.map(u => `${u.quantity}x ${u.item?.item_name || 'Item'}`).join(', ')
                                                                : item.description || 'Walk-in Sale'}
                                                        </td>
                                                        <td className="p-2.5 text-right">{formatCurrency(item.cash_amount || 0)}</td>
                                                        <td className="p-2.5 text-right">{formatCurrency(item.gcash_amount || 0)}</td>
                                                        <td className="p-2.5 text-right font-bold text-emerald-400">{formatCurrency(item.amount)}</td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="7" className="text-center py-4 text-slate-500 font-sans">
                                                        No walk-in minibar POS sales recorded.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div>
                                <h3 className="text-sm font-bold text-slate-200 uppercase mb-3 font-mono">2. Minibar Stays Checkout Charges</h3>
                                <div className="overflow-x-auto rounded-lg border border-slate-700">
                                    <table className="w-full text-left border-collapse text-xs text-slate-200">
                                        <thead>
                                            <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                                <th className="p-2.5">Time</th>
                                                <th className="p-2.5">Room</th>
                                                <th className="p-2.5">Guest</th>
                                                <th className="p-2.5">Items Consumed</th>
                                                <th className="p-2.5 text-right">Unit Cost</th>
                                                <th className="p-2.5 text-center">Qty</th>
                                                <th className="p-2.5 text-right font-bold text-indigo-400">Total Billed</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-700/50">
                                            {report.inventory_usage_details?.filter(u => u.booking_id !== null).length > 0 ? (
                                                report.inventory_usage_details.filter(u => u.booking_id !== null).map((usage) => (
                                                    <tr key={usage.id} className="hover:bg-slate-800/40 font-mono">
                                                        <td className="p-2.5">{formatTime(usage.created_at)}</td>
                                                        <td className="p-2.5 font-sans font-bold text-indigo-300">Room {usage.booking?.room?.room_number || '-'}</td>
                                                        <td className="p-2.5 font-sans text-slate-300">{usage.booking?.guest_name || 'Stay Guest'}</td>
                                                        <td className="p-2.5 font-sans">{usage.item?.item_name || 'Item'}</td>
                                                        <td className="p-2.5 text-right">{formatCurrency(usage.unit_price)}</td>
                                                        <td className="p-2.5 text-center font-bold">{usage.quantity}</td>
                                                        <td className="p-2.5 text-right font-bold text-emerald-400">{formatCurrency(usage.total_price)}</td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="7" className="text-center py-4 text-slate-500 font-sans">
                                                        No minibar items billed to staying rooms.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tab 4: INVENTORY STATUS */}
                    {activeTab === 'inventory' && (
                        <div className="flex flex-col gap-6">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h3 className="text-sm font-bold text-slate-200 uppercase mb-3 font-mono flex items-center justify-between">
                                        <span>Items Used/Sold During Shift</span>
                                        <span className="px-2 py-0.5 bg-slate-750 text-indigo-300 rounded font-mono text-[10px]">
                                            Value: {formatCurrency(report.inventory_summary?.total_value || 0)}
                                        </span>
                                    </h3>
                                    <div className="overflow-x-auto rounded-lg border border-slate-700">
                                        <table className="w-full text-left border-collapse text-xs text-slate-200">
                                            <thead>
                                                <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                                    <th className="p-2.5">Item Name</th>
                                                    <th className="p-2.5 text-center">Total Qty</th>
                                                    <th className="p-2.5 text-right">Billed Value</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-700/50">
                                                {report.inventory_items && report.inventory_items.length > 0 ? (
                                                    report.inventory_items.map((inv) => (
                                                        <tr key={inv.item_id} className="hover:bg-slate-800/40 font-mono">
                                                            <td className="p-2.5 font-sans font-medium">{inv.item_name}</td>
                                                            <td className="p-2.5 text-center font-bold">{inv.qty}</td>
                                                            <td className="p-2.5 text-right font-bold text-emerald-400">{formatCurrency(inv.total)}</td>
                                                        </tr>
                                                    ))
                                                ) : (
                                                    <tr>
                                                        <td colSpan="3" className="text-center py-4 text-slate-500 font-sans">
                                                            No stock items checked out or sold.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-sm font-bold text-slate-200 uppercase mb-3 font-mono flex items-center gap-2">
                                        <span>Critical Low Stock Level Warnings</span>
                                        {report.low_stock?.length > 0 && (
                                            <AlertTriangle size={15} className="text-red-400 animate-pulse" />
                                        )}
                                    </h3>
                                    <div className="overflow-x-auto rounded-lg border border-slate-700">
                                        <table className="w-full text-left border-collapse text-xs text-slate-200">
                                            <thead>
                                                <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                                    <th className="p-2.5">Item Name</th>
                                                    <th className="p-2.5 text-center">Minimum</th>
                                                    <th className="p-2.5 text-center">Current Stock</th>
                                                    <th className="p-2.5 text-center">Alert Status</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-700/50">
                                                {report.low_stock && report.low_stock.length > 0 ? (
                                                    report.low_stock.map((stock) => (
                                                        <tr key={stock.id} className="hover:bg-slate-800/40 font-mono">
                                                            <td className="p-2.5 font-sans font-medium text-red-300">{stock.item_name}</td>
                                                            <td className="p-2.5 text-center">{stock.minimum_stock}</td>
                                                            <td className="p-2.5 text-center font-bold text-red-400">{stock.current_stock}</td>
                                                            <td className="p-2.5 text-center">
                                                                <span className="px-2 py-0.5 bg-red-950 text-red-400 rounded text-[9px] uppercase font-sans font-bold">
                                                                    LOW STOCK
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    ))
                                                ) : (
                                                    <tr>
                                                        <td colSpan="4" className="text-center py-6 text-slate-500 font-sans">
                                                            All minibar and pantry stock levels are stable.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tab 5: EXPENSES */}
                    {activeTab === 'expenses' && (
                        <div className="flex flex-col gap-4">
                            <h3 className="text-sm font-bold text-slate-200 uppercase font-mono flex justify-between">
                                <span>Shift Outflow Expenses</span>
                                <span className="text-red-400 font-bold font-mono">Total: -{formatCurrency(report.expenses_sum)}</span>
                            </h3>

                            <div className="overflow-x-auto rounded-lg border border-slate-700">
                                <table className="w-full text-left border-collapse text-xs text-slate-200">
                                    <thead>
                                        <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                            <th className="p-2.5">Time</th>
                                            <th className="p-2.5">Reference</th>
                                            <th className="p-2.5">Drawer Source</th>
                                            <th className="p-2.5">Expense Details</th>
                                            <th className="p-2.5">Recorded By</th>
                                            <th className="p-2.5 text-right font-bold text-red-400">Outflow</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-700/50">
                                        {report.expenses && report.expenses.length > 0 ? (
                                            report.expenses.map((exp) => (
                                                <tr key={exp.id} className="hover:bg-slate-800/40 font-mono">
                                                    <td className="p-2.5">{formatTime(exp.created_at || exp.expense_date)}</td>
                                                    <td className="p-2.5 text-slate-350">EXP-{exp.id}</td>
                                                    <td className="p-2.5 font-bold uppercase text-[10px]">{exp.cash_drawer} Drawer</td>
                                                    <td className="p-2.5 font-sans">{exp.notes || 'Miscellaneous disbursement'}</td>
                                                    <td className="p-2.5 font-sans">{exp.user?.name || '-'}</td>
                                                    <td className="p-2.5 text-right font-bold text-red-400">-{formatCurrency(exp.amount)}</td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="6" className="text-center py-6 text-slate-500 font-sans">
                                                    No expenses recorded during this shift.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Tab 6: ADDITIONAL INCOME */}
                    {activeTab === 'income' && (
                        <div className="flex flex-col gap-4">
                            <h3 className="text-sm font-bold text-slate-200 uppercase font-mono flex justify-between">
                                <span>Shift Additional Cash Deposits / Inflow</span>
                                <span className="text-emerald-400 font-bold font-mono">Total: +{formatCurrency(report.incomes_sum)}</span>
                            </h3>

                            <div className="overflow-x-auto rounded-lg border border-slate-700">
                                <table className="w-full text-left border-collapse text-xs text-slate-200">
                                    <thead>
                                        <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                            <th className="p-2.5">Time</th>
                                            <th className="p-2.5">Reference</th>
                                            <th className="p-2.5">Drawer Deposit</th>
                                            <th className="p-2.5">Income Description</th>
                                            <th className="p-2.5">Recorded By</th>
                                            <th className="p-2.5 text-right font-bold text-emerald-400">Inflow</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-700/50">
                                        {report.incomes && report.incomes.length > 0 ? (
                                            report.incomes.map((inc) => (
                                                <tr key={inc.id} className="hover:bg-slate-800/40 font-mono">
                                                    <td className="p-2.5">{formatTime(inc.created_at || inc.income_date)}</td>
                                                    <td className="p-2.5 text-slate-350">INC-{inc.id}</td>
                                                    <td className="p-2.5 font-bold uppercase text-[10px]">{inc.cash_drawer} Drawer</td>
                                                    <td className="p-2.5 font-sans">{inc.notes || 'Miscellaneous deposit'}</td>
                                                    <td className="p-2.5 font-sans">{inc.user?.name || '-'}</td>
                                                    <td className="p-2.5 text-right font-bold text-emerald-400">+{formatCurrency(inc.amount)}</td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="6" className="text-center py-6 text-slate-500 font-sans">
                                                    No additional incomes recorded during this shift.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Tab 7: MAINTENANCE TICKETS */}
                    {activeTab === 'maintenance' && (
                        <div className="flex flex-col gap-4">
                            <h3 className="text-sm font-bold text-slate-200 uppercase font-mono">
                                Maintenance Tickets Reported or Handled
                            </h3>

                            <div className="overflow-x-auto rounded-lg border border-slate-700">
                                <table className="w-full text-left border-collapse text-xs text-slate-200">
                                    <thead>
                                        <tr className="bg-slate-750 text-slate-400 border-b border-slate-700">
                                            <th className="p-2.5">Time Logged</th>
                                            <th className="p-2.5 text-center">Room No</th>
                                            <th className="p-2.5">Issue Title</th>
                                            <th className="p-2.5 text-center">Status</th>
                                            <th className="p-2.5">Reported By</th>
                                            <th className="p-2.5">Resolution Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-700/50">
                                        {report.maintenance_tickets && report.maintenance_tickets.length > 0 ? (
                                            report.maintenance_tickets.map((ticket) => (
                                                <tr key={ticket.id} className="hover:bg-slate-800/40 font-mono">
                                                    <td className="p-2.5">{new Date(ticket.created_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</td>
                                                    <td className="p-2.5 text-center font-bold text-indigo-300">Room {ticket.room?.room_number || '-'}</td>
                                                    <td className="p-2.5 font-sans font-medium text-slate-200">{ticket.title}</td>
                                                    <td className="p-2.5 text-center">
                                                        {ticket.status === 'resolved' ? (
                                                            <span className="px-2 py-0.5 bg-emerald-950 text-emerald-400 rounded text-[9px] uppercase font-sans font-bold flex items-center gap-1 justify-center max-w-[90px] mx-auto border border-emerald-800">
                                                                <CheckCircle size={10} /> RESOLVED
                                                            </span>
                                                        ) : (
                                                            <span className="px-2 py-0.5 bg-slate-700 text-slate-300 rounded text-[9px] uppercase font-sans font-bold flex items-center gap-1 justify-center max-w-[90px] mx-auto">
                                                                REPORTED
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-2.5 font-sans">{ticket.reported_by?.name || ticket.reported_by_name || '-'}</td>
                                                    <td className="p-2.5 font-sans text-slate-350 italic">
                                                        {ticket.notes || (ticket.status === 'resolved' ? 'Resolved successfully.' : 'Pending repair/evaluation')}
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="6" className="text-center py-6 text-slate-500 font-sans">
                                                    No maintenance tickets logged or resolved during this shift.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                </div>
            </div>

            {/* ========================================================================= */}
            {/* PRINT VIEW (Visible only in print mode)                                   */}
            {/* ========================================================================= */}
            <div className="hidden print:block w-full text-black font-mono">

                {/* 1. OVERVIEW & CASH RECONCILIATION */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'overview' ? 'hidden' : ''}`}>
                    <PrintHeader title="I. OVERVIEW & CASH RECONCILIATION" pageNum={1} />

                    <div className="mb-4">
                        <table className="w-full text-left border-collapse logbook-table">
                            <tbody>
                                <tr>
                                    <th className="w-[18%]">Opening Cash</th>
                                    <td className="w-[15%] text-right font-bold">{formatCurrency(shift.opening_cash + shift.opening_cash_minibar)}</td>
                                    <th className="w-[18%]">Rooms Occupied</th>
                                    <td className="w-[15%] text-center">{report.rooms_occupied}</td>
                                    <th className="w-[18%]">Active Stays</th>
                                    <td className="w-[16%] text-center">{report.active_stays}</td>
                                </tr>
                                <tr>
                                    <th>Closing Cash</th>
                                    <td className="text-right font-bold">{shift.ended_at ? formatCurrency(shift.closing_cash + shift.closing_cash_minibar) : 'OPEN'}</td>
                                    <th>Rooms Checked In</th>
                                    <td className="text-center">{report.rooms_checked_in}</td>
                                    <th>Vacant Rooms</th>
                                    <td className="text-center">{report.vacant_rooms}</td>
                                </tr>
                                <tr>
                                    <th>Expected Cash</th>
                                    <td className="text-right font-bold">{formatCurrency(report.grand_cash_collection)}</td>
                                    <th>Rooms Checked Out</th>
                                    <td className="text-center">{report.rooms_checked_out}</td>
                                    <th>Maintenance Rooms</th>
                                    <td className="text-center">{report.maintenance_rooms}</td>
                                </tr>
                                <tr>
                                    <th>Cash Difference</th>
                                    <td className={`text-right font-bold ${(report.cashVariance + report.cashVarianceMinibar) !== 0 ? 'text-red-700' : ''}`}>
                                        {shift.ended_at ? formatCurrency(report.cashVariance + report.cashVarianceMinibar) : 'N/A'}
                                    </td>
                                    <th>Reservations</th>
                                    <td className="text-center">{report.reservations}</td>
                                    <th>Mini Bar Sales</th>
                                    <td className="text-right">{formatCurrency(report.minibar_sales)}</td>
                                </tr>
                                <tr>
                                    <th>Grand Cash Collection</th>
                                    <td className="text-right font-bold">{formatCurrency(report.grand_cash_collection)}</td>
                                    <th>Walk-ins</th>
                                    <td className="text-center">{report.walk_ins}</td>
                                    <th>Expenses</th>
                                    <td className="text-right font-bold text-red-700">{formatCurrency(report.expenses_sum)}</td>
                                </tr>
                                <tr>
                                    <th>Total Guests</th>
                                    <td className="text-center font-bold">{report.total_guests} pax</td>
                                    <th>Additional Income</th>
                                    <td className="text-right">{formatCurrency(report.incomes_sum)}</td>
                                    <td colSpan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div className="mb-4">
                        <h2 className="text-[10px] font-bold uppercase mb-2">FINANCIAL RECONCILIATION BY DRAWER</h2>
                        <table className="w-full text-center border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th>Drawer</th>
                                    <th>Opening</th>
                                    <th>Cash Sales</th>
                                    <th>GCash Payments</th>
                                    <th>Add. Income</th>
                                    <th>Expenses Out</th>
                                    <th>Expected Cash</th>
                                    <th>Actual Cash</th>
                                    <th>Variance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="font-bold">
                                    <td className="text-left">Rooms Drawer</td>
                                    <td>{formatCurrency(shift.opening_cash)}</td>
                                    <td>{formatCurrency(report.sales.rooms_cash)}</td>
                                    <td>{formatCurrency(report.sales.rooms_gcash)}</td>
                                    <td>{formatCurrency(report.incomes.filter(i => i.cash_drawer === 'room').reduce((sum, i) => sum + Number(i.amount), 0))}</td>
                                    <td className="text-red-700">-{formatCurrency(report.expenses.filter(e => e.cash_drawer === 'room').reduce((sum, e) => sum + Number(e.amount), 0))}</td>
                                    <td>{formatCurrency(report.expectedDrawerCash)}</td>
                                    <td>{shift.ended_at ? formatCurrency(shift.closing_cash) : 'OPEN'}</td>
                                    <td className={report.cashVariance !== 0 ? 'text-red-700' : ''}>
                                        {shift.ended_at ? formatCurrency(report.cashVariance) : 'N/A'}
                                    </td>
                                </tr>
                                <tr className="font-bold">
                                    <td className="text-left">Minibar Drawer</td>
                                    <td>{formatCurrency(shift.opening_cash_minibar)}</td>
                                    <td>{formatCurrency(report.sales.minibar_cash)}</td>
                                    <td>{formatCurrency(report.sales.minibar_gcash)}</td>
                                    <td>{formatCurrency(report.incomes.filter(i => i.cash_drawer === 'minibar').reduce((sum, i) => sum + Number(i.amount), 0))}</td>
                                    <td className="text-red-700">-{formatCurrency(report.expenses.filter(e => e.cash_drawer === 'minibar').reduce((sum, e) => sum + Number(e.amount), 0))}</td>
                                    <td>{formatCurrency(report.expectedDrawerCashMinibar)}</td>
                                    <td>{shift.ended_at ? formatCurrency(shift.closing_cash_minibar) : 'OPEN'}</td>
                                    <td className={report.cashVarianceMinibar !== 0 ? 'text-red-700' : ''}>
                                        {shift.ended_at ? formatCurrency(report.cashVarianceMinibar) : 'N/A'}
                                    </td>
                                </tr>
                                <tr className="font-bold bg-gray-100">
                                    <td className="text-left">Grand Totals</td>
                                    <td>{formatCurrency(shift.opening_cash + shift.opening_cash_minibar)}</td>
                                    <td>{formatCurrency(report.sales.rooms_cash + report.sales.minibar_cash)}</td>
                                    <td>{formatCurrency(report.sales.rooms_gcash + report.sales.minibar_gcash)}</td>
                                    <td>{formatCurrency(report.incomes_sum)}</td>
                                    <td className="text-red-700">-{formatCurrency(report.expenses_sum)}</td>
                                    <td>{formatCurrency(report.grand_cash_collection)}</td>
                                    <td>{shift.ended_at ? formatCurrency(shift.closing_cash + shift.closing_cash_minibar) : 'OPEN'}</td>
                                    <td className={report.cashVariance + report.cashVarianceMinibar !== 0 ? 'text-red-700' : ''}>
                                        {shift.ended_at ? formatCurrency(report.cashVariance + report.cashVarianceMinibar) : 'N/A'}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div className="grid grid-cols-2 gap-4 mb-4 text-[8px]">
                        <div className="border border-black p-2 min-h-[60px]">
                            <strong>1. SHIFT NOTES / ENDORSEMENTS:</strong>
                            <p className="mt-1 leading-normal italic">{shift.notes || 'No notes entered.'}</p>
                            <div className="handwrite-line"></div>
                            <div className="handwrite-line"></div>
                        </div>
                        <div className="border border-black p-2 min-h-[60px]">
                            <strong>2. PENDING TASKS & FOLLOW-UPS:</strong>
                            <div className="handwrite-line"></div>
                            <div className="handwrite-line"></div>
                        </div>
                        <div className="border border-black p-2 min-h-[60px]">
                            <strong>3. MAINTENANCE NOTES DURING SHIFT:</strong>
                            <div className="handwrite-line"></div>
                            <div className="handwrite-line"></div>
                        </div>
                        <div className="border border-black p-2 min-h-[60px]">
                            <strong>4. GUEST CONCERNS & INCIDENTS:</strong>
                            <div className="handwrite-line"></div>
                            <div className="handwrite-line"></div>
                        </div>
                    </div>

                    <div className="border-t border-black pt-4 font-mono text-[9px] mt-6">
                        <div className="grid grid-cols-4 gap-4 text-center">
                            <div>
                                <div className="border-b border-black h-8 flex items-end justify-center font-bold">{shift.user?.name}</div>
                                <span className="text-[8px] uppercase font-bold mt-1 block">Prepared By (Cashier)</span>
                            </div>
                            <div>
                                <div className="border-b border-black h-8"></div>
                                <span className="text-[8px] uppercase font-bold mt-1 block">Received By (Next Cashier)</span>
                            </div>
                            <div>
                                <div className="border-b border-black h-8"></div>
                                <span className="text-[8px] uppercase font-bold mt-1 block">Supervisor Audit</span>
                            </div>
                            <div>
                                <div className="border-b border-black h-8"></div>
                                <span className="text-[8px] uppercase font-bold mt-1 block">Manager Approval</span>
                            </div>
                        </div>
                    </div>

                    <PrintFooter title="Overview & Financial Summary" />
                </div>

                {/* 2. ROOM BOOKINGS LEDGER */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'bookings' ? 'hidden' : ''}`}>
                    <PrintHeader title="II. ROOM BOOKINGS LEDGER (LOG BOOK)" pageNum={2} />

                    <div className="mb-4">
                        <p className="mb-2 text-[7px] italic">
                            Room sales include only stays checked in or checked out during this shift. Future reservation deposits are excluded.
                        </p>
                        <table className="w-full text-left border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th className="w-[5%]">ROOM NO.</th>
                                    <th className="w-[8%]">DATE IN</th>
                                    <th className="w-[7%]">TIME IN</th>
                                    <th className="w-[8%]">DATE OUT</th>
                                    <th className="w-[7%]">TIME OUT</th>
                                    <th className="w-[6%]">HRS</th>
                                    <th className="w-[9%]">ROOM RATE</th>
                                    <th className="w-[7%]">PAID THIS<br />SHIFT</th>
                                    <th className="w-[7%]">BALANCE<br />DUE</th>
                                    <th className="w-[6%]">THIS SHIFT<br />MOP</th>
                                    <th className="w-[12%]">GUEST NAME</th>
                                    <th className="w-[9%]">CONTACT</th>
                                    <th className="w-[6%]">STATUS</th>
                                </tr>
                            </thead>
                            <tbody>
                                 {report.bookings && report.bookings.length > 0 ? (
                                     report.bookings.map((booking) => {
                                         const roomRate = getBookingRoomRate(booking);
                                         return (
                                            <tr key={booking.id} className="highlight-row text-[9px] leading-tight">
                                                <td className="text-center font-bold">{booking.room?.room_number || '-'}</td>
                                                <td className="text-center">{formatDate(booking.check_in)}</td>
                                                <td className="text-center">{formatTime(booking.check_in)}</td>
                                                <td className="text-center">{formatDate(booking.check_out || booking.expected_check_out)}</td>
                                                <td className="text-center">{formatTime(booking.check_out || booking.expected_check_out)}</td>
                                                <td className="text-center">{booking.booking_type === 'overnight' ? `${booking.num_nights} NTS` : `${booking.short_time_hours} HRS`}</td>
                                                 <td className="text-right">
                                                     <div className="font-bold">{formatCurrency(roomRate)}</div>
                                                     <div className="text-[6px] font-normal">
                                                         {booking.booking_type === 'overnight' ? '/ night' : `${booking.short_time_hours || 0}h rate`}
                                                     </div>
                                                 </td>
                                                <td className="text-right font-bold">{formatCurrency(booking.shift_collection_amount)}</td>
                                                <td className="text-right font-bold">{formatCurrency(booking.balance_amount)}</td>
                                                 <td className="text-center uppercase font-bold text-[6px] leading-tight whitespace-pre-line break-words">{formatPaymentMethods(booking)}</td>
                                                <td className="font-bold truncate max-w-[120px]">{booking.guest_name}</td>
                                                <td className="text-center">{booking.guest_contact || '-'}</td>
                                                <td className="text-center uppercase font-bold text-[7px]">{formatPaymentStatus(booking)}</td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan="13" className="text-center py-6">
                                            No stay bookings checked in or checked out during this shift.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Circular subtotals mimicking handwritten marks */}
                    <div className="flex justify-end items-center gap-6 mt-6 pb-4">
                        <div className="ledger-handwritten-circle text-[9px]">
                            Room Sales Cash: {formatCurrency(cashBookingsTotal)}
                        </div>
                        <div className="ledger-handwritten-circle text-[9px]">
                            Room Sales GCash: {formatCurrency(gcashBookingsTotal)}
                        </div>
                        {otherBookingsTotal > 0 && (
                            <div className="ledger-handwritten-circle text-[9px]">
                                Room Sales Other: {formatCurrency(otherBookingsTotal)}
                            </div>
                        )}
                        <div className="ledger-handwritten-circle text-[10px] border-black">
                            Verified Room Sales: {formatCurrency(staysTotalCollection)}
                        </div>
                        {staysRefunds > 0 && (
                            <>
                                <div className="ledger-handwritten-circle text-[9px]">
                                    Refunds: {formatCurrency(staysRefunds)}
                                </div>
                                <div className="ledger-handwritten-circle text-[10px] border-black">
                                    Net Collections: {formatCurrency(staysNetCollection)}
                                </div>
                            </>
                        )}
                    </div>

                    <PrintFooter title="Stays Ledger (Log Book Format)" />
                </div>

                {/* DAILY CASH REPORT - printed separately in A4 portrait when selected */}
                <div className={`daily-cash-report-page print-page-break ${printMode === 'active' && activeTab !== 'daily-cash' ? 'hidden' : ''}`}>
                    <div className="border-b-2 border-black pb-2 mb-3">
                        <div className="text-center">
                            <h1 className="text-[15px] font-bold tracking-wide">PENSION HOUSE DAILY CASH REPORT</h1>
                            <p className="text-[9px] font-bold tracking-wide">FRONT DESK CASH MONITORING AND DAILY RECONCILIATION</p>
                        </div>
                        <div className="grid grid-cols-3 gap-2 mt-3 text-[8px]">
                            <div><strong>Date:</strong> {new Date(report.end).toLocaleDateString()}</div>
                            <div className="text-center"><strong>Shift:</strong> {(shift.shift_code || '-').toUpperCase()}</div>
                            <div className="text-right"><strong>Prepared by:</strong> {shift.user?.name || '-'}</div>
                        </div>
                    </div>

                    <h2 className="text-[9px] font-bold mb-1">1. CASH RECONCILIATION</h2>
                    <table className="daily-cash-table mb-3">
                        <tbody>
                            <tr><th className="w-[72%] text-left">Cash on Hand (Opening Balance)</th><td className="text-right font-bold">{formatCurrency(shift.opening_cash)}</td></tr>
                            <tr><th className="text-left">Add: Total Cash Check-In / Room Sales</th><td className="text-right font-bold">{formatCurrency(dailyCash.room_sales_cash)}</td></tr>
                            <tr><th className="text-left">Total Cash Available</th><td className="text-right font-bold">{formatCurrency(Number(shift.opening_cash || 0) + Number(dailyCash.room_sales_cash || 0))}</td></tr>
                            <tr><th className="text-left">Less: Expenses / Withdrawals</th><td className="text-right font-bold">-{formatCurrency(Number(dailyCash.room_expenses || 0) + Number(dailyCash.withdrawals || 0))}</td></tr>
                            <tr><th className="text-left">Less: Transfer to Cashier</th><td className="text-right font-bold">-{formatCurrency(dailyCash.cashier_transfers)}</td></tr>
                            <tr><th className="text-left">Expected Cash in Drawer</th><td className="text-right font-bold">{formatCurrency(dailyCash.expected_cash)}</td></tr>
                            <tr><th className="text-left">Actual Cash Tally</th><td className="text-right font-bold">{dailyCash.actual_cash === null ? 'PENDING' : formatCurrency(dailyCash.actual_cash)}</td></tr>
                            <tr><th className="text-left">Variance (Expected - Actual)</th><td className="text-right font-bold">{dailyVariance === null ? 'PENDING' : formatCurrency(dailyVariance)}</td></tr>
                        </tbody>
                    </table>
                    <div className={`border px-3 py-1.5 text-center text-[9px] font-bold mb-3 ${dailyCashStatusClass}`}>CASH STATUS: {dailyCashStatus}{dailyVariance !== null ? ` (${formatCurrency(Math.abs(dailyVariance))})` : ''}</div>

                    <h2 className="text-[9px] font-bold mb-1">2. LESS: EXPENSES / WITHDRAWALS DETAILS</h2>
                    <table className="daily-cash-table mb-3 daily-cash-details">
                        <thead><tr><th className="w-[16%] text-center">TIME</th><th className="text-left">PARTICULARS / DESCRIPTION</th><th className="w-[20%] text-right">AMOUNT</th></tr></thead>
                        <tbody>
                            {dailyCashDetails.length ? dailyCashDetails.map(detail => <tr key={detail.id}><td className="text-center">{formatTime(detail.time)}</td><td>{detail.kind}: {detail.particulars}</td><td className="text-right">{formatCurrency(detail.amount)}</td></tr>) : <tr><td colSpan="3" className="text-center italic">No room drawer expenses, withdrawals, or cashier transfers.</td></tr>}
                            <tr><th colSpan="2" className="text-right">TOTAL EXPENSES / WITHDRAWALS</th><th className="text-right">{formatCurrency(Number(dailyCash.room_expenses || 0) + Number(dailyCash.withdrawals || 0) + Number(dailyCash.cashier_transfers || 0))}</th></tr>
                        </tbody>
                    </table>

                    <h2 className="text-[9px] font-bold mb-1">3. CASH BREAKDOWN / CASH TALLY</h2>
                    <table className="daily-cash-table">
                        <thead><tr><th className="w-[25%]">DENOMINATION</th><th className="w-[20%]">QUANTITY</th><th className="w-[25%]">AMOUNT</th><th>VERIFIED BY</th></tr></thead>
                        <tbody>
                            {cashDenominations.map(denomination => {
                                const quantity = Number(closingDenominations[denomination.toString()] || 0);
                                return <tr key={denomination}><td className="text-center">{formatCurrency(denomination)}</td><td className="text-center">{quantity || '-'}</td><td className="text-right">{formatCurrency(denomination * quantity)}</td><td className="text-center">{shift.user?.name || ''}</td></tr>;
                            })}
                            <tr><th colSpan="2" className="text-right">TOTAL CASH TALLY</th><th className="text-right">{dailyCash.actual_cash === null ? 'PENDING' : formatCurrency(dailyCash.actual_cash)}</th><th /></tr>
                        </tbody>
                    </table>
                    <div className="grid grid-cols-2 gap-12 mt-7 text-[8px] text-center">
                        <div><div className="border-b border-black h-5" /><strong>Prepared by: Front Desk Staff</strong></div>
                        <div><div className="border-b border-black h-5" /><strong>Checked by: Cashier / Supervisor</strong></div>
                    </div>
                </div>

                {/* 3. MINIBAR & POS SALES */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'minibar' ? 'hidden' : ''}`}>
                    <PrintHeader title="III. MINIBAR & POS SALES LEDGER" pageNum={3} />

                    <div className="mb-4">
                        <h2 className="text-[10px] font-bold uppercase mb-2">1. Pantry / Minibar Walk-in POS Sales</h2>
                        <table className="w-full text-left border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th className="w-[10%]">TIME</th>
                                    <th className="w-[15%]">OR / REF NO</th>
                                    <th className="w-[10%]">METHOD</th>
                                    <th className="w-[35%]">ITEMS SOLD</th>
                                    <th className="w-[10%] text-right">CASH</th>
                                    <th className="w-[10%] text-right">GCASH</th>
                                    <th className="w-[10%] text-right">TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.transactions?.filter(t => t.transaction_type === 'pos_sale').length > 0 ? (
                                    report.transactions.filter(t => t.transaction_type === 'pos_sale').map((item) => (
                                        <tr key={item.id} className="text-[9px]">
                                            <td className="text-center">{formatTime(item.created_at)}</td>
                                            <td className="text-center font-bold">{item.formatted_or_number || `POS-${item.id}`}</td>
                                            <td className="text-center uppercase">{item.payment_method || 'Cash'}</td>
                                            <td>
                                                {item.inventory_usages && item.inventory_usages.length > 0
                                                    ? item.inventory_usages.map(u => `${u.quantity}x ${u.item?.item_name || 'Item'}`).join(', ')
                                                    : item.description || 'Walk-in Sale'}
                                            </td>
                                            <td className="text-right">{formatCurrency(item.cash_amount || 0)}</td>
                                            <td className="text-right">{formatCurrency(item.gcash_amount || 0)}</td>
                                            <td className="text-right font-bold">{formatCurrency(item.amount)}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="7" className="text-center py-4">
                                            No walk-in POS sales recorded during this shift.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="mb-4">
                        <h2 className="text-[10px] font-bold uppercase mb-2">2. Billed Minibar charges to Stay Checkouts</h2>
                        <table className="w-full text-left border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th className="w-[10%]">TIME</th>
                                    <th className="w-[10%] text-center">ROOM</th>
                                    <th className="w-[20%]">GUEST</th>
                                    <th className="w-[30%]">ITEMS CONSUMED</th>
                                    <th className="w-[10%] text-right">UNIT PRICE</th>
                                    <th className="w-[10%] text-center">QTY</th>
                                    <th className="w-[10%] text-right">TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.inventory_usage_details?.filter(u => u.booking_id !== null).length > 0 ? (
                                    report.inventory_usage_details.filter(u => u.booking_id !== null).map((usage) => (
                                        <tr key={usage.id} className="text-[9px]">
                                            <td className="text-center">{formatTime(usage.created_at)}</td>
                                            <td className="text-center font-bold">Room {usage.booking?.room?.room_number || '-'}</td>
                                            <td>{usage.booking?.guest_name || 'Stay Guest'}</td>
                                            <td>{usage.item?.item_name || 'Item'}</td>
                                            <td className="text-right">{formatCurrency(usage.unit_price)}</td>
                                            <td className="text-center font-bold">{usage.quantity}</td>
                                            <td className="text-right font-bold">{formatCurrency(usage.total_price)}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="7" className="text-center py-4">
                                            No minibar charges billed to checkout rooms.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex justify-end mt-4">
                        <div className="ledger-handwritten-circle text-[9px]">
                            Total Minibar Sales Revenue: {formatCurrency(report.minibar_revenue)}
                        </div>
                    </div>

                    <PrintFooter title="Minibar & Pantry POS Ledger" />
                </div>

                {/* 4. INVENTORY STOCK STATUS */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'inventory' ? 'hidden' : ''}`}>
                    <PrintHeader title="IV. INVENTORY STOCK USAGE & STATUS" pageNum={4} />

                    <div className="grid grid-cols-2 gap-6 w-full">
                        <div>
                            <h2 className="text-[10px] font-bold uppercase mb-2">Pantry Inventory Usages (Aggregated)</h2>
                            <table className="w-full text-left border-collapse logbook-table">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th className="text-center">Qty Used</th>
                                        <th className="text-right">Billed Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.inventory_items && report.inventory_items.length > 0 ? (
                                        report.inventory_items.map((inv) => (
                                            <tr key={inv.item_id} className="text-[9px]">
                                                <td>{inv.item_name}</td>
                                                <td className="text-center font-bold">{inv.qty}</td>
                                                <td className="text-right font-bold">{formatCurrency(inv.total)}</td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="3" className="text-center py-4">
                                                No stock items consumed during this shift.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <h2 className="text-[10px] font-bold uppercase mb-2">Critical Stock Warnings</h2>
                            <table className="w-full text-left border-collapse logbook-table">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th className="text-center">Limit</th>
                                        <th className="text-center">Current</th>
                                        <th className="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.low_stock && report.low_stock.length > 0 ? (
                                        report.low_stock.map((stock) => (
                                            <tr key={stock.id} className="text-[9px] font-bold">
                                                <td className="text-red-700">{stock.item_name}</td>
                                                <td className="text-center">{stock.minimum_stock}</td>
                                                <td className="text-center text-red-700">{stock.current_stock}</td>
                                                <td className="text-center text-red-700 uppercase text-[8px]">Low Stock</td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="4" className="text-center py-6">
                                                All inventory items have safe stock levels.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <PrintFooter title="Inventory Stock Controls" />
                </div>

                {/* 5. EXPENSES LEDGER */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'expenses' ? 'hidden' : ''}`}>
                    <PrintHeader title="V. SHIFT EXPENSES LEDGER" pageNum={5} />

                    <div className="mb-4">
                        <table className="w-full text-left border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th className="w-[10%]">TIME</th>
                                    <th className="w-[15%]">REF NO</th>
                                    <th className="w-[15%]">DRAWER</th>
                                    <th className="w-[30%]">EXPENSE CATEGORY / NOTES</th>
                                    <th className="w-[15%]">RECORDED BY</th>
                                    <th className="w-[15%] text-right">OUTFLOW</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.expenses && report.expenses.length > 0 ? (
                                    report.expenses.map((exp) => (
                                        <tr key={exp.id} className="text-[9px]">
                                            <td className="text-center">{formatTime(exp.created_at || exp.expense_date)}</td>
                                            <td className="text-center">EXP-{exp.id}</td>
                                            <td className="text-center font-bold uppercase">{exp.cash_drawer} Drawer</td>
                                            <td>{exp.notes || 'Miscellaneous disbursement'}</td>
                                            <td>{exp.user?.name || '-'}</td>
                                            <td className="text-right font-bold text-red-700">-{formatCurrency(exp.amount)}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="text-center py-6">
                                            No shift drawer expenses recorded.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex justify-end gap-4 mt-6">
                        <div className="ledger-handwritten-circle text-[9px] border-red-700">
                            Total Shift Expenses: -{formatCurrency(report.expenses_sum)}
                        </div>
                    </div>

                    <PrintFooter title="Drawer Disbursement Records" />
                </div>

                {/* 6. ADDITIONAL INCOME LOG */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'income' ? 'hidden' : ''}`}>
                    <PrintHeader title="VI. ADDITIONAL INCOME LOG" pageNum={6} />

                    <div className="mb-4">
                        <table className="w-full text-left border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th className="w-[10%]">TIME</th>
                                    <th className="w-[15%]">REF NO</th>
                                    <th className="w-[15%]">DRAWER</th>
                                    <th className="w-[30%]">INCOME CATEGORY / DESCRIPTION</th>
                                    <th className="w-[15%]">RECORDED BY</th>
                                    <th className="w-[15%] text-right">INFLOW</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.incomes && report.incomes.length > 0 ? (
                                    report.incomes.map((inc) => (
                                        <tr key={inc.id} className="text-[9px]">
                                            <td className="text-center">{formatTime(inc.created_at || inc.income_date)}</td>
                                            <td className="text-center">INC-{inc.id}</td>
                                            <td className="text-center font-bold uppercase">{inc.cash_drawer} Drawer</td>
                                            <td>{inc.notes || 'Miscellaneous deposit'}</td>
                                            <td>{inc.user?.name || '-'}</td>
                                            <td className="text-right font-bold text-emerald-700">+{formatCurrency(inc.amount)}</td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="text-center py-6">
                                            No additional incomes recorded during this shift.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex justify-end gap-4 mt-6">
                        <div className="ledger-handwritten-circle text-[9px]">
                            Total Shift Incomes: +{formatCurrency(report.incomes_sum)}
                        </div>
                    </div>

                    <PrintFooter title="Drawer Inflow Logs" />
                </div>

                {/* 7. MAINTENANCE TICKETS */}
                <div className={`print-page-break ${printMode === 'active' && activeTab !== 'maintenance' ? 'hidden' : ''}`}>
                    <PrintHeader title="VII. MAINTENANCE TICKETS REPORT" pageNum={7} />

                    <div className="mb-4">
                        <table className="w-full text-left border-collapse logbook-table">
                            <thead>
                                <tr>
                                    <th className="w-[15%]">TIME REPORTED</th>
                                    <th className="w-[10%] text-center">ROOM</th>
                                    <th className="w-[20%]">ISSUE TITLE</th>
                                    <th className="w-[10%] text-center">STATUS</th>
                                    <th className="w-[20%]">REPORTED BY</th>
                                    <th className="w-[25%]">RESOLUTION / ACTION REMARKS</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.maintenance_tickets && report.maintenance_tickets.length > 0 ? (
                                    report.maintenance_tickets.map((ticket) => (
                                        <tr key={ticket.id} className="text-[9px]">
                                            <td>{new Date(ticket.created_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</td>
                                            <td className="text-center font-bold">Room {ticket.room?.room_number || '-'}</td>
                                            <td className="font-bold">{ticket.title}</td>
                                            <td className="text-center font-bold uppercase text-[8px]">
                                                {ticket.status}
                                            </td>
                                            <td>{ticket.reported_by?.name || ticket.reported_by_name || '-'}</td>
                                            <td className="italic text-slate-700">
                                                {ticket.notes || (ticket.status === 'resolved' ? 'Resolved successfully.' : 'Pending repair/evaluation')}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="text-center py-6">
                                            No maintenance concerns registered or resolved.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <PrintFooter title="Maintenance Concerns & Tickets" />
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
