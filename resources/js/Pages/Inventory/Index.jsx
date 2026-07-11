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
    Trash2,
    ChevronRight,
    Download,
    ChevronDown
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import ActionModal from '@/Components/ActionModal';
import CustomSelect from '@/Components/CustomSelect';
import SortableHeader from '@/Components/SortableHeader';
import Pagination from '@/Components/Pagination';

export default function Index({ items, activeBookings = [], currentSearch, currentCategory, sortBy, sortDir }) {
    const { auth } = usePage().props;
    const user = auth.user;
    const isAdmin = user.role === 'admin';

    // State modals
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [isAdjustOpen, setIsAdjustOpen] = useState(false);
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [selectedItem, setSelectedItem] = useState(null);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [actionModalItem, setActionModalItem] = useState(null);

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

    const handleExport = () => {
        let url = route('inventory.export');
        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (category) params.append('category', category);
        if (params.toString()) {
            url += '?' + params.toString();
        }
        window.location.href = url;
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

    const confirmDelete = (item) => {
        setSelectedItem(item);
        setIsDeleteOpen(true);
    };

    const handleDelete = () => {
        router.delete(route('inventory.destroy', selectedItem.id), {
            onSuccess: () => {
                setIsDeleteOpen(false);
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
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Inventory Catalog
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-400 font-medium mt-1">Manage minibar stocks, in-room amenities catalog, and operational hotel tracking supplies.</p>
                    </div>

                    <div className="flex flex-col sm:flex-row gap-3 w-full sm:w-auto mt-2 sm:mt-0">
                        <button
                            onClick={handleExport}
                            className="flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-slate-700/20 transition-all w-full sm:w-auto"
                        >
                            <Download size={16} /> Export Stocks
                        </button>
                        {isAdmin && (
                            <button
                                onClick={() => setIsAddOpen(true)}
                                className="flex items-center justify-center gap-2 px-4 sm:px-5 py-2.5 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all w-full sm:w-auto"
                            >
                                <PlusCircle size={16} /> Add Item
                            </button>
                        )}
                    </div>
                </div>

                {/* Filter and Search Panels */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 justify-between">
                    {/* Category CustomSelect Dropdown */}
                    <CustomSelect
                        value={category}
                        onChange={handleCategoryChange}
                        containerClassName="sm:w-56"
                        options={[
                            { id: '', name: 'All Items' },
                            { id: 'minibar', name: 'Minibar' },
                            { id: 'toiletries', name: 'Toiletries' },
                            { id: 'laundry', name: 'Laundry' },
                            { id: 'amenities', name: 'Amenities' },
                            { id: 'supplies', name: 'Supplies' }
                        ]}
                    />
                    <div className="flex items-center gap-2 w-full sm:w-auto">
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
                        <button type="button" onClick={() => router.reload({ only: ['items'] })} className="p-2.5 rounded-xl border border-[#334155] bg-[#1e293b] text-slate-400 hover:text-slate-200 hover:border-brand-500/40 transition-all shrink-0 shadow-sm" title="Refresh Table">
                            <RefreshCw size={16} />
                        </button>
                    </div>
                </div>



                {/* Inventory Stock Table */}
                <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-xl overflow-hidden mt-2">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse table-fixed">
                            <thead>
                                <tr className="border-b border-[#334155] bg-[#0f172a]/60">
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-16">Image</th>
                                    <SortableHeader sortKey="item_name" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Item Name</SortableHeader>
                                    <SortableHeader sortKey="category" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Category / Unit</SortableHeader>
                                    <SortableHeader sortKey="current_stock" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left w-48">Stock Level</SortableHeader>
                                    <SortableHeader sortKey="selling_price" currentSortBy={sortBy} currentSortDir={sortDir} className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-left">Pricing</SortableHeader>
                                    <th className="px-4 py-3 text-[10px] font-semibold text-slate-400 uppercase tracking-wider text-right w-32">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.data.length > 0 ? (
                                    items.data.map((item) => {
                                        const isLow = item.current_stock <= item.minimum_stock;
                                        const progress = Math.min((item.current_stock / (item.minimum_stock * 2 || 10)) * 100, 100);

                                        return (
                                            <tr
                                                key={item.id}
                                                className={`border-b border-[#334155]/50 transition-all hover:bg-[#0f172a]/40 ${isLow ? 'bg-red-950/5' : ''}`}
                                            >
                                                {/* Image */}
                                                <td className="p-4 pl-6">
                                                    <div className="relative w-12 h-12 bg-[#0f172a] rounded-lg overflow-hidden border border-[#334155]/60 flex items-center justify-center">
                                                        {item.image_path ? (
                                                            <img src={item.image_path} alt={item.item_name} className="w-full h-full object-cover" />
                                                        ) : (
                                                            <Package className="text-slate-600" size={16} />
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Details */}
                                                <td className="p-4">
                                                    <div className="flex flex-col gap-1">
                                                        <span className="font-outfit font-bold text-slate-100">{item.item_name}</span>
                                                        {!item.is_active && (
                                                            <span className="inline-flex items-center gap-1 text-[9px] bg-slate-800 text-slate-400 px-2 py-0.5 rounded uppercase font-bold w-max">
                                                                Inactive
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Category & Unit */}
                                                <td className="p-4">
                                                    <div className="flex flex-col gap-1">
                                                        <span className="text-xs font-semibold capitalize text-brand-300">{item.category}</span>
                                                        <span className="text-[10px] text-slate-500 font-mono">per {item.unit}</span>
                                                    </div>
                                                </td>

                                                {/* Stock */}
                                                <td className="p-4">
                                                    <div className="flex flex-col gap-1.5 w-full">
                                                        <div className="flex justify-between items-end">
                                                            <span className={`font-mono font-black ${isLow ? 'text-red-400' : 'text-emerald-400'}`}>
                                                                {item.current_stock}
                                                            </span>
                                                            <span className="text-[9px] text-slate-500 uppercase font-bold">Min: {item.minimum_stock}</span>
                                                        </div>
                                                        <div className="w-full bg-[#0f172a] h-1.5 rounded-full overflow-hidden">
                                                            <div
                                                                className={`h-full ${isLow ? 'bg-red-500' : 'bg-emerald-500'}`}
                                                                style={{ width: `${progress}%` }}
                                                            ></div>
                                                        </div>
                                                        {isLow && (
                                                            <span className="text-[9px] text-red-400 font-bold uppercase tracking-wider animate-pulse flex items-center gap-1">
                                                                <AlertTriangle size={8} /> Restock Needed
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Pricing */}
                                                <td className="p-4">
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-[9px] text-slate-500 uppercase font-bold w-8">Sell</span>
                                                            <span className="font-mono text-sm font-black text-slate-200">
                                                                ₱{Number(item.selling_price).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                            </span>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-[9px] text-slate-500 uppercase font-bold w-8">Cost</span>
                                                            <span className="font-mono text-[10px] text-slate-400">
                                                                ₱{Number(item.unit_cost).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="p-4 pr-6 text-right">
                                                    <button onClick={() => setActionModalItem(item)} className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f172a] hover:bg-slate-800 border border-[#334155] rounded-lg text-[10px] font-bold text-slate-300 transition-colors">
                                                        Manage
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan="6" className="py-16 text-center text-slate-500">
                                            <div className="flex flex-col items-center gap-3">
                                                <Package size={32} className="opacity-20" />
                                                <span>No catalog items found matching your filters.</span>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    {/* Pagination */}
                    {items && items.last_page > 1 && (
                        <div className="px-4 py-3 border-t border-[#334155] flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#0f172a]/40">
                            <span className="text-[10px] text-slate-500">
                                Showing {items.from}–{items.to} of {items.total} records
                            </span>
                            <Pagination links={items.links} />
                        </div>
                    )}
                </div>

                {/* MODAL: ADD CATALOG ITEM */}
                <AnimatePresence>
                    {isAddOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsAddOpen(false)} />
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
                                            <CustomSelect
                                                value={createForm.data.category}
                                                onChange={e => createForm.setData('category', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            >
                                                <option value="minibar">Minibar</option>
                                                <option value="toiletries">Toiletries</option>
                                                <option value="laundry">Laundry</option>
                                                <option value="amenities">Amenities</option>
                                                <option value="supplies">Supplies</option>
                                            </CustomSelect>
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
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsEditOpen(false)} />
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
                                            <CustomSelect
                                                value={editForm.data.category}
                                                onChange={e => editForm.setData('category', e.target.value)}
                                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            >
                                                <option value="minibar">Minibar</option>
                                                <option value="toiletries">Toiletries</option>
                                                <option value="laundry">Laundry</option>
                                                <option value="amenities">Amenities</option>
                                                <option value="supplies">Supplies</option>
                                            </CustomSelect>
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
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsAdjustOpen(false)} />
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

            </div>

            {/* MODAL: DELETE CONFIRMATION */}
            <AnimatePresence>
                {isDeleteOpen && selectedItem && (
                    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-[#070b13]/90" onClick={() => setIsDeleteOpen(false)} />
                        <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-red-900/50 rounded-2xl w-full max-w-sm shadow-2xl relative z-10 overflow-hidden">
                            <div className="p-6 flex flex-col items-center text-center gap-4">
                                <div className="w-16 h-16 rounded-full bg-red-500/10 flex items-center justify-center text-red-500 mb-2">
                                    <Trash2 size={32} />
                                </div>
                                <h2 className="font-outfit font-black text-slate-100 text-xl">Delete Item?</h2>
                                <p className="text-sm text-slate-400">
                                    Are you sure you want to delete <span className="font-bold text-slate-200">{selectedItem.item_name}</span>?
                                    This will soft delete the item so historical receipts remain intact.
                                </p>

                                <div className="flex gap-3 w-full mt-4">
                                    <button onClick={() => setIsDeleteOpen(false)} className="flex-1 px-4 py-2.5 bg-[#0f172a] hover:bg-[#334155] border border-[#334155] text-slate-300 rounded-xl text-sm font-bold transition-colors">
                                        Cancel
                                    </button>
                                    <button onClick={handleDelete} className="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-500 text-slate-50 rounded-xl text-sm font-bold transition-colors shadow-lg shadow-red-900/20">
                                        Confirm Delete
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            <ActionModal
                isOpen={!!actionModalItem}
                onClose={() => setActionModalItem(null)}
                title={`Manage ${actionModalItem?.item_name}`}
            >
                {actionModalItem && (
                    <>
                        <button
                            onClick={() => { setActionModalItem(null); openAdjustModal(actionModalItem); }}
                            className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-emerald-600/20 border border-[#334155] hover:border-emerald-500/40 rounded-xl text-xs font-bold text-emerald-400 transition-colors"
                        >
                            <RefreshCw size={16} /> Adjust Stock
                        </button>
                        {isAdmin && (
                            <button
                                onClick={() => { setActionModalItem(null); openEditModal(actionModalItem); }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-brand-600/20 border border-[#334155] hover:border-brand-500/40 rounded-xl text-xs font-bold text-brand-400 transition-colors"
                            >
                                <Edit2 size={16} /> Edit Catalog Details
                            </button>
                        )}
                        {isAdmin && (
                            <button
                                onClick={() => { setActionModalItem(null); confirmDelete(actionModalItem); }}
                                className="w-full flex items-center gap-2 px-4 py-3 bg-[#1e293b] hover:bg-red-900/30 border border-[#334155] hover:border-red-500/40 rounded-xl text-xs font-bold text-red-400 transition-colors"
                            >
                                <Trash2 size={16} /> Delete Item
                            </button>
                        )}
                    </>
                )}
            </ActionModal>
        </AuthenticatedLayout>
    );
}
