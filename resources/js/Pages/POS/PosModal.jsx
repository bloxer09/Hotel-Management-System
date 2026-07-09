import React, { useState, useMemo, useEffect, Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { useForm, router } from '@inertiajs/react';
import { 
    Search, X, Plus, Minus, ShoppingCart, 
    User, Check, CircleCheck, Utensils, Bath, 
    Tv, FileText, Wind, BedDouble, ShoppingBag
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function PosModal({ isOpen, onClose, items = [], activeBookings = [] }) {
    const [selectedCategory, setSelectedCategory] = useState('all');
    const [searchQuery, setSearchQuery] = useState('');
    const [checkoutSuccess, setCheckoutSuccess] = useState(false);
    const [lastOrderDetails, setLastOrderDetails] = useState(null);
    const [toastMessage, setToastMessage] = useState(null);

    const showToast = (msg) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    const usageForm = useForm({
        target_type: 'walk_in',
        booking_id: '',
        consumer_name: '',
        items: [],
        payment_method: 'cash',
        cash_amount: 0.00,
        gcash_amount: 0.00,
        gcash_ref: '',
        bank_amount: 0.00,
        bank_ref: ''
    });

    useEffect(() => {
        if (!isOpen) {
            setCheckoutSuccess(false);
            usageForm.reset();
            setSearchQuery('');
            setSelectedCategory('all');
            setLastOrderDetails(null);
        }
    }, [isOpen]);

    const getProductImage = (itemName = '', category = '') => {
        const name = itemName.toLowerCase();
        if (name.includes('coke') || name.includes('cola') || name.includes('soda') || name.includes('soft drink')) return 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?w=300&auto=format&fit=crop&q=60';
        if (name.includes('sprite') || name.includes('lemon')) return 'https://images.unsplash.com/photo-1625772290748-390939a579c5?w=300&auto=format&fit=crop&q=60';
        if (name.includes('beer') || name.includes('pilsen') || name.includes('alcohol')) return 'https://images.unsplash.com/photo-1608270586620-248524c67de9?w=300&auto=format&fit=crop&q=60';
        if (name.includes('water') || name.includes('mineral')) return 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=300&auto=format&fit=crop&q=60';
        if (name.includes('chips') || name.includes('potato') || name.includes('snacks') || name.includes('snack')) return 'https://images.unsplash.com/photo-1566478989037-eec170784d0b?w=300&auto=format&fit=crop&q=60';
        if (name.includes('coffee') || name.includes('nescafe') || name.includes('tea')) return 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=300&auto=format&fit=crop&q=60';
        if (name.includes('tissue') || name.includes('wipe') || name.includes('wipes')) return 'https://images.unsplash.com/photo-1618219908412-a29a1bb7b86e?w=300&auto=format&fit=crop&q=60';
        if (name.includes('toothbrush') || name.includes('dental')) return 'https://images.unsplash.com/photo-1559591937-e26941dfc72f?w=300&auto=format&fit=crop&q=60';
        if (name.includes('soap') || name.includes('bath') || name.includes('wash')) return 'https://images.unsplash.com/photo-1607006342411-9a3363b63b2f?w=300&auto=format&fit=crop&q=60';
        if (name.includes('shampoo') || name.includes('conditioner')) return 'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=300&auto=format&fit=crop&q=60';
        if (name.includes('detergent') || name.includes('powder') || name.includes('fabric')) return 'https://images.unsplash.com/photo-1610557892470-76d747eed2f3?w=300&auto=format&fit=crop&q=60';
        if (name.includes('slipper') || name.includes('slippers')) return 'https://images.unsplash.com/photo-1595950653106-6c9ebd614d3a?w=300&auto=format&fit=crop&q=60';
        if (name.includes('towel') || name.includes('towels') || name.includes('linen')) return 'https://images.unsplash.com/photo-1563453392212-326f5e854473?w=300&auto=format&fit=crop&q=60';

        switch (category) {
            case 'minibar': return 'https://images.unsplash.com/photo-1540340561282-411149a2d90c?w=300&auto=format&fit=crop&q=60';
            case 'toiletries': return 'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=300&auto=format&fit=crop&q=60';
            case 'laundry': return 'https://images.unsplash.com/photo-1545173168-9f1947eebd01?w=300&auto=format&fit=crop&q=60';
            case 'supplies': return 'https://images.unsplash.com/photo-1586075010923-2dd4570fb338?w=300&auto=format&fit=crop&q=60';
            case 'amenities': return 'https://images.unsplash.com/photo-1582719508461-905c673771fd?w=300&auto=format&fit=crop&q=60';
            default: return 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=300&auto=format&fit=crop&q=60';
        }
    };

    const categories = [
        { id: 'all', name: 'All', icon: ShoppingBag },
        { id: 'minibar', name: 'Minibar', icon: Utensils },
        { id: 'toiletries', name: 'Toilet', icon: Bath },
        { id: 'laundry', name: 'Laundry', icon: Wind },
        { id: 'amenities', name: 'Amenities', icon: Tv },
        { id: 'supplies', name: 'Supplies', icon: FileText },
    ];

    const filteredItems = useMemo(() => {
        return items.filter(item => {
            const matchesCategory = selectedCategory === 'all' || item.category === selectedCategory;
            const matchesSearch = item.item_name.toLowerCase().includes(searchQuery.toLowerCase());
            return matchesCategory && matchesSearch;
        });
    }, [items, selectedCategory, searchQuery]);

    const addToCart = (item) => {
        if (item.current_stock <= 0) return;
        const existingIndex = usageForm.data.items.findIndex(i => i.item_id === item.id);
        const list = [...usageForm.data.items];

        if (existingIndex > -1) {
            const newQty = list[existingIndex].quantity + 1;
            if (newQty > item.current_stock) {
                showToast(`Limit: ${item.current_stock} ${item.unit}s available.`);
                return;
            }
            list[existingIndex].quantity = newQty;
        } else {
            list.push({
                item_id: item.id,
                name: item.item_name,
                price: Number(item.selling_price),
                quantity: 1,
                unit: item.unit,
                max_stock: item.current_stock
            });
        }
        usageForm.setData('items', list);
    };

    const updateQuantity = (itemId, delta) => {
        const list = [...usageForm.data.items];
        const idx = list.findIndex(i => i.item_id === itemId);
        if (idx === -1) return;
        const newQty = list[idx].quantity + delta;
        if (newQty <= 0) {
            list.splice(idx, 1);
        } else {
            if (newQty > list[idx].max_stock) {
                showToast(`Limit: ${list[idx].max_stock} ${list[idx].unit}s available.`);
                return;
            }
            list[idx].quantity = newQty;
        }
        usageForm.setData('items', list);
    };

    const clearCart = () => {
        usageForm.setData('items', []);
    };

    const cartTotals = useMemo(() => {
        const count = usageForm.data.items.reduce((acc, i) => acc + i.quantity, 0);
        const subtotal = usageForm.data.items.reduce((acc, i) => acc + (i.price * i.quantity), 0);
        const total = subtotal;
        return { count, subtotal, total };
    }, [usageForm.data.items]);

    const handleCheckout = (e) => {
        e.preventDefault();
        if (usageForm.data.items.length === 0) {
            showToast('Cart is empty.');
            return;
        }
        if (usageForm.data.target_type === 'booking' && !usageForm.data.booking_id) {
            showToast('Select a room stay to charge.');
            return;
        }
        if (usageForm.data.target_type === 'walk_in' && !usageForm.data.consumer_name.trim()) {
            showToast('Enter walk-in consumer name.');
            return;
        }

        const totalCharge = cartTotals.total;
        let totalPaid = 0;
        if (usageForm.data.payment_method === 'cash') {
            totalPaid = usageForm.data.cash_amount;
        } else if (usageForm.data.payment_method === 'split') {
            totalPaid = Number(usageForm.data.cash_amount || 0) + Number(usageForm.data.gcash_amount || 0) + Number(usageForm.data.bank_amount || 0);
        } else {
            // GCash or Bank Transfer assumed full payment via reference, but we can enforce input
            totalPaid = usageForm.data.payment_method === 'gcash' ? (usageForm.data.gcash_amount || 0) : (usageForm.data.bank_amount || 0);
        }

        if (totalPaid < totalCharge && usageForm.data.target_type !== 'booking') {
            showToast('Insufficient payment amount.');
            return;
        }

        const payload = { ...usageForm.data };
        router.post(route('pos.checkout'), payload, {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
            }
        });
    };

    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-[100]">
                {/* Backdrop transition */}
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-[#070b13]/80 backdrop-blur-sm" />
                </TransitionChild>

                {/* Dialog Panel wrapper */}
                <div className="fixed inset-0 flex items-center justify-center p-4 sm:p-6 overflow-hidden">
                    <TransitionChild
                        as={Fragment}
                        enter="ease-out duration-300"
                        enterFrom="opacity-0 scale-95"
                        enterTo="opacity-100 scale-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100 scale-100"
                        leaveTo="opacity-0 scale-95"
                    >
                        <DialogPanel className="bg-[#0f172a] border border-[#334155] rounded-3xl shadow-2xl w-full max-w-7xl max-h-[90vh] h-[850px] relative z-10 flex flex-col overflow-hidden">
                        {/* Header */}
                        <div className="h-16 flex items-center justify-between px-6 border-b border-[#334155] bg-[#1e293b] shrink-0">
                            <h2 className="font-outfit font-extrabold text-lg text-slate-100 flex items-center gap-2">
                                <ShoppingCart size={20} className="text-brand-400"/> New POS Sale
                            </h2>
                            <button onClick={onClose} className="p-2 bg-[#0f172a] hover:bg-slate-800 rounded-xl text-slate-400 transition-colors">
                                <X size={20} />
                            </button>
                        </div>

                        <div className="flex-1 flex flex-col lg:flex-row min-h-0 overflow-hidden">
                                
                                {/* Catalog Area */}
                                <div className="flex-1 flex flex-col p-4 md:p-6 min-h-0 relative border-r border-[#334155]">
                                    <div className="flex gap-4 mb-4 shrink-0">
                                        <div className="relative flex-1">
                                            <Search size={18} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500" />
                                            <input
                                                type="text"
                                                placeholder="Search catalog..."
                                                value={searchQuery}
                                                onChange={e => setSearchQuery(e.target.value)}
                                                className="w-full bg-[#1e293b] border border-[#334155] rounded-xl pl-11 pr-4 py-3 text-slate-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500/20 font-medium"
                                            />
                                        </div>
                                    </div>
                                    <div className="flex gap-2 overflow-x-auto pb-2 scrollbar-none mb-4 shrink-0">
                                        {categories.map(cat => (
                                            <button
                                                key={cat.id}
                                                onClick={() => setSelectedCategory(cat.id)}
                                                className={`px-4 py-2 rounded-xl flex items-center gap-2 whitespace-nowrap text-[11px] font-bold uppercase tracking-wider transition-all ${
                                                    selectedCategory === cat.id 
                                                        ? 'bg-brand-600 text-white shadow-lg shadow-brand-500/20 border border-brand-500' 
                                                        : 'bg-[#1e293b] text-slate-400 border border-[#334155] hover:bg-[#334155]'
                                                }`}
                                            >
                                                <cat.icon size={14} />
                                                {cat.name}
                                            </button>
                                        ))}
                                    </div>
                                    <div className="flex-1 overflow-y-auto pr-2 scrollbar-thin">
                                        <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4 pb-4">
                                            {filteredItems.map(item => (
                                                <div 
                                                    key={item.id} 
                                                    onClick={() => addToCart(item)}
                                                    className={`group bg-[#1e293b] border border-[#334155]/60 rounded-2xl overflow-hidden cursor-pointer transition-all hover:-translate-y-1 ${
                                                        item.current_stock <= 0 ? 'opacity-50 grayscale cursor-not-allowed' : 'hover:shadow-xl hover:border-brand-500/30 hover:shadow-brand-500/5'
                                                    }`}
                                                >
                                                    <div className="h-28 bg-[#0f172a] relative">
                                                        <img 
                                                            src={item.image_path || getProductImage(item.item_name, item.category)} 
                                                            alt={item.item_name}
                                                            className="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity"
                                                        />
                                                        {item.current_stock <= 0 && (
                                                            <div className="absolute inset-0 flex items-center justify-center bg-black/60 backdrop-blur-sm">
                                                                <span className="font-outfit font-black text-red-500 uppercase tracking-widest text-[10px] px-2 py-1 bg-red-950/80 rounded border border-red-500/30">
                                                                    Out of Stock
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="p-3.5">
                                                        <h3 className="font-outfit font-bold text-slate-200 text-xs mb-1 truncate">{item.item_name}</h3>
                                                        <div className="flex justify-between items-end mt-2">
                                                            <span className="font-mono font-black text-brand-400 text-sm">₱{Number(item.selling_price).toFixed(2)}</span>
                                                            <span className={`text-[10px] font-bold uppercase ${item.current_stock > item.minimum_stock ? 'text-slate-500' : 'text-red-400'}`}>
                                                                {item.current_stock} left
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                {/* Cart Area */}
                                <div className="w-full lg:w-[380px] bg-[#1e293b]/50 p-4 md:p-6 flex flex-col min-h-0 shrink-0">
                                    <div className="flex items-center justify-between mb-4 shrink-0">
                                        <div className="flex items-center gap-2">
                                            <ShoppingCart className="text-emerald-400 shrink-0" size={16} />
                                            <h2 className="font-outfit font-black text-base text-slate-100">Cart</h2>
                                            <span className="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] px-2 py-0.5 rounded-full font-bold ml-1">
                                                {cartTotals.count} items
                                            </span>
                                        </div>
                                        {usageForm.data.items.length > 0 && (
                                            <button onClick={clearCart} className="text-[10px] text-slate-500 hover:text-red-400 font-bold uppercase transition-colors">Clear</button>
                                        )}
                                    </div>

                                    <form onSubmit={handleCheckout} className="flex flex-col gap-4 flex-1 overflow-y-auto scrollbar-thin pr-1">
                                        <div className="space-y-2 shrink-0 min-h-[150px]">
                                            {usageForm.data.items.length === 0 ? (
                                                <div className="py-8 flex flex-col items-center justify-center text-slate-500 text-center px-2">
                                                    <ShoppingBag size={32} className="stroke-[1.5] mb-2 opacity-30" />
                                                    <p className="font-outfit font-bold text-xs">Cart is empty</p>
                                                    <p className="text-[10px] text-slate-500 mt-1">Select items in the catalog to add them.</p>
                                                </div>
                                            ) : (
                                                usageForm.data.items.map((line) => (
                                                    <div key={line.item_id} className="p-3 rounded-xl bg-[#0f172a]/80 border border-[#334155]/60 flex items-center justify-between gap-3 group">
                                                        <div className="min-w-0 flex-1">
                                                            <h4 className="font-outfit font-bold text-xs text-slate-200 truncate leading-tight">{line.name}</h4>
                                                            <p className="text-[10px] text-slate-500 mt-0.5">₱{line.price.toFixed(2)} / {line.unit}</p>
                                                        </div>
                                                        <div className="flex items-center gap-1 shrink-0">
                                                            <button type="button" onClick={() => updateQuantity(line.item_id, -1)} className="h-7 w-7 rounded bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-slate-100 flex items-center justify-center"><Minus size={10} /></button>
                                                            <span className="font-mono text-xs font-bold w-6 text-center text-slate-200">{line.quantity}</span>
                                                            <button type="button" onClick={() => updateQuantity(line.item_id, 1)} className="h-7 w-7 rounded bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-slate-100 flex items-center justify-center"><Plus size={10} /></button>
                                                        </div>
                                                        <div className="text-right shrink-0 min-w-[60px] flex flex-col items-end">
                                                            <span className="font-mono font-bold text-xs text-brand-400">₱{(line.price * line.quantity).toFixed(2)}</span>
                                                        </div>
                                                    </div>
                                                ))
                                            )}
                                        </div>

                                        <div className="space-y-1.5 border-t border-[#334155]/60 pt-4 mt-auto">
                                            <span className="text-[10px] uppercase font-bold tracking-wider text-slate-400">Bill Target</span>
                                            <div className="grid grid-cols-2 gap-2">
                                                <button type="button" onClick={() => usageForm.setData(d => ({...d, target_type: 'booking', consumer_name: ''}))} className={`py-2 rounded-xl text-center font-outfit font-extrabold text-[10px] uppercase tracking-wider border transition-all flex items-center justify-center gap-1.5 ${usageForm.data.target_type === 'booking' ? 'bg-brand-600 text-white border-brand-500 shadow-md' : 'bg-[#0f172a]/50 text-slate-400 border-transparent hover:bg-[#0f172a]'}`}><BedDouble size={12} /> Charge Room</button>
                                                <button type="button" onClick={() => usageForm.setData(d => ({...d, target_type: 'walk_in', booking_id: ''}))} className={`py-2 rounded-xl text-center font-outfit font-extrabold text-[10px] uppercase tracking-wider border transition-all flex items-center justify-center gap-1.5 ${usageForm.data.target_type === 'walk_in' ? 'bg-brand-600 text-white border-brand-500 shadow-md' : 'bg-[#0f172a]/50 text-slate-400 border-transparent hover:bg-[#0f172a]'}`}><User size={12} /> Direct Sale</button>
                                            </div>
                                        </div>

                                        {usageForm.data.target_type === 'booking' ? (
                                            <select value={usageForm.data.booking_id} onChange={e => usageForm.setData('booking_id', e.target.value)} className="w-full py-2.5 px-3 rounded-xl bg-[#0f172a] border border-[#334155] text-slate-200 text-xs font-semibold focus:border-brand-500 focus:ring-1">
                                                <option value="">Select Stay / Guest...</option>
                                                {activeBookings.map(b => (
                                                    <option key={b.id} value={b.id}>Room {b.room?.room_number || 'N/A'} — {b.guest_name}</option>
                                                ))}
                                            </select>
                                        ) : (
                                            <input type="text" placeholder="Walk-in Customer Name..." value={usageForm.data.consumer_name} onChange={e => usageForm.setData('consumer_name', e.target.value)} className="w-full py-2.5 px-3 rounded-xl bg-[#0f172a] border border-[#334155] text-slate-200 text-xs font-semibold focus:border-brand-500 focus:ring-1" />
                                        )}

                                        {/* Payments Setup */}
                                        <div className="pt-3 border-t border-[#334155]/60 space-y-3 shrink-0">
                                            <div className="flex flex-col gap-1.5">
                                                <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Payment Method</label>
                                                <select value={usageForm.data.payment_method} onChange={e => usageForm.setData('payment_method', e.target.value)} className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 font-bold text-xs focus:border-brand-500">
                                                    <option value="cash">Cash</option>
                                                    <option value="gcash">GCash</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="split">Split Payment</option>
                                                </select>
                                            </div>

                                            {['gcash', 'split'].includes(usageForm.data.payment_method) && (
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">GCash Amount / Ref</label>
                                                    <div className="flex gap-2">
                                                        <input type="number" placeholder="Amt" value={usageForm.data.gcash_amount || ''} onChange={e => usageForm.setData('gcash_amount', parseFloat(e.target.value) || 0)} className="w-1/3 bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 font-mono text-xs" />
                                                        <input type="text" placeholder="Reference No." value={usageForm.data.gcash_ref} onChange={e => usageForm.setData('gcash_ref', e.target.value)} className="flex-1 bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 text-xs" />
                                                    </div>
                                                </div>
                                            )}

                                            {['bank_transfer', 'split'].includes(usageForm.data.payment_method) && (
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Bank Amount / Ref</label>
                                                    <div className="flex gap-2">
                                                        <input type="number" placeholder="Amt" value={usageForm.data.bank_amount || ''} onChange={e => usageForm.setData('bank_amount', parseFloat(e.target.value) || 0)} className="w-1/3 bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 font-mono text-xs" />
                                                        <input type="text" placeholder="Reference No." value={usageForm.data.bank_ref} onChange={e => usageForm.setData('bank_ref', e.target.value)} className="flex-1 bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2 text-xs" />
                                                    </div>
                                                </div>
                                            )}

                                            {['cash', 'split'].includes(usageForm.data.payment_method) && (
                                                <div className="flex flex-col gap-1.5">
                                                    <label className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Cash Received</label>
                                                    <input type="number" placeholder="0.00" value={usageForm.data.cash_amount || ''} onChange={e => usageForm.setData('cash_amount', parseFloat(e.target.value) || 0)} className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-3 py-2.5 font-mono text-emerald-400 font-bold text-sm" />
                                                </div>
                                            )}
                                        </div>

                                        <div className="p-3.5 rounded-xl bg-[#0f172a]/60 border border-[#334155]/65 space-y-2 text-xs text-slate-400 shrink-0 mt-2">
                                            <div className="flex justify-between items-baseline">
                                                <span className="font-outfit font-extrabold text-slate-200 text-[11px] uppercase tracking-wide">Total</span>
                                                <span className="font-mono text-lg font-black text-brand-400">₱{cartTotals.total.toFixed(2)}</span>
                                            </div>
                                            {['cash', 'split'].includes(usageForm.data.payment_method) && (
                                                <div className="flex justify-between items-baseline pt-2 border-t border-[#334155]/60 mt-2">
                                                    <span className="font-outfit font-extrabold text-slate-200 text-[11px] uppercase tracking-wide">Change</span>
                                                    <span className="font-mono text-sm font-black text-emerald-400">
                                                        ₱{Math.max(0, (usageForm.data.cash_amount || 0) - (usageForm.data.payment_method === 'split' ? (cartTotals.total - (usageForm.data.gcash_amount||0) - (usageForm.data.bank_amount||0)) : cartTotals.total)).toFixed(2)}
                                                    </span>
                                                </div>
                                            )}
                                        </div>

                                        <button type="submit" disabled={usageForm.processing || usageForm.data.items.length === 0} className="w-full py-3.5 mt-2 bg-brand-600 hover:bg-brand-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-xl text-white font-bold text-sm font-outfit transition-all flex items-center justify-center gap-2 shadow-lg shadow-brand-600/20 shrink-0">
                                            <Check size={16} /> Checkout Purchase
                                        </button>
                                    </form>
                                </div>
                            </div>


                        <AnimatePresence>
                            {toastMessage && (
                                <motion.div initial={{ opacity: 0, y: 20, x: '-50%' }} animate={{ opacity: 1, y: 0, x: '-50%' }} exit={{ opacity: 0, y: 20, x: '-50%' }} className="absolute bottom-6 left-1/2 z-[99999] px-5 py-3.5 bg-red-950/90 border border-red-500/40 text-red-200 rounded-2xl shadow-2xl backdrop-blur-md text-xs font-bold flex items-center gap-2.5">
                                    <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse shrink-0" />
                                    <span>{toastMessage}</span>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </Transition>
    );
}
