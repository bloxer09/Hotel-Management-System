import React, { useState, useEffect, useRef } from 'react';
import { ChevronDown } from 'lucide-react';

export default function CustomSelect({ value, onChange, options, className = '', containerClassName = '' }) {
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const selectedOption = options.find(o => {
        const optValue = o.key !== undefined ? o.key : o.id;
        return optValue?.toString() === value?.toString();
    }) || options[0];

    const handleSelect = (val) => {
        onChange(val);
        setIsOpen(false);
    };

    return (
        <div ref={containerRef} className={`relative inline-block w-full text-left shrink-0 z-30 ${containerClassName}`}>
            {/* Trigger Button */}
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className={`w-full flex items-center justify-between bg-[#1e293b] hover:bg-[#334155]/60 text-slate-200 border border-[#334155] rounded-xl px-4 py-2.5 text-xs font-bold font-outfit shadow-md transition-all text-left ${className}`}
            >
                <span className="truncate uppercase font-bold text-slate-100">
                    {selectedOption?.label || selectedOption?.name || ''}
                </span>
                <ChevronDown size={14} className="text-slate-400 shrink-0 ml-2" />
            </button>

            {/* Dropdown Options Popup */}
            {isOpen && (
                <div className="absolute left-0 mt-2 w-full min-w-[200px] bg-[#0f172a] border border-[#334155] rounded-xl shadow-xl z-50 overflow-hidden py-1.5 animate-in fade-in slide-in-from-top-2 duration-150 max-h-60 overflow-y-auto">
                    {options.map((opt) => {
                        const optValue = opt.key !== undefined ? opt.key : opt.id;
                        const isSelected = optValue?.toString() === value?.toString();
                        return (
                            <button
                                key={optValue}
                                type="button"
                                onClick={() => handleSelect(optValue)}
                                className={`w-full text-left px-4 py-2.5 text-xs font-semibold transition-all ${
                                    isSelected 
                                        ? 'bg-brand-600 text-slate-50 font-bold' 
                                        : 'text-slate-300 hover:bg-[#1e293b] hover:text-slate-100'
                                }`}
                            >
                                {opt.label || opt.name}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
