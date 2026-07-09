import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage, router } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import {
    Plus,
    Download,
    FileText,
    Edit,
    Trash2,
    Search,
    Coins,
    X,
    RefreshCw
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import ActionModal from '@/Components/ActionModal';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';
import ConfirmModal from '@/Components/ConfirmModal';

export default function IncomesIndex({ incomes, filters, summary, sortBy, sortDir }) {
    const { auth } = usePage().props;
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [selectedIncome, setSelectedIncome] = useState(null);
    const [actionModalIncome, setActionModalIncome] = useState(null);
    const [confirmDeleteIncome, setConfirmDeleteIncome] = useState(null);
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [dateFrom, setDateFrom] = useState(filters.from || '');
    const [dateTo, setDateTo] = useState(filters.to || '');

    // Form states
    const [incomeDate, setIncomeDate] = useState('');
    const [amount, setAmount] = useState('');
    const [cashDrawer, setCashDrawer] = useState('room');
    const [notes, setNotes] = useState('');
    const [receipt, setReceipt] = useState(null);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('incomes.index'), { search: searchQuery, from: dateFrom, to: dateTo }, { preserveState: true });
    };

    const handleClearFilters = () => {
        setSearchQuery('');
        setDateFrom('');
        setDateTo('');
        router.get(route('incomes.index'), {}, { preserveState: true });
    };

    const handleExport = () => {
        let url = route('incomes.export');
        const params = new URLSearchParams();
        if (dateFrom) params.append('from', dateFrom);
        if (dateTo) params.append('to', dateTo);
        if (searchQuery) params.append('search', searchQuery);

        if (params.toString()) {
            url += '?' + params.toString();
        }
        window.location.href = url;
    };

    const openAddModal = () => {
        setIncomeDate(new Date().toISOString().split('T')[0]);
        setAmount('');
        setCashDrawer('room');
        setNotes('');
        setReceipt(null);
        setIsAddModalOpen(true);
    };

    const openEditModal = (inc) => {
        setSelectedIncome(inc);
        setIncomeDate(inc.income_date.split('T')[0]);
        setAmount(inc.amount);
        setCashDrawer(inc.cash_drawer || 'room');
        setNotes(inc.notes || '');
        setReceipt(null);
        setIsEditModalOpen(true);
    };

    const handleDelete = () => {
        if (confirmDeleteIncome) {
            router.delete(route('incomes.destroy', confirmDeleteIncome.id), { preserveScroll: true });
            setConfirmDeleteIncome(null);
        }
    };

    const submitAdd = (e) => {
        e.preventDefault();
        const formData = new FormData();
        formData.append('income_date', incomeDate);
        formData.append('amount', amount);
        formData.append('cash_drawer', cashDrawer);
        formData.append('notes', notes);
        if (receipt) formData.append('receipt', receipt);

        router.post(route('incomes.store'), formData, {
            onSuccess: () => {
                setIsAddModalOpen(false);
            },
            forceFormData: true
        });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        const formData = new FormData();
        formData.append('_method', 'post'); // Using post with _method for file uploads
        formData.append('income_date', incomeDate);
        formData.append('amount', amount);
        formData.append('cash_drawer', cashDrawer);
        formData.append('notes', notes);
        if (receipt) formData.append('receipt', receipt);

        router.post(route('incomes.update', selectedIncome.id), formData, {
            onSuccess: () => {
                setIsEditModalOpen(false);
            },
            forceFormData: true
        });
    };

    const handlePageChange = (url) => {
        if (url) router.get(url, {}, { preserveState: true, replace: true });
    };

    const inputCls = "w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 focus:outline-none focus:border-brand-500 text-xs";

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Additional Incomes" />

            <div className="flex flex-col gap-6">

                {/* Header */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">Additional Incomes</h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Manage extra cash entries, float injections, and miscellaneous sales.</p>
                    </div>
                    <div className="flex flex-col sm:flex-row gap-3 w-full sm:w-auto mt-2 sm:mt-0">
                        <button onClick={handleExport}
                            className="flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-slate-700/20 active:scale-95 shrink-0 w-full sm:w-auto">
                            <Download size={16} /> Export XLSX
                        </button>
                        <button onClick={openAddModal}
                            className="flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-outfit font-bold text-sm transition-all shadow-lg shadow-brand-600/20 active:scale-95 shrink-0 w-full sm:w-auto">
                            <Plus size={16} /> Record Income
                        </button>
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="bg-[#1e293b] border border-[#334155] p-5 rounded-2xl flex items-center shadow-lg">
                        <div className="p-3 rounded-xl bg-emerald-500/10 text-emerald-400 mr-4">
                            <Coins className="w-6 h-6" />
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Total Incomes Amount</p>
                            <h3 className="text-2xl font-bold font-mono text-slate-100">
                                ₱{Number(summary.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </h3>
                        </div>
                    </div>
                    <div className="bg-[#1e293b] border border-[#334155] p-5 rounded-2xl flex items-center shadow-lg">
                        <div className="p-3 rounded-xl bg-indigo-500/10 text-indigo-400 mr-4">
                            <FileText className="w-6 h-6" />
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Recorded Incomes</p>
                            <h3 className="text-2xl font-bold font-mono text-slate-100">{summary.total_count}</h3>
                        </div>
                    </div>
                </div>

                {/* Toolbar */}
                <form onSubmit={handleSearch} className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    <div className="flex flex-wrap items-center gap-2 bg-[#1e293b] p-1.5 rounded-xl border border-[#334155] w-full sm:w-auto">
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={e => setDateFrom(e.target.value)}
                            className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2 sm:px-3 py-1.5 focus:outline-none focus:border-brand-500 text-xs flex-1 sm:flex-none min-w-[100px]"
                        />
                        <span className="text-slate-500 text-[10px] font-bold px-1">TO</span>
                        <input
                            type="date"
                            value={dateTo}
                            onChange={e => setDateTo(e.target.value)}
                            className="bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 px-2 sm:px-3 py-1.5 focus:outline-none focus:border-brand-500 text-xs flex-1 sm:flex-none min-w-[100px]"
                        />
                        <button type="submit" className="px-3 py-1.5 bg-brand-600 hover:bg-brand-500 text-white font-bold text-xs rounded-lg transition-all ml-auto sm:ml-1">
                            Filter
                        </button>
                        {(searchQuery || dateFrom || dateTo) && (
                            <button type="button" onClick={handleClearFilters} className="px-3 py-1.5 text-slate-400 hover:text-white transition-all text-xs font-bold">
                                Clear
                            </button>
                        )}
                    </div>
                    <div className="flex items-center gap-2 w-full sm:w-auto">
                        <div className="relative w-full sm:w-64">
                            <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                            <input
                                type="text"
                                placeholder="Search notes..."
                                value={searchQuery}
                                onChange={e => setSearchQuery(e.target.value)}
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs"
                            />
                        </div>
                        <button type="button" onClick={() => router.reload({ only: ['incomes'] })} className="p-2.5 rounded-xl border border-[#334155] bg-[#1e293b] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 transition-all shrink-0 shadow-sm" title="Refresh Table">
                            <RefreshCw size={16} />
                        </button>
                    </div>
                </form>

                {/* Table */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs min-w-[850px]">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <SortableHeader sortKey="income_date" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Date</SortableHeader>
                                    <SortableHeader sortKey="notes" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Notes / Description</SortableHeader>
                                    <SortableHeader sortKey="cash_drawer" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Drawer</SortableHeader>
                                    <SortableHeader sortKey="amount" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Amount</SortableHeader>
                                    <SortableHeader sortKey="recorded_by" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Recorded By</SortableHeader>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-center">Receipt</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {incomes.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                                            No incomes found matching the criteria.
                                        </td>
                                    </tr>
                                ) : incomes.data.map((inc, i) => (
                                    <motion.tr key={inc.id} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors">
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-brand-400 font-bold block">{new Date(inc.income_date).toLocaleDateString()}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-semibold text-slate-200 whitespace-normal min-w-[200px] block">
                                                {inc.notes || <span className="text-slate-500 italic font-normal">No description</span>}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold border ${
                                                inc.cash_drawer === 'minibar' 
                                                    ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' 
                                                    : 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20'
                                            }`}>
                                                {inc.cash_drawer === 'minibar' ? 'Minibar' : 'Room'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-emerald-400 font-bold text-xs">
                                                ₱{Number(inc.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-slate-400 text-[11px]">{inc.user?.full_name || 'Unknown'}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {inc.receipt_path ? (
                                                <a href={`/storage/${inc.receipt_path}`} target="_blank" rel="noreferrer"
                                                    className="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 hover:bg-indigo-500/20 rounded-lg transition-all text-[10px] font-bold">
                                                    <FileText size={11} /> View
                                                </a>
                                            ) : (
                                                <span className="text-slate-600">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <button onClick={() => setActionModalIncome(inc)} className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] hover:bg-slate-800 border border-[#334155] rounded-lg text-[10px] font-bold text-slate-300 transition-colors">
                                                Manage
                                            </button>
                                        </td>
                                    </motion.tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {/* Pagination */}
                    {incomes && incomes.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                            <span className="text-[10px] text-slate-500">
                                Showing {incomes.from}–{incomes.to} of {incomes.total} records
                            </span>
                            <Pagination links={incomes.links} />
                        </div>
                    )}
                </div>
            </div>

            {/* Add Income Modal */}
            <AnimatePresence>
                {isAddModalOpen && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-10 px-4"
                        onClick={e => { if (e.target === e.currentTarget) setIsAddModalOpen(false); }}>
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }} transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-md bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden">

                            {/* Modal Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-brand-500/10 text-brand-400 rounded-xl"><Coins size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">Record Income</h2>
                                        <p className="text-[10px] text-slate-400">Save a new additional cash injection or miscellaneous sale</p>
                                    </div>
                                </div>
                                <button type="button" onClick={() => setIsAddModalOpen(false)} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            <form onSubmit={submitAdd} className="p-6">
                                <div className="flex flex-col gap-4">
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Date *</label>
                                        <input
                                            type="date"
                                            required
                                            value={incomeDate}
                                            onChange={e => setIncomeDate(e.target.value)}
                                            className={inputCls}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Amount (₱) *</label>
                                        <input
                                            type="number"
                                            required
                                            min="0.01"
                                            step="0.01"
                                            value={amount}
                                            onChange={e => setAmount(e.target.value)}
                                            className={`${inputCls} font-mono`}
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cash Drawer *</label>
                                        <select
                                            value={cashDrawer}
                                            onChange={e => setCashDrawer(e.target.value)}
                                            className={inputCls}
                                        >
                                            <option value="room">Room Cash Drawer</option>
                                            <option value="minibar">Minibar Cash Drawer</option>
                                        </select>
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Notes / Description</label>
                                        <textarea
                                            value={notes}
                                            onChange={e => setNotes(e.target.value)}
                                            rows="3"
                                            className={inputCls}
                                            placeholder="E.g., Additional Cash Float, Mini-store Miscellaneous Income..."
                                        ></textarea>
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Receipt / Reference (Optional)</label>
                                        <input
                                            type="file"
                                            accept=".jpg,.jpeg,.png,.pdf"
                                            onChange={e => setReceipt(e.target.files[0])}
                                            className="w-full text-xs text-slate-400 file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:uppercase file:bg-[#1e293b] file:text-slate-200 hover:file:bg-[#334155] transition-all"
                                        />
                                    </div>
                                </div>
                                <div className="mt-8 flex justify-end gap-3 pt-4 border-t border-[#334155]">
                                    <button type="button" onClick={() => setIsAddModalOpen(false)}
                                        className="px-4 py-2 bg-[#1e293b] hover:bg-[#334155] text-slate-300 font-bold text-xs rounded-xl transition-colors border border-[#334155]">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                        className="px-5 py-2 bg-brand-600 hover:bg-brand-500 text-white font-bold text-xs rounded-xl transition-all shadow-lg shadow-brand-500/20">
                                        Save Income
                                    </button>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Edit Income Modal */}
            <AnimatePresence>
                {isEditModalOpen && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-start justify-center bg-[#070b13]/90 overflow-y-auto py-10 px-4"
                        onClick={e => { if (e.target === e.currentTarget) setIsEditModalOpen(false); }}>
                        <motion.div
                            initial={{ opacity: 0, scale: 0.97, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 20 }} transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                            className="w-full max-w-md bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl overflow-hidden">

                            {/* Modal Header */}
                            <div className="flex items-center justify-between px-6 py-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-amber-500/10 text-amber-400 rounded-xl"><Edit size={18} /></div>
                                    <div>
                                        <h2 className="text-base font-outfit font-extrabold text-slate-100">Edit Income</h2>
                                        <p className="text-[10px] text-slate-400">Update additional income record details</p>
                                    </div>
                                </div>
                                <button type="button" onClick={() => setIsEditModalOpen(false)} className="p-2 rounded-xl text-slate-400 hover:text-slate-100 hover:bg-[#334155] transition-all">
                                    <X size={18} />
                                </button>
                            </div>

                            <form onSubmit={submitEdit} className="p-6">
                                <div className="flex flex-col gap-4">
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Date *</label>
                                        <input
                                            type="date"
                                            required
                                            value={incomeDate}
                                            onChange={e => setIncomeDate(e.target.value)}
                                            className={inputCls}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Amount (₱) *</label>
                                        <input
                                            type="number"
                                            required
                                            min="0.01"
                                            step="0.01"
                                            value={amount}
                                            onChange={e => setAmount(e.target.value)}
                                            className={`${inputCls} font-mono`}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cash Drawer *</label>
                                        <select
                                            value={cashDrawer}
                                            onChange={e => setCashDrawer(e.target.value)}
                                            className={inputCls}
                                        >
                                            <option value="room">Room Cash Drawer</option>
                                            <option value="minibar">Minibar Cash Drawer</option>
                                        </select>
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Notes / Description</label>
                                        <textarea
                                            value={notes}
                                            onChange={e => setNotes(e.target.value)}
                                            rows="3"
                                            className={inputCls}
                                        ></textarea>
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Update Receipt / Reference</label>
                                        {selectedIncome?.receipt_path && (
                                            <p className="text-[10px] text-brand-400 mb-1 font-semibold">Current attachment exists. Uploading a new one will replace it.</p>
                                        )}
                                        <input
                                            type="file"
                                            accept=".jpg,.jpeg,.png,.pdf"
                                            onChange={e => setReceipt(e.target.files[0])}
                                            className="w-full text-xs text-slate-400 file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:uppercase file:bg-[#1e293b] file:text-slate-200 hover:file:bg-[#334155] transition-all"
                                        />
                                    </div>
                                </div>
                                <div className="mt-8 flex justify-end gap-3 pt-4 border-t border-[#334155]">
                                    <button type="button" onClick={() => setIsEditModalOpen(false)}
                                        className="px-4 py-2 bg-[#1e293b] hover:bg-[#334155] text-slate-300 font-bold text-xs rounded-xl transition-colors border border-[#334155]">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                        className="px-5 py-2 bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs rounded-xl transition-all shadow-lg shadow-amber-500/20">
                                        Update
                                    </button>
                                </div>
                            </form>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            <ActionModal
                isOpen={!!actionModalIncome}
                onClose={() => setActionModalIncome(null)}
                title={`Manage Income`}
            >
                {actionModalIncome && (
                    <>
                        <button
                            onClick={() => { setActionModalIncome(null); openEditModal(actionModalIncome); }}
                            className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 rounded-xl text-xs font-bold text-amber-400 transition-colors uppercase"
                        >
                            <Edit size={16} /> Edit Income
                        </button>
                        <button
                            onClick={() => { setActionModalIncome(null); setConfirmDeleteIncome(actionModalIncome); }}
                            className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-rose-900/30 border border-[#334155] hover:border-rose-500/40 rounded-xl text-xs font-bold text-rose-400 transition-colors uppercase"
                        >
                            <Trash2 size={16} /> Delete Income
                        </button>
                    </>
                )}
            </ActionModal>

            <ConfirmModal
                isOpen={!!confirmDeleteIncome}
                onClose={() => setConfirmDeleteIncome(null)}
                onConfirm={handleDelete}
                title="Delete Income"
                message="Are you sure you want to delete this additional income entry?"
                confirmText="Delete"
                isDanger={true}
            />
        </AuthenticatedLayout>
    );
}
