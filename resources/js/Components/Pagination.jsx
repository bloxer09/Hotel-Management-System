import React from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export default function Pagination({ links, className = '' }) {
    if (!links || links.length <= 3) return null; // Only prev and next links means 1 page

    return (
        <div className={`flex flex-wrap items-center gap-1.5 ${className}`}>
            {links.map((link, idx) => {
                const isPrevious = link.label.includes('Previous');
                const isNext = link.label.includes('Next');
                let content = link.label;
                
                if (isPrevious) {
                    content = <ChevronLeft size={16} />;
                } else if (isNext) {
                    content = <ChevronRight size={16} />;
                }

                if (link.url === null) {
                    if (isPrevious || isNext) {
                        return (
                            <div
                                key={idx}
                                className={`min-w-[36px] min-h-[36px] px-3 py-2 rounded-lg text-xs font-semibold text-slate-500 bg-[#0f172a] border border-[#334155]/50 flex items-center justify-center opacity-50 cursor-not-allowed`}
                            >
                                {content}
                            </div>
                        );
                    }
                    return (
                        <div
                            key={idx}
                            className={`px-3 py-2 rounded-lg text-xs font-semibold text-slate-500 bg-[#0f172a] border border-[#334155]/50 flex items-center justify-center opacity-50 cursor-not-allowed`}
                            dangerouslySetInnerHTML={{ __html: content }}
                        />
                    );
                }

                const linkClasses = `min-w-[36px] min-h-[36px] px-3 py-2 rounded-lg text-xs font-semibold border flex items-center justify-center transition-colors ${
                    link.active
                        ? 'bg-brand-500 text-white border-brand-500 shadow-md shadow-brand-500/20'
                        : 'bg-[#1e293b] text-slate-300 border-[#334155] hover:bg-[#334155] hover:text-white'
                }`;

                if (isPrevious || isNext) {
                    return (
                        <Link
                            key={idx}
                            href={link.url}
                            className={linkClasses}
                            preserveState
                            preserveScroll
                        >
                            {content}
                        </Link>
                    );
                }

                return (
                    <Link
                        key={idx}
                        href={link.url}
                        className={linkClasses}
                        preserveState
                        preserveScroll
                        dangerouslySetInnerHTML={{ __html: content }}
                    />
                );
            })}
        </div>
    );
}
