import React from 'react';

export function Card({ children, className = '', ...props }) {
    return (
        <div
            className={`bg-slate-900 border border-slate-800 rounded-2xl shadow-sm overflow-hidden ${className}`}
            {...props}
        >
            {children}
        </div>
    );
}

export function CardHeader({ children, className = '', ...props }) {
    return (
        <div
            className={`px-6 py-4 border-b border-slate-800 flex items-center justify-between ${className}`}
            {...props}
        >
            {children}
        </div>
    );
}

export function CardBody({ children, className = '', ...props }) {
    return (
        <div className={`p-6 ${className}`} {...props}>
            {children}
        </div>
    );
}

export function CardFooter({ children, className = '', ...props }) {
    return (
        <div
            className={`px-6 py-4 bg-slate-900/50 border-t border-slate-800 flex items-center justify-end gap-3 ${className}`}
            {...props}
        >
            {children}
        </div>
    );
}
