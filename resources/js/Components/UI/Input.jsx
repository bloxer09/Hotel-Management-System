import React, { forwardRef, useRef, useEffect, useImperativeHandle } from 'react';

const Input = forwardRef(function Input(
    { label, error, hint, icon: Icon, className = '', id, isFocused = false, as: Tag = 'input', ...props },
    ref
) {
    const inputId = id || props.name;
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <div className="w-full">
            {label && (
                <label
                    htmlFor={inputId}
                    className="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider"
                >
                    {label}
                </label>
            )}

            <div className="relative">
                {Icon && (
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                        <Icon className="w-4 h-4" />
                    </div>
                )}

                <Tag
                    ref={localRef}
                    id={inputId}
                    className={`w-full px-3.5 py-2 bg-slate-950 border rounded-lg text-sm text-slate-100 placeholder-slate-500 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 disabled:opacity-50 disabled:bg-slate-900 ${
                        Icon ? 'pl-9' : ''
                    } ${
                        error
                            ? 'border-rose-500 focus:ring-rose-500'
                            : 'border-slate-800 hover:border-slate-700'
                    } ${className}`}
                    {...props}
                />
            </div>

            {hint && !error && (
                <p className="mt-1 text-xs text-slate-400">{hint}</p>
            )}

            {error && (
                <p className="mt-1 text-xs text-rose-400 font-medium">{error}</p>
            )}
        </div>
    );
});

export default Input;
