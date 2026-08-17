import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { ClipboardList, CheckCircle2, XCircle, AlertTriangle } from 'lucide-react';
import Pagination from '@/Components/Pagination';
import { motion, AnimatePresence } from 'framer-motion';

const typeLabel = (type) => ({
    create_item: 'New Item',
    add: 'Add Stock',
    subtract: 'Subtract Stock',
    set: 'Set Exact',
}[type] || type || 'request');

const statusBadge = (status) => {
    const map = {
        pending: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        approved: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        rejected: 'bg-rose-500/10 text-rose-400 border-rose-500/20',
    };
    return map[status] || 'bg-slate-500/10 text-slate-400 border-slate-500/20';
};

export default function PendingRequestsTab({ requests, isAdmin }) {
    const [reviewing, setReviewing] = useState(null);
    const [confirmApprove, setConfirmApprove] = useState(null);
    const [approveNote, setApproveNote] = useState('');
    const [rejecting, setRejecting] = useState(null);
    const [rejectNote, setRejectNote] = useState('');
    const [rejectError, setRejectError] = useState('');

    const submitApprove = (e) => {
        e?.preventDefault();
        if (!confirmApprove) return;
        router.post(route('inventory.requests.approve', confirmApprove.id), {
            review_note: approveNote.trim(),
        }, { preserveScroll: true });
        setConfirmApprove(null);
        setReviewing(null);
        setApproveNote('');
    };

    const submitReject = (e) => {
        e.preventDefault();
        if (!rejectNote.trim()) {
            setRejectError('A rejection reason is required.');
            return;
        }
        router.post(route('inventory.requests.reject', rejecting.id), {
            review_note: rejectNote.trim(),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setRejecting(null);
                setReviewing(null);
                setRejectNote('');
            },
        });
    };

    return (
        <>
            <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-xl overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Date / Time</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Item</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Type</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Qty</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stock</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Requester</th>
                                <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Status</th>
                                {isAdmin && <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {requests.data.length > 0 ? requests.data.map((req) => (
                                <tr key={req.id} className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40">
                                    <td className="px-4 py-3 text-[11px] font-mono text-brand-400 font-bold">{req.requested_at_manila}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-outfit font-bold text-slate-100 text-xs">{req.item_name}</div>
                                        {req.reason && <div className="text-[10px] text-slate-500 mt-0.5">{req.reason}</div>}
                                    </td>
                                    <td className="px-4 py-3 text-xs font-semibold text-slate-300">{typeLabel(req.request_type)}</td>
                                    <td className="px-4 py-3 font-mono text-xs text-slate-200">{req.quantity}</td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400">
                                        {req.request_type === 'create_item' ? (
                                            <span>Initial {req.quantity}</span>
                                        ) : (
                                            <div className="flex flex-col gap-0.5">
                                                <span>At request: {req.stock_at_request ?? '—'}</span>
                                                <span>Now: {req.current_stock ?? '—'}</span>
                                                {req.stock_changed_since_request && (
                                                    <span className="text-amber-400 font-bold flex items-center gap-1"><AlertTriangle size={10} /> Changed</span>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-[11px] text-slate-400">{req.requester?.full_name || 'Unknown'}</td>
                                    <td className="px-4 py-3">
                                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold border capitalize ${statusBadge(req.status)}`}>
                                            {req.status}
                                        </span>
                                        {req.review_note && (
                                            <div className="text-[10px] text-slate-500 mt-1">Note: {req.review_note}</div>
                                        )}
                                    </td>
                                    {isAdmin && (
                                        <td className="px-4 py-3 text-right">
                                            {req.status === 'pending' && (
                                                <button
                                                    onClick={() => setReviewing(req)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] hover:bg-slate-800 border border-[#334155] rounded-lg text-[10px] font-bold text-slate-300"
                                                >
                                                    Review
                                                </button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan={isAdmin ? 8 : 7} className="py-16 text-center text-slate-500">
                                        <div className="flex flex-col items-center gap-3">
                                            <ClipboardList size={32} className="opacity-20" />
                                            <span>No inventory requests to display.</span>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                {requests.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                        <span className="text-[10px] text-slate-500">
                            Showing {requests.from}–{requests.to} of {requests.total} records
                        </span>
                        <Pagination links={requests.links} />
                    </div>
                )}
            </div>

            <AnimatePresence>
                {reviewing && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setReviewing(null)} />
                        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg max-h-[calc(100dvh-2rem)] shadow-2xl relative z-10 overflow-hidden flex flex-col">
                            <div className="p-5 border-b border-[#334155] flex items-center justify-between">
                                <h2 className="font-outfit font-black text-slate-100">Review Request</h2>
                                <button onClick={() => setReviewing(null)} className="text-slate-400 hover:text-slate-100">✕</button>
                            </div>
                            <div className="p-5 space-y-3 overflow-y-auto text-xs">
                                <Detail label="Type" value={typeLabel(reviewing.request_type)} />
                                <Detail label="Item" value={reviewing.item_name} />
                                <Detail label="Requester" value={reviewing.requester?.full_name} />
                                <Detail label="Requested" value={reviewing.requested_at_manila} />
                                <Detail label="Reason" value={reviewing.reason || '—'} />
                                {reviewing.request_type === 'create_item' ? (
                                    <>
                                        <Detail label="Category" value={reviewing.payload?.category} />
                                        <Detail label="Unit" value={reviewing.payload?.unit} />
                                        <Detail label="Initial stock" value={reviewing.payload?.current_stock} />
                                        <Detail label="Minimum stock" value={reviewing.payload?.minimum_stock} />
                                        <Detail label="Unit cost" value={reviewing.payload?.unit_cost} />
                                        <Detail label="Selling price" value={reviewing.payload?.selling_price} />
                                        {reviewing.pending_image_url && (
                                            <div className="flex items-center gap-3">
                                                <span className="text-[10px] uppercase font-bold text-slate-400 w-32">Image</span>
                                                <img src={reviewing.pending_image_url} alt="" className="w-16 h-16 object-cover rounded-lg border border-[#334155]" />
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        <Detail label="Requested quantity" value={reviewing.quantity} />
                                        <Detail label="Stock at request" value={reviewing.stock_at_request} />
                                        <Detail label="Current stock" value={reviewing.current_stock ?? 'Item missing'} />
                                        <Detail label="Projected after approval" value={reviewing.projected_stock ?? '—'} />
                                        {reviewing.stock_changed_since_request && (
                                            <div className="flex items-start gap-2 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300">
                                                <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                                                <span>Current stock differs from the stock recorded when this request was submitted.</span>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                            <div className="p-5 border-t border-[#334155] flex flex-col-reverse sm:flex-row justify-end gap-3">
                                <button onClick={() => { setRejecting(reviewing); setRejectNote(''); setRejectError(''); }} className="px-5 py-2.5 bg-rose-600 hover:bg-rose-500 text-white rounded-xl text-xs font-bold flex items-center justify-center gap-1">
                                    <XCircle size={14} /> Reject
                                </button>
                                <button onClick={() => { setConfirmApprove(reviewing); setApproveNote(''); }} className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold flex items-center justify-center gap-1">
                                    <CheckCircle2 size={14} /> Approve
                                </button>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            <AnimatePresence>
                {rejecting && (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setRejecting(null)} />
                        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 p-6">
                            <h3 className="font-outfit font-black text-slate-100 mb-2">Reject Request</h3>
                            <p className="text-xs text-slate-400 mb-4">Provide a reason. The submitted values will not be changed.</p>
                            <form onSubmit={submitReject} className="space-y-4">
                                <textarea
                                    value={rejectNote}
                                    onChange={(e) => { setRejectNote(e.target.value); setRejectError(''); }}
                                    rows={4}
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                    placeholder="Rejection reason"
                                    required
                                />
                                {rejectError && <p className="text-[10px] text-rose-400 font-semibold">{rejectError}</p>}
                                <div className="flex justify-end gap-3">
                                    <button type="button" onClick={() => setRejecting(null)} className="px-4 py-2 bg-slate-800 text-slate-300 rounded-xl text-xs font-bold">Cancel</button>
                                    <button type="submit" className="px-4 py-2 bg-rose-600 hover:bg-rose-500 text-white rounded-xl text-xs font-bold">Reject Request</button>
                                </div>
                            </form>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            <AnimatePresence>
                {confirmApprove && (
                    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setConfirmApprove(null)} />
                        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 p-6">
                            <h3 className="font-outfit font-black text-slate-100 mb-2">Approve Request</h3>
                            <p className="text-xs text-slate-400 mb-4">
                                Approve this {typeLabel(confirmApprove.request_type).toLowerCase()} request for {confirmApprove.item_name}? This cannot be undone.
                            </p>
                            <form onSubmit={submitApprove} className="space-y-4">
                                <textarea
                                    value={approveNote}
                                    onChange={(e) => setApproveNote(e.target.value)}
                                    rows={3}
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                    placeholder="Optional approval note"
                                />
                                <div className="flex justify-end gap-3">
                                    <button type="button" onClick={() => setConfirmApprove(null)} className="px-4 py-2 bg-slate-800 text-slate-300 rounded-xl text-xs font-bold">Cancel</button>
                                    <button type="submit" className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold">Approve</button>
                                </div>
                            </form>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>
        </>
    );
}

function Detail({ label, value }) {
    return (
        <div className="flex gap-3">
            <span className="text-[10px] uppercase font-bold text-slate-400 w-36 shrink-0">{label}</span>
            <span className="text-slate-200 font-semibold capitalize">{value ?? '—'}</span>
        </div>
    );
}
