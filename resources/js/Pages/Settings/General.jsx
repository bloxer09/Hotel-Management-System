import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Sliders, Percent, Save, Hash } from 'lucide-react';
import { motion } from 'framer-motion';

export default function General({ settings }) {
    const { data, setData, post, processing, errors } = useForm({
        vat_enabled: settings.vat_enabled,
        vat_percent: settings.vat_percent,
        or_prefix: settings.or_prefix,
        or_sequence: settings.or_sequence,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('settings.general.update'));
    };

    return (
        <AuthenticatedLayout>
            <Head title="General Settings" />

            <div className="flex flex-col gap-8 max-w-4xl">
                {/* Header title */}
                <div>
                    <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                        General Settings
                    </h1>
                    <p className="text-sm text-slate-400 font-medium mt-1">Configure administrative configurations, base tax variables, and global transaction rules.</p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Receipt OR configurations card */}
                    <motion.div
                        initial={{ opacity: 0, y: 15 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.1 }}
                        className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl flex flex-col gap-6"
                    >
                        <div className="flex items-center gap-3 border-b border-[#334155] pb-4">
                            <div className="p-2.5 rounded-xl bg-indigo-500/10 text-indigo-400">
                                <Hash size={20} />
                            </div>
                            <div>
                                <h2 className="font-outfit font-bold text-slate-100">Official Receipt (OR) Numbering</h2>
                                <p className="text-xs text-slate-400">Configure sequential OR numbers printed on folios and payments.</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            {/* OR Prefix */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                    Receipt Prefix
                                </label>
                                <input
                                    type="text"
                                    value={data.or_prefix}
                                    onChange={e => setData('or_prefix', e.target.value)}
                                    placeholder="e.g. OR, IV, TX..."
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-3 focus:outline-none focus:border-brand-500 transition-all"
                                    required
                                />
                                {errors.or_prefix && <span className="text-[10px] text-red-400 font-semibold">{errors.or_prefix}</span>}
                            </div>

                            {/* OR Sequence counter */}
                            <div className="flex flex-col gap-1.5">
                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                    Next Receipt Number
                                </label>
                                <input
                                    type="number"
                                    value={data.or_sequence}
                                    onChange={e => setData('or_sequence', parseInt(e.target.value) || 1)}
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-3 focus:outline-none focus:border-brand-500 transition-all font-mono"
                                    required
                                />
                                <span className="text-[10px] text-slate-500">
                                    Next receipt printed will be labeled: <strong className="text-indigo-400 font-mono">{data.or_prefix}-{String(data.or_sequence).padStart(6, '0')}</strong>
                                </span>
                                {errors.or_sequence && <span className="text-[10px] text-red-400 font-semibold">{errors.or_sequence}</span>}
                            </div>
                        </div>
                    </motion.div>

                    {/* Action buttons */}
                    <div className="flex justify-end gap-3 pt-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 px-6 py-3 bg-brand-600 hover:bg-brand-500 disabled:opacity-50 text-slate-50 rounded-xl font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all"
                        >
                            <Save size={16} /> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
