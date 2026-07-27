import React from 'react';
import { ChevronDown } from 'lucide-react';
import Dropdown from '@/Components/Dropdown';

function CustomSelectInner({ value, onChange, options, selectOptions, selectedOption, className, open, setOpen, handleSelect }) {
    const [focusedIndex, setFocusedIndex] = React.useState(-1);

    React.useEffect(() => {
        if (open) {
            const idx = selectOptions.findIndex(o => {
                const optValue = o.key !== undefined ? o.key : o.id;
                return optValue?.toString() === value?.toString();
            });
            setFocusedIndex(idx >= 0 ? idx : 0);
        } else {
            setFocusedIndex(-1);
        }
    }, [open, value, selectOptions]);

    const handleKeyDown = (e) => {
        if (!open) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                setOpen(true);
            }
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setFocusedIndex(prev => (prev + 1) % selectOptions.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setFocusedIndex(prev => (prev - 1 + selectOptions.length) % selectOptions.length);
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (focusedIndex >= 0 && focusedIndex < selectOptions.length) {
                const opt = selectOptions[focusedIndex];
                const optValue = opt.key !== undefined ? opt.key : opt.id;
                handleSelect(optValue, setOpen);
            }
        } else if (e.key === 'Escape' || e.key === 'Tab') {
            setOpen(false);
        }
    };

    return (
        <>
            <Dropdown.Trigger>
                <button
                    type="button"
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    onKeyDown={handleKeyDown}
                    className={`w-full flex items-center justify-between bg-[#1e293b] hover:bg-[#334155]/60 text-slate-200 border border-[#334155] rounded-xl px-4 py-2.5 text-xs font-bold font-outfit shadow-md transition-all text-left ${className}`}
                >
                    <span className="truncate font-bold text-slate-100">
                        {selectedOption?.label || selectedOption?.name || ''}
                    </span>
                    <ChevronDown size={14} className="text-slate-400 shrink-0 ml-2" />
                </button>
            </Dropdown.Trigger>

            <Dropdown.Content
                align="left"
                width="full"
                contentClasses="py-1.5 bg-[#0f172a] border border-[#334155] rounded-xl shadow-xl overflow-hidden max-h-60 overflow-y-auto"
            >
                <div role="listbox" onKeyDown={handleKeyDown}>
                    {selectOptions.map((opt, idx) => {
                        const optValue = opt.key !== undefined ? opt.key : opt.id;
                        const isSelected = optValue?.toString() === value?.toString();
                        const isFocused = idx === focusedIndex;
                        return (
                            <button
                                key={optValue}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                onClick={() => handleSelect(optValue, setOpen)}
                                className={`w-full text-left px-4 py-2.5 text-xs font-semibold transition-all ${
                                    isSelected 
                                        ? 'bg-brand-600 text-slate-50 font-bold' 
                                        : isFocused 
                                            ? 'bg-[#1e293b] text-slate-100 ring-1 ring-inset ring-brand-500/50' 
                                            : 'text-slate-300 hover:bg-[#1e293b] hover:text-slate-100'
                                }`}
                            >
                                {opt.label || opt.name}
                            </button>
                        );
                    })}
                </div>
            </Dropdown.Content>
        </>
    );
}

export default function CustomSelect({ value, onChange, options, children, className = '', containerClassName = '', elevateWhenOpen = false }) {
    // Resolve options from either the options prop or children option tags
    let resolvedOptions = options;
    if (!resolvedOptions && children) {
        const list = [];
        const parseChildren = (node) => {
            React.Children.forEach(node, child => {
                if (!child) return;
                if (child.type === 'option') {
                    list.push({
                        key: child.props.value !== undefined ? child.props.value : '',
                        label: child.props.children
                    });
                } else if (child.props && child.props.children) {
                    parseChildren(child.props.children);
                }
            });
        };
        parseChildren(children);
        resolvedOptions = list;
    }

    const selectOptions = resolvedOptions || [];

    const selectedOption = selectOptions.find(o => {
        const optValue = o.key !== undefined ? o.key : o.id;
        return optValue?.toString() === value?.toString();
    }) || selectOptions[0];

    const handleSelect = (val, setOpen) => {
        if (options) {
            // Options array pattern: pass raw value directly
            onChange(val);
        } else {
            // HTML children pattern: pass a simulated event object
            onChange({ target: { value: val } });
        }
        setOpen(false);
    };

    return (
        <Dropdown>
            {({ open, setOpen }) => (
                <div className={`relative inline-block w-full text-left shrink-0 ${elevateWhenOpen && open ? 'z-[200]' : 'z-30'} ${containerClassName}`}>
                    <CustomSelectInner
                        value={value}
                        onChange={onChange}
                        options={options}
                        selectOptions={selectOptions}
                        selectedOption={selectedOption}
                        className={className}
                        open={open}
                        setOpen={setOpen}
                        handleSelect={handleSelect}
                    />
                </div>
            )}
        </Dropdown>
    );
}
