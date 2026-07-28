import React, { useEffect } from 'react';
import { Head } from '@inertiajs/react';

export default function RoomBookingsLedgerPrint({ shift, bookings, stay_collections, date_printed }) {
    useEffect(() => {
        // Automatically trigger print dialog on load
        window.print();
    }, []);

    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(amount);
    };

    const formatDate = (dateString) => {
        if (!dateString) return '';
        const date = new Date(dateString);
        return new Intl.DateTimeFormat('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: '2-digit',
        }).format(date);
    };

    const formatTime = (dateString) => {
        if (!dateString) return '';
        const date = new Date(dateString);
        return new Intl.DateTimeFormat('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        }).format(date);
    };

    const shiftDate = shift.started_at ? formatDate(shift.started_at) : '';
    const shiftCode = shift.shift_code ? shift.shift_code.toUpperCase() : '';
    const cashierName = shift.user?.full_name || '';

    // Calculate totals
    const cashTotal = stay_collections.cash || 0;
    const gcashTotal = stay_collections.gcash || 0;
    const verifiedRoomSales = cashTotal + gcashTotal;

    return (
        <>
            <Head title={`Room Bookings Ledger - Shift ${shift.id}`} />
            
            <style>
                {`
                @media print {
                    @page {
                        size: A4 landscape;
                        margin: 10mm;
                    }
                    body {
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                        background-color: white !important;
                    }
                    .no-print {
                        display: none !important;
                    }
                }
                `}
            </style>

            <div className="bg-white text-gray-900 font-sans p-4 text-[11px] leading-tight min-h-screen">
                {/* Header */}
                <div className="flex justify-between items-start mb-2">
                    <div>
                        <div className="flex items-center gap-2">
                            <div>
                                <div className="font-bold text-xs">LARAVEL</div>
                                <h1 className="font-bold text-base tracking-wide uppercase">II. ROOM BOOKINGS LEDGER (LOG BOOK)</h1>
                            </div>
                        </div>
                    </div>
                    <div className="text-right">
                        <div><span className="font-semibold">Date:</span> {shiftDate}</div>
                        <div><span className="font-semibold">Shift:</span> {shiftCode}</div>
                        <div><span className="font-semibold">Cashier:</span> {cashierName}</div>
                        <div><span className="font-semibold">Prepared By:</span> {cashierName}</div>
                        <div><span className="font-semibold">Sheet:</span> 1</div>
                    </div>
                </div>

                {/* Subtitle/Note */}
                <div className="mb-2 border-b-2 border-blue-200 pb-1">
                    <p className="text-gray-600 italic text-[10px]">Room sales include only stays checked in or checked out during this shift. Future reservation deposits are excluded.</p>
                </div>

                {/* Main Table */}
                <table className="w-full border-collapse border border-slate-300 text-center mb-6">
                    <thead>
                        <tr className="bg-[#EBF1F5] text-slate-700 font-bold border-b-2 border-blue-200">
                            <th className="border border-slate-300 px-2 py-3 w-12">ROOM<br/>NO.</th>
                            <th className="border border-slate-300 px-2 py-3 w-20">DATE IN</th>
                            <th className="border border-slate-300 px-2 py-3 w-20">TIME IN</th>
                            <th className="border border-slate-300 px-2 py-3 w-20">DATE OUT</th>
                            <th className="border border-slate-300 px-2 py-3 w-20">TIME OUT</th>
                            <th className="border border-slate-300 px-2 py-3 w-16">HRS</th>
                            <th className="border border-slate-300 px-2 py-3 w-24">ROOM RATE</th>
                            <th className="border border-slate-300 px-2 py-3 w-24">PAID THIS<br/>SHIFT</th>
                            <th className="border border-slate-300 px-2 py-3 w-24">BALANCE<br/>DUE</th>
                            <th className="border border-slate-300 px-2 py-3 w-20">THIS<br/>SHIFT<br/>MOP</th>
                            <th className="border border-slate-300 px-2 py-3">GUEST NAME</th>
                            <th className="border border-slate-300 px-2 py-3 w-28">CONTACT</th>
                            <th className="border border-slate-300 px-2 py-3 w-20">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        {bookings.map((b, i) => {
                            // Formatting details
                            let roomRateLabel = "";
                            if (b.booking_type === 'short_time') {
                                roomRateLabel = `${formatCurrency(b.base_amount)}\n/ ${b.short_time_hours}h rate`;
                            } else if (b.booking_type === 'hourly') {
                                roomRateLabel = `${formatCurrency(b.base_amount)}\n/ hr rate`;
                            } else {
                                roomRateLabel = `${formatCurrency(b.base_amount)}\n/ night`;
                            }
                            
                            const hrsNights = b.booking_type === 'overnight'
                                ? `${b.num_nights} NTS`
                                : `${b.short_time_hours || b.num_nights} HRS`;

                            const paidThisShift = b.shift_collection_amount || 0;
                            const balanceDue = b.balance_amount || 0;
                            
                            let status = "UNPAID";
                            if (balanceDue <= 0 && b.paid_amount > 0) {
                                status = "FULLY PAID";
                            } else if (b.paid_amount > 0) {
                                status = "PARTIAL";
                            } else if (b.status === "active" && balanceDue <= 0 && b.paid_amount == 0 && b.total_amount == 0) {
                                status = "FULLY PAID";
                            }

                            // MOP logic
                            let mopArr = [];
                            if (b.shift_collection_methods) {
                                Object.keys(b.shift_collection_methods).forEach(method => {
                                    if (b.shift_collection_methods[method] > 0) {
                                        let methodStr = method.toUpperCase();
                                        if (b.shift_collection_references && b.shift_collection_references[method]) {
                                            b.shift_collection_references[method].forEach(ref => {
                                                methodStr += `\nREF:\n${ref}`;
                                            });
                                        }
                                        mopArr.push(methodStr);
                                    }
                                });
                            }
                            const mopDisplay = mopArr.join("\\n") || "-";

                            return (
                                <tr key={b.id} className={`hover:bg-slate-50 ${i % 2 === 0 ? 'bg-slate-50' : 'bg-white'}`}>
                                    <td className="border border-slate-300 px-2 py-2 font-bold">{b.room?.room_number || ''}</td>
                                    <td className="border border-slate-300 px-2 py-2">{formatDate(b.check_in)}</td>
                                    <td className="border border-slate-300 px-2 py-2">{formatTime(b.check_in)}</td>
                                    <td className="border border-slate-300 px-2 py-2">{formatDate(b.check_out || b.expected_check_out)}</td>
                                    <td className="border border-slate-300 px-2 py-2">{formatTime(b.check_out || b.expected_check_out)}</td>
                                    <td className="border border-slate-300 px-2 py-2 font-semibold">{hrsNights}</td>
                                    <td className="border border-slate-300 px-2 py-2 whitespace-pre-line text-[10px] font-bold">
                                        {roomRateLabel}
                                    </td>
                                    <td className="border border-slate-300 px-2 py-2 font-bold text-gray-900">{formatCurrency(paidThisShift)}</td>
                                    <td className="border border-slate-300 px-2 py-2 font-bold text-gray-900">{formatCurrency(balanceDue)}</td>
                                    <td className="border border-slate-300 px-2 py-2 whitespace-pre-line text-[10px] font-semibold">{mopDisplay}</td>
                                    <td className="border border-slate-300 px-2 py-2 text-left font-semibold uppercase truncate max-w-[150px]">{b.guest_name}</td>
                                    <td className="border border-slate-300 px-2 py-2">{b.guest_contact || ''}</td>
                                    <td className="border border-slate-300 px-2 py-2 font-bold uppercase">{status}</td>
                                </tr>
                            );
                        })}
                        {bookings.length === 0 && (
                            <tr>
                                <td colSpan="13" className="border border-slate-300 px-2 py-8 text-gray-500 italic">No room bookings were checked in or out during this shift.</td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {/* Footer Totals */}
                <div className="flex justify-end gap-4 mb-8">
                    <div className="border-2 border-blue-300 rounded-md px-4 py-2 bg-white text-sm font-semibold shadow-sm">
                        Room Sales Cash: <span className="font-bold">{formatCurrency(cashTotal)}</span>
                    </div>
                    <div className="border-2 border-blue-300 rounded-md px-4 py-2 bg-white text-sm font-semibold shadow-sm">
                        Room Sales GCash: <span className="font-bold">{formatCurrency(gcashTotal)}</span>
                    </div>
                    <div className="border-2 border-slate-400 rounded-md px-4 py-2 bg-white text-sm font-bold shadow-sm">
                        Verified Room Sales: {formatCurrency(verifiedRoomSales)}
                    </div>
                </div>

                {/* Print Footer */}
                <div className="flex justify-between items-center text-[9px] text-gray-400 border-t border-gray-200 pt-2 mt-auto">
                    <div>Uptown Pension House PMS • Stays Ledger (Log Book Format)</div>
                    <div>Printed: {date_printed}</div>
                </div>

                {/* Action Button for preview mode */}
                <div className="fixed bottom-4 right-4 no-print">
                    <button 
                        onClick={() => window.print()} 
                        className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded shadow-lg"
                    >
                        Print PDF
                    </button>
                </div>
            </div>
        </>
    );
}
