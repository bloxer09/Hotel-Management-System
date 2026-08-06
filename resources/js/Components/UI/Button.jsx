import React from 'react';
import { Loader2 } from 'lucide-react';

const VARIANTS = {
    primary: 'bg-brand-600 hover:bg-brand-500 text-white shadow-sm border border-brand-500/30 focus-visible:ring-brand-500',
    emerald: 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm border border-emerald-500/30 focus-visible:ring-emerald-500',
    amber: 'bg-amber-600 hover:bg-amber-500 text-white shadow-sm border border-amber-500/30 focus-visible:ring-amber-500',
    danger: 'bg-rose-600 hover:bg-rose-500 text-white shadow-sm border border-rose-500/30 focus-visible:ring-rose-500',
    secondary: 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 focus-visible:ring-slate-400',
    outline: 'bg-transparent hover:bg-slate-800/60 text-slate-300 border border-slate-700 focus-visible:ring-slate-400',
    ghost: 'bg-transparent hover:bg-slate-800/50 text-slate-400 hover:text-slate-200 focus-visible:ring-slate-400',
};

const SIZES = {
    sm: 'px-2.5 py-1.5 text-xs rounded-md',
    md: 'px-3.5 py-2 text-sm rounded-lg',
    lg: 'px-5 py-2.5 text-base rounded-xl',
};

export default function Button({
    children,
    type = 'button',
    variant = 'primary',
    size = 'md',
    isLoading = false,
    disabled = false,
    icon: Icon = null,
    className = '',
    ...props
}) {
    const variantStyle = VARIANTS[variant] || VARIANTS.primary;
    const sizeStyle = SIZES[size] || SIZES.md;

    return (
        <button
            type={type}
            disabled={disabled || isLoading}
            className={`inline-flex items-center justify-center font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none ${variantStyle} ${sizeStyle} ${className}`}
            {...props}
        >
            {isLoading ? (
                <Loader2 className="w-4 h-4 mr-2 animate-spin shrink-0" />
            ) : Icon ? (
                <Icon className="w-4 h-4 mr-2 shrink-0" />
            ) : null}
            {children}
        </button>
    );
}
