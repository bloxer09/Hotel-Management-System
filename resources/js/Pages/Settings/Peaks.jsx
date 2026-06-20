import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { CalendarDays, Plus, Trash2, Calendar, ShieldAlert, X } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function Peaks({ peakDates }) {
    const [isOpen, setIsOpen] = useState(false);

    const form = useForm({
        date_from: '',
        date_to: '',
        label: '',
        surcharge_amount: 0,
        surcharge_type: 'fixed'
    });

    const handleFormSubmit = (e) => {
        e.preventDefault();
        form.post(route('settings.peaks.store'), {
            onSuccess: () => {
                setIsOpen(false);
                form.reset();
            }
        });
    };

    const handleToggle = (id) => {
        router.post(route('settings.peaks.toggle', id));
    };

    const handleDelete = (id, label) => {
        if (confirm(`Are you sure you want to delete the peak date surcharge '${label}'?`)) {
            router.delete(route('settings.peaks.destroy', id));
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Peak Dates Holiday Surcharges" />

            <div className="flex flex-col gap-8">

                {/* Header title */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Holiday & Peak Surcharges
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Specify high-demand calendar ranges to apply automatic surcharge premiums on overnight stays.</p>
                    </div>

                    <button
                        onClick={() => setIsOpen(true)}
                        className="inline-flex items-center gap-2 px-5 py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all self-start"
                    >
                        <Plus size={16} /> Schedule Peak Date
                    </button>
                </div>

                {/* Surcharge listing table */}
                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-xs table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] text-slate-400 uppercase tracking-wider font-semibold">
                                    <th className="pb-3">Surcharge Event Title</th>
                                    <th className="pb-3">Applies From</th>
                                    <th className="pb-3">Applies To</th>
                                    <th className="pb-3">Surcharge Rate</th>
                                    <th className="pb-3">Status</th>
                                    <th className="pb-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#334155]/60 text-slate-300">
                                {peakDates.length > 0 ? (
                                    peakDates.map((pd) => (
                                        <tr key={pd.id} className="hover:bg-[#0f172a]/20 transition-colors">
                                            <td className="py-4 font-outfit font-black text-slate-200">
                                                {pd.label}
                                            </td>
                                            <td className="py-4 font-mono">
                                                {new Date(pd.date_from).toLocaleDateString(undefined, { dateStyle: 'medium' })}
                                            </td>
                                            <td className="py-4 font-mono">
                                                {new Date(pd.date_to).toLocaleDateString(undefined, { dateStyle: 'medium' })}
                                            </td>
                                            <td className="py-4 font-mono font-bold text-brand-300">
                                                {pd.surcharge_type === 'percent' ? (
                                                    <span>+{pd.surcharge_amount}% base rate</span>
                                                ) : (
                                                    <span>+₱{Number(pd.surcharge_amount).toLocaleString()} fixed</span>
                                                )}
                                            </td>
                                            <td className="py-4">
                                                <button
                                                    onClick={() => handleToggle(pd.id)}
                                                    className={`px-3 py-1 text-[10px] uppercase font-bold rounded-lg transition-all ${pd.is_active
                                                            ? 'bg-emerald-950/65 border border-emerald-800 text-emerald-400'
                                                            : 'bg-slate-900 border border-slate-700 text-slate-500'
                                                        }`}
                                                >
                                                    {pd.is_active ? 'Active' : 'Disabled'}
                                                </button>
                                            </td>
                                            <td className="py-4 text-right">
                                                <button
                                                    onClick={() => handleDelete(pd.id, pd.label)}
                                                    className="p-2 bg-red-950/20 border border-red-900/30 hover:bg-red-900/30 text-red-400 hover:text-red-300 rounded-xl transition-all"
                                                    title="Delete Schedule"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                            </td>
                                        </tr>
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

                {/* MODAL: ADD PEAK DATE RANGE */}
                <AnimatePresence>
                    {isOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg">Configure New Surcharge</h2>
                                    <button onClick={() => setIsOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

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
                                        {form.errors.date_to && <span className="text-[10px] text-red-400 font-semibold">{form.errors.date_to}</span>}
                                    </div>

                                    {/* Surcharge Type Selection */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Surcharge Type</label>
                                        <select
                                            value={form.data.surcharge_type}
                                            onChange={e => form.setData('surcharge_type', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                        >
                                            <option value="fixed">Fixed Amount (₱)</option>
                                            <option value="percent">Percentage Surcharge (%)</option>
                                        </select>
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

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={form.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Add Schedule</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

            </div>
        </AuthenticatedLayout>
    );
}
