import React, { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { formatHotelDate, formatHotelTime } from '@/Utils/datetime';

const DENOMINATIONS = [
    { key: '1000', label: 'P 1,000' },
    { key: '500', label: 'P 500' },
    { key: '200', label: 'P 200' },
    { key: '100', label: 'P 100' },
    { key: '50', label: 'P 50' },
    { key: '20', label: 'P 20' },
    { key: '10', label: 'P 10 / Coins' },
    { key: '5', label: 'P 5' },
    { key: '1', label: 'P 1' },
    { key: '0.25', label: 'P 0.25' },
    { key: '0.05', label: 'P 0.05' },
    { key: '0.01', label: 'P 0.01' },
];

const php = (amount) =>
    'P ' + (Number(amount) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const formatDate = (dateString) => {
    if (!dateString) return '—';
    const d = new Date(dateString);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatTime = (dateString) => {
    if (!dateString) return '—';
    const d = new Date(dateString);
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
};

const formatDateTime = (dateString) => {
    if (!dateString) return '—';
    return `${formatDate(dateString)} ${formatTime(dateString)}`;
};

const denomValue = (key, qty) => {
    const vals = { '1000': 1000, '500': 500, '200': 200, '100': 100, '50': 50, '20': 20, '10': 10, '5': 5, '1': 1, '0.25': 0.25, '0.05': 0.05, '0.01': 0.01 };
    return (vals[key] || 0) * (qty || 0);
};

function DrawerTallyPage({ title, salesLabel, shiftPeriod, shiftCode, cashierName, datePrinted, cashTally, pageNumber, pageCaption }) {
    const tally = cashTally || {};
    const expenses = tally.expenses || [];
    const movements = tally.cash_movements || [];
    const incomes = tally.incomes || [];
    const closingDenominations = tally.closing_denominations || {};
    const deductionRows = [
        ...expenses.map(expense => ({ label: expense.notes || expense.category || 'Expense', amount: expense.amount, remark: expense.description || '' })),
        ...movements.map(movement => ({
            label: movement.movement_type === 'cashier_transfer' ? 'Cash Transfer' : 'Cash Withdrawal',
            amount: movement.amount,
            remark: movement.description || '',
        })),
    ];
    const totalDeductions = deductionRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const denomRows = DENOMINATIONS.map(denomination => ({
        ...denomination,
        qty: closingDenominations[denomination.key] ?? 0,
        amount: denomValue(denomination.key, closingDenominations[denomination.key] ?? 0),
    })).filter(denomination => denomination.qty > 0);
    const actualCashCount = denomRows.reduce((sum, denomination) => sum + denomination.amount, 0);
    const variance = tally.variance;
    const varianceColor = variance === null ? '' : variance < 0 ? '#fde8e8' : variance > 0 ? '#e8f5e9' : '#e8f4e8';

    return (
        <div className="page page-break tally-page">
            <div className="page2-banner">
                <h1>{title}</h1>
                <p>Cash Collection Summary and Reconciliation</p>
            </div>
            <div className="subheader-row">
                <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                <span>Shift Period: {shiftPeriod} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Shift Operator: {cashierName}</span>
            </div>
            <div className="p2-layout">
                <div>
                    <table className="tally-table">
                        <thead><tr><th>CASH COLLECTION SUMMARY</th><th className="right" style={{ width: 130 }}>AMOUNT</th></tr></thead>
                        <tbody>
                            <tr><td>Beginning cash on hand</td><td className="right">{php(tally.opening_cash)}</td></tr>
                            <tr><td>{salesLabel}</td><td className="right">{php(tally.sales_cash)}</td></tr>
                            <tr><td>Other cash receipts / cash added</td><td className="right">{php(tally.other_cash_receipts)}</td></tr>
                            <tr className="total-row"><td>TOTAL CASH AVAILABLE</td><td className="right">{php(tally.total_cash_available)}</td></tr>
                        </tbody>
                    </table>
                    <table className="tally-table">
                        <thead><tr><th>LESS: CASH EXPENSES / WITHDRAWALS</th><th className="right" style={{ width: 100 }}>AMOUNT</th><th style={{ width: 150 }}>REFERENCE / REMARKS</th></tr></thead>
                        <tbody>
                            {deductionRows.length === 0 ? (
                                <tr><td colSpan={3} style={{ color: '#888', fontStyle: 'italic', textAlign: 'center' }}>No expenses or withdrawals recorded.</td></tr>
                            ) : deductionRows.map((row, index) => (
                                <tr key={index}><td>{row.label}</td><td className="right">{php(row.amount)}</td><td>{row.remark || '—'}</td></tr>
                            ))}
                            <tr className="deduct-total"><td>TOTAL LESS / EXPENSES</td><td className="right">{php(totalDeductions)}</td><td></td></tr>
                        </tbody>
                    </table>
                    <div className="section-title">DETAIL OF OTHER CASH RECEIPTS (ALREADY INCLUDED ABOVE)</div>
                    <table className="tally-table">
                        <thead><tr><th>INCOME / RECEIPT</th><th className="right" style={{ width: 130 }}>AMOUNT</th><th style={{ width: 150 }}>REFERENCE / REMARKS</th></tr></thead>
                        <tbody>
                            {incomes.length === 0 ? (
                                <tr><td colSpan={3} style={{ color: '#888', fontStyle: 'italic', textAlign: 'center' }}>No other cash receipts recorded.</td></tr>
                            ) : incomes.map((income, index) => (
                                <tr key={index}><td>{income.category || 'Income'}</td><td className="right">{php(income.amount)}</td><td>{income.notes || income.reference_number || '—'}</td></tr>
                            ))}
                            <tr className="total-row" style={{ backgroundColor: '#e8f4e8' }}><td>TOTAL OTHER RECEIPTS</td><td className="right">{php(incomes.reduce((sum, income) => sum + Number(income.amount || 0), 0))}</td><td></td></tr>
                        </tbody>
                    </table>
                    <div className="expected-box"><span className="exp-label">EXPECTED CASH ON HAND</span><span className="exp-value">{php(tally.expected_cash)}</span></div>
                    <table className="recon-table">
                        <thead><tr><th>RECONCILIATION</th><th className="right" style={{ width: 130 }}>AMOUNT</th></tr></thead>
                        <tbody>
                            <tr><td>Expected cash on hand</td><td className="right">{php(tally.expected_cash)}</td></tr>
                            <tr><td>Actual cash count</td><td className="right">{tally.actual_cash !== null ? php(actualCashCount || tally.actual_cash) : '(shift not yet closed)'}</td></tr>
                            <tr className="variance-row" style={{ backgroundColor: variance !== null ? varianceColor : '' }}><td>VARIANCE (SHORT / OVER)</td><td className="right">{variance !== null ? (variance === 0 ? php(0) : (variance > 0 ? '+' : '') + php(variance)) : '—'}</td></tr>
                        </tbody>
                    </table>
                    <div className="sig-row" style={{ padding: '0', marginTop: 20 }}>
                        <div className="sig-box"><div className="sig-line"></div><div className="sig-label">Prepared by: Front Desk Officer</div></div>
                        <div className="sig-box"><div className="sig-line"></div><div className="sig-label">Checked by: Duty Manager</div></div>
                    </div>
                </div>
                <div>
                    <table className="denom-table">
                        <thead><tr><th>CASH BREAKDOWN / ACTUAL TALLY</th><th className="right" style={{ width: 50 }}>QTY</th><th className="right" style={{ width: 90 }}>AMOUNT</th></tr></thead>
                        <tbody>
                            {DENOMINATIONS.filter(denomination => (closingDenominations[denomination.key] ?? 0) > 0 || denomRows.length === 0).map(denomination => {
                                const qty = closingDenominations[denomination.key] ?? 0;
                                const amount = denomValue(denomination.key, qty);
                                if (!qty && denomRows.length > 0) return null;
                                return <tr key={denomination.key}><td>{denomination.label}</td><td className="right">{qty > 0 ? qty : '—'}</td><td className="right">{qty > 0 ? php(amount) : '—'}</td></tr>;
                            })}
                            <tr className="total-row"><td><strong>ACTUAL CASH COUNT</strong></td><td></td><td className="right">{denomRows.length > 0 ? php(actualCashCount) : (tally.actual_cash !== null ? php(tally.actual_cash) : '—')}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="page-footer"><span>Pension House PMS • {title}</span><span>Printed: {datePrinted} &nbsp;|&nbsp; Page {pageNumber} of 6 — {pageCaption}</span></div>
        </div>
    );
}

const methodLabel = (method) => ({
    cash: 'Cash',
    gcash: 'GCash',
    bank_transfer: 'Bank Transfer',
    card: 'Card',
    maya: 'Maya',
    other_ewallet: 'E-Wallet',
    other: 'Other',
}[method] || method);

const bookingStatusLabel = (status) => ({
    reserved: 'Reserved',
    active: 'Active',
    cancelled: 'Cancelled',
    no_show: 'No Show',
    checked_out: 'Checked Out',
    completed: 'Completed',
}[status] || status);

const bookingTypeLabel = (booking) => {
    const type = booking.booking_type === 'overnight' ? 'Overnight' : 'Short Time';
    const source = booking.booking_source === 'online' ? 'Online' : 'Walk-in';
    return `${type} / ${source}`;
};

const bookingPaymentCell = (booking) => {
    const lines = [];
    const methods = booking.shift_collection_methods || {};
    const references = booking.shift_collection_references || {};
    const methodNames = Object.keys(methods).filter((key) => methods[key] > 0);

    if (methodNames.length > 0) {
        lines.push(methodNames.map((method) => {
            let label = methodLabel(method);
            if (method !== 'cash' && references[method]?.length) {
                label += ` (Ref: ${references[method].join(', ')})`;
            }
            return `${label} ${php(methods[method])}`;
        }).join('\n'));
    }

    if ((booking.pending_payment_amount || 0) > 0) {
        lines.push(`Pending verification: ${php(booking.pending_payment_amount)}`);
    }

    return lines.length > 0 ? lines.join('\n') : '—';
};

export default function RoomBookingsLedgerPrint({
    shift,
    bookings,
    booking_transactions = [],
    stay_collections,
    date_printed,
    cash_tally,
    totals,
    minibar,
}) {
    useEffect(() => { window.print(); }, []);

    const shiftCode = shift.shift_code ? shift.shift_code.toUpperCase() : '—';
    const cashierName = shift.user?.full_name || '—';
    const shiftPeriod = shift.started_at
        ? `${formatDateTime(shift.started_at)} – ${shift.ended_at ? formatDateTime(shift.ended_at) : 'Active'}`
        : '—';

    // Page 1 footer totals
    const totalRoomSales = totals?.total_room_sales ?? 0;
    const cashCollection = totals?.cash_collection ?? 0;
    const digitalPayment = totals?.digital_payment ?? 0;
    const outstandingBalance = totals?.outstanding_balance ?? 0;

    // Page 2 - cash tally
    const ct = cash_tally || {};
    const openingCash = ct.opening_cash ?? 0;
    const roomSalesCash = ct.room_sales_cash ?? 0;
    const otherCashReceipts = ct.other_cash_receipts ?? 0;
    const totalCashAvailable = ct.total_cash_available ?? 0;
    const expenses = ct.expenses ?? [];
    const cashMovements = ct.cash_movements ?? [];
    const incomes = ct.incomes ?? [];
    const totalExpenses = ct.total_expenses ?? 0;
    const totalMovements = ct.total_movements ?? 0;
    const expectedCash = ct.expected_cash ?? 0;
    const actualCash = ct.actual_cash;
    const variance = ct.variance;
    const closingDenoms = ct.closing_denominations ?? {};
    const minibarData = minibar || {};
    const minibarPosSales = minibarData.pos_sales || [];
    const minibarStayCharges = minibarData.stay_charges || [];
    const lowStock = minibarData.low_stock || [];
    const bookingTransactions = Array.isArray(booking_transactions) ? booking_transactions : [];
    const reservationsMade = bookingTransactions.length;
    const totalBookedValue = bookingTransactions.reduce((sum, booking) => sum + Number(booking.total_amount || 0), 0);
    const verifiedBookingPayments = bookingTransactions.reduce((sum, booking) => sum + Number(booking.shift_collection_amount || 0), 0);
    const outstandingReservationBalance = bookingTransactions.reduce((sum, booking) => sum + Math.max(0, Number(booking.balance_amount || 0)), 0);

    // Compute actual cash count from closing denominations
    const denomRows = DENOMINATIONS.map(d => ({
        ...d,
        qty: closingDenoms[d.key] ?? 0,
        amount: denomValue(d.key, closingDenoms[d.key] ?? 0),
    })).filter(d => d.qty > 0);

    const actualCashCount = denomRows.reduce((s, d) => s + d.amount, 0);

    // All expense + movement rows for the deductions table
    const deductionRows = [
        ...expenses.map(e => ({ label: e.notes || e.category || 'Expense', amount: e.amount, remark: e.description || '' })),
        ...cashMovements.map(m => ({ label: m.movement_type === 'cashier_transfer' ? 'Cash Transfer' : 'Cash Withdrawal', amount: m.amount, remark: m.description || '' })),
    ];
    const totalDeductions = deductionRows.reduce((s, r) => s + Number(r.amount), 0);

    const varColor = variance === null ? '' : variance < 0 ? '#fde8e8' : variance > 0 ? '#e8f5e9' : '#e8f4e8';

    return (
        <>
            <Head title={`Room Bookings Ledger - Shift ${shift.id}`} />

            <style>{`
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: white; color: #1a1a2e; font-size: 11px; }

                @media print {
                    @page { size: A4 landscape; margin: 5mm 10mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-print { display: none !important; }
                    .page-break { page-break-before: always; }
                    .tally-page .page2-banner { padding: 8px 16px; margin-bottom: 6px; }
                    .tally-page .subheader-row { margin-bottom: 6px; }
                    .tally-page .tally-table { margin-bottom: 8px; }
                    .tally-page .tally-table td { padding: 4px 10px; }
                    .tally-page .expected-box { padding: 8px 16px; margin-bottom: 8px; }
                    .tally-page .recon-table { margin-bottom: 8px; }
                    .tally-page .recon-table td { padding: 4px 10px; }
                    .tally-page .sig-row { margin-top: 10px !important; }
                }

                /* ── shared ── */
                .page { padding: 0; max-width: 100%; }
                .subheader-row { display: flex; justify-content: space-between; align-items: center; font-size: 10px; margin-bottom: 8px; color: #444; }
                
                /* ── Page 1 ── */
                .banner { background: #1a3a5c; color: white; text-align: center; padding: 14px 20px; margin-bottom: 10px; }
                .banner h1 { font-size: 22px; font-weight: 800; letter-spacing: 1px; margin-bottom: 3px; }
                .banner p { font-size: 11px; opacity: 0.85; }

                .ledger-table { width: 100%; border-collapse: collapse; font-size: 10.5px; margin-bottom: 0; }
                .ledger-table th { background: #1a3a5c; color: white; padding: 7px 6px; text-align: center; font-weight: 700; font-size: 10px; letter-spacing: 0.3px; border: 1px solid #1a3a5c; }
                .ledger-table td { padding: 6px 6px; text-align: center; border: 1px solid #c5cfe0; vertical-align: middle; }
                .ledger-table tbody tr:nth-child(even) td { background: #f4f7fb; }
                .ledger-table tbody tr:nth-child(odd) td { background: #ffffff; }
                .ledger-table .td-left { text-align: left; }
                .ledger-table .td-bold { font-weight: 700; }

                .footer-totals { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; border: 2px solid #1a3a5c; margin-top: 0; }
                .footer-box { padding: 8px 12px; text-align: center; border-right: 1px solid #1a3a5c; }
                .footer-box:last-child { border-right: none; }
                .footer-box .label { font-size: 9px; font-weight: 700; color: #1a3a5c; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; }
                .footer-box .value { font-size: 15px; font-weight: 800; color: #1a3a5c; }
                .footer-box.highlight { background: #1a3a5c; }
                .footer-box.highlight .label { color: #a8c4e0; }
                .footer-box.highlight .value { color: #ffffff; }

                .sig-row { display: flex; justify-content: space-between; margin-top: 16px; padding: 0 20px; }
                .sig-box { text-align: center; width: 180px; }
                .sig-line { border-bottom: 1px solid #555; margin-bottom: 3px; height: 28px; }
                .sig-label { font-size: 9px; color: #555; }

                /* ── Page 2 ── */
                .page2-banner { background: #1a3a5c; color: white; padding: 10px 18px; margin-bottom: 10px; }
                .page2-banner h1 { font-size: 16px; font-weight: 800; letter-spacing: 0.5px; }
                .page2-banner p { font-size: 10px; opacity: 0.8; margin-top: 2px; }

                .p2-layout { display: grid; grid-template-columns: 1fr 320px; gap: 16px; }

                .section-title { background: #1a3a5c; color: white; font-size: 10px; font-weight: 700; padding: 7px 10px; letter-spacing: 0.4px; text-transform: uppercase; }
                .tally-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10.5px; }
                .tally-table th { background: #1a3a5c; color: white; padding: 6px 10px; text-align: left; font-weight: 700; font-size: 10px; border: 1px solid #1a3a5c; }
                .tally-table th.right { text-align: right; }
                .tally-table td { padding: 6px 10px; border: 1px solid #c5cfe0; }
                .tally-table td.right { text-align: right; }
                .tally-table tr.total-row td { background: #dce8f5; font-weight: 700; }
                .tally-table tr.deduct-total td { background: #fde8e8; font-weight: 700; }

                .expected-box { background: #1a3a5c; color: white; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; margin-bottom: 12px; }
                .expected-box .exp-label { font-size: 13px; font-weight: 700; letter-spacing: 0.5px; }
                .expected-box .exp-value { font-size: 22px; font-weight: 800; }

                .recon-table { width: 100%; border-collapse: collapse; font-size: 10.5px; margin-bottom: 12px; }
                .recon-table th { background: #1a3a5c; color: white; padding: 6px 10px; text-align: left; font-weight: 700; font-size: 10px; border: 1px solid #1a3a5c; }
                .recon-table th.right { text-align: right; }
                .recon-table td { padding: 6px 10px; border: 1px solid #c5cfe0; }
                .recon-table td.right { text-align: right; }
                .recon-table tr.variance-row td { font-weight: 700; }

                .denom-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
                .denom-table th { background: #1a3a5c; color: white; padding: 6px 8px; text-align: left; font-weight: 700; font-size: 10px; border: 1px solid #1a3a5c; }
                .denom-table th.right { text-align: right; }
                .denom-table td { padding: 5px 8px; border: 1px solid #c5cfe0; }
                .denom-table td.right { text-align: right; }
                .denom-table tr.total-row td { background: #dce8f5; font-weight: 700; }

                .page-footer { display: flex; justify-content: space-between; font-size: 8.5px; color: #aaa; border-top: 1px solid #ddd; padding-top: 4px; margin-top: 8px; }
                .print-btn { position: fixed; bottom: 20px; right: 20px; background: #1a3a5c; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.25); }
                .print-btn:hover { background: #254e82; }
            `}</style>

            {/* ═══════════════════════════════════════════════════ PAGE 1 ══ */}
            <div className="page">
                {/* Banner */}
                <div className="banner">
                    <h1>FRONT DESK ROOM SALES LOGBOOK</h1>
                    <p>Daily Check-in, Check-out and Payment Monitoring</p>
                </div>

                {/* Sub-header */}
                <div className="subheader-row">
                    <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                    <span>Shift Period: {shiftPeriod} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Shift Operator: {cashierName}</span>
                </div>

                {/* Main Logbook Table */}
                <table className="ledger-table">
                    <thead>
                        <tr>
                            <th style={{ width: 60 }}>DATE</th>
                            <th style={{ width: 62 }}>TIME<br />IN</th>
                            <th style={{ width: 72 }}>TIME<br />OUT</th>
                            <th style={{ width: 52 }}>ROOM<br />NO.</th>
                            <th style={{ width: 55 }}>NO. OF<br />NIGHTS</th>
                            <th style={{ width: 72 }}>RATE</th>
                            <th style={{ width: 80 }}>PAID THIS<br />SHIFT</th>
                            <th style={{ width: 120 }}>PAYMENT /<br />LAST PAYMENT</th>
                            <th style={{ width: 125 }}>OTHER CHARGES /<br />REMARKS</th>
                            <th>GUEST NAME</th>
                            <th style={{ width: 100 }}>CONTACT NO.</th>
                        </tr>
                    </thead>
                    <tbody>
                        {bookings.map((b) => {
                            const isOvernight = b.booking_type === 'overnight';
                            const nights = isOvernight ? b.num_nights : null;
                            const hrsLabel = isOvernight
                                ? `${b.num_nights} night${b.num_nights !== 1 ? 's' : ''}`
                                : `${b.short_time_hours || b.num_nights} hrs`;

                            // Calculate actual rate per night for overnight, or block rate for short time
                            const baseRate = Number(b.base_amount || 0);
                            const actualRate = isOvernight
                                ? baseRate / Math.max(1, b.num_nights || 1)
                                : baseRate;

                            const rateLabel = `P ${actualRate.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

                            const totalAmount = b.total_amount ?? 0;
                            const paidThisShift = b.shift_collection_amount ?? 0;
                            const balance = b.balance_amount ?? 0;
                            const dpAmount = b.dp_amount ?? 0;
                            const extraCharges = Array.isArray(b.shift_extra_charges)
                                ? b.shift_extra_charges
                                : [];

                            // Build PAID THIS SHIFT column text
                            let paidCell = `P ${Number(paidThisShift).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
                            if (dpAmount > 0) {
                                paidCell += `\nDP: P ${Number(dpAmount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
                            }

                            // Build payment label
                            let paymentLines = [];
                            const sourceLabel = b.booking_source === 'online' ? 'Online Booking' : 'Walk-in';
                            paymentLines.push(`Source: ${sourceLabel}`);
                            const methods = b.shift_collection_methods || {};
                            const methodNames = Object.keys(methods).filter(k => methods[k] > 0);
                            const references = b.shift_collection_references || {};
                            const cashTenders = Array.isArray(b.shift_cash_tenders) ? b.shift_cash_tenders : [];

                            if (methodNames.length > 0) {
                                const mopStr = methodNames.map(m => {
                                    let label = m === 'gcash' ? 'GCash' : m === 'bank_transfer' ? 'Bank Transfer' : m.charAt(0).toUpperCase() + m.slice(1);
                                    if (m !== 'cash' && references[m] && references[m].length > 0) {
                                        label += ` (Ref: ${references[m].join(', ')})`;
                                    }
                                    return label;
                                }).join(' + ');

                                if (balance <= 0) {
                                    paymentLines.push(`${mopStr} - PAID`);
                                } else {
                                    paymentLines.push(`${mopStr} - P ${Number(paidThisShift).toLocaleString('en-PH', { minimumFractionDigits: 2 })}\nbalance P ${Number(balance).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`);
                                }
                                cashTenders.forEach(tender => {
                                    paymentLines.push(
                                        `Cash received: P ${Number(tender.cash_received || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                                        + ` | Change: P ${Number(tender.change || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                                    );
                                });
                            } else if (b.status === 'active' && totalAmount === 0) {
                                paymentLines.push('COMPLIMENTARY');
                            } else if (balance <= 0 && totalAmount > 0 && paidThisShift === 0 && dpAmount === 0) {
                                paymentLines.push('PAID');
                            } else if (paidThisShift === 0 && balance > 0) {
                                paymentLines.push(`Balance: P ${Number(balance).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`);
                            } else if (paidThisShift === 0 && balance <= 0 && dpAmount > 0) {
                                paymentLines.push(`PAID`);
                            }

                            if (dpAmount > 0) {
                                const dpMethods = b.dp_methods || {};
                                const dpMethodNames = Object.keys(dpMethods).filter(k => dpMethods[k] > 0);
                                if (dpMethodNames.length > 0) {
                                    const dpMopStr = dpMethodNames.map(m => {
                                        let label = m === 'gcash' ? 'GCash' : m === 'bank_transfer' ? 'Bank Transfer' : m.charAt(0).toUpperCase() + m.slice(1);
                                        if (m !== 'cash' && b.dp_references?.[m] && b.dp_references[m].length > 0) {
                                            label += ` (Ref: ${b.dp_references[m].join(', ')})`;
                                        }
                                        return label;
                                    }).join(' + ');
                                    paymentLines.push(`DP: ${dpMopStr}`);
                                }
                            }

                            if (paymentLines.length === 0) {
                                paymentLines.push('—');
                            }

                            return (
                                <tr key={b.id}>
                                    <td>{formatHotelDate(b.check_in)}</td>
                                    <td>{formatHotelTime(b.check_in)}</td>
                                    <td style={{ whiteSpace: 'pre-line' }}>
                                        {formatHotelDate(b.check_out || b.expected_check_out)}{'\n'}{formatHotelTime(b.check_out || b.expected_check_out)}
                                    </td>
                                    <td className="td-bold">{b.room?.room_number || '—'}</td>
                                    <td>{hrsLabel}</td>
                                    <td className="td-bold">{rateLabel}</td>
                                    <td className="td-bold" style={{ whiteSpace: 'pre-line' }}>{paidCell}</td>
                                    <td className="td-left" style={{ whiteSpace: 'pre-line', fontSize: 10 }}>{paymentLines.join('\n')}</td>
                                    <td className="td-left" style={{ whiteSpace: 'pre-line', fontSize: 10 }}>
                                        {extraCharges.length > 0
                                            ? extraCharges.map(charge => {
                                                const reference = charge.payment_method === 'gcash' && charge.payment_reference
                                                    ? ` • GCash Ref: ${charge.payment_reference}`
                                                    : charge.payment_method === 'cash'
                                                        ? ' • Cash'
                                                        : '';
                                                return `${charge.description} - ${php(charge.amount)}${reference}`;
                                            }).join('\n')
                                            : '—'}
                                    </td>
                                    <td className="td-left td-bold" style={{ textTransform: 'uppercase' }}>{b.guest_name}</td>
                                    <td>{b.guest_contact || '—'}</td>
                                </tr>
                            );
                        })}
                        {bookings.length === 0 && (
                            <tr>
                                <td colSpan={11} style={{ padding: '24px', color: '#888', fontStyle: 'italic' }}>
                                    No room bookings were checked in or out during this shift.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {/* Footer Totals Bar */}
                <div className="footer-totals">
                    <div className="footer-box">
                        <div className="label">Total Room Sales</div>
                        <div className="value">{php(totalRoomSales)}</div>
                    </div>
                    <div className="footer-box">
                        <div className="label">Total Cash Payments on Reported Stays</div>
                        <div className="value">{php(cashCollection)}</div>
                    </div>
                    <div className="footer-box">
                        <div className="label">Digital Payment</div>
                        <div className="value">{php(digitalPayment)}</div>
                    </div>
                    <div className="footer-box highlight">
                        <div className="label">Outstanding Balance</div>
                        <div className="value">{php(outstandingBalance)}</div>
                    </div>
                </div>
                <div style={{ fontSize: 8.5, color: '#555', fontStyle: 'italic', marginTop: 6 }}>
                    Includes prior cash downpayments. See Daily Cash Tally for cash physically received during this shift.
                </div>

                {/* Signature Lines */}
                <div className="sig-row">
                    <div className="sig-box">
                        <div className="sig-line"></div>
                        <div className="sig-label">Prepared by: Front Desk Officer</div>
                    </div>
                    <div className="sig-box">
                        <div className="sig-line"></div>
                        <div className="sig-label">Verified by: Duty Manager</div>
                    </div>
                </div>

                {/* Page Footer */}
                <div className="page-footer">
                    <span>Pension House PMS • Front Desk Room Sales Logbook</span>
                    <span>Printed: {date_printed} &nbsp;|&nbsp; Page 1 of 6 — Room Sales Logbook</span>
                </div>
            </div>

            {/* ═══════════════════════════════════════════════════ PAGE 2 ══ */}
            <div className="page page-break">
                <div className="banner">
                    <h1>FRONT DESK BOOKING TRANSACTIONS</h1>
                    <p>Reservations Created During This Shift</p>
                </div>
                <div className="subheader-row">
                    <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                    <span>Shift Period: {shiftPeriod} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Shift Operator: {cashierName}</span>
                </div>
                <table className="ledger-table" style={{ fontSize: 10 }}>
                    <thead>
                        <tr>
                            <th style={{ width: 78 }}>BOOKED AT</th>
                            <th style={{ width: 92 }}>BOOKING REF</th>
                            <th style={{ width: 48 }}>ROOM</th>
                            <th>GUEST / CONTACT</th>
                            <th style={{ width: 110 }}>STAY SCHEDULE</th>
                            <th style={{ width: 88 }}>TYPE / SOURCE</th>
                            <th style={{ width: 78 }}>BOOKING TOTAL</th>
                            <th style={{ width: 78 }}>PAID THIS SHIFT</th>
                            <th style={{ width: 120 }}>PAYMENT / REFERENCE</th>
                            <th style={{ width: 72 }}>BALANCE</th>
                            <th style={{ width: 78 }}>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        {bookingTransactions.length === 0 ? (
                            <tr>
                                <td colSpan={11} style={{ padding: '24px', color: '#888', fontStyle: 'italic' }}>
                                    No reservations were created by this Front Desk officer during this shift.
                                </td>
                            </tr>
                        ) : bookingTransactions.map((booking) => {
                            const isClosedStatus = booking.status === 'cancelled' || booking.status === 'no_show';
                            return (
                                <tr key={booking.id}>
                                    <td style={{ whiteSpace: 'pre-line' }}>{formatDate(booking.created_at)}{'\n'}{formatTime(booking.created_at)}</td>
                                    <td className="td-bold">{booking.booking_ref}</td>
                                    <td className="td-bold">{booking.room?.room_number || '—'}</td>
                                    <td className="td-left" style={{ whiteSpace: 'pre-line' }}>
                                        <strong style={{ textTransform: 'uppercase' }}>{booking.guest_name}</strong>
                                        {'\n'}{booking.guest_contact || '—'}
                                    </td>
                                    <td style={{ whiteSpace: 'pre-line' }}>
                                        {formatHotelDate(booking.check_in)} {formatHotelTime(booking.check_in)}
                                        {'\n'}to {formatHotelDate(booking.check_out || booking.expected_check_out)} {formatHotelTime(booking.check_out || booking.expected_check_out)}
                                    </td>
                                    <td>{bookingTypeLabel(booking)}</td>
                                    <td className="td-bold">{php(booking.total_amount)}</td>
                                    <td className="td-bold">{php(booking.shift_collection_amount)}</td>
                                    <td className="td-left" style={{ whiteSpace: 'pre-line', fontSize: 9.5 }}>{bookingPaymentCell(booking)}</td>
                                    <td className="td-bold">{php(booking.balance_amount)}</td>
                                    <td className="td-bold" style={{ color: isClosedStatus ? '#c62828' : undefined }}>{bookingStatusLabel(booking.status)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="footer-totals">
                    <div className="footer-box">
                        <div className="label">Reservations Made</div>
                        <div className="value">{reservationsMade}</div>
                    </div>
                    <div className="footer-box">
                        <div className="label">Total Booked Value</div>
                        <div className="value">{php(totalBookedValue)}</div>
                    </div>
                    <div className="footer-box">
                        <div className="label">Verified Payments Collected During Shift</div>
                        <div className="value">{php(verifiedBookingPayments)}</div>
                    </div>
                    <div className="footer-box highlight">
                        <div className="label">Outstanding Reservation Balance</div>
                        <div className="value">{php(outstandingReservationBalance)}</div>
                    </div>
                </div>
                <div style={{ fontSize: 8.5, color: '#555', fontStyle: 'italic', marginTop: 6 }}>
                    Booking value is informational only and is not included in Total Room Sales, Cash Collection, Digital Payment, or Daily Cash Tally.
                    {' '}Booking transaction records are for audit purposes and may also appear in the Room Sales Logbook after check-in. Do not add these totals to Room Sales.
                </div>
                <div className="sig-row">
                    <div className="sig-box">
                        <div className="sig-line"></div>
                        <div className="sig-label">Prepared by: Front Desk Officer</div>
                    </div>
                    <div className="sig-box">
                        <div className="sig-line"></div>
                        <div className="sig-label">Verified by: Duty Manager</div>
                    </div>
                </div>
                <div className="page-footer">
                    <span>Pension House PMS • Front Desk Booking Transactions</span>
                    <span>Printed: {date_printed} &nbsp;|&nbsp; Page 2 of 6 — Booking Transactions</span>
                </div>
            </div>

            <div className="page page-break tally-page">
                {/* Banner */}
                <div className="page2-banner">
                    <h1>DAILY CASH TALLY &amp; DRAWER REPORT</h1>
                    <p>Cash Collection Summary and Reconciliation</p>
                </div>

                {/* Sub-header */}
                <div className="subheader-row">
                    <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                    <span>Shift Period: {shiftPeriod} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Shift Operator: {cashierName}</span>
                </div>

                <div className="p2-layout">
                    {/* ── LEFT COLUMN ── */}
                    <div>
                        {/* Cash Collection Summary */}
                        <table className="tally-table">
                            <thead>
                                <tr>
                                    <th>CASH COLLECTION SUMMARY</th>
                                    <th className="right" style={{ width: 130 }}>AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Beginning cash on hand</td>
                                    <td className="right">{php(openingCash)}</td>
                                </tr>
                                <tr>
                                    <td>CASH ROOM / RESERVATION PAYMENTS RECEIVED THIS SHIFT</td>
                                    <td className="right">{php(roomSalesCash)}</td>
                                </tr>
                                <tr>
                                    <td>Other cash receipts</td>
                                    <td className="right">{php(otherCashReceipts)}</td>
                                </tr>
                                <tr className="total-row">
                                    <td>TOTAL CASH AVAILABLE</td>
                                    <td className="right">{php(totalCashAvailable)}</td>
                                </tr>
                            </tbody>
                        </table>

                        {/* Less: Cash Expenses / Withdrawals */}
                        <table className="tally-table">
                            <thead>
                                <tr>
                                    <th>LESS: CASH EXPENSES / WITHDRAWALS</th>
                                    <th className="right" style={{ width: 100 }}>AMOUNT</th>
                                    <th style={{ width: 150 }}>REFERENCE / REMARKS</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deductionRows.length === 0 ? (
                                    <tr>
                                        <td colSpan={3} style={{ color: '#888', fontStyle: 'italic', textAlign: 'center' }}>No expenses or withdrawals recorded.</td>
                                    </tr>
                                ) : deductionRows.map((row, i) => (
                                    <tr key={i}>
                                        <td>{row.label}</td>
                                        <td className="right">{php(row.amount)}</td>
                                        <td>{row.remark || '—'}</td>
                                    </tr>
                                ))}
                                <tr className="deduct-total">
                                    <td>TOTAL LESS / EXPENSES</td>
                                    <td className="right">{php(totalDeductions)}</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>

                        {/* Income Table */}
                        <div className="section-title">DETAIL OF OTHER CASH RECEIPTS (ALREADY INCLUDED ABOVE)</div>
                        <table className="tally-table">
                            <thead>
                                <tr>
                                    <th>INCOME / RECEIPT</th>
                                    <th className="right" style={{ width: 130 }}>AMOUNT</th>
                                    <th style={{ width: 150 }}>REFERENCE / REMARKS</th>
                                </tr>
                            </thead>
                            <tbody>
                                {incomes.length === 0 ? (
                                    <tr>
                                        <td colSpan={3} style={{ color: '#888', fontStyle: 'italic', textAlign: 'center' }}>No other cash receipts recorded.</td>
                                    </tr>
                                ) : incomes.map((row, i) => (
                                    <tr key={i}>
                                        <td>{row.category || 'Income'}</td>
                                        <td className="right">{php(row.amount)}</td>
                                        <td>{row.notes || row.reference_number || '—'}</td>
                                    </tr>
                                ))}
                                <tr className="total-row" style={{ backgroundColor: '#e8f4e8' }}>
                                    <td>TOTAL OTHER RECEIPTS</td>
                                    <td className="right">{php(incomes.reduce((s, i) => s + Number(i.amount), 0))}</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>

                        {/* Expected Cash on Hand */}
                        <div className="expected-box">
                            <span className="exp-label">EXPECTED CASH ON HAND</span>
                            <span className="exp-value">{php(expectedCash)}</span>
                        </div>

                        {/* Reconciliation */}
                        <table className="recon-table">
                            <thead>
                                <tr>
                                    <th>RECONCILIATION</th>
                                    <th className="right" style={{ width: 130 }}>AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Expected cash on hand</td>
                                    <td className="right">{php(expectedCash)}</td>
                                </tr>
                                <tr>
                                    <td>Actual cash count</td>
                                    <td className="right">{actualCash !== null ? php(actualCashCount || actualCash) : '(shift not yet closed)'}</td>
                                </tr>
                                <tr
                                    className="variance-row"
                                    style={{ backgroundColor: variance !== null ? varColor : '' }}
                                >
                                    <td>VARIANCE (SHORT / OVER)</td>
                                    <td className="right">
                                        {variance !== null
                                            ? (variance === 0 ? php(0) : (variance > 0 ? '+' : '') + php(variance))
                                            : '—'}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        {/* Signature Lines */}
                        <div className="sig-row" style={{ padding: '0 0', marginTop: 20 }}>
                            <div className="sig-box">
                                <div className="sig-line"></div>
                                <div className="sig-label">Prepared by: Front Desk Officer</div>
                            </div>
                            <div className="sig-box">
                                <div className="sig-line"></div>
                                <div className="sig-label">Checked by: Duty Manager</div>
                            </div>
                        </div>
                    </div>

                    {/* ── RIGHT COLUMN – Cash Breakdown / Actual Tally ── */}
                    <div>
                        <table className="denom-table">
                            <thead>
                                <tr>
                                    <th>CASH BREAKDOWN / ACTUAL TALLY</th>
                                    <th className="right" style={{ width: 50 }}>QTY</th>
                                    <th className="right" style={{ width: 90 }}>AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody>
                                {DENOMINATIONS.filter(d => (closingDenoms[d.key] ?? 0) > 0 || denomRows.length === 0).map(d => {
                                    const qty = closingDenoms[d.key] ?? 0;
                                    const amt = denomValue(d.key, qty);
                                    if (!qty && denomRows.length > 0) return null;
                                    return (
                                        <tr key={d.key}>
                                            <td>{d.label}</td>
                                            <td className="right">{qty > 0 ? (qty >= 1000 ? '1 lot' : qty) : '—'}</td>
                                            <td className="right">{qty > 0 ? php(amt) : '—'}</td>
                                        </tr>
                                    );
                                })}

                                <tr className="total-row">
                                    <td colSpan={1}><strong>ACTUAL CASH COUNT</strong></td>
                                    <td></td>
                                    <td className="right">
                                        {denomRows.length > 0 ? php(actualCashCount) : (actualCash !== null ? php(actualCash) : '—')}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Page Footer */}
                <div className="page-footer">
                    <span>Pension House PMS • Daily Cash Tally &amp; Drawer Report</span>
                    <span>Printed: {date_printed} &nbsp;|&nbsp; Page 3 of 6 — Daily Cash Tally</span>
                </div>
            </div>

            <div className="page page-break">
                <div className="page2-banner">
                    <h1>MINI BAR SALES LOGBOOK</h1>
                    <p>Walk-in POS Sales and Room-Billed Mini Bar Charges</p>
                </div>
                <div className="subheader-row">
                    <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                    <span>Shift Period: {shiftPeriod} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Shift Operator: {cashierName}</span>
                </div>
                <div style={{ marginBottom: 14 }}>
                    <div className="section-title">WALK-IN MINI BAR / POS SALES</div>
                    <table className="tally-table">
                        <thead><tr><th>TIME</th><th>OR / REF NO.</th><th>METHOD</th><th>ITEMS SOLD</th><th className="right">CASH</th><th className="right">GCASH</th><th className="right">TOTAL</th></tr></thead>
                        <tbody>
                            {minibarPosSales.length === 0 ? (
                                <tr><td colSpan={7} style={{ color: '#888', fontStyle: 'italic', textAlign: 'center' }}>No walk-in mini bar sales recorded.</td></tr>
                            ) : minibarPosSales.map(sale => (
                                <tr key={sale.id}>
                                    <td>{formatTime(sale.created_at)}</td>
                                    <td>{sale.formatted_or_number || `POS-${sale.id}`}</td>
                                    <td>{sale.payment_method || 'Cash'}</td>
                                    <td>{sale.inventory_usages?.length ? sale.inventory_usages.map(usage => `${usage.quantity}x ${usage.item?.item_name || 'Item'}`).join(', ') : sale.description || 'Walk-in sale'}</td>
                                    <td className="right">{php(sale.cash_amount)}</td>
                                    <td className="right">{php(sale.gcash_amount)}</td>
                                    <td className="right">{php(sale.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div>
                    <div className="section-title">MINI BAR CHARGES BILLED TO STAY CHECKOUTS</div>
                    <table className="tally-table">
                        <thead><tr><th>TIME</th><th>ROOM</th><th>GUEST</th><th>ITEM CONSUMED</th><th className="right">UNIT PRICE</th><th>QTY</th><th className="right">TOTAL</th></tr></thead>
                        <tbody>
                            {minibarStayCharges.length === 0 ? (
                                <tr><td colSpan={7} style={{ color: '#888', fontStyle: 'italic', textAlign: 'center' }}>No mini bar charges billed to stays during this shift.</td></tr>
                            ) : minibarStayCharges.map(usage => (
                                <tr key={usage.id}>
                                    <td>{formatTime(usage.created_at)}</td>
                                    <td>{usage.booking?.room?.room_number || '—'}</td>
                                    <td>{usage.booking?.guest_name || 'Stay guest'}</td>
                                    <td>{usage.item?.item_name || 'Item'}</td>
                                    <td className="right">{php(usage.unit_price)}</td>
                                    <td>{usage.quantity}</td>
                                    <td className="right">{php(usage.total_price)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="page-footer"><span>Pension House PMS • Mini Bar Sales Logbook</span><span>Printed: {date_printed} &nbsp;|&nbsp; Page 4 of 6 — Mini Bar Sales</span></div>
            </div>

            <DrawerTallyPage
                title="MINI BAR DAILY CASH TALLY & DRAWER REPORT"
                salesLabel="Cash mini bar / POS sales"
                shiftPeriod={shiftPeriod}
                shiftCode={shiftCode}
                cashierName={cashierName}
                datePrinted={date_printed}
                cashTally={minibarData.cash_tally}
                pageNumber={5}
                pageCaption="Mini Bar Cash Tally"
            />

            <div className="page page-break">
                <div className="page2-banner">
                    <h1>CRITICAL STOCK WARNINGS</h1>
                    <p>Current inventory status as of printing</p>
                </div>
                <div className="subheader-row">
                    <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                    <span>Shift Period: {shiftPeriod} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Shift Operator: {cashierName}</span>
                </div>
                <table className="tally-table" style={{ fontSize: 11 }}>
                    <thead><tr><th>ITEM NAME</th><th className="right" style={{ width: 120 }}>LIMIT</th><th className="right" style={{ width: 120 }}>CURRENT</th><th style={{ width: 160 }}>STATUS</th></tr></thead>
                    <tbody>
                        {lowStock.length === 0 ? (
                            <tr><td colSpan={4} style={{ padding: 24, color: '#256029', textAlign: 'center', fontWeight: 700 }}>All inventory items have safe stock levels.</td></tr>
                        ) : lowStock.map(stock => (
                            <tr key={stock.id} style={{ color: '#c62828', fontWeight: 700 }}>
                                <td>{stock.item_name}</td><td className="right">{stock.minimum_stock}</td><td className="right">{stock.current_stock}</td><td style={{ textAlign: 'center' }}>LOW STOCK</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="page-footer"><span>Pension House PMS • Critical Stock Warnings</span><span>Printed: {date_printed} &nbsp;|&nbsp; Page 6 of 6 — Critical Stock Warnings</span></div>
            </div>

            {/* Print Button (screen only) */}
            <button className="print-btn no-print" onClick={() => window.print()}>
                🖨️ Print PDF
            </button>
        </>
    );
}
