import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';

const DEFAULT_DENOMINATIONS = {
    c_0_01: 0,
    c_0_05: 0,
    c_0_25: 0,
    c_1: 0,
    c_5: 0,
    c_10: 0,
    c_20: 0,
    b_20: 0,
    b_50: 0,
    b_100: 0,
    b_200: 0,
    b_500: 0,
    b_1000: 0,
};

const COIN_TYPES = [
    { key: 'c_0_01', label: '1¢', value: 0.01 },
    { key: 'c_0_05', label: '5¢', value: 0.05 },
    { key: 'c_0_25', label: '25¢', value: 0.25 },
    { key: 'c_1', label: '₱1', value: 1.00 },
    { key: 'c_5', label: '₱5', value: 5.00 },
    { key: 'c_10', label: '₱10', value: 10.00 },
    { key: 'c_20', label: '₱20', value: 20.00 },
];

const BILL_TYPES = [
    { key: 'b_20', label: '₱20', value: 20.00 },
    { key: 'b_50', label: '₱50', value: 50.00 },
    { key: 'b_100', label: '₱100', value: 100.00 },
    { key: 'b_200', label: '₱200', value: 200.00 },
    { key: 'b_500', label: '₱500', value: 500.00 },
    { key: 'b_1000', label: '₱1000', value: 1000.00 },
];

export default function DenominationCounter({ initialDenominations, onChange }) {
    const [counts, setCounts] = useState(initialDenominations || DEFAULT_DENOMINATIONS);

    const calculateTotal = (currentCounts) => {
        let total = 0;
        [...COIN_TYPES, ...BILL_TYPES].forEach(item => {
            total += (currentCounts[item.key] || 0) * item.value;
        });
        return total;
    };

    const handleCountChange = (key, value) => {
        const val = Math.max(0, parseInt(value) || 0);
        const newCounts = { ...counts, [key]: val };
        setCounts(newCounts);
        if (onChange) onChange(newCounts, calculateTotal(newCounts));
    };

    useEffect(() => {
        if (initialDenominations) {
            setCounts(initialDenominations);
        }
    }, [initialDenominations]);

    const renderInput = (item, isBill) => (
        <div key={item.key} className="flex flex-col gap-1.5 p-3 bg-[#0f172a]/50 border border-[#334155]/40 rounded-xl hover:border-[#334155] transition-colors">
            <div className="flex justify-between items-center mb-1">
                <span className={`text-xs font-bold ${isBill ? 'text-emerald-400' : 'text-slate-300'}`}>{item.label}</span>
                <span className="text-[10px] text-slate-500 font-mono font-medium">
                    = ₱{((counts[item.key] || 0) * item.value).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </span>
            </div>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    tabIndex="-1"
                    onClick={() => handleCountChange(item.key, (counts[item.key] || 0) - 1)}
                    className="w-8 h-8 flex items-center justify-center rounded-lg bg-[#1e293b] hover:bg-[#334155] text-slate-300 transition-colors"
                >
                    -
                </button>
                <input
                    type="number"
                    min="0"
                    value={counts[item.key] || ''}
                    onChange={(e) => handleCountChange(item.key, e.target.value)}
                    placeholder="0"
                    className="flex-1 min-w-0 bg-[#0f172a] border border-[#334155] rounded-lg text-slate-100 text-center py-1.5 focus:outline-none focus:border-brand-500 text-sm font-bold placeholder-slate-600 appearance-none"
                    style={{ WebkitAppearance: 'none', MozAppearance: 'textfield' }}
                />
                <button
                    type="button"
                    tabIndex="-1"
                    onClick={() => handleCountChange(item.key, (counts[item.key] || 0) + 1)}
                    className="w-8 h-8 flex items-center justify-center rounded-lg bg-[#1e293b] hover:bg-[#334155] text-slate-300 transition-colors"
                >
                    +
                </button>
            </div>
        </div>
    );

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-3">
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                    <span className="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Paper Bills
                </h4>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    {BILL_TYPES.map(item => renderInput(item, true))}
                </div>
            </div>

            <div className="flex flex-col gap-3">
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                    <span className="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                    Coins
                </h4>
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    {COIN_TYPES.map(item => renderInput(item, false))}
                </div>
            </div>

            <div className="mt-2 p-4 bg-brand-500/10 border border-brand-500/30 rounded-xl flex justify-between items-center">
                <span className="text-sm font-bold text-slate-300 uppercase tracking-wider">Total Cash Drawer</span>
                <span className="text-2xl font-mono font-black text-brand-300">
                    ₱{calculateTotal(counts).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                </span>
            </div>
        </div>
    );
}
