import React, { useState, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { 
    Package, 
    ArrowLeft, 
    Plus, 
    Minus, 
    Trash2, 
    ShoppingCart, 
    User, 
    Search, 
    X, 
    Check, 
    Utensils, 
    Bath, 
    Tv, 
    FileText, 
    Wind,
    CircleCheck,
    BedDouble,
    ShoppingBag
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function BulkUsage({ items = [], activeBookings = [] }) {
    // Left category menu selection
    const [selectedCategory, setSelectedCategory] = useState('all');
    // Search query for items
    const [searchQuery, setSearchQuery] = useState('');
    // Successful checkout show state
    const [checkoutSuccess, setCheckoutSuccess] = useState(false);
    const [lastOrderDetails, setLastOrderDetails] = useState(null);
    const [isCartOpen, setIsCartOpen] = useState(false);

    // Toast notification state
    const [toastMessage, setToastMessage] = useState(null);
    const showToast = (msg) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    // Dynamic POS Cart State managed via useForm
    const usageForm = useForm({
        target_type: 'booking', // 'booking' or 'walk_in'
        booking_id: '',
        consumer_name: '',
        items: [] // array of { item_id, quantity, name, price, unit }
    });

    // Curated high-resolution Unsplash images for products based on category/name
    const getProductImage = (itemName = '', category = '') => {
        const name = itemName.toLowerCase();
        
        if (name.includes('coke') || name.includes('cola') || name.includes('soda') || name.includes('soft drink')) {
            return 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('sprite') || name.includes('lemon')) {
            return 'https://images.unsplash.com/photo-1625772290748-390939a579c5?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('beer') || name.includes('pilsen') || name.includes('alcohol')) {
            return 'https://images.unsplash.com/photo-1608270586620-248524c67de9?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('water') || name.includes('mineral')) {
            return 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('chips') || name.includes('potato') || name.includes('snacks') || name.includes('snack')) {
            return 'https://images.unsplash.com/photo-1566478989037-eec170784d0b?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('coffee') || name.includes('nescafe') || name.includes('tea')) {
            return 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('tissue') || name.includes('wipe') || name.includes('wipes')) {
            return 'https://images.unsplash.com/photo-1618219908412-a29a1bb7b86e?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('toothbrush') || name.includes('dental')) {
            return 'https://images.unsplash.com/photo-1559591937-e26941dfc72f?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('toothpaste')) {
            return 'https://images.unsplash.com/photo-1559591937-e26941dfc72f?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('soap') || name.includes('bath') || name.includes('wash')) {
            return 'https://images.unsplash.com/photo-1607006342411-9a3363b63b2f?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('shampoo') || name.includes('conditioner')) {
            return 'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('detergent') || name.includes('powder') || name.includes('fabric')) {
            return 'https://images.unsplash.com/photo-1610557892470-76d747eed2f3?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('slipper') || name.includes('slippers')) {
            return 'https://images.unsplash.com/photo-1595950653106-6c9ebd614d3a?w=300&auto=format&fit=crop&q=60';
        }
        if (name.includes('towel') || name.includes('towels') || name.includes('linen')) {
            return 'https://images.unsplash.com/photo-1563453392212-326f5e854473?w=300&auto=format&fit=crop&q=60';
        }

        switch (category) {
            case 'minibar':
                return 'https://images.unsplash.com/photo-1540340561282-411149a2d90c?w=300&auto=format&fit=crop&q=60';
            case 'toiletries':
                return 'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=300&auto=format&fit=crop&q=60';
            case 'laundry':
                return 'https://images.unsplash.com/photo-1545173168-9f1947eebd01?w=300&auto=format&fit=crop&q=60';
            case 'supplies':
                return 'https://images.unsplash.com/photo-1586075010923-2dd4570fb338?w=300&auto=format&fit=crop&q=60';
            case 'amenities':
                return 'https://images.unsplash.com/photo-1582719508461-905c673771fd?w=300&auto=format&fit=crop&q=60';
            default:
                return 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=300&auto=format&fit=crop&q=60';
        }
    };

    // Categories mapping with sleek design matching PMS theme
    const categories = [
        { id: 'all', name: 'All Items', icon: ShoppingBag },
        { id: 'minibar', name: 'Minibar', icon: Utensils },
        { id: 'toiletries', name: 'Toiletries', icon: Bath },
        { id: 'laundry', name: 'Laundry', icon: Wind },
        { id: 'amenities', name: 'Amenities', icon: Tv },
        { id: 'supplies', name: 'Supplies', icon: FileText },
    ];

    // Filter items based on active category and search input
    const filteredItems = useMemo(() => {
        return items.filter(item => {
            const matchesCategory = selectedCategory === 'all' || item.category === selectedCategory;
            const matchesSearch = item.item_name.toLowerCase().includes(searchQuery.toLowerCase());
            return matchesCategory && matchesSearch;
        });
    }, [items, selectedCategory, searchQuery]);

    // Cart Helper Actions
    const addToCart = (item) => {
        if (item.current_stock <= 0) return;
        
        const existingIndex = usageForm.data.items.findIndex(i => i.item_id === item.id);
        const list = [...usageForm.data.items];

        if (existingIndex > -1) {
            const newQty = list[existingIndex].quantity + 1;
            if (newQty > item.current_stock) {
                showToast(`Cannot exceed available stock of ${item.current_stock} ${item.unit}s.`);
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
                showToast(`Cannot exceed available stock of ${list[idx].max_stock} ${list[idx].unit}s.`);
                return;
            }
            list[idx].quantity = newQty;
        }
        usageForm.setData('items', list);
    };

    const removeFromCart = (itemId) => {
        const list = usageForm.data.items.filter(i => i.item_id !== itemId);
        usageForm.setData('items', list);
    };

    const clearCart = () => {
        usageForm.setData('items', []);
    };

    // Calculate totals
    const cartTotals = useMemo(() => {
        const count = usageForm.data.items.reduce((acc, i) => acc + i.quantity, 0);
        const subtotal = usageForm.data.items.reduce((acc, i) => acc + (i.price * i.quantity), 0);
        const total = subtotal;
        return { count, subtotal, total };
    }, [usageForm.data.items]);

    // Handle POS Checkout Submission
    const handleCheckout = (e) => {
        e.preventDefault();
        
        if (usageForm.data.items.length === 0) {
            showToast('Your active cart is empty. Add items from the catalog.');
            return;
        }

        if (usageForm.data.target_type === 'booking' && !usageForm.data.booking_id) {
            showToast('Please select an active guest room stay to charge these items.');
            return;
        }

        if (usageForm.data.target_type === 'walk_in' && !usageForm.data.consumer_name.trim()) {
            showToast('Please specify a walk-in consumer name or description.');
            return;
        }

        const payload = {
            booking_id: usageForm.data.target_type === 'booking' ? usageForm.data.booking_id : null,
            consumer_name: usageForm.data.target_type === 'walk_in' ? usageForm.data.consumer_name : null,
            items: usageForm.data.items.map(i => ({ item_id: i.item_id, quantity: i.quantity }))
        };

        router.post(route('inventory.use'), payload, {
            preserveScroll: true,
            onSuccess: () => {
                let targetLabel = 'Walk-In Customer';
                if (usageForm.data.target_type === 'booking') {
                    const matched = activeBookings.find(b => String(b.id) === String(usageForm.data.booking_id));
                    if (matched) {
                        targetLabel = `Room ${matched.room?.room_number} - ${matched.guest_name}`;
                    }
                } else {
                    targetLabel = usageForm.data.consumer_name;
                }

                setLastOrderDetails({
                    items: [...usageForm.data.items],
                    total: cartTotals.total,
                    target: targetLabel,
                    orderNo: Math.floor(1000 + Math.random() * 9000)
                });
                
                setCheckoutSuccess(true);
                setIsCartOpen(false);

                // Reset form
                usageForm.reset({
                    target_type: 'booking',
                    booking_id: '',
                    consumer_name: '',
                    items: []
                });
            }
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Bulk Item Usage - Staff POS" />

            <div className="flex flex-col gap-6 h-full text-slate-100 relative">
                
                {/* Clean Header matching other PMS pages */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 shrink-0">
                    <div className="flex items-center gap-4">
                        <Link 
                            href={route('inventory.index')}
                            className="p-2.5 rounded-xl bg-[#1e293b] border border-[#334155] text-slate-400 hover:text-slate-200 hover:bg-[#334155]/60 transition-all shadow-md"
                        >
                            <ArrowLeft size={16} />
                        </Link>
                        <div>
                            <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                                Bulk Item Usage
                            </h1>
                            <p className="text-sm text-slate-400 font-medium mt-0.5">Quick administrative console to record inventory consumption and charge room stays or log direct sales.</p>
                        </div>
                    </div>
                </div>

                {/* Main POS Container - Spacious Catalog first */}
                <div className="flex flex-col gap-6 w-full pb-24">
                    
                    {/* Search and Category pills (Full Width, Airy) */}
                    <div className="flex flex-col gap-4 bg-[#1e293b] border border-[#334155] rounded-3xl p-5 shadow-xl shrink-0">
                        {/* Search Input bar */}
                        <div className="relative">
                            <span className="absolute inset-y-0 left-4 flex items-center pointer-events-none text-slate-500">
                                <Search size={18} className="text-slate-400" />
                            </span>
                            <input
                                type="text"
                                placeholder="Search items by name... (e.g. Sprite, Soap)"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="w-full pl-12 pr-10 py-3.5 rounded-2xl bg-[#0f172a] border border-[#334155] text-slate-100 placeholder-slate-500 font-sans text-xs focus:border-brand-500 focus:ring-1 focus:ring-brand-500/20 shadow-inner transition-all"
                            />
                            {searchQuery && (
                                <button
                                    onClick={() => setSearchQuery('')}
                                    className="absolute inset-y-0 right-4 flex items-center text-slate-400 hover:text-slate-200"
                                >
                                    <X size={18} />
                                </button>
                            )}
                        </div>

                        {/* categories selector */}
                        <div className="flex items-center gap-2.5 overflow-x-auto pb-1 scrollbar-none">
                            {categories.map((cat) => {
                                const IconComp = cat.icon;
                                const isSelected = selectedCategory === cat.id;
                                return (
                                    <button
                                        key={cat.id}
                                        onClick={() => setSelectedCategory(cat.id)}
                                        className={`px-5 py-2.5 rounded-2xl flex items-center gap-2 transition-all border text-xs font-bold whitespace-nowrap shadow-sm ${
                                            isSelected 
                                                ? 'bg-brand-600 border-brand-500 text-white shadow-lg shadow-brand-600/20' 
                                                : 'bg-[#0f172a]/50 border-[#334155]/40 text-slate-400 hover:bg-[#334155]/40 hover:text-slate-100'
                                        }`}
                                    >
                                        <IconComp size={14} className="shrink-0" />
                                        <span>{cat.name}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Product Grid Area inside Spacious Container */}
                    <div className="bg-[#1e293b] border border-[#334155] rounded-3xl p-6 shadow-xl">
                        {filteredItems.length === 0 ? (
                            <div className="py-20 flex flex-col items-center justify-center text-slate-500 p-8">
                                <Package size={48} className="stroke-[1.5] mb-3.5 opacity-40 text-slate-400" />
                                <p className="font-outfit font-bold text-sm">No items found matching criteria</p>
                                <p className="text-xs text-slate-650 mt-1">Try selecting a different category or clearing search</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                                {filteredItems.map((item) => {
                                    const isInCart = usageForm.data.items.find(i => i.item_id === item.id);
                                    const cartQty = isInCart ? isInCart.quantity : 0;
                                    const isLowStock = item.current_stock > 0 && item.current_stock <= item.minimum_stock;
                                    const isOutOfStock = item.current_stock <= 0;

                                    return (
                                        <div
                                            key={item.id}
                                            className={`rounded-2xl border bg-[#0f172a]/30 flex flex-col overflow-hidden relative transition-all duration-300 group ${
                                                isInCart 
                                                    ? 'border-brand-500 shadow-md ring-1 ring-brand-500/20 bg-brand-500/5' 
                                                    : 'border-[#334155]/60 hover:border-[#475569] hover:bg-[#0f172a]/55'
                                            }`}
                                        >
                                            {/* Mini badge count if already added */}
                                            {cartQty > 0 && (
                                                <div className="absolute top-3 right-3 bg-brand-600 text-white font-mono font-bold text-[10px] h-6 w-6 rounded-full flex items-center justify-center shadow-lg border border-brand-400 z-10 animate-pulse-subtle">
                                                    {cartQty}
                                                </div>
                                            )}

                                            <div className="p-4 flex-1 flex flex-col justify-between gap-4">
                                                {/* Media Block representing Catalog Item */}
                                                <div className="h-36 rounded-xl bg-slate-950/70 flex items-center justify-center relative overflow-hidden border border-[#334155]/30 shadow-inner">
                                                    <img 
                                                        src={getProductImage(item.item_name, item.category)} 
                                                        alt={item.item_name}
                                                        className="absolute inset-0 h-full w-full object-cover opacity-80 group-hover:opacity-100 group-hover:scale-110 transition-all duration-500 ease-out" 
                                                    />
                                                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent" />
                                                    
                                                    {/* Dynamic Category Pill */}
                                                    <span className="absolute bottom-2 left-2 text-[8px] uppercase tracking-wider font-extrabold px-2 py-0.5 rounded bg-slate-900/90 border border-slate-800 text-slate-400">
                                                        {item.category}
                                                    </span>

                                                    {/* Stock Status Badge */}
                                                    <span className={`absolute top-2 left-2 text-[8px] uppercase tracking-widest font-black px-2 py-0.5 rounded shadow ${
                                                        isOutOfStock 
                                                            ? 'bg-red-950/90 text-red-400 border border-red-500/20' 
                                                            : isLowStock 
                                                                ? 'bg-amber-950/90 text-amber-400 border border-amber-500/20' 
                                                                : 'bg-emerald-950/90 text-emerald-400 border border-emerald-500/20'
                                                    }`}>
                                                        {isOutOfStock ? 'Out of Stock' : `${item.current_stock} ${item.unit}s`}
                                                    </span>
                                                </div>

                                                <div>
                                                    <h3 className="font-outfit font-extrabold text-sm tracking-wide text-slate-200 line-clamp-2 min-h-[36px] leading-snug group-hover:text-white transition-colors" title={item.item_name}>
                                                        {item.item_name}
                                                    </h3>
                                                    <div className="flex items-baseline justify-between mt-1">
                                                        <span className="text-base font-outfit font-black text-brand-400">
                                                            ₱{Number(item.selling_price).toFixed(2)}
                                                        </span>
                                                        <span className="text-[10px] text-slate-500 font-bold">
                                                            per {item.unit}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Action Buttons */}
                                            <div className="px-4 pb-4 shrink-0">
                                                {isOutOfStock ? (
                                                    <button
                                                        disabled
                                                        className="w-full py-2.5 rounded-xl bg-slate-800 text-slate-600 font-outfit font-bold text-[10px] uppercase tracking-wider border border-[#334155] cursor-not-allowed text-center"
                                                    >
                                                        Unavailable
                                                    </button>
                                                ) : cartQty > 0 ? (
                                                    <div className="flex items-center gap-1 bg-[#0f172a] rounded-xl p-1 border border-brand-500/30">
                                                        <button
                                                            type="button"
                                                            onClick={() => updateQuantity(item.id, -1)}
                                                            className="h-8 w-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center transition-colors shadow"
                                                        >
                                                            <Minus size={12} />
                                                        </button>
                                                        <span className="flex-1 text-center font-mono font-bold text-[11px] text-white">
                                                            {cartQty} {item.unit}s
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => addToCart(item)}
                                                            className="h-8 w-8 rounded-lg bg-brand-600 hover:bg-brand-500 text-white flex items-center justify-center transition-colors shadow"
                                                        >
                                                            <Plus size={12} />
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            addToCart(item);
                                                        }}
                                                        className="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-outfit font-extrabold text-[10px] uppercase tracking-wider shadow transition-all flex items-center justify-center gap-1.5 border border-brand-500/30 active:scale-95"
                                                    >
                                                        <Plus size={12} /> Select Item
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>

                {/* Floating Bottom Cart Pill Trigger */}
                <AnimatePresence>
                    {cartTotals.count > 0 && (
                        <motion.div
                            initial={{ opacity: 0, y: 50, scale: 0.9 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 50, scale: 0.9 }}
                            className="fixed bottom-6 right-6 z-40"
                        >
                            <button
                                onClick={() => setIsCartOpen(true)}
                                className="flex items-center gap-3 px-6 py-4 bg-brand-600 hover:bg-brand-500 border border-brand-400 text-white rounded-full font-outfit font-extrabold text-xs uppercase tracking-widest shadow-2xl hover:shadow-brand-500/30 active:scale-95 transition-all duration-200"
                            >
                                <ShoppingCart size={16} />
                                <span>View Cart</span>
                                <span className="bg-white/20 px-2 py-0.5 rounded-full text-[10px] font-bold">
                                    {cartTotals.count}
                                </span>
                                <span className="border-l border-white/20 pl-3 text-brand-100 font-mono">
                                    ₱{cartTotals.total.toFixed(2)}
                                </span>
                            </button>
                        </motion.div>
                    )}
                </AnimatePresence>

                {/* Centered Checkout Modal */}
                <AnimatePresence>
                    {isCartOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto">
                            {/* Modal Backdrop */}
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 0.6 }}
                                exit={{ opacity: 0 }}
                                onClick={() => setIsCartOpen(false)}
                                className="absolute inset-0 bg-black"
                            />

                            {/* Centered Modal Card Container */}
                            <motion.div
                                initial={{ scale: 0.95, opacity: 0, y: 15 }}
                                animate={{ scale: 1, opacity: 1, y: 0 }}
                                exit={{ scale: 0.95, opacity: 0, y: 15 }}
                                transition={{ type: 'spring', damping: 25, stiffness: 220 }}
                                className="w-full max-w-md bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl p-6 relative z-10 flex flex-col overflow-hidden"
                            >
                                {/* Header — matches standard add modals */}
                                <div className="flex items-center justify-between mb-5 shrink-0">
                                    <div className="flex items-center gap-2 text-slate-100">
                                        <ShoppingCart className="text-emerald-400 shrink-0" size={18} />
                                        <h2 className="font-outfit font-black text-lg text-slate-100">Purchase Cart</h2>
                                        <span className="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] px-2.5 py-0.5 rounded-full font-bold ml-1">
                                            {cartTotals.count} items
                                        </span>
                                    </div>
         
                                    <div className="flex items-center gap-3">
                                        {usageForm.data.items.length > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    clearCart();
                                                    setIsCartOpen(false);
                                                }}
                                                className="text-[10px] text-slate-500 hover:text-red-400 font-bold uppercase tracking-wider flex items-center gap-1 transition-colors"
                                            >
                                                <Trash2 size={12} /> Clear
                                            </button>
                                        )}
                                        <button 
                                            type="button"
                                            onClick={() => setIsCartOpen(false)}
                                            className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400 hover:text-slate-100 transition-colors"
                                        >
                                            <X size={18} />
                                        </button>
                                    </div>
                                </div>

                                {/* Main scrollable form body container */}
                                <form onSubmit={handleCheckout} className="flex flex-col gap-4 flex-1 overflow-y-auto max-h-[75vh] pr-1.5 -mr-1.5 scrollbar-thin">
                                    
                                    {/* Order Cart Items List */}
                                    <div className="space-y-3.5 shrink-0 max-h-[180px] overflow-y-auto pr-1 scrollbar-thin">
                                        {usageForm.data.items.length === 0 ? (
                                            <div className="py-8 flex flex-col items-center justify-center text-slate-500 text-center px-2">
                                                <ShoppingCart size={38} className="stroke-[1.5] mb-2 opacity-30 text-slate-400" />
                                                <p className="font-outfit font-bold text-xs">Cart is empty</p>
                                                <p className="text-[10px] text-slate-500 mt-1 max-w-[200px]">Select items in the catalog to add them to this purchase order.</p>
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {usageForm.data.items.map((line) => (
                                                    <div 
                                                        key={line.item_id}
                                                        className="p-3 rounded-xl bg-[#0f172a]/45 border border-[#334155]/60 flex items-center justify-between gap-3 group"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <h4 className="font-outfit font-bold text-xs text-slate-200 truncate leading-tight">
                                                                {line.name}
                                                            </h4>
                                                            <p className="text-[10px] text-slate-500 mt-0.5">
                                                                ₱{line.price.toFixed(2)} / {line.unit}
                                                            </p>
                                                        </div>
             
                                                        {/* Quantity Editors */}
                                                        <div className="flex items-center gap-1 shrink-0">
                                                            <button
                                                                type="button"
                                                                onClick={() => updateQuantity(line.item_id, -1)}
                                                                className="h-7 w-7 rounded bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-slate-100 flex items-center justify-center text-xs"
                                                            >
                                                                <Minus size={10} />
                                                            </button>
                                                            <span className="font-mono text-xs font-bold w-6 text-center text-slate-200">
                                                                {line.quantity}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => updateQuantity(line.item_id, 1)}
                                                                className="h-7 w-7 rounded bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-slate-100 flex items-center justify-center text-xs"
                                                            >
                                                                <Plus size={10} />
                                                            </button>
                                                        </div>
             
                                                        {/* Line Price & Remove */}
                                                        <div className="text-right shrink-0 min-w-[70px] flex flex-col items-end">
                                                            <span className="font-mono font-bold text-xs text-brand-400">
                                                                ₱{(line.price * line.quantity).toFixed(2)}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => removeFromCart(line.item_id)}
                                                                className="text-[9px] text-slate-650 hover:text-red-400 mt-0.5"
                                                            >
                                                                Remove
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {/* Toggle assignment selection */}
                                    <div className="space-y-1.5 border-t border-[#334155]/60 pt-3">
                                        <span className="text-[10px] uppercase font-bold tracking-wider text-slate-400">Bill Charge Target</span>
                                        <div className="grid grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    usageForm.setData(data => ({
                                                        ...data,
                                                        target_type: 'booking',
                                                        consumer_name: ''
                                                    }));
                                                }}
                                                className={`py-2 rounded-xl text-center font-outfit font-extrabold text-[10px] uppercase tracking-wider border transition-all flex items-center justify-center gap-1.5 ${
                                                    usageForm.data.target_type === 'booking'
                                                        ? 'bg-brand-600 text-white border-brand-500 shadow-md'
                                                        : 'bg-[#0f172a]/50 text-slate-400 border-transparent hover:bg-[#0f172a] hover:text-slate-200'
                                                }`}
                                            >
                                                <BedDouble size={12} /> Charge Room
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    usageForm.setData(data => ({
                                                        ...data,
                                                        target_type: 'walk_in',
                                                        booking_id: ''
                                                    }));
                                                }}
                                                className={`py-2 rounded-xl text-center font-outfit font-extrabold text-[10px] uppercase tracking-wider border transition-all flex items-center justify-center gap-1.5 ${
                                                    usageForm.data.target_type === 'walk_in'
                                                        ? 'bg-brand-600 text-white border-brand-500 shadow-md'
                                                        : 'bg-[#0f172a]/50 text-slate-400 border-transparent hover:bg-[#0f172a] hover:text-slate-200'
                                                }`}
                                            >
                                                <User size={12} /> Direct Sale
                                            </button>
                                        </div>
                                    </div>

                                    {/* Conditional Form fields */}
                                    <AnimatePresence mode="wait">
                                        {usageForm.data.target_type === 'booking' ? (
                                            <motion.div
                                                key="booking-field"
                                                initial={{ opacity: 0, y: 5 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -5 }}
                                                className="space-y-1"
                                            >
                                                <label className="text-[10px] uppercase font-bold tracking-wider text-slate-400 block">Select Active Room Stay</label>
                                                <select
                                                    value={usageForm.data.booking_id}
                                                    onChange={e => usageForm.setData('booking_id', e.target.value)}
                                                    className="w-full py-2 px-3 rounded-xl bg-[#0f172a] border border-[#334155] text-slate-200 text-xs font-semibold focus:border-brand-500 focus:ring-1 focus:ring-brand-500/20 transition-all"
                                                >
                                                    <option value="">Select Stay / Guest...</option>
                                                    {activeBookings.map(b => (
                                                        <option key={b.id} value={b.id}>
                                                            Room {b.room?.room_number || 'N/A'} — {b.guest_name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </motion.div>
                                        ) : (
                                            <motion.div
                                                key="walk-in-field"
                                                initial={{ opacity: 0, y: 5 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -5 }}
                                                className="space-y-1"
                                            >
                                                <label className="text-[10px] uppercase font-bold tracking-wider text-slate-400 block">Consumer / Usage Label</label>
                                                <input
                                                    type="text"
                                                    placeholder="e.g. Walk-in Customer, Coffee Shop, Front Desk"
                                                    value={usageForm.data.consumer_name}
                                                    onChange={e => usageForm.setData('consumer_name', e.target.value)}
                                                    className="w-full py-2 px-3 rounded-xl bg-[#0f172a] border border-[#334155] text-slate-200 placeholder-slate-650 text-xs font-semibold focus:border-brand-500 focus:ring-1 focus:ring-brand-500/20 transition-all"
                                                />
                                            </motion.div>
                                        )}
                                    </AnimatePresence>

                                    {/* Calculations card */}
                                    <div className="p-3.5 rounded-xl bg-[#0f172a]/60 border border-[#334155]/65 space-y-2 text-xs text-slate-400 shrink-0">
                                        <div className="flex justify-between font-medium">
                                            <span>Subtotal</span>
                                            <span className="font-mono text-slate-200">₱{cartTotals.subtotal.toFixed(2)}</span>
                                        </div>
                                        <div className="border-t border-[#334155]/60 pt-2 flex justify-between items-baseline">
                                            <span className="font-outfit font-extrabold text-slate-200 text-xs uppercase tracking-wide">Total Charge</span>
                                            <span className="font-mono text-base font-black text-brand-400">₱{cartTotals.total.toFixed(2)}</span>
                                        </div>
                                    </div>

                                    {/* Action buttons — perfectly matched standard modals */}
                                    <div className="flex justify-end gap-3 pt-3 border-t border-[#334155]/60 shrink-0">
                                        <button 
                                            type="button" 
                                            onClick={() => setIsCartOpen(false)} 
                                            className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold font-outfit transition-colors"
                                        >
                                            Cancel
                                        </button>
                                        <button 
                                            type="submit" 
                                            disabled={usageForm.processing || usageForm.data.items.length === 0} 
                                            className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-xl text-white font-bold text-xs font-outfit transition-all flex items-center gap-1.5 shadow-md shadow-emerald-950/20"
                                        >
                                            <Check size={12} />
                                            Record Purchase
                                        </button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

            {/* Complete Receipt Modal Overlay */}
            <AnimatePresence>
                {checkoutSuccess && lastOrderDetails && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                        {/* Modal Backdrop */}
                        <motion.div 
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 0.6 }}
                            exit={{ opacity: 0 }}
                            onClick={() => setCheckoutSuccess(false)}
                            className="absolute inset-0 bg-black"
                        />
                        
                        {/* Transaction Receipt Card */}
                        <motion.div
                            initial={{ scale: 0.95, opacity: 0, y: 15 }}
                            animate={{ scale: 1, opacity: 1, y: 0 }}
                            exit={{ scale: 0.95, opacity: 0, y: 15 }}
                            transition={{ type: 'spring', damping: 25 }}
                            className="bg-[#1e293b] border border-[#334155] rounded-2xl p-6 shadow-2xl max-w-sm w-full relative overflow-hidden"
                        >
                            <div className="text-center pb-4 mb-4 border-b border-[#334155]/60 flex flex-col items-center">
                                <div className="h-12 w-12 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-full flex items-center justify-center mb-3">
                                    <CircleCheck size={26} />
                                </div>
                                <h3 className="font-outfit font-extrabold text-base text-emerald-400 uppercase tracking-wider">
                                    Purchase Logged!
                                </h3>
                                <p className="text-[11px] text-slate-400 mt-0.5">Inventory Transaction slip recorded successfully</p>
                            </div>

                            {/* Reference Transaction Number */}
                            <div className="text-center py-2.5 bg-[#0f172a]/60 rounded-xl mb-4 border border-[#334155]/40 flex flex-col items-center">
                                <span className="text-[9px] text-slate-500 uppercase tracking-widest font-bold">Transaction Reference ID</span>
                                <span className="font-mono font-bold text-xl text-brand-400 tracking-wide mt-0.5">
                                    #TXN-{lastOrderDetails.orderNo}
                                </span>
                            </div>

                            {/* Summary Receipt details */}
                            <div className="space-y-3.5 mb-6 text-xs">
                                <div className="flex justify-between">
                                    <span className="text-slate-400 uppercase font-bold text-[9px] tracking-wider">Bill Target</span>
                                    <span className="text-slate-200 font-bold">{lastOrderDetails.target}</span>
                                </div>
                                <div className="space-y-1.5">
                                    <span className="text-slate-400 uppercase font-bold text-[9px] tracking-wider block">Items Disbursed</span>
                                    <div className="max-h-32 overflow-y-auto space-y-1.5 pr-1 scrollbar-thin">
                                        {lastOrderDetails.items.map((line, idx) => (
                                            <div key={idx} className="flex justify-between items-baseline font-mono text-[11px] text-slate-300">
                                                <span>{line.name} <span className="text-slate-500 font-bold">x{line.quantity}</span></span>
                                                <span className="text-slate-400">₱{(line.price * line.quantity).toFixed(2)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className="border-t border-[#334155]/60 pt-3.5 flex justify-between items-baseline">
                                    <span className="text-slate-400 uppercase font-bold text-[9px] tracking-wider">Total Charge Rec.</span>
                                    <span className="font-mono text-sm font-bold text-brand-400">₱{lastOrderDetails.total.toFixed(2)}</span>
                                </div>
                            </div>

                            {/* Done buttons */}
                            <div className="space-y-2">
                                <button
                                    onClick={() => setCheckoutSuccess(false)}
                                    className="w-full py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-outfit font-extrabold text-xs uppercase tracking-wider rounded-lg shadow transition-colors flex items-center justify-center gap-1.5"
                                >
                                    Done
                                </button>
                                <Link
                                    href={route('inventory.index')}
                                    className="w-full py-2.5 bg-[#0f172a] hover:bg-slate-900 border border-[#334155] text-slate-300 hover:text-slate-100 font-outfit font-bold text-xs uppercase tracking-wider rounded-lg transition-all flex items-center justify-center"
                                >
                                    Back to Inventory
                                </Link>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>

            {/* Custom Toast Notification Banner */}
            <AnimatePresence>
                {toastMessage && (
                    <motion.div
                        initial={{ opacity: 0, y: -20, scale: 0.95 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -20, scale: 0.95 }}
                        className="fixed top-6 left-1/2 -translate-x-1/2 z-[1100] px-5 py-3.5 bg-red-950/90 border border-red-500/40 text-red-200 rounded-2xl shadow-2xl backdrop-blur-md text-xs font-bold font-outfit flex items-center gap-2.5"
                    >
                        <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse shrink-0" />
                        <span>{toastMessage}</span>
                    </motion.div>
                )}
            </AnimatePresence>
            </div>
        </AuthenticatedLayout>
    );
}
