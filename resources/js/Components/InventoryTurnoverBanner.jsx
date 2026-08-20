import React from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

export default function InventoryTurnoverBanner({ banner }) {
    if (!banner || !banner.message) {
        return null;
    }

    return (
        <div
            className="mb-4 rounded-2xl border border-amber-500/40 bg-amber-950/30 px-4 py-3.5 text-amber-100 shadow-lg print:hidden"
            role="status"
            data-testid="inventory-turnover-banner"
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-start gap-3 min-w-0">
                    <AlertTriangle size={20} className="mt-0.5 shrink-0 text-amber-400" />
                    <div className="min-w-0">
                        <div className="font-outfit text-sm font-extrabold uppercase tracking-wide text-amber-200">
                            {banner.title}
                        </div>
                        <p className="mt-1 text-xs leading-relaxed text-amber-100/85">
                            {banner.message}
                        </p>
                    </div>
                </div>
                {banner.href && (
                    <Link
                        href={banner.href}
                        className="inline-flex shrink-0 items-center justify-center rounded-lg border border-amber-500/40 bg-amber-900/40 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-amber-50 hover:border-amber-400 hover:bg-amber-800/50"
                    >
                        Open Turnover
                    </Link>
                )}
            </div>
        </div>
    );
}
