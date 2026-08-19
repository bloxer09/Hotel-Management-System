import React, { useState } from 'react';
import { router } from '@inertiajs/react';

export const VOID_WARNING = 'Void only if the recorded cash movement did not actually occur. If cash physically moved, use a correction/reversal transaction instead.';

export function ClosedPostedCashNotice({ postedShiftId, auditNoteRoute = null }) {
    const [note, setNote] = useState('');

    return (
        <div className="rounded-2xl border border-amber-500/30 bg-[#1e293b] p-5 space-y-3">
            <h2 className="font-outfit font-bold text-amber-200">Historical Posted Transaction</h2>
            <p className="text-sm text-slate-200">
                {postedShiftId ? `Closed Shift #${postedShiftId}` : 'Not linked to an open shift'}
            </p>
            <p className="text-xs text-slate-400 leading-relaxed">
                Ordinary void cannot change this shift&apos;s official cash. The original POSTED financial treatment is kept.
                If cash must move now, record the physical correction on the current open shift.
            </p>
            {auditNoteRoute && (
                <form
                    className="space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(auditNoteRoute, { reason: note }, { onSuccess: () => setNote('') });
                    }}
                >
                    <textarea
                        required
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl p-3 text-sm"
                        rows={3}
                        placeholder="Admin audit note (does not change posted cash)"
                    />
                    <button type="submit" className="px-4 py-2 rounded-xl bg-amber-700 text-white text-xs font-bold uppercase">
                        Add audit note
                    </button>
                </form>
            )}
        </div>
    );
}

export default function VoidPostedCashForm({ action, reference, onCancel, onSuccess }) {
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);

    return (
        <form
            className="w-full space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(action, {
                    reason,
                    confirm_no_physical_movement: confirmed,
                }, {
                    onSuccess: () => {
                        setReason('');
                        setConfirmed(false);
                        onSuccess?.();
                    },
                });
            }}
        >
            <h3 className="font-bold text-slate-100">Void {reference} as erroneous posting</h3>
            <p className="text-xs text-amber-200 leading-relaxed">{VOID_WARNING}</p>
            <textarea
                required
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full bg-[#1e293b] border border-[#334155] rounded-xl p-3 text-sm"
                rows={3}
                placeholder="Required reason (duplicate, accidental posting, cash never moved)"
            />
            <label className="flex items-start gap-2 text-xs text-slate-300">
                <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={confirmed}
                    onChange={(e) => setConfirmed(e.target.checked)}
                    required
                />
                <span>I confirm the recorded cash movement did not actually occur.</span>
            </label>
            <div className="flex justify-end gap-2">
                {onCancel && (
                    <button type="button" onClick={onCancel} className="px-3 py-2 text-xs">
                        Close
                    </button>
                )}
                <button
                    type="submit"
                    disabled={!confirmed}
                    className="px-3 py-2 rounded-xl bg-rose-700 text-white text-xs font-bold uppercase disabled:opacity-40"
                >
                    Void erroneous posting
                </button>
            </div>
        </form>
    );
}
