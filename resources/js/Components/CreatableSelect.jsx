import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, Plus } from 'lucide-react';

function toTitleCase(value) {
    return String(value)
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase()
        .replace(/\b([a-z])/g, (letter) => letter.toUpperCase());
}

export default function CreatableSelect({
    value = '',
    onChange,
    options = [],
    placeholder = 'Select or type...',
    className = '',
    required = false,
}) {
    const containerRef = useRef(null);
    const inputRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(value || '');
    const [focusedIndex, setFocusedIndex] = useState(0);

    useEffect(() => {
        setQuery(value || '');
    }, [value]);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
                commitQuery(query);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [query, options]);

    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) {
            return options;
        }

        return options.filter((option) => option.name.toLowerCase().includes(needle));
    }, [options, query]);

    const normalizedQuery = toTitleCase(query);
    const hasExactMatch = options.some(
        (option) => option.name.toLowerCase() === normalizedQuery.toLowerCase()
    );
    const canCreate = normalizedQuery.length > 0 && !hasExactMatch;

    const visibleItems = canCreate
        ? [...filteredOptions, { id: '__create__', name: normalizedQuery, isCreate: true }]
        : filteredOptions;

    const commitQuery = (raw) => {
        const next = toTitleCase(raw);
        if (next !== (value || '')) {
            onChange(next);
        } else {
            setQuery(value || '');
        }
    };

    const handleSelect = (name) => {
        const next = toTitleCase(name);
        onChange(next);
        setQuery(next);
        setOpen(false);
    };

    const handleKeyDown = (event) => {
        if (!open && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            setOpen(true);
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setFocusedIndex((prev) => (visibleItems.length ? (prev + 1) % visibleItems.length : 0));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setFocusedIndex((prev) => (
                visibleItems.length ? (prev - 1 + visibleItems.length) % visibleItems.length : 0
            ));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (open && visibleItems[focusedIndex]) {
                handleSelect(visibleItems[focusedIndex].name);
            } else {
                commitQuery(query);
                setOpen(false);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setQuery(value || '');
            setOpen(false);
        }
    };

    useEffect(() => {
        setFocusedIndex(0);
    }, [query, open]);

    return (
        <div ref={containerRef} className={`relative w-full ${open ? 'z-[200]' : 'z-30'}`}>
            <div className="relative">
                <input
                    ref={inputRef}
                    type="text"
                    required={required}
                    value={query}
                    placeholder={placeholder}
                    autoComplete="off"
                    onFocus={() => setOpen(true)}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                    onKeyDown={handleKeyDown}
                    className={`w-full bg-[#1e293b] hover:bg-[#334155]/60 text-slate-200 border border-[#334155] rounded-xl px-4 py-2.5 pr-9 text-xs font-bold font-outfit shadow-md transition-all text-left ${className}`}
                />
                <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => {
                        setOpen((prev) => !prev);
                        inputRef.current?.focus();
                    }}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400"
                >
                    <ChevronDown size={14} />
                </button>
            </div>

            {open && (
                <div className="absolute z-50 mt-2 w-full rounded-xl shadow-xl bg-[#0f172a] border border-[#334155] overflow-hidden max-h-60 overflow-y-auto">
                    {visibleItems.length === 0 ? (
                        <div className="px-4 py-2.5 text-xs text-slate-500 font-semibold">
                            No categories found
                        </div>
                    ) : visibleItems.map((option, index) => {
                        const isFocused = index === focusedIndex;
                        const isSelected = !option.isCreate && option.name === value;

                        return (
                            <button
                                key={option.isCreate ? 'create' : option.id}
                                type="button"
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => handleSelect(option.name)}
                                className={`w-full text-left px-4 py-2.5 text-xs font-semibold transition-all flex items-center gap-2 ${
                                    isSelected
                                        ? 'bg-brand-600 text-slate-50 font-bold'
                                        : isFocused
                                            ? 'bg-[#1e293b] text-slate-100 ring-1 ring-inset ring-brand-500/50'
                                            : 'text-slate-300 hover:bg-[#1e293b] hover:text-slate-100'
                                }`}
                            >
                                {option.isCreate ? (
                                    <>
                                        <Plus size={12} className="shrink-0" />
                                        <span>Create “{option.name}”</span>
                                    </>
                                ) : (
                                    option.name
                                )}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
