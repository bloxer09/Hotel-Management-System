import React from 'react';

const VARIANTS = {
    vacant: 'bg-emerald-950/60 text-emerald-300 border-emerald-500/40',
    active: 'bg-emerald-950/60 text-emerald-300 border-emerald-500/40',
    checked_in: 'bg-emerald-950/60 text-emerald-300 border-emerald-500/40',
    occupied: 'bg-rose-950/60 text-rose-300 border-rose-500/40',
    cleaning: 'bg-amber-950/60 text-amber-300 border-amber-500/40',
    out_of_order: 'bg-slate-800 text-slate-300 border-slate-700',
    reserved: 'bg-indigo-950/60 text-indigo-300 border-indigo-500/40',
    pending: 'bg-indigo-950/60 text-indigo-300 border-indigo-500/40',
    checked_out: 'bg-slate-800 text-slate-300 border-slate-700',
    completed: 'bg-slate-800 text-slate-300 border-slate-700',
    cancelled: 'bg-rose-950/60 text-rose-300 border-rose-500/40',
    no_show: 'bg-amber-950/60 text-amber-400 border-amber-500/40',
    brand: 'bg-brand-950/60 text-brand-300 border-brand-500/40',
};

export default function Badge({ variant = 'brand', children, dot = true, className = '' }) {
    const variantStyle = VARIANTS[variant] || VARIANTS.brand;

    return (
        <span
            className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold border ${variantStyle} ${className}`}
        >
            {dot && <span className="w-1.5 h-1.5 rounded-full bg-current opacity-80" />}
            {children}
        </span>
    );
}
