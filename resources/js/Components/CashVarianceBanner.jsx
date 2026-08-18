import React from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { formatPHP } from '@/Utils/currency';
import { formatUtcToManila } from '@/Utils/datetime';

function statusLabel(status) {
    return String(status || '')
        .replaceAll('_', ' ')
        .trim();
}

function money(amount) {
    return formatPHP(Math.abs(Number(amount) || 0));
}

export default function CashVarianceBanner({ banner }) {
    if (!banner || !banner.count) {
        return null;
    }

    const isSingle = banner.count === 1 && banner.shift;
    const shift = banner.shift;
    const title = isSingle
        ? 'Pending Cash Variance'
        : `${banner.count} Unresolved Cash Variances`;

    return (
        <div
            className="mb-4 rounded-2xl border border-amber-500/40 bg-amber-950/30 px-4 py-3.5 text-amber-100 shadow-lg print:hidden"
            role="status"
            data-testid="cash-variance-banner"
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-start gap-3 min-w-0">
                    <AlertTriangle size={20} className="mt-0.5 shrink-0 text-amber-400" />
                    <div className="min-w-0">
                        <div className="font-outfit text-sm font-extrabold uppercase tracking-wide text-amber-200">
                            {title}
                        </div>
                        <p className="mt-1 text-xs leading-relaxed text-amber-100/85">
                            {isSingle
                                ? 'You have an unresolved cash variance from a previous shift.'
                                : 'You have cash variances from previous shifts that still require review.'}
                        </p>

                        {isSingle ? (
                            <div className="mt-2.5 space-y-1.5 text-xs text-amber-50/90">
                                <div className="font-semibold text-amber-100">
                                    Shift #{shift.shift_id}
                                    {shift.shift_code ? ` • ${String(shift.shift_code).toUpperCase()}` : ''}
                                    {shift.closed_at_display || shift.closed_at
                                        ? ` • ${shift.closed_at_display || formatUtcToManila(shift.closed_at)}`
                                        : ''}
                                </div>
                                {(shift.drawers || []).map((drawer) => (
                                    <div key={drawer.drawer} className="leading-relaxed">
                                        <span className="font-bold text-amber-50">
                                            {drawer.label} {drawer.kind}:
                                        </span>{' '}
                                        {money(drawer.original_variance)}
                                        <span className="text-amber-200/70">
                                            {' '}· Resolved: {money(drawer.resolved_amount)}
                                        </span>
                                        <span className="font-bold text-amber-200">
                                            {' '}· Remaining: {money(drawer.remaining)}
                                        </span>
                                    </div>
                                ))}
                                <div className="pt-0.5">
                                    Status:{' '}
                                    <span className="font-bold uppercase tracking-wide">
                                        {statusLabel(shift.overall_status)}
                                    </span>
                                </div>
                                {shift.awaiting_admin_review && (
                                    <div className="text-amber-200/80">
                                        Resolution submitted — awaiting Admin review
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="mt-2.5 space-y-1 text-xs text-amber-50/90">
                                {Number(banner.total_remaining_shortage) >= 0.01 && (
                                    <div>
                                        Total Remaining Shortage:{' '}
                                        <span className="font-bold">{money(banner.total_remaining_shortage)}</span>
                                    </div>
                                )}
                                {Number(banner.total_remaining_overage) >= 0.01 && (
                                    <div>
                                        Total Remaining Overage:{' '}
                                        <span className="font-bold">{money(banner.total_remaining_overage)}</span>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
                {banner.view_url && (
                    <Link
                        href={banner.view_url}
                        className="inline-flex shrink-0 items-center justify-center rounded-lg border border-amber-500/40 bg-amber-900/40 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-amber-50 hover:border-amber-400 hover:bg-amber-800/50"
                    >
                        {banner.view_label || (isSingle ? 'View Variance' : 'View My Variances')}
                    </Link>
                )}
            </div>
        </div>
    );
}
