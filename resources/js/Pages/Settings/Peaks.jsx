import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { CalendarDays, Plus, Trash2, Calendar, ShieldAlert, X, Settings2, Edit, Power, PowerOff } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import ConfirmModal from '@/Components/ConfirmModal';
import ActionModal from '@/Components/ActionModal';
import CustomSelect from '@/Components/CustomSelect';

export default function Peaks({ peakDates }) {
    const [isOpen, setIsOpen] = useState(false);
    const [editMode, setEditMode] = useState(false);
    const [editId, setEditId] = useState(null);

    const [actionModalItem, setActionModalItem] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);

    const form = useForm({
        date_from: '',
        date_to: '',
        label: '',
        surcharge_amount: 0,
        surcharge_type: 'fixed'
    });

    const openAddModal = () => {
        setEditMode(false);
        setEditId(null);
        form.reset();
        setIsOpen(true);
    };

    const openEditModal = (pd) => {
        setEditMode(true);
        setEditId(pd.id);
        form.setData({
            date_from: pd.date_from,
            date_to: pd.date_to,
            label: pd.label,
            surcharge_amount: pd.surcharge_amount,
            surcharge_type: pd.surcharge_type
        });
        setIsOpen(true);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        if (editMode) {
            form.put(route('settings.peaks.update', editId), {
                onSuccess: () => {
                    setIsOpen(false);
                    form.reset();
                }
            });
        } else {
            form.post(route('settings.peaks.store'), {
                onSuccess: () => {
                    setIsOpen(false);
                    form.reset();
                }
            });
        }
    };

    const handleToggle = (id) => {
        router.post(route('settings.peaks.toggle', id));
        setActionModalItem(null);
    };

    const handleDelete = () => {
        if (confirmDelete) {
            router.delete(route('settings.peaks.destroy', confirmDelete.id));
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Peak Dates Holiday Surcharges" />

            <div className="flex flex-col gap-8">
                {/* Header title */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Holiday & Peak Surcharges
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Specify high-demand calendar ranges to apply automatic surcharge premiums on overnight stays.</p>
                    </div>

                    <div className="w-full sm:w-auto mt-2 sm:mt-0">
                        <button
                            onClick={openAddModal}
                            className="flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all w-full sm:w-auto"
                        >
                            <Plus size={16} /> Schedule Peak Date
                        </button>
                    </div>
                </div>

                {/* Surcharge listing table */}
                <div className="rounded-2xl bg-[#1e293b] border border-[#334155] overflow-hidden shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs min-w-[850px]">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Surcharge Event Title</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Applies From</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Applies To</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Surcharge Rate</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Status</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {peakDates.length > 0 ? (
                                    peakDates.map((pd, i) => (
                                        <motion.tr
                                            key={pd.id}
                                            initial={{ opacity: 0, y: 6 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            transition={{ delay: i * 0.03 }}
                                            className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors"
                                        >
                                            <td className="px-4 py-3 font-outfit font-black text-slate-200">
                                                {pd.label}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-slate-300">
                                                {new Date(pd.date_from).toLocaleDateString(undefined, { dateStyle: 'medium' })}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-slate-300">
                                                {new Date(pd.date_to).toLocaleDateString(undefined, { dateStyle: 'medium' })}
                                            </td>
                                            <td className="px-4 py-3 font-mono font-bold text-brand-300">
                                                {pd.surcharge_type === 'percent' ? (
                                                    <span>+{pd.surcharge_amount}% base rate</span>
                                                ) : (
                                                    <span>+₱{Number(pd.surcharge_amount).toLocaleString()} fixed</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`px-3 py-1 text-[10px] uppercase font-bold rounded-lg ${pd.is_active
                                                        ? 'bg-emerald-950/65 border border-emerald-800 text-emerald-400'
                                                        : 'bg-slate-900 border border-slate-700 text-slate-500'
                                                        }`}
                                                >
                                                    {pd.is_active ? 'Active' : 'Disabled'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <button
                                                    onClick={() => setActionModalItem(pd)}
                                                    className="px-4 py-2 bg-[#0f172a] hover:bg-[#334155] border border-[#334155] rounded-xl text-slate-300 hover:text-white transition-all text-xs font-bold flex items-center gap-2 ml-auto"
                                                >
                                                    <Settings2 size={14} /> Manage
                                                </button>
                                            </td>
                                        </motion.tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="py-8 text-center text-slate-500">
                                            No peak dates schedules configured yet. Surcharges won't apply to bookings.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* MODAL: ADD / EDIT PEAK DATE RANGE */}
                <AnimatePresence>
                    {isOpen && (
                        <div className="fixed inset-0 z-[1000] flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-[#070b13]/90" onClick={() => setIsOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden flex flex-col max-h-[90vh]">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between shrink-0">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg">
                                        {editMode ? 'Edit Surcharge' : 'Configure New Surcharge'}
                                    </h2>
                                    <button onClick={() => setIsOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <div className="overflow-y-auto custom-scrollbar">
                                    <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                                        {/* Label Event */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Event Label / Surcharge Name</label>
                                            <input
                                                type="text"
                                                value={form.data.label}
                                                onChange={e => form.setData('label', e.target.value)}
                                                placeholder="e.g. Christmas Season, Holy Week Peak..."
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        {/* From date */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Start Date</label>
                                            <input
                                                type="date"
                                                value={form.data.date_from}
                                                onChange={e => form.setData('date_from', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        {/* To date */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">End Date</label>
                                            <input
                                                type="date"
                                                value={form.data.date_to}
                                                onChange={e => form.setData('date_to', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                            {form.errors.date_from && <span className="text-[10px] text-red-400 font-semibold">{form.errors.date_from}</span>}
                                            {form.errors.date_to && <span className="text-[10px] text-red-400 font-semibold">{form.errors.date_to}</span>}
                                        </div>

                                        {/* Surcharge Type Selection */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Surcharge Type</label>
                                            <CustomSelect
                                                value={form.data.surcharge_type}
                                                onChange={e => form.setData('surcharge_type', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            >
                                                <option value="fixed">Fixed Amount (₱)</option>
                                                <option value="percent">Percentage Surcharge (%)</option>
                                            </CustomSelect>
                                        </div>

                                        {/* Surcharge Rate */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                {form.data.surcharge_type === 'percent' ? 'Surcharge rate percentage (%)' : 'Surcharge amount (₱)'}
                                            </label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.surcharge_amount}
                                                onChange={e => form.setData('surcharge_amount', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3 shrink-0">
                                            <button type="button" onClick={() => setIsOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                            <button type="submit" disabled={form.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">
                                                {editMode ? 'Save Changes' : 'Add Schedule'}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                <ActionModal
                    isOpen={!!actionModalItem}
                    onClose={() => setActionModalItem(null)}
                    title={`Manage ${actionModalItem?.label}`}
                >
                    {actionModalItem && (
                        <>
                            <button
                                onClick={() => {
                                    handleToggle(actionModalItem.id);
                                }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-emerald-600/20 border border-[#334155] hover:border-emerald-500/40 rounded-xl text-xs font-bold text-emerald-400 transition-colors"
                            >
                                {actionModalItem.is_active ? <PowerOff size={16} /> : <Power size={16} />}
                                {actionModalItem.is_active ? 'Disable Surcharge' : 'Enable Surcharge'}
                            </button>
                            <button
                                onClick={() => {
                                    setActionModalItem(null);
                                    openEditModal(actionModalItem);
                                }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-amber-600/20 border border-[#334155] hover:border-amber-500/40 rounded-xl text-xs font-bold text-amber-400 transition-colors"
                            >
                                <Edit size={16} /> Edit Details
                            </button>
                            <button
                                onClick={() => {
                                    setConfirmDelete(actionModalItem);
                                    setActionModalItem(null);
                                }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-rose-900/30 border border-[#334155] hover:border-rose-500/40 rounded-xl text-xs font-bold text-rose-400 transition-colors"
                            >
                                <Trash2 size={16} /> Delete Surcharge
                            </button>
                        </>
                    )}
                </ActionModal>

                <ConfirmModal
                    isOpen={!!confirmDelete}
                    onClose={() => setConfirmDelete(null)}
                    onConfirm={handleDelete}
                    title="Delete Surcharge"
                    message={`Are you sure you want to delete the peak date surcharge '${confirmDelete?.label}'?`}
                    confirmText="Delete"
                    isDanger={true}
                />

            </div>
        </AuthenticatedLayout>
    );
}
