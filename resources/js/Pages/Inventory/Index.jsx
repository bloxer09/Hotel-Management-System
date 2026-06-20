import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, usePage, Link } from '@inertiajs/react';
import {
    Package,
    Search,
    Plus,
    Minus,
    SlidersHorizontal,
    AlertTriangle,
    PlusCircle,
    CheckCircle2,
    RefreshCw,
    Edit2,
    X,
    Trash2
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import BulkUsageModal from './BulkUsageModal';

export default function Index({ items, activeBookings = [], currentSearch, currentCategory }) {
    const { auth } = usePage().props;
    const user = auth.user;
    const isAdmin = user.role === 'admin';

    // State modals
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [isAdjustOpen, setIsAdjustOpen] = useState(false);
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [selectedItem, setSelectedItem] = useState(null);
    const [isBulkUsageOpen, setIsBulkUsageOpen] = useState(false);

    // Search and Filter states
    const [search, setSearch] = useState(currentSearch || '');
    const [category, setCategory] = useState(currentCategory || '');

    // Form: Create Item
    const createForm = useForm({
        item_name: '',
        category: 'minibar',
        unit: 'piece',
        current_stock: 0,
        minimum_stock: 5,
        unit_cost: 0,
        selling_price: 0,
        image: null
    });

    // Form: Edit Item
    const editForm = useForm({
        item_name: '',
        category: 'minibar',
        unit: '',
        minimum_stock: 5,
        unit_cost: 0,
        selling_price: 0,
        is_active: true,
        image: null,
        _method: 'PATCH'
    });

    // Form: Adjust Stock
    const adjustForm = useForm({
        adjustment_type: 'add',
        quantity: 1,
        reason: ''
    });


    const triggerSearch = (e) => {
        e.preventDefault();
        router.get(route('inventory.index'), { search, category }, { preserveState: true });
    };

    const handleCategoryChange = (cat) => {
        setCategory(cat);
        router.get(route('inventory.index'), { search, category: cat }, { preserveState: true });
    };

    const openAdjustModal = (item) => {
        setSelectedItem(item);
        adjustForm.reset({
            adjustment_type: 'add',
            quantity: 1,
            reason: ''
        });
        setIsAdjustOpen(true);
    };

    const openEditModal = (item) => {
        setSelectedItem(item);
        editForm.setData({
            item_name: item.item_name,
            category: item.category,
            unit: item.unit,
            minimum_stock: item.minimum_stock,
            unit_cost: item.unit_cost,
            selling_price: item.selling_price,
            is_active: item.is_active ? true : false,
            image: null,
            _method: 'PATCH'
        });
        setIsEditOpen(true);
    };

    const handleCreateSubmit = (e) => {
        e.preventDefault();
        createForm.post(route('inventory.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                createForm.reset();
            }
        });
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        editForm.post(route('inventory.update', selectedItem.id), {
            onSuccess: () => {
                setIsEditOpen(false);
            }
        });
    };

    const handleAdjustSubmit = (e) => {
        e.preventDefault();
        adjustForm.post(route('inventory.adjust', selectedItem.id), {
            onSuccess: () => {
                setIsAdjustOpen(false);
            }
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Inventory" />

            <div className="flex flex-col gap-8">

                {/* Title Section */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Inventory Catalog
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Manage minibar stocks, in-room amenities catalog, and operational hotel tracking supplies.</p>
                    </div>

                    <div className="flex gap-3 self-start flex-wrap">
                        <button
                            onClick={() => setIsBulkUsageOpen(true)}
                            className="inline-flex items-center gap-2 px-5 py-3 bg-indigo-600 hover:bg-indigo-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-indigo-600/20 transition-all"
                        >
                            <Package size={16} /> Record Bulk Usage
                        </button>

                        {isAdmin && (
                            <button
                                onClick={() => setIsAddOpen(true)}
                                className="inline-flex items-center gap-2 px-5 py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all"
                            >
                                <PlusCircle size={16} /> Add Item
                            </button>
                        )}
                    </div>
                </div>

                {/* Filter and Search Panels */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    <div className="flex gap-1 bg-[#1e293b] p-1 rounded-xl border border-[#334155] flex-wrap">
                        {['', 'minibar', 'toiletries', 'laundry', 'amenities', 'supplies'].map((cat) => (
                            <button
                                key={cat}
                                onClick={() => handleCategoryChange(cat)}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all capitalize ${
                                    category === cat
                                        ? 'bg-[#0f172a] text-slate-100 shadow'
                                        : 'text-slate-400 hover:text-slate-200'
                                }`}
                            >
                                {cat === '' ? 'All Items' : cat}
                            </button>
                        ))}
                    </div>
                    <form onSubmit={triggerSearch} className="relative w-full sm:w-64">
                        <Search className="absolute left-4 top-3 text-slate-500" size={16} />
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Search inventory..."
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 pl-11 pr-4 py-2.5 focus:outline-none focus:border-brand-500 text-xs"
                        />
                    </form>
                </div>
                {/* Restock Alerts Warning Hub */}
                {(() => {
                    const lowStockItems = items.filter(item => item.current_stock <= item.minimum_stock && item.is_active);
                    if (lowStockItems.length === 0) return null;
                    return (
                        <motion.div
                            initial={{ opacity: 0, y: -10 }}
                            animate={{ opacity: 1, y: 0 }}
                            className="p-5 rounded-2xl bg-amber-950/25 border border-amber-500/40 shadow-lg shadow-amber-950/20 flex flex-col gap-3 relative overflow-hidden"
                        >
                            {/* Pulse glowing backlight */}
                            <span className="absolute top-0 right-0 h-24 w-24 bg-amber-500/10 rounded-full blur-2xl animate-pulse"></span>

                            <div className="flex items-center gap-2 text-amber-400 font-bold text-sm">
                                <AlertTriangle className="shrink-0 animate-bounce" size={18} />
                                <h2 className="font-outfit font-extrabold uppercase tracking-wider">Restock Alerts Required</h2>
                            </div>
                            <p className="text-xs text-slate-300 font-medium leading-relaxed">
                                The following items are currently at or below their minimum safety stock threshold. Please initiate a restock update immediately.
                            </p>
                            <div className="flex flex-wrap gap-2 mt-1 z-10">
                                {lowStockItems.map(item => (
                                    <button
                                        key={item.id}
                                        onClick={() => openAdjustModal(item)}
                                        className="flex items-center gap-2.5 px-3 py-1.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 text-amber-300 text-xs font-bold font-mono transition-all duration-200"
                                        title={`Instant restock ${item.item_name}`}
                                    >
                                        <span>{item.item_name} ({item.current_stock} {item.unit} left)</span>
                                        <span className="bg-amber-500 text-amber-950 px-2 py-0.5 rounded font-black text-[9px] tracking-wide">RESTOCK</span>
                                    </button>
                                ))}
                            </div>
                        </motion.div>
                    );
                })()}

                {/* Inventory Stock Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {items.length > 0 ? (
                        items.map((item) => {
                            const isLow = item.current_stock <= item.minimum_stock;
                            const progress = Math.min((item.current_stock / (item.minimum_stock * 2 || 10)) * 100, 100);

                            return (
                                <div
                                    key={item.id}
                                    className={`p-6 rounded-2xl bg-[#1e293b] border shadow-xl flex flex-col justify-between gap-4 transition-all hover:scale-[1.01] ${isLow ? 'border-red-950/80 shadow-red-950/10' : 'border-[#334155]'
                                        }`}
                                >
                                    <div>
                                        {/* Card Image Banner */}
                                        <div className="relative w-full h-36 bg-[#0f172a] rounded-xl overflow-hidden mb-4 border border-[#334155]/40 flex items-center justify-center group">
                                            {item.image_path ? (
                                                <img src={item.image_path} alt={item.item_name} className="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                            ) : (
                                                <div className="w-full h-full bg-gradient-to-br from-indigo-950/30 to-slate-900/60 flex flex-col items-center justify-center gap-2">
                                                    <Package className="text-slate-600 group-hover:text-indigo-400 transition-colors" size={28} />
                                                </div>
                                            )}
                                            {/* Badges */}
                                            <span className="absolute top-2.5 left-2.5 text-[9px] uppercase font-mono font-bold bg-[#0f172a]/95 backdrop-blur-sm text-slate-300 border border-[#334155]/60 px-2 py-0.5 rounded-lg shadow-sm">
                                                {item.category}
                                            </span>
                                            {isLow && (
                                                <span className="absolute top-2.5 right-2.5 inline-flex items-center gap-1 text-[9px] bg-red-950/95 backdrop-blur-sm border border-red-800/60 text-red-400 px-2 py-0.5 rounded-lg font-extrabold uppercase animate-pulse shadow-sm">
                                                    <AlertTriangle size={10} /> Critical Low
                                                </span>
                                            )}
                                        </div>

                                        {/* Body details */}
                                        <div className="mt-3 flex flex-col gap-1">
                                            <h3 className="font-outfit font-black text-slate-100 text-base leading-tight">
                                                {item.item_name}
                                            </h3>
                                            <span className="text-[10px] text-slate-500 font-medium">Standard packaging: <span className="font-semibold text-slate-400 capitalize">{item.unit}</span></span>
                                        </div>

                                        {/* Stock Level Progress Indicator */}
                                        <div className="mt-5 flex flex-col gap-1.5">
                                            <div className="flex justify-between text-xs font-semibold">
                                                <span className="text-slate-400">Stock</span>
                                                <span className={isLow ? 'text-red-400 font-mono font-bold' : 'text-emerald-400 font-mono font-bold'}>
                                                    {item.current_stock} / {item.minimum_stock} <span className="text-[9px] text-slate-500 font-medium">min</span>
                                                </span>
                                            </div>
                                            <div className="w-full bg-[#0f172a] h-2 rounded-full overflow-hidden">
                                                <div
                                                    className={`h-full ${isLow ? 'bg-red-500' : 'bg-emerald-500'}`}
                                                    style={{ width: `${progress}%` }}
                                                ></div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Cost/Pricing & Control Buttons */}
                                    <div className="pt-3 border-t border-[#334155]/55 flex items-center justify-between">
                                        <div className="flex flex-col">
                                            <span className="text-[10px] text-slate-500 font-bold uppercase">Price</span>
                                            <span className="font-mono text-sm font-black text-brand-300">
                                                ₱{Number(item.selling_price).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </span>
                                        </div>

                                        <div className="flex gap-2">
                                            {/* Adjust stock button */}
                                            <button
                                                onClick={() => openAdjustModal(item)}
                                                className="p-2 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] rounded-xl text-slate-300 hover:text-slate-50 transition-colors"
                                                title="Adjust Stock Level"
                                            >
                                                <RefreshCw size={14} />
                                            </button>

                                            {/* Edit details button */}
                                            {isAdmin && (
                                                <button
                                                    onClick={() => openEditModal(item)}
                                                    className="p-2 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] rounded-xl text-slate-300 hover:text-slate-50 transition-colors"
                                                    title="Edit Specifications"
                                                >
                                                    <Edit2 size={14} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })
                    ) : (
                        <div className="col-span-full py-16 text-center text-slate-500">
                            No catalog items found matching your filters.
                        </div>
                    )}
                </div>

                {/* MODAL: ADD CATALOG ITEM */}
                <AnimatePresence>
                    {isAddOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsAddOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg">Add New Item</h2>
                                    <button onClick={() => setIsAddOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleCreateSubmit} className="p-6 space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="col-span-2 flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Item Name</label>
                                            <input
                                                type="text"
                                                value={createForm.data.item_name}
                                                onChange={e => createForm.setData('item_name', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                            {createForm.errors.item_name && <span className="text-[10px] text-red-400 font-semibold">{createForm.errors.item_name}</span>}
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Category</label>
                                            <select
                                                value={createForm.data.category}
                                                onChange={e => createForm.setData('category', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            >
                                                <option value="minibar">Minibar</option>
                                                <option value="toiletries">Toiletries</option>
                                                <option value="laundry">Laundry</option>
                                                <option value="amenities">Amenities</option>
                                                <option value="supplies">Supplies</option>
                                            </select>
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Unit</label>
                                            <input
                                                type="text"
                                                value={createForm.data.unit}
                                                onChange={e => createForm.setData('unit', e.target.value)}
                                                placeholder="piece, bottle, pack"
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Initial Stock</label>
                                            <input
                                                type="number"
                                                value={createForm.data.current_stock}
                                                onChange={e => createForm.setData('current_stock', parseInt(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Minimum Stock</label>
                                            <input
                                                type="number"
                                                value={createForm.data.minimum_stock}
                                                onChange={e => createForm.setData('minimum_stock', parseInt(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Cost per unit (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={createForm.data.unit_cost}
                                                onChange={e => createForm.setData('unit_cost', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Selling Price (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={createForm.data.selling_price}
                                                onChange={e => createForm.setData('selling_price', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="col-span-2 flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Product Image</label>
                                            <div className="flex items-center gap-4 bg-[#0f172a] p-4 rounded-xl border border-[#334155] mt-1">
                                                {createForm.data.image ? (
                                                    <div className="relative w-16 h-16 rounded-lg overflow-hidden border border-[#334155]">
                                                        <img src={URL.createObjectURL(createForm.data.image)} alt="Preview" className="w-full h-full object-cover" />
                                                        <button
                                                            type="button"
                                                            onClick={() => createForm.setData('image', null)}
                                                            className="absolute -top-1 -right-1 bg-red-600 text-white rounded-full p-0.5 hover:bg-red-505"
                                                        >
                                                            <X size={12} />
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div className="w-16 h-16 rounded-lg bg-[#1e293b] border border-dashed border-[#334155] flex items-center justify-center text-slate-500">
                                                        <Package size={20} />
                                                    </div>
                                                )}
                                                <div className="flex-1">
                                                    <input
                                                        type="file"
                                                        accept="image/*"
                                                        onChange={e => createForm.setData('image', e.target.files[0])}
                                                        className="hidden"
                                                        id="add-item-image"
                                                    />
                                                    <label
                                                        htmlFor="add-item-image"
                                                        className="inline-flex items-center gap-2 px-3 py-1.5 bg-[#1e293b] hover:bg-[#334155] border border-[#334155] text-slate-300 hover:text-slate-100 rounded-lg text-xs font-bold cursor-pointer transition-colors"
                                                    >
                                                        Choose Image
                                                    </label>
                                                    <p className="text-[10px] text-slate-500 mt-1">PNG, JPG, WEBP up to 2MB</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsAddOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={createForm.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Add Item</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: EDIT CATALOG ITEM */}
                <AnimatePresence>
                    {isEditOpen && selectedItem && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsEditOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-lg shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg">Edit Item: {selectedItem.item_name}</h2>
                                    <button onClick={() => setIsEditOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleEditSubmit} className="p-6 space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="col-span-2 flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Item Name</label>
                                            <input
                                                type="text"
                                                value={editForm.data.item_name}
                                                onChange={e => editForm.setData('item_name', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Category</label>
                                            <select
                                                value={editForm.data.category}
                                                onChange={e => editForm.setData('category', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            >
                                                <option value="minibar">Minibar</option>
                                                <option value="toiletries">Toiletries</option>
                                                <option value="laundry">Laundry</option>
                                                <option value="amenities">Amenities</option>
                                                <option value="supplies">Supplies</option>
                                            </select>
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Unit</label>
                                            <input
                                                type="text"
                                                value={editForm.data.unit}
                                                onChange={e => editForm.setData('unit', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Minimum Stock</label>
                                            <input
                                                type="number"
                                                value={editForm.data.minimum_stock}
                                                onChange={e => editForm.setData('minimum_stock', parseInt(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Cost per unit (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={editForm.data.unit_cost}
                                                onChange={e => editForm.setData('unit_cost', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Selling Price (₱)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={editForm.data.selling_price}
                                                onChange={e => editForm.setData('selling_price', parseFloat(e.target.value) || 0)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                                required
                                            />
                                        </div>

                                        <div className="flex flex-col gap-1 justify-center">
                                            <label className="flex items-center gap-2 cursor-pointer mt-4">
                                                <input
                                                    type="checkbox"
                                                    checked={editForm.data.is_active}
                                                    onChange={e => editForm.setData('is_active', e.target.checked)}
                                                    className="rounded bg-[#0f172a] border-[#334155] text-brand-600 focus:ring-0 focus:ring-offset-0"
                                                />
                                                <span className="text-xs font-semibold text-slate-300">Active</span>
                                            </label>
                                        </div>

                                        <div className="col-span-2 flex flex-col gap-1">
                                            <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Product Image</label>
                                            <div className="flex items-center gap-4 bg-[#0f172a] p-4 rounded-xl border border-[#334155] mt-1">
                                                {editForm.data.image ? (
                                                    <div className="relative w-16 h-16 rounded-lg overflow-hidden border border-[#334155]">
                                                        <img src={URL.createObjectURL(editForm.data.image)} alt="Preview" className="w-full h-full object-cover" />
                                                        <button
                                                            type="button"
                                                            onClick={() => editForm.setData('image', null)}
                                                            className="absolute -top-1 -right-1 bg-red-600 text-white rounded-full p-0.5 hover:bg-red-505"
                                                        >
                                                            <X size={12} />
                                                        </button>
                                                    </div>
                                                ) : selectedItem.image_path ? (
                                                    <div className="relative w-16 h-16 rounded-lg overflow-hidden border border-[#334155]">
                                                        <img src={selectedItem.image_path} alt="Current" className="w-full h-full object-cover" />
                                                    </div>
                                                ) : (
                                                    <div className="w-16 h-16 rounded-lg bg-[#1e293b] border border-dashed border-[#334155] flex items-center justify-center text-slate-500">
                                                        <Package size={20} />
                                                    </div>
                                                )}
                                                <div className="flex-1">
                                                    <input
                                                        type="file"
                                                        accept="image/*"
                                                        onChange={e => editForm.setData('image', e.target.files[0])}
                                                        className="hidden"
                                                        id="edit-item-image"
                                                    />
                                                    <label
                                                        htmlFor="edit-item-image"
                                                        className="inline-flex items-center gap-2 px-3 py-1.5 bg-[#1e293b] hover:bg-[#334155] border border-[#334155] text-slate-300 hover:text-slate-100 rounded-lg text-xs font-bold cursor-pointer transition-colors"
                                                    >
                                                        Change Image
                                                    </label>
                                                    <p className="text-[10px] text-slate-500 mt-1">PNG, JPG, WEBP up to 2MB</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsEditOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={editForm.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Save Changes</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: ADJUST STOCK LEVEL */}
                <AnimatePresence>
                    {isAdjustOpen && selectedItem && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsAdjustOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <div className="flex flex-col">
                                        <h2 className="font-outfit font-black text-slate-100 text-lg">Adjust Stock Level</h2>
                                        <span className="text-[10px] text-slate-500">{selectedItem.item_name} (Current: {selectedItem.current_stock} {selectedItem.unit})</span>
                                    </div>
                                    <button onClick={() => setIsAdjustOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleAdjustSubmit} className="p-6 space-y-4">
                                    {/* Add / Subtract / Set selectors */}
                                    <div className="grid grid-cols-3 gap-2 p-1 bg-[#0f172a] rounded-xl border border-[#334155]">
                                        <button
                                            type="button"
                                            onClick={() => adjustForm.setData('adjustment_type', 'add')}
                                            className={`py-2 rounded-lg font-outfit font-extrabold text-[10px] flex items-center justify-center gap-1 transition-all ${adjustForm.data.adjustment_type === 'add'
                                                ? 'bg-emerald-950 border border-emerald-500/40 text-emerald-400'
                                                : 'text-slate-400'
                                                }`}
                                        >
                                            <Plus size={12} /> Add
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => adjustForm.setData('adjustment_type', 'subtract')}
                                            className={`py-2 rounded-lg font-outfit font-extrabold text-[10px] flex items-center justify-center gap-1 transition-all ${adjustForm.data.adjustment_type === 'subtract'
                                                ? 'bg-red-950 border border-red-500/40 text-red-400'
                                                : 'text-slate-400'
                                                }`}
                                        >
                                            <Minus size={12} /> Subtract
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => adjustForm.setData('adjustment_type', 'set')}
                                            className={`py-2 rounded-lg font-outfit font-extrabold text-[10px] flex items-center justify-center gap-1 transition-all ${adjustForm.data.adjustment_type === 'set'
                                                ? 'bg-indigo-950 border border-indigo-500/40 text-indigo-400'
                                                : 'text-slate-400'
                                                }`}
                                        >
                                            <SlidersHorizontal size={12} /> Set exact
                                        </button>
                                    </div>

                                    {/* Qty field */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Adjustment Quantity ({selectedItem.unit})</label>
                                        <input
                                            type="number"
                                            min={adjustForm.data.adjustment_type === 'set' ? "0" : "1"}
                                            value={adjustForm.data.quantity}
                                            onChange={e => adjustForm.setData('quantity', parseInt(e.target.value) || 0)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                    </div>

                                    {/* Reason field */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Reason for adjustment</label>
                                        <input
                                            type="text"
                                            value={adjustForm.data.reason}
                                            onChange={e => adjustForm.setData('reason', e.target.value)}
                                            placeholder="e.g. Sourced fresh items, Minibar check waste, Damage..."
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                    </div>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsAdjustOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={adjustForm.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Apply Adjustment</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: RECORD BULK USAGE */}
                <BulkUsageModal 
                    isOpen={isBulkUsageOpen} 
                    onClose={() => setIsBulkUsageOpen(false)} 
                    items={items} 
                    activeBookings={activeBookings} 
                />

            </div>
        </AuthenticatedLayout>
    );
}
