import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { Sparkles, Plus, Trash2, Pencil, X } from 'lucide-react';
import ActionModal from '@/Components/ActionModal';
import ConfirmModal from '@/Components/ConfirmModal';
import CustomSelect from '@/Components/CustomSelect';

export default function AmenityPolicies({ policies = [], inventoryItems = [], stayKeys = [] }) {
    const [isOpen, setIsOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);

    const form = useForm({
        stay_key: stayKeys[0]?.id || 'overnight',
        inventory_item_id: '',
        default_quantity: 1,
        is_active: true,
    });

    const openAdd = () => {
        setEditing(null);
        form.reset();
        form.setData({
            stay_key: stayKeys[0]?.id || 'overnight',
            inventory_item_id: '',
            default_quantity: 1,
            is_active: true,
        });
        setIsOpen(true);
    };

    const openEdit = (policy) => {
        setEditing(policy);
        form.setData({
            stay_key: policy.stay_key,
            inventory_item_id: String(policy.inventory_item_id),
            default_quantity: policy.default_quantity,
            is_active: Boolean(policy.is_active),
        });
        setIsOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            form.put(route('settings.amenity_policies.update', editing.id), {
                onSuccess: () => setIsOpen(false),
            });
            return;
        }
        form.post(route('settings.amenity_policies.store'), {
            onSuccess: () => setIsOpen(false),
        });
    };

    const stayLabel = (key) => stayKeys.find((entry) => entry.id === key)?.name || key;

    return (
        <AuthenticatedLayout>
            <Head title="Complimentary Amenity Policies" />
            <div className="p-4 sm:p-8 max-w-6xl mx-auto flex flex-col gap-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-outfit font-extrabold text-slate-100 flex items-center gap-2">
                            <Sparkles className="text-emerald-400" size={22} /> Complimentary Amenity Policies
                        </h1>
                        <p className="text-sm text-slate-400 mt-1 max-w-2xl">
                            Assign which inventory products Front Desk may issue as complimentary stock for Overnight and 24 Hours stays.
                            Quantity is a UX default only. Stock is never deducted until staff actually issues the item.
                        </p>
                    </div>
                    <button type="button" onClick={openAdd} className="px-4 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-sm font-bold flex items-center gap-2">
                        <Plus size={16} /> Add Policy
                    </button>
                </div>

                <div className="bg-[#1e293b] border border-[#334155] rounded-2xl overflow-hidden">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b border-[#334155] bg-[#0f172a]/60 text-[10px] uppercase tracking-wider text-slate-400">
                                <th className="px-4 py-3">Stay Type</th>
                                <th className="px-4 py-3">Inventory Product</th>
                                <th className="px-4 py-3">Default Qty</th>
                                <th className="px-4 py-3">Active</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {policies.length > 0 ? policies.map((policy) => (
                                <tr key={policy.id} className="border-b border-[#334155]/50 text-sm text-slate-200">
                                    <td className="px-4 py-3 font-bold">{stayLabel(policy.stay_key)}</td>
                                    <td className="px-4 py-3">{policy.item?.item_name || `Item #${policy.inventory_item_id}`}</td>
                                    <td className="px-4 py-3 font-mono">{policy.default_quantity}</td>
                                    <td className="px-4 py-3">{policy.is_active ? 'Active' : 'Inactive'}</td>
                                    <td className="px-4 py-3 text-right">
                                        <button type="button" onClick={() => openEdit(policy)} className="inline-flex p-2 text-slate-400 hover:text-white">
                                            <Pencil size={14} />
                                        </button>
                                        <button type="button" onClick={() => setConfirmDelete(policy)} className="inline-flex p-2 text-rose-400 hover:text-rose-300">
                                            <Trash2 size={14} />
                                        </button>
                                    </td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center text-slate-500">
                                        No complimentary amenity products configured yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <ActionModal isOpen={isOpen} onClose={() => setIsOpen(false)} title={editing ? 'Edit Amenity Policy' : 'Add Amenity Policy'}>
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <label className="text-[10px] font-bold uppercase text-slate-400">Stay Type
                        <CustomSelect value={form.data.stay_key} onChange={(e) => form.setData('stay_key', e.target.value)} className="mt-1 w-full rounded-lg border border-[#334155] bg-[#0f172a] px-3 py-2 text-sm text-slate-100">
                            {stayKeys.map((key) => (
                                <option key={key.id} value={key.id}>{key.name}</option>
                            ))}
                        </CustomSelect>
                    </label>
                    <label className="text-[10px] font-bold uppercase text-slate-400">Inventory Product
                        <CustomSelect value={form.data.inventory_item_id} onChange={(e) => form.setData('inventory_item_id', e.target.value)} className="mt-1 w-full rounded-lg border border-[#334155] bg-[#0f172a] px-3 py-2 text-sm text-slate-100">
                            <option value="">Select product...</option>
                            {inventoryItems.map((item) => (
                                <option key={item.id} value={item.id}>{item.item_name}</option>
                            ))}
                        </CustomSelect>
                    </label>
                    <label className="text-[10px] font-bold uppercase text-slate-400">Default Complimentary Quantity
                        <input type="number" min="1" max="99" value={form.data.default_quantity} onChange={(e) => form.setData('default_quantity', e.target.value)} className="mt-1 w-full rounded-lg border border-[#334155] bg-[#0f172a] px-3 py-2 text-sm text-slate-100" />
                    </label>
                    <label className="flex items-center gap-2 text-sm text-slate-200">
                        <input type="checkbox" checked={Boolean(form.data.is_active)} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        Active
                    </label>
                    {form.errors.inventory_item_id && <p className="text-xs text-rose-400">{form.errors.inventory_item_id}</p>}
                    <div className="flex justify-end gap-2 mt-2">
                        <button type="button" onClick={() => setIsOpen(false)} className="px-4 py-2 rounded-xl border border-[#334155] text-slate-300 text-sm font-bold">
                            <X size={14} className="inline mr-1" /> Cancel
                        </button>
                        <button type="submit" disabled={form.processing} className="px-4 py-2 rounded-xl bg-brand-600 text-white text-sm font-bold">
                            Save
                        </button>
                    </div>
                </form>
            </ActionModal>

            <ConfirmModal
                isOpen={Boolean(confirmDelete)}
                title="Remove amenity policy?"
                message={confirmDelete ? `Remove ${confirmDelete.item?.item_name || 'this product'} from ${stayLabel(confirmDelete.stay_key)} complimentary amenities? Past issuance history is kept.` : ''}
                onClose={() => setConfirmDelete(null)}
                onConfirm={() => {
                    router.delete(route('settings.amenity_policies.destroy', confirmDelete.id));
                    setConfirmDelete(null);
                }}
            />
        </AuthenticatedLayout>
    );
}
