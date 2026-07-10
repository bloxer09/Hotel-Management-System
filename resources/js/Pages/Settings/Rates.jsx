import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { BedDouble, Edit3, ShieldAlert, X, Plus, Trash2, Settings2, Users } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import ActionModal from '@/Components/ActionModal';
import ConfirmModal from '@/Components/ConfirmModal';
import AlertModal from '@/Components/AlertModal';

export default function Rates({ roomTypes }) {
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [selectedType, setSelectedType] = useState(null);

    const [actionModalItem, setActionModalItem] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);
    const [alertMessage, setAlertMessage] = useState(null);

    const form = useForm({
        base_rate: 0,
        hourly_rate: 0,
        short_time_3h_rate: 0,
        short_time_6h_rate: 0,
        short_time_12h_rate: 0,
        short_time_24h_rate: 0,
        max_occupancy: 2,
        description: '',
        amenities: '',
        photo: null,
        _method: 'PUT'
    });

    const openEditModal = (roomType) => {
        setSelectedType(roomType);
        form.setData({
            base_rate: roomType.base_rate,
            hourly_rate: roomType.hourly_rate,
            short_time_3h_rate: roomType.short_time_3h_rate,
            short_time_6h_rate: roomType.short_time_6h_rate,
            short_time_12h_rate: roomType.short_time_12h_rate,
            short_time_24h_rate: roomType.short_time_24h_rate,
            max_occupancy: roomType.max_occupancy,
            description: roomType.description || '',
            amenities: roomType.amenities || '',
            photo: null,
            _method: 'PUT'
        });
        setIsEditOpen(true);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        form.post(route('settings.rates.update', selectedType.id), {
            onSuccess: () => {
                setIsEditOpen(false);
            }
        });
    };

    const addForm = useForm({
        type_name: '',
        description: '',
        base_rate: 0,
        hourly_rate: 0,
        short_time_3h_rate: 0,
        short_time_6h_rate: 0,
        short_time_12h_rate: 0,
        short_time_24h_rate: 0,
        max_occupancy: 2,
        amenities: '',
        photo: null
    });

    const handleAddSubmit = (e) => {
        e.preventDefault();
        addForm.post(route('settings.rates.store'), {
            onSuccess: () => { setIsAddOpen(false); addForm.reset(); }
        });
    };

    const attemptDelete = (rt) => {
        if (rt.room_count > 0) {
            setAlertMessage(`Cannot delete '${rt.type_name}' because it is assigned to ${rt.room_count} room(s). Reassign those rooms first.`);
            return;
        }
        setConfirmDelete(rt);
    };

    const handleDelete = () => {
        if (confirmDelete) {
            router.delete(route('settings.rates.destroy', confirmDelete.id));
            setConfirmDelete(null);
        }
    };

    const formatCurrency = (val) => {
        return '₱' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2 });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Room Rates Pricing Tiers" />

            <div className="flex flex-col gap-8">

                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Room Type Pricing Configurations
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Configure baseline lodging rates, custom short-time hourly rate structures, and room occupancy constraints.</p>
                    </div>
                    <button
                        onClick={() => setIsAddOpen(true)}
                        className="flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold transition-all shadow-lg"
                    >
                        <Plus size={15} /> Add Room Type
                    </button>
                </div>

                <div className="p-6 rounded-2xl bg-[#1e293b] border border-[#334155] shadow-xl">
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs min-w-[850px]">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left min-w-[200px]">Room Type Details</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Base Rates</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Short-Time Rates</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Capacity</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Assigned Rooms</th>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roomTypes.map((rt, i) => (
                                    <motion.tr 
                                        key={rt.id} 
                                        initial={{ opacity: 0, y: 6 }} 
                                        animate={{ opacity: 1, y: 0 }} 
                                        transition={{ delay: i * 0.03 }}
                                        className="border-b border-[#334155]/50 hover:bg-[#0f172a]/40 transition-colors"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-start gap-3">
                                                {rt.photo_url ? (
                                                    <img src={rt.photo_url} alt={rt.type_name} className="w-12 h-12 rounded-xl object-cover border border-[#334155] shrink-0" />
                                                ) : (
                                                    <div className="w-12 h-12 rounded-xl bg-[#0f172a] border border-[#334155] flex items-center justify-center text-slate-500 shrink-0">
                                                        <BedDouble size={20} />
                                                    </div>
                                                )}
                                                <div className="flex flex-col gap-1 max-w-[200px]">
                                                    <span className="font-outfit font-black text-slate-100 text-sm">{rt.type_name}</span>
                                                    <span className="text-[10px] text-slate-400 truncate" title={rt.description}>{rt.description || 'No description listed'}</span>
                                                    {rt.amenities && (
                                                        <div className="flex flex-wrap gap-1 mt-1">
                                                            {rt.amenities.split(/[,\n]/).map((amenity, idx) => {
                                                                const val = amenity.trim();
                                                                if (!val) return null;
                                                                return (
                                                                    <span key={idx} className="text-[9px] bg-slate-800 text-slate-300 border border-slate-700/60 px-1.5 py-0.5 rounded font-semibold whitespace-nowrap">
                                                                        {val}
                                                                    </span>
                                                                );
                                                            })}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 font-mono">
                                            <div className="flex flex-col gap-1">
                                                <span className="text-emerald-400 font-bold tracking-tight">{formatCurrency(rt.base_rate)} / night</span>
                                                <span className="text-brand-300 text-[10px] tracking-tight">{formatCurrency(rt.hourly_rate)} / hr OT</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 font-mono text-[10px] text-slate-400">
                                            <div className="flex flex-col gap-0.5">
                                                <span>3h: <span className="text-slate-300 font-semibold">{formatCurrency(rt.short_time_3h_rate)}</span></span>
                                                <span>6h: <span className="text-slate-300 font-semibold">{formatCurrency(rt.short_time_6h_rate)}</span></span>
                                                <span>12h: <span className="text-slate-300 font-semibold">{formatCurrency(rt.short_time_12h_rate)}</span></span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 font-medium text-slate-300">
                                            <div className="flex items-center gap-1.5 bg-[#0f172a] px-2 py-1 rounded-lg border border-[#334155] w-fit">
                                                <Users size={12} className="text-indigo-400" />
                                                <span className="text-[10px]">{rt.max_occupancy} Pax</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center justify-center px-2 py-1 bg-slate-800 text-slate-300 border border-slate-700/60 rounded-md font-bold text-[10px]">
                                                {rt.room_count} Room{rt.room_count !== 1 && 's'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <button
                                                onClick={() => setActionModalItem(rt)}
                                                className="px-4 py-2 bg-[#0f172a] hover:bg-[#334155] border border-[#334155] rounded-xl text-slate-300 hover:text-white transition-all text-xs font-bold flex items-center gap-2 ml-auto shadow-sm"
                                            >
                                                <Settings2 size={14} /> Manage
                                            </button>
                                        </td>
                                    </motion.tr>
                                ))}
                                {roomTypes.length === 0 && (
                                    <tr>
                                        <td colSpan="6" className="py-8 text-center text-slate-500">
                                            No room types configured. Add a room type to get started.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* MODAL: ADD ROOM TYPE */}
                <AnimatePresence>
                    {isAddOpen && (
                        <div className="fixed inset-0 z-[1000] flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsAddOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-xl shadow-2xl relative z-10 flex flex-col max-h-[90vh]">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between shrink-0">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg flex items-center gap-2"><Plus size={18} className="text-emerald-400" /> Add New Room Type</h2>
                                    <button onClick={() => setIsAddOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>
                                <form onSubmit={handleAddSubmit} className="flex flex-col flex-1 min-h-0 overflow-hidden">
                                    <div className="p-6 space-y-4 overflow-y-auto custom-scrollbar pr-2">
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Room Type Name *</label>
                                        <input type="text" value={addForm.data.type_name} onChange={e => addForm.setData('type_name', e.target.value)} required
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500" />
                                        {addForm.errors.type_name && <p className="text-red-400 text-xs">{addForm.errors.type_name}</p>}
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        {[
                                            { key: 'base_rate', label: 'Overnight Base Rate (₱)' },
                                            { key: 'hourly_rate', label: 'Hourly Overtime (₱)' },
                                            { key: 'short_time_3h_rate', label: '3 Hours Rate (₱)' },
                                            { key: 'short_time_6h_rate', label: '6 Hours Rate (₱)' },
                                            { key: 'short_time_12h_rate', label: '12 Hours Rate (₱)' },
                                            { key: 'short_time_24h_rate', label: '24 Hours Rate (₱)' },
                                        ].map(({ key, label }) => (
                                            <div key={key} className="flex flex-col gap-1">
                                                <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">{label}</label>
                                                <input type="number" step="0.01" min="0" value={addForm.data[key]}
                                                    onChange={e => addForm.setData(key, parseFloat(e.target.value) || 0)} required
                                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500" />
                                            </div>
                                        ))}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Max Occupancy</label>
                                            <input type="number" min="1" max="20" value={addForm.data.max_occupancy}
                                                onChange={e => addForm.setData('max_occupancy', parseInt(e.target.value) || 2)} required
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500" />
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Amenities (comma-separated)</label>
                                            <input type="text" value={addForm.data.amenities}
                                                onChange={e => addForm.setData('amenities', e.target.value)}
                                                placeholder="AC, TV, WiFi, Hot Shower"
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500" />
                                        </div>
                                        <div className="flex flex-col gap-1 col-span-2">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Description</label>
                                            <textarea value={addForm.data.description}
                                                onChange={e => addForm.setData('description', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 min-h-[50px] focus:outline-none focus:border-brand-500" />
                                        </div>

                                        {/* Photo upload */}
                                        <div className="flex flex-col gap-1 col-span-2">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Room Type Image</label>
                                            {addForm.data.photo && (
                                                <div className="flex items-center gap-3 mb-2 bg-[#0f172a] p-2 rounded-xl border border-[#334155]">
                                                    <img src={URL.createObjectURL(addForm.data.photo)} alt="Preview" className="w-12 h-12 rounded-lg object-cover" />
                                                    <span className="text-[10px] text-emerald-400 font-medium">Image Preview Selected</span>
                                                </div>
                                            )}
                                            <input
                                                type="file"
                                                accept="image/*"
                                                onChange={e => addForm.setData('photo', e.target.files[0])}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-400 px-4 py-2.5 file:mr-4 file:py-1.5 file:px-3.5 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-800 file:text-slate-200 hover:file:bg-slate-700 cursor-pointer focus:outline-none focus:border-brand-500"
                                            />
                                            {addForm.errors.photo && <p className="text-red-400 text-[10px] mt-1">{addForm.errors.photo}</p>}
                                        </div>
                                    </div>
                                    </div>
                                    <div className="p-6 border-t border-[#334155]/60 flex justify-end gap-3 shrink-0">
                                        <button type="button" onClick={() => setIsAddOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={addForm.processing} className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold font-outfit shadow-md">Add Room Type</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: EDIT RATES CONFIG */}
                <AnimatePresence>
                    {isEditOpen && selectedType && (
                        <div className="fixed inset-0 z-[1000] flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-[#070b13]/90" onClick={() => setIsEditOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-xl shadow-2xl relative z-10 flex flex-col max-h-[90vh]">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between shrink-0 bg-[#1e293b]/60">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg">Configure Pricing: {selectedType.type_name}</h2>
                                    <button onClick={() => setIsEditOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleFormSubmit} className="flex flex-col flex-1 min-h-0 overflow-hidden">
                                    <div className="p-6 space-y-4 overflow-y-auto custom-scrollbar pr-2">
                                    <div className="grid grid-cols-2 gap-4">

                                        {/* Overnight rate */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Overnight Base rate (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.base_rate}
                                                onChange={e => form.setData('base_rate', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        {/* Hourly rate */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Hourly Overtime surcharge (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.hourly_rate}
                                                onChange={e => form.setData('hourly_rate', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        {/* Max Pax */}
                                        <div className="flex flex-col gap-1 col-span-2">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Maximum occupancy Pax</label>
                                            <input
                                                type="number"
                                                value={form.data.max_occupancy}
                                                onChange={e => form.setData('max_occupancy', parseInt(e.target.value) || 2)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        {/* Short time structures */}
                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Short-Time 3 Hours (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.short_time_3h_rate}
                                                onChange={e => form.setData('short_time_3h_rate', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Short-Time 6 Hours (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.short_time_6h_rate}
                                                onChange={e => form.setData('short_time_6h_rate', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Short-Time 12 Hours (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.short_time_12h_rate}
                                                onChange={e => form.setData('short_time_12h_rate', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Short-Time 24 Hours (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={form.data.short_time_24h_rate}
                                                onChange={e => form.setData('short_time_24h_rate', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        {/* Description */}
                                        <div className="flex flex-col gap-1 col-span-2">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Description</label>
                                            <textarea
                                                value={form.data.description}
                                                onChange={e => form.setData('description', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 min-h-[60px] focus:outline-none focus:border-brand-500"
                                            />
                                        </div>

                                        {/* Amenities */}
                                        <div className="flex flex-col gap-1 col-span-2">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Room Amenities (comma or newline separated)</label>
                                            <textarea
                                                value={form.data.amenities}
                                                onChange={e => form.setData('amenities', e.target.value)}
                                                placeholder="e.g. Smart TV, AC, Wifi, Hot Water Bath"
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 min-h-[60px] focus:outline-none focus:border-brand-500"
                                            />
                                        </div>

                                        {/* Photo upload */}
                                        <div className="flex flex-col gap-1 col-span-2">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Room Type Image</label>
                                            {selectedType?.photo_url && !form.data.photo && (
                                                <div className="flex items-center gap-3 mb-2 bg-[#0f172a] p-2 rounded-xl border border-[#334155]">
                                                    <img src={selectedType.photo_url} alt="Current" className="w-12 h-12 rounded-lg object-cover" />
                                                    <span className="text-[10px] text-slate-400 font-medium">Current Image</span>
                                                </div>
                                            )}
                                            {form.data.photo && (
                                                <div className="flex items-center gap-3 mb-2 bg-[#0f172a] p-2 rounded-xl border border-[#334155]">
                                                    <img src={URL.createObjectURL(form.data.photo)} alt="Preview" className="w-12 h-12 rounded-lg object-cover" />
                                                    <span className="text-[10px] text-emerald-400 font-medium">New Image Selected</span>
                                                </div>
                                            )}
                                            <input
                                                type="file"
                                                accept="image/*"
                                                onChange={e => form.setData('photo', e.target.files[0])}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-400 px-4 py-2.5 file:mr-4 file:py-1.5 file:px-3.5 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-800 file:text-slate-200 hover:file:bg-slate-700 cursor-pointer focus:outline-none focus:border-brand-500"
                                            />
                                            {form.errors.photo && <p className="text-red-400 text-[10px] mt-1">{form.errors.photo}</p>}
                                        </div>

                                    </div>
                                    </div>

                                    <div className="p-6 border-t border-[#334155]/60 flex justify-end gap-3 shrink-0">
                                        <button type="button" onClick={() => setIsEditOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={form.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Update Pricing</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                <ActionModal
                    isOpen={!!actionModalItem}
                    onClose={() => setActionModalItem(null)}
                    title={`Manage ${actionModalItem?.type_name}`}
                >
                    {actionModalItem && (
                        <>
                            <button
                                onClick={() => {
                                    const item = actionModalItem;
                                    setActionModalItem(null);
                                    openEditModal(item);
                                }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-indigo-600/20 border border-[#334155] hover:border-indigo-500/40 rounded-xl text-xs font-bold text-indigo-400 transition-colors"
                            >
                                <Edit3 size={16} /> Configure Pricing
                            </button>
                            <button
                                onClick={() => {
                                    const item = actionModalItem;
                                    setActionModalItem(null);
                                    attemptDelete(item);
                                }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-rose-900/30 border border-[#334155] hover:border-rose-500/40 rounded-xl text-xs font-bold text-rose-400 transition-colors"
                            >
                                <Trash2 size={16} /> Delete Room Type
                            </button>
                        </>
                    )}
                </ActionModal>

                <ConfirmModal
                    isOpen={!!confirmDelete}
                    onClose={() => setConfirmDelete(null)}
                    onConfirm={handleDelete}
                    title="Delete Room Type"
                    message={`Are you sure you want to delete room type '${confirmDelete?.type_name}'? This cannot be undone.`}
                    confirmText="Delete"
                    isDanger={true}
                />

                <AlertModal
                    isOpen={!!alertMessage}
                    onClose={() => setAlertMessage(null)}
                    title="Cannot Delete Room Type"
                    message={alertMessage}
                />
            </div>
        </AuthenticatedLayout>
    );
}
