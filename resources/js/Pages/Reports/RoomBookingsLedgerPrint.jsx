import React, { useEffect } from 'react';
import { Head } from '@inertiajs/react';

const DENOMINATIONS = [
    { key: '1000', label: 'P 1,000' },
    { key: '500',  label: 'P 500' },
    { key: '200',  label: 'P 200' },
    { key: '100',  label: 'P 100' },
    { key: '50',   label: 'P 50' },
    { key: '20',   label: 'P 20' },
    { key: '10',   label: 'P 10 / Coins' },
    { key: '5',    label: 'P 5' },
    { key: '1',    label: 'P 1' },
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

const denomValue = (key, qty) => {
    const vals = { '1000': 1000, '500': 500, '200': 200, '100': 100, '50': 50, '20': 20, '10': 10, '5': 5, '1': 1, '0.25': 0.25, '0.05': 0.05, '0.01': 0.01 };
    return (vals[key] || 0) * (qty || 0);
};

export default function RoomBookingsLedgerPrint({
    shift,
    bookings,
    stay_collections,
    date_printed,
    cash_tally,
    totals,
}) {
    useEffect(() => { window.print(); }, []);

    const shiftDate   = shift.started_at ? formatDate(shift.started_at) : '—';
    const shiftCode   = shift.shift_code ? shift.shift_code.toUpperCase() : '—';
    const cashierName = shift.user?.full_name || '—';

    // Page 1 footer totals
    const totalRoomSales    = totals?.total_room_sales    ?? 0;
    const cashCollection    = totals?.cash_collection     ?? 0;
    const digitalPayment    = totals?.digital_payment     ?? 0;
    const outstandingBalance = totals?.outstanding_balance ?? 0;

    // Page 2 - cash tally
    const ct = cash_tally || {};
    const openingCash        = ct.opening_cash        ?? 0;
    const roomSalesCash      = ct.room_sales_cash      ?? 0;
    const otherCashReceipts  = ct.other_cash_receipts  ?? 0;
    const totalCashAvailable = ct.total_cash_available ?? 0;
    const expenses           = ct.expenses             ?? [];
    const cashMovements      = ct.cash_movements       ?? [];
    const totalExpenses      = ct.total_expenses       ?? 0;
    const totalMovements     = ct.total_movements      ?? 0;
    const expectedCash       = ct.expected_cash        ?? 0;
    const actualCash         = ct.actual_cash;
    const variance           = ct.variance;
    const closingDenoms      = ct.closing_denominations ?? {};

    // Compute actual cash count from closing denominations
    const denomRows = DENOMINATIONS.map(d => ({
        ...d,
        qty:    closingDenoms[d.key] ?? 0,
        amount: denomValue(d.key, closingDenoms[d.key] ?? 0),
    })).filter(d => d.qty > 0);

    const actualCashCount = denomRows.reduce((s, d) => s + d.amount, 0);

    // All expense + movement rows for the deductions table
    const deductionRows = [
        ...expenses.map(e => ({ label: e.notes || e.category || 'Expense', amount: e.amount, remark: e.description || '' })),
        ...cashMovements.map(m => ({ label: m.movement_type === 'cashier_transfer' ? 'Cashier Transfer' : 'Cash Withdrawal', amount: m.amount, remark: m.description || '' })),
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
                    @page { size: A4 landscape; margin: 8mm 10mm; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .no-print { display: none !important; }
                    .page-break { page-break-before: always; }
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
                    <span>Report Date: {shiftDate} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Cashier: {cashierName}</span>
                </div>

                {/* Main Logbook Table */}
                <table className="ledger-table">
                    <thead>
                        <tr>
                            <th style={{ width: 60 }}>DATE</th>
                            <th style={{ width: 62 }}>TIME<br/>IN</th>
                            <th style={{ width: 72 }}>TIME<br/>OUT</th>
                            <th style={{ width: 52 }}>ROOM<br/>NO.</th>
                            <th style={{ width: 55 }}>NO. OF<br/>NIGHTS</th>
                            <th style={{ width: 72 }}>RATE</th>
                            <th style={{ width: 80 }}>TOTAL<br/>AMOUNT</th>
                            <th style={{ width: 150 }}>PAYMENT /<br/>LAST PAYMENT</th>
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

                            const rate = b.base_amount;
                            const rateLabel = isOvernight
                                ? `P ${Number(rate).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                                : `P ${Number(rate).toLocaleString('en-PH', { minimumFractionDigits: 2 })} / hr`;

                            const totalAmount = b.total_amount ?? 0;
                            const paidThisShift = b.shift_collection_amount ?? 0;
                            const balance = b.balance_amount ?? 0;

                            // Build payment label
                            let paymentLabel = '—';
                            const methods = b.shift_collection_methods || {};
                            const methodNames = Object.keys(methods).filter(k => methods[k] > 0);
                            if (methodNames.length > 0) {
                                const mopStr = methodNames.map(m => m === 'gcash' ? 'GCash' : m === 'bank_transfer' ? 'Bank Transfer' : m.charAt(0).toUpperCase() + m.slice(1)).join(' + ');
                                if (balance <= 0) {
                                    paymentLabel = `${mopStr} - PAID`;
                                } else {
                                    paymentLabel = `${mopStr} - P ${Number(paidThisShift).toLocaleString('en-PH', { minimumFractionDigits: 2 })}\nbalance P ${Number(balance).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
                                }
                            } else if (b.status === 'active' && totalAmount === 0) {
                                paymentLabel = 'COMPLIMENTARY';
                            } else if (balance <= 0 && totalAmount > 0) {
                                paymentLabel = 'PAID';
                            } else {
                                paymentLabel = `Balance: P ${Number(balance).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
                            }

                            return (
                                <tr key={b.id}>
                                    <td>{formatDate(b.check_in)}</td>
                                    <td>{formatTime(b.check_in)}</td>
                                    <td style={{ whiteSpace: 'pre-line' }}>
                                        {formatDate(b.check_out || b.expected_check_out)}{'\n'}{formatTime(b.check_out || b.expected_check_out)}
                                    </td>
                                    <td className="td-bold">{b.room?.room_number || '—'}</td>
                                    <td>{hrsLabel}</td>
                                    <td className="td-bold">{rateLabel}</td>
                                    <td className="td-bold">P {Number(totalAmount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                                    <td className="td-left" style={{ whiteSpace: 'pre-line', fontSize: 10 }}>{paymentLabel}</td>
                                    <td className="td-left td-bold" style={{ textTransform: 'uppercase' }}>{b.guest_name}</td>
                                    <td>{b.guest_contact || '—'}</td>
                                </tr>
                            );
                        })}
                        {bookings.length === 0 && (
                            <tr>
                                <td colSpan={10} style={{ padding: '24px', color: '#888', fontStyle: 'italic' }}>
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
                        <div className="label">Cash Collection</div>
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
                    <span>Printed: {date_printed} &nbsp;|&nbsp; Page 1 of 2</span>
                </div>
            </div>

            {/* ═══════════════════════════════════════════════════ PAGE 2 ══ */}
            <div className="page page-break">
                {/* Banner */}
                <div className="page2-banner">
                    <h1>DAILY CASH TALLY &amp; DRAWER REPORT</h1>
                    <p>Cash Collection Summary and Reconciliation</p>
                </div>

                {/* Sub-header */}
                <div className="subheader-row">
                    <span style={{ fontWeight: 700, fontSize: 11 }}>PENSION HOUSE DAILY OPERATIONS REPORT</span>
                    <span>Report Date: {shiftDate} &nbsp;|&nbsp; Shift: {shiftCode} &nbsp;|&nbsp; Cashier: {cashierName}</span>
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
                                    <td>Cash check-ins / room collections</td>
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
                                {denomRows.length === 0 && DENOMINATIONS.map(d => (
                                    <tr key={d.key}>
                                        <td>{d.label}</td>
                                        <td className="right">—</td>
                                        <td className="right">—</td>
                                    </tr>
                                ))}
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
                    <span>Printed: {date_printed} &nbsp;|&nbsp; Page 2 of 2</span>
                </div>
            </div>

            {/* Print Button (screen only) */}
            <button className="print-btn no-print" onClick={() => window.print()}>
                🖨️ Print PDF
            </button>
        </>
    );
}
