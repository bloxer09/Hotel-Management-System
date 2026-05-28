import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { Ticket, Plus, Trash2, Calendar, Edit3, X, Eye, BadgePercent, Check, AlertCircle } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function PromoCodes({ promoCodes }) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingPromo, setEditingPromo] = useState(null);

    const form = useForm({
        code: '',
        label: '',
        discount_type: 'percent',
        discount_value: 0,
        max_uses: '',
        expires_at: '',
        is_active: true
    });

    const handleOpenAdd = () => {
        setEditingPromo(null);
        form.reset();
        form.clearErrors();
        setIsOpen(true);
    };

    const handleOpenEdit = (promo) => {
        setEditingPromo(promo);
        form.setData({
            code: promo.code,
            label: promo.label,
            discount_type: promo.discount_type,
            discount_value: promo.discount_value,
            max_uses: promo.max_uses ?? '',
            expires_at: promo.expires_at ? promo.expires_at.split('T')[0] : '',
            is_active: promo.is_active === 1 || promo.is_active === true
        });
        form.clearErrors();
        setIsOpen(true);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();

        // Format payload
        const payload = {
            ...form.data,
            expires_at: form.data.expires_at || null,
            max_uses: form.data.max_uses === '' ? null : parseInt(form.data.max_uses),
            is_active: form.data.is_active ? 1 : 0
        };

        if (editingPromo) {
            router.put(route('settings.promo_codes.update', editingPromo.id), payload, {
                onSuccess: () => {
                    setIsOpen(false);
                    form.reset();
                }
            });
        } else {
            router.post(route('settings.promo_codes.store'), payload, {
                onSuccess: () => {
                    setIsOpen(false);
                    form.reset();
                }
            });
        }
    };

    const handleDelete = (promo) => {
        if (confirm(`Are you absolutely sure you want to delete promo code '${promo.code}'?`)) {
            router.delete(route('settings.promo_codes.destroy', promo.id));
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Promo & Discount Codes Management" />

            <div className="flex flex-col gap-8">
                {/* Header Section */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Promo & Discount Codes
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Manage transactional promo codes, marketing discount vouchers, and specialized VIP incentives.</p>
                    </div>

                    <button
                        onClick={handleOpenAdd}
                        className="inline-flex items-center gap-2 px-5 py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all self-start"
                    >
                        <Plus size={16} /> Add Promo Code
                    </button>
                </div>

                {/* Table Listing */}
                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr className="border-b border-[#334155] text-slate-400 uppercase tracking-wider font-semibold">
                                    <th className="pb-3">Code / Label</th>
                                    <th className="pb-3">Discount Rate</th>
                                    <th className="pb-3">Redemption Limit</th>
                                    <th className="pb-3">Expiry Date</th>
                                    <th className="pb-3">Created By</th>
                                    <th className="pb-3">Status</th>
                                    <th className="pb-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#334155]/60 text-slate-300">
                                {promoCodes.length > 0 ? (
                                    promoCodes.map((promo) => {
                                        const isExpired = promo.expires_at && new Date(promo.expires_at) < new Date();
                                        const isLimitReached = promo.max_uses !== null && promo.used_count >= promo.max_uses;
                                        const isValid = promo.is_active && !isExpired && !isLimitReached;

                                        return (
                                            <tr key={promo.id} className="hover:bg-[#0f172a]/20 transition-colors">
                                                <td className="py-4">
                                                    <div className="flex flex-col gap-0.5">
                                                        <span className="font-outfit font-black text-slate-100 text-sm tracking-wider uppercase">
                                                            {promo.code}
                                                        </span>
                                                        <span className="text-slate-400 font-medium text-[10px]">
                                                            {promo.label}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="py-4 font-mono font-bold text-emerald-400">
                                                    {promo.discount_type === 'percent' ? (
                                                        <span className="flex items-center gap-1">
                                                            <BadgePercent size={14} className="text-emerald-400" />
                                                            {promo.discount_value}% Off
                                                        </span>
                                                    ) : (
                                                        <span>
                                                            ₱{Number(promo.discount_value).toLocaleString()} Off
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-4 font-mono">
                                                    <div className="flex flex-col">
                                                        <span>{promo.used_count} used</span>
                                                        <span className="text-[10px] text-slate-500">
                                                            Limit: {promo.max_uses ?? 'Unlimited'}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="py-4 font-mono">
                                                    {promo.expires_at ? (
                                                        <span className={isExpired ? 'text-red-400 font-semibold' : 'text-slate-300'}>
                                                            {new Date(promo.expires_at).toLocaleDateString(undefined, { dateStyle: 'medium' })}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-500 font-medium">Never Expires</span>
                                                    )}
                                                </td>
                                                <td className="py-4 font-medium text-slate-400">
                                                    {promo.creator?.name || 'Admin'}
                                                </td>
                                                <td className="py-4">
                                                    <span className={`inline-flex items-center gap-1 px-2.5 py-1 text-[10px] uppercase font-bold rounded-lg border ${isValid
                                                            ? 'bg-emerald-950/40 border-emerald-800 text-emerald-400'
                                                            : 'bg-red-950/40 border-red-800 text-red-400'
                                                        }`}>
                                                        {isValid ? (
                                                            <>
                                                                <Check size={10} /> Active
                                                            </>
                                                        ) : (
                                                            <>
                                                                <AlertCircle size={10} /> Invalid
                                                            </>
                                                        )}
                                                    </span>
                                                    {!promo.is_active && <span className="block text-[9px] text-slate-500 mt-0.5">Manually Disabled</span>}
                                                    {isExpired && <span className="block text-[9px] text-red-400 mt-0.5 font-bold">Expired</span>}
                                                    {isLimitReached && <span className="block text-[9px] text-red-400 mt-0.5 font-bold">Max Uses Reached</span>}
                                                </td>
                                                <td className="py-4 text-right">
                                                    <div className="inline-flex gap-2">
                                                        <button
                                                            onClick={() => handleOpenEdit(promo)}
                                                            className="p-2 bg-indigo-950/20 border border-indigo-900/30 hover:bg-indigo-900/30 text-indigo-400 hover:text-indigo-300 rounded-xl transition-all"
                                                            title="Edit Details"
                                                        >
                                                            <Edit3 size={14} />
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(promo)}
                                                            className="p-2 bg-red-950/20 border border-red-900/30 hover:bg-red-900/30 text-red-400 hover:text-red-300 rounded-xl transition-all"
                                                            title="Delete Promo"
                                                        >
                                                            <Trash2 size={14} />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan="7" className="py-8 text-center text-slate-500">
                                            No promo codes configured yet. Click "Add Promo Code" to create one.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* MODAL: ADD / EDIT PROMO CODE */}
                <AnimatePresence>
                    {isOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 0.5 }}
                                exit={{ opacity: 0 }}
                                className="fixed inset-0 bg-black"
                                onClick={() => setIsOpen(false)}
                            />

                            <motion.div
                                initial={{ opacity: 0, scale: 0.95 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.95 }}
                                className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden"
                            >
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg flex items-center gap-2">
                                        <Ticket size={20} className="text-brand-400" />
                                        {editingPromo ? 'Edit Promo Code' : 'Create Promo Code'}
                                    </h2>
                                    <button onClick={() => setIsOpen(false)} className="text-slate-400 hover:text-slate-100">
                                        <X size={18} />
                                    </button>
                                </div>

                                <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                                    {/* Code string */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                            Code Identifier
                                        </label>
                                        <input
                                            type="text"
                                            value={form.data.code}
                                            onChange={e => form.setData('code', e.target.value.toUpperCase().replace(/\s+/g, ''))}
                                            placeholder="e.g. OFF50, SUMMER26..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 uppercase tracking-widest"
                                            required
                                            disabled={!!editingPromo}
                                        />
                                        {form.errors.code && <span className="text-[10px] text-red-400 font-semibold">{form.errors.code}</span>}
                                    </div>

                                    {/* Label description */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                            Campaign Description / Label
                                        </label>
                                        <input
                                            type="text"
                                            value={form.data.label}
                                            onChange={e => form.setData('label', e.target.value)}
                                            placeholder="e.g. 50% Off Summer Promo"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                        {form.errors.label && <span className="text-[10px] text-red-400 font-semibold">{form.errors.label}</span>}
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        {/* Surcharge Type Selection */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                Discount Mode
                                            </label>
                                            <select
                                                value={form.data.discount_type}
                                                onChange={e => form.setData('discount_type', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            >
                                                <option value="percent">Percentage (%)</option>
                                                <option value="fixed">Fixed Cash (₱)</option>
                                            </select>
                                        </div>

                                        {/* Surcharge Rate */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                Discount Value
                                            </label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.discount_value}
                                                onChange={e => form.setData('discount_value', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                            {form.errors.discount_value && <span className="text-[10px] text-red-400 font-semibold">{form.errors.discount_value}</span>}
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        {/* Max Uses */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                Redemption Limit
                                            </label>
                                            <input
                                                type="number"
                                                value={form.data.max_uses}
                                                onChange={e => form.setData('max_uses', e.target.value)}
                                                placeholder="Unlimited"
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 font-mono"
                                            />
                                            {form.errors.max_uses && <span className="text-[10px] text-red-400 font-semibold">{form.errors.max_uses}</span>}
                                        </div>

                                        {/* Expiry Date */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                Expiry Date
                                            </label>
                                            <input
                                                type="date"
                                                value={form.data.expires_at}
                                                onChange={e => form.setData('expires_at', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 font-mono"
                                            />
                                            {form.errors.expires_at && <span className="text-[10px] text-red-400 font-semibold">{form.errors.expires_at}</span>}
                                        </div>
                                    </div>

                                    {/* Toggle Active state */}
                                    <div className="flex flex-col gap-2 pt-2">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                            Campaign Active State
                                        </label>
                                        <div className="flex items-center">
                                            <label className="relative inline-flex items-center cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.is_active}
                                                    onChange={e => form.setData('is_active', e.target.checked)}
                                                    className="sr-only peer"
                                                />
                                                <div className="w-9 h-5 bg-[#0f172a] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-300 after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-brand-600"></div>
                                                <span className="ml-3 text-xs font-bold text-slate-300">
                                                    {form.data.is_active ? 'Active & Redeemable' : 'Disabled / Suspended'}
                                                </span>
                                            </label>
                                        </div>
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button
                                            type="button"
                                            onClick={() => setIsOpen(false)}
                                            className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md disabled:opacity-50"
                                        >
                                            {editingPromo ? 'Save Changes' : 'Create Promo'}
                                        </button>
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
