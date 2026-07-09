import React, { useState, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { Search, Plus, ShoppingCart, ReceiptText, Printer, Download } from 'lucide-react';
import { motion } from 'framer-motion';
import PosModal from './PosModal';
import PosReceiptModal from '@/Components/PosReceiptModal';

const FILTER_TABS = [
    { key: 'all', label: 'All Sales', color: 'text-brand-400', dot: 'bg-brand-400' },
    { key: 'cash', label: 'Cash', color: 'text-emerald-400', dot: 'bg-emerald-400' },
    { key: 'gcash', label: 'GCash', color: 'text-indigo-400', dot: 'bg-indigo-400' },
    { key: 'bank_transfer', label: 'Bank Transfer', color: 'text-amber-400', dot: 'bg-amber-400' },
    { key: 'split', label: 'Split Payment', color: 'text-purple-400', dot: 'bg-purple-400' },
];

export default function Index({ items = [], activeBookings = [], transactions = [] }) {
    const [currentFilter, setCurrentFilter] = useState('all');
    const [searchTerm, setSearchTerm] = useState('');
    const [showPosModal, setShowPosModal] = useState(false);
    const [selectedTransaction, setSelectedTransaction] = useState(null);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

    const { flash } = usePage().props;

    // Handle newly created transaction
    React.useEffect(() => {
        if (flash?.new_pos_txn_id) {
            setShowPosModal(false);
            const newTxn = transactions.find(t => t.id === flash.new_pos_txn_id);
            if (newTxn) {
                setSelectedTransaction(newTxn);
            }
        }
    }, [flash?.new_pos_txn_id, transactions]);

    // Filtering Logic
    const filteredTransactions = useMemo(() => {
        return transactions.filter(txn => {
            // Apply Tab Filter
            if (currentFilter !== 'all' && txn.payment_method !== currentFilter) {
                return false;
            }
            // Apply Search
            if (searchTerm) {
                const searchLower = searchTerm.toLowerCase();
                const matchOR = txn.or_number && String(txn.or_number).toLowerCase().includes(searchLower);
                const matchDesc = txn.description && txn.description.toLowerCase().includes(searchLower);
                const matchProcess = txn.processed_by?.name && txn.processed_by.name.toLowerCase().includes(searchLower);
                if (!matchOR && !matchDesc && !matchProcess) return false;
            }
            // Apply Date Filter
            if (dateFrom) {
                const dateStr = new Date(txn.created_at).toISOString().split('T')[0];
                if (dateStr < dateFrom) return false;
            }
            if (dateTo) {
                const dateStr = new Date(txn.created_at).toISOString().split('T')[0];
                if (dateStr > dateTo) return false;
            }
            return true;
        });
    }, [transactions, currentFilter, searchTerm, dateFrom, dateTo]);

    const handleExport = () => {
        let url = route('pos.export');
        const params = new URLSearchParams();
        if (dateFrom) params.append('from', dateFrom);
        if (dateTo) params.append('to', dateTo);
        if (params.toString()) {
            url += '?' + params.toString();
        }
        window.location.href = url;
    };

    return (
        <AuthenticatedLayout>
            <Head title="POS" />

            <div className="flex flex-col gap-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">POS</h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Record walk-in purchases, charge room stays, and view history.</p>
                    </div>

                    <div className="flex flex-col sm:flex-row gap-3 w-full sm:w-auto mt-2 sm:mt-0">
                        <button onClick={handleExport}
                            className="flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-[#334155] hover:bg-slate-600 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-slate-700/20 active:scale-95 shrink-0 w-full sm:w-auto">
                            <Download size={16} /> Export Sold Items
                        </button>
                        <button onClick={() => setShowPosModal(true)}
                            className="flex items-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-brand-600/20 active:scale-95 shrink-0 w-full sm:w-auto justify-center">
                            <Plus size={16} /> New Sale
                        </button>
                    </div>
                </div>

                {/* Tabs + Search */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155] overflow-x-auto mobile-scroll-tabs">
                        {FILTER_TABS.map(tab => (
                            <button key={tab.key} onClick={() => setCurrentFilter(tab.key)}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${currentFilter === tab.key ? 'bg-[#0f172a] text-slate-100 shadow' : 'text-slate-400 hover:text-slate-200'
                                    }`}>
                                <span className={`w-1.5 h-1.5 rounded-full ${tab.dot} ${currentFilter === tab.key ? 'opacity-100' : 'opacity-40'}`} />
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                        <div className="flex items-center gap-2 bg-[#1e293b] p-1 rounded-xl border border-[#334155]">
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={e => setDateFrom(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2 py-1 focus:outline-none focus:border-brand-500 text-xs w-[120px]"
                            />
                            <span className="text-slate-500 text-[10px] font-bold">TO</span>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={e => setDateTo(e.target.value)}
                                className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2 py-1 focus:outline-none focus:border-brand-500 text-xs w-[120px]"
                            />
                            {(dateFrom || dateTo) && (
                                <button type="button" onClick={() => { setDateFrom(''); setDateTo(''); }} className="px-2 text-slate-400 hover:text-white text-xs font-bold">
                                    Clear
                                </button>
                            )}
                        </div>
                        <div className="relative flex-1 sm:flex-initial">
                            <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                            <input type="text" value={searchTerm} onChange={e => setSearchTerm(e.target.value)}
                                placeholder="Search OR#, details..."
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs" />
                        </div>
                    </div>
                </div>

                {/* Table Layout similar to Bookings */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    {['OR Number', 'Details', 'Date', 'Payment', 'Amount', 'Actions'].map((h, i) => (
                                        <th key={h} className={`px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider ${i === 5 ? 'text-right' : 'text-left'}`}>{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {filteredTransactions.length === 0 ? (
                                    <tr><td colSpan={6} className="px-4 py-12 text-center text-slate-500">
                                        {searchTerm ? `No results for "${searchTerm}"` : `No sales found.`}
                                    </td></tr>
                                ) : filteredTransactions.map((txn, i) => (
                                    <motion.tr key={txn.id} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">

                                        {/* OR Number */}
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-brand-400 font-bold block">
                                                {txn.or_number ? `OR-${txn.or_number}` : 'N/A'}
                                            </span>
                                            <span className="text-[9px] bg-[#0f172a] text-slate-500 px-1.5 py-0.5 rounded font-bold uppercase block mt-1 w-fit border border-[#334155]">
                                                TXN ID: {txn.id}
                                            </span>
                                        </td>

                                        {/* Details */}
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1.5 max-w-[200px]">
                                                <span className="font-semibold text-slate-200 truncate">{txn.description}</span>
                                            </div>
                                        </td>

                                        {/* Date */}
                                        <td className="px-4 py-3 leading-normal">
                                            <div className="flex items-center gap-1 text-slate-400 font-bold text-[10px]">
                                                <span className="font-mono text-slate-300">
                                                    {new Date(txn.created_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}
                                                </span>
                                            </div>
                                        </td>

                                        {/* Payment */}
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-black uppercase ${txn.payment_method === 'cash' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' :
                                                txn.payment_method === 'gcash' ? 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' :
                                                    txn.payment_method === 'bank_transfer' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' :
                                                        'bg-purple-500/10 text-purple-400 border border-purple-500/20'
                                                }`}>
                                                {txn.payment_method?.replace('_', ' ') || 'N/A'}
                                            </span>
                                        </td>

                                        {/* Amount */}
                                        <td className="px-4 py-3">
                                            <div className="flex flex-col gap-0.5">
                                                <span className="font-mono text-emerald-400 font-bold">
                                                    ₱{Number(txn.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                            </div>
                                        </td>

                                        {/* Actions */}
                                        <td className="px-4 py-3 text-right">
                                            <div className="inline-flex items-center gap-1.5">
                                                <button onClick={() => setSelectedTransaction(txn)}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-[#0f172a] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 text-slate-400 hover:text-brand-400 transition-all text-[10px] font-bold cursor-pointer">
                                                    <Printer size={11} /> Print Receipt
                                                </button>
                                            </div>
                                        </td>
                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <PosModal
                isOpen={showPosModal}
                onClose={() => setShowPosModal(false)}
                items={items}
                activeBookings={activeBookings}
            />

            <PosReceiptModal
                isOpen={!!selectedTransaction}
                transaction={selectedTransaction}
                onClose={() => setSelectedTransaction(null)}
            />
        </AuthenticatedLayout>
    );
}