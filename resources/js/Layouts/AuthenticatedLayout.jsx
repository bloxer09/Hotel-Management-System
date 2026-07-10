import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    LayoutDashboard,
    BedDouble,
    ClipboardList,
    Users,
    TrendingUp,
    Package,
    Clock,
    Settings,
    LogOut,
    Menu,
    X,
    ChevronLeft,
    ChevronRight,
    AlertTriangle,
    CheckCircle2,
    XCircle,
    UserSquare2,
    CalendarDays,
    Users2,
    SearchCode,
    ChevronDown,
    Building,
    Bell,
    BellRing,
    Timer,
    ShoppingCart,
    Wrench,
    Ticket,
    Sliders,
    Receipt,
    Coins
} from 'lucide-react';
import ProfileModal from '@/Components/ProfileModal';
import ConfirmModal from '@/Components/ConfirmModal';


export default function AuthenticatedLayout({ children }) {
    const { auth, flash, app_name } = usePage().props;
    const nameParts = (app_name || 'Uptown Pension House').split(' ');
    const firstWord = nameParts[0] || 'Uptown';
    const remainingWords = nameParts.slice(1).join(' ');
    const user = auth.user;
    const activeShift = auth.active_shift;

    const isCollapsed = false;
    const [isMobileOpen, setIsMobileOpen] = useState(false);
    const [isSettingsOpen, setIsSettingsOpen] = useState(false);
    const [isProfileOpen, setIsProfileOpen] = useState(false);
    const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);

    // ─── Toast state ─────────────────────────────────────────────────────────
    const [toast, setToast] = useState(null);

    // ─── Notification System State ────────────────────────────────────────────
    const [notifications, setNotifications] = useState([]);
    const [notifCounts, setNotifCounts] = useState({ total: 0, checkout: 0, inventory: 0, overdue: 0, out_of_stock: 0 });
    const [isBellOpen, setIsBellOpen] = useState(false);
    const [alertToasts, setAlertToasts] = useState([]);
    const seenAlertKeysRef = useRef(new Set());
    const notifInitializedRef = useRef(false);
    const audioCtxRef = useRef(null);
    const bellDropdownRef = useRef(null);
    const canSeeNotifications = ['admin', 'front_desk', 'cashier'].includes(user.role);

    useEffect(() => {
        if (flash.success) {
            setToast({ type: 'success', message: flash.success });
        } else if (flash.warning) {
            setToast({ type: 'warning', message: flash.warning });
        } else if (flash.error) {
            setToast({ type: 'error', message: flash.error });
        }
    }, [flash]);

    useEffect(() => {
        if (toast) {
            const timer = setTimeout(() => setToast(null), 5000);
            return () => clearTimeout(timer);
        }
    }, [toast]);

    // ─── Web Audio API: Dual-Oscillator Synthesizer Chime ────────────────────
    const unlockAudio = useCallback(() => {
        if (!audioCtxRef.current) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) audioCtxRef.current = new Ctx();
        }
        if (audioCtxRef.current?.state === 'suspended') {
            audioCtxRef.current.resume().catch(() => { });
        }
    }, []);

    useEffect(() => {
        const handler = () => unlockAudio();
        window.addEventListener('click', handler, { once: true });
        window.addEventListener('keydown', handler, { once: true });
        return () => {
            window.removeEventListener('click', handler);
            window.removeEventListener('keydown', handler);
        };
    }, [unlockAudio]);

    const playAlertChime = useCallback(() => {
        try {
            unlockAudio();
            const ctx = audioCtxRef.current;
            if (!ctx) return;
            const now = ctx.currentTime;
            [{ freq: 880, offset: 0 }, { freq: 660, offset: 0.22 }].forEach(({ freq, offset }) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, now + offset);
                gain.gain.exponentialRampToValueAtTime(0.14, now + offset + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now + offset);
                osc.stop(now + offset + 0.22);
            });
        } catch (e) {
            console.warn('Notification chime failed:', e);
        }
    }, [unlockAudio]);

    // ─── Fetch + Process Notifications ───────────────────────────────────────
    const loadNotifications = useCallback(async () => {
        if (!canSeeNotifications) return;
        try {
            const res = await fetch('/api/notifications', { cache: 'no-store' });
            if (!res.ok) return;
            const payload = await res.json();
            if (!payload.success) return;

            const items = Array.isArray(payload.items) ? payload.items : [];
            setNotifications(items);
            setNotifCounts(payload.counts || {});

            const newItems = items.filter(item => !seenAlertKeysRef.current.has(item.alert_key));
            newItems.forEach(item => seenAlertKeysRef.current.add(item.alert_key));

            if (notifInitializedRef.current && newItems.length > 0) {
                playAlertChime();
                setAlertToasts(prev => {
                    const incoming = newItems.slice(0, 3).map((item, i) => ({
                        ...item,
                        id: Date.now() + i,
                    }));
                    return [...prev, ...incoming].slice(-5);
                });
            }
            notifInitializedRef.current = true;
        } catch (e) {
            console.warn('Notifications failed to load:', e);
        }
    }, [canSeeNotifications, playAlertChime]);

    useEffect(() => {
        if (!canSeeNotifications) return;
        loadNotifications();
        const interval = setInterval(loadNotifications, 30000);
        return () => clearInterval(interval);
    }, [canSeeNotifications, loadNotifications]);

    const dismissAlertToast = useCallback((id) => {
        setAlertToasts(prev => prev.filter(t => t.id !== id));
    }, []);

    useEffect(() => {
        if (alertToasts.length === 0) return;
        const timers = alertToasts.map(t => setTimeout(() => dismissAlertToast(t.id), 7000));
        return () => timers.forEach(clearTimeout);
    }, [alertToasts, dismissAlertToast]);

    useEffect(() => {
        const handler = (e) => {
            if (bellDropdownRef.current && !bellDropdownRef.current.contains(e.target)) {
                setIsBellOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const handleLogout = (e) => {
        e.preventDefault();
        setShowLogoutConfirm(true);
    };

    const hasRole = (roles) => {
        if (user.role === 'admin') return true;
        return roles.includes(user.role);
    };

    const navItems = [
        {
            name: 'Dashboard',
            icon: LayoutDashboard,
            href: route('dashboard'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('dashboard')
        },

        {
            name: 'Rooms',
            icon: BedDouble,
            href: route('rooms.index'),
            roles: ['admin', 'front_desk', 'cashier', 'housekeeping'],
            current: route().current('rooms.*')
        },
        {
            name: 'Check In',
            icon: ClipboardList,
            href: route('checkin.index'),
            roles: ['admin', 'front_desk'],
            current: route().current('checkin.*') || route().current('bookings.*'),
            requiresShift: true
        },
        {
            name: 'Bookings',
            icon: CalendarDays,
            href: route('reservations.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('reservations.*'),
            requiresShift: true
        },
        {
            name: 'Guest History',
            icon: Users,
            href: route('guests.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('guests.*')
        },
        {
            name: 'Inventory',
            icon: Package,
            href: route('inventory.index'),
            roles: ['admin', 'front_desk'],
            current: route().current('inventory.*')
        },
        {
            name: 'POS',
            icon: ShoppingCart,
            href: route('pos.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('pos.*'),
            requiresShift: true
        },
        {
            name: 'Sales & Reports',
            icon: TrendingUp,
            href: route('reports.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('reports.index')
        },
        {
            name: 'Expenses',
            icon: Receipt,
            href: route('expenses.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('expenses.*')
        },
        {
            name: 'Additional Incomes',
            icon: Coins,
            href: route('incomes.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('incomes.*')
        },
        {
            name: 'Maintenance Tickets',
            icon: Wrench,
            href: route('maintenance.index'),
            roles: ['admin', 'front_desk', 'cashier', 'housekeeping'],
            current: route().current('maintenance.*')
        },
        {
            name: 'Shift Register',
            icon: Clock,
            href: route('shifts.index'),
            roles: ['admin', 'front_desk', 'cashier'],
            current: route().current('shifts.*')
        }
    ];

    const adminSettings = [
        {
            name: 'Promo/Discount Codes',
            icon: Ticket,
            href: route('settings.promo_codes.index'),
            current: route().current('settings.promo_codes.*')
        },
        {
            name: 'Room Rates',
            icon: BedDouble,
            href: route('settings.rates.index'),
            current: route().current('settings.rates.*')
        },
        {
            name: 'Peak Dates Surcharge',
            icon: CalendarDays,
            href: route('settings.peaks.index'),
            current: route().current('settings.peaks.*')
        },
        {
            name: 'User Management',
            icon: Users2,
            href: route('settings.users.index'),
            current: route().current('settings.users.*')
        },
        {
            name: 'Audit Trail Logs',
            icon: SearchCode,
            href: route('settings.audit.index'),
            current: route().current('settings.audit.*')
        }
    ];

    // ─── Alert Toast Card Component ───────────────────────────────────────────
    const AlertToastCard = ({ item, onDismiss }) => {
        let titleText = 'Alert';
        let isCritical = false;
        let Icon = Bell;
        let colorClass = 'bg-amber-950/90 border-amber-500/50 text-amber-100 shadow-amber-950/40';
        let iconColorClass = 'text-amber-400';
        let iconBgClass = 'bg-amber-500/20';
        let barColorClass = 'bg-amber-400';

        if (item.type === 'checkout') {
            isCritical = item.state === 'overdue';
            titleText = isCritical ? 'Overdue Checkout' : 'Upcoming Checkout';
            Icon = Timer;
            if (isCritical) {
                colorClass = 'bg-red-950/90 border-red-500/50 text-red-100 shadow-red-950/40';
                iconColorClass = 'text-red-400';
                iconBgClass = 'bg-red-500/20';
                barColorClass = 'bg-red-400';
            }
        } else if (item.type === 'inventory') {
            isCritical = item.state === 'out_of_stock';
            titleText = isCritical ? 'Out of Stock' : 'Low Stock Alert';
            Icon = ShoppingCart;
            if (isCritical) {
                colorClass = 'bg-red-950/90 border-red-500/50 text-red-100 shadow-red-950/40';
                iconColorClass = 'text-red-400';
                iconBgClass = 'bg-red-500/20';
                barColorClass = 'bg-red-400';
            }
        } else if (item.type === 'cleaning_finished') {
            titleText = 'Cleaning Finished';
            Icon = CheckCircle2;
            colorClass = 'bg-emerald-950/90 border-emerald-500/50 text-emerald-100 shadow-emerald-950/40';
            iconColorClass = 'text-emerald-400';
            iconBgClass = 'bg-emerald-500/20';
            barColorClass = 'bg-emerald-400';
        } else if (item.type === 'maintenance') {
            isCritical = item.priority === 'critical';
            titleText = isCritical ? 'Critical Maintenance' : 'High Maintenance';
            Icon = Wrench;
            colorClass = 'bg-red-950/90 border-red-500/50 text-red-100 shadow-red-950/40';
            iconColorClass = 'text-red-400';
            iconBgClass = 'bg-red-500/20';
            barColorClass = 'bg-red-400';
        }

        return (
            <motion.div
                layout
                initial={{ opacity: 0, x: 80, scale: 0.95 }}
                animate={{ opacity: 1, x: 0, scale: 1 }}
                exit={{ opacity: 0, x: 80, scale: 0.9 }}
                transition={{ type: 'spring', damping: 22, stiffness: 280 }}
                className={`relative flex items-start gap-3 p-4 rounded-2xl border shadow-2xl backdrop-blur-xl max-w-xs w-full ${colorClass}`}
            >
                <div className={`shrink-0 mt-0.5 p-1.5 rounded-lg ${iconBgClass}`}>
                    <Icon size={16} className={iconColorClass} />
                </div>
                <div className="flex-1 min-w-0">
                    <div className={`text-[10px] uppercase font-black tracking-wider mb-0.5 ${iconColorClass}`}>
                        {titleText}
                    </div>
                    <p className="text-xs font-medium leading-relaxed opacity-90">{item.message}</p>
                </div>
                <button
                    onClick={onDismiss}
                    className="shrink-0 text-current opacity-50 hover:opacity-100 transition-opacity"
                >
                    <X size={14} />
                </button>
                {/* Auto-dismiss progress bar */}
                <motion.div
                    className={`absolute bottom-0 left-0 h-0.5 rounded-full ${barColorClass}`}
                    initial={{ width: '100%' }}
                    animate={{ width: '0%' }}
                    transition={{ duration: 7, ease: 'linear' }}
                />
            </motion.div>
        );
    };

    // Derived values for the notification dropdown
    const inventoryAlerts = notifications.filter(n => n.type === 'inventory');
    const checkoutAlerts = notifications.filter(n => n.type === 'checkout');
    const cleaningAlerts = notifications.filter(n => n.type === 'cleaning_finished');
    const maintenanceAlerts = notifications.filter(n => n.type === 'maintenance');
    const totalAlerts = notifCounts.total || 0;

    // Helper mapping sidebar items to active alert counts
    const getSidebarBadgeCount = (itemName) => {
        if (itemName === 'Rooms') {
            return cleaningAlerts.length;
        }
        if (itemName === 'Bookings') {
            return checkoutAlerts.length;
        }
        if (itemName === 'Inventory') {
            return inventoryAlerts.length;
        }
        if (itemName === 'Maintenance Tickets') {
            return maintenanceAlerts.length;
        }
        return 0;
    };

    return (
        <div className="flex h-screen overflow-hidden bg-[#0f172a] text-slate-100 font-sans antialiased print:h-auto print:overflow-visible">

            {/* Desktop Sidebar */}
            <aside
                className="hidden md:flex flex-col bg-[#1e293b] border-r border-[#334155] shadow-2xl transition-all duration-300 print:hidden w-72"
            >
                {/* Header Logo */}
                <div className="h-20 flex items-center px-6 border-b border-[#334155]">
                    <div className="flex items-center gap-3 overflow-hidden min-w-0">
                        {/* Expanded: logo and app name side-by-side */}
                        <img
                            src="/images/logo.jpg"
                            alt={app_name || 'Uptown Pension House'}
                            className="h-12 w-12 object-contain rounded-lg shrink-0 bg-white p-0.5"
                            onError={(e) => {
                                e.currentTarget.style.display = 'none';
                            }}
                        />
                        <div className="flex flex-col justify-center min-w-0 leading-tight">
                            <span className="font-outfit font-extrabold text-sm tracking-wider uppercase text-slate-100 truncate">
                                {firstWord}
                            </span>
                            {remainingWords && (
                                <span className="font-outfit font-bold text-[10px] tracking-widest uppercase text-brand-400 mt-0.5 truncate">
                                    {remainingWords}
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Staff Shift Card */}
                {/* {!isCollapsed && (
                    <div className="m-4 p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] flex flex-col gap-2">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-slate-400 uppercase tracking-wider font-semibold">Current Staff</span>
                            <span className="text-[10px] bg-[#334155] text-slate-300 px-2 py-0.5 rounded font-mono uppercase font-bold">{user.role}</span>
                        </div>
                        <div className="font-outfit font-bold text-sm truncate">{user.name}</div>

                        {user.role !== 'housekeeping' && (
                            <div className="mt-2 pt-2 border-t border-[#334155]/60 flex items-center gap-2">
                                {activeShift ? (
                                    <>
                                        <span className="relative flex h-2 w-2">
                                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                            <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                        </span>
                                        <span className="text-xs text-emerald-400 font-semibold font-outfit uppercase">
                                            {activeShift.shift_code} Shift Active
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                                        <Link
                                            href={route('shifts.index')}
                                            className="text-xs text-amber-400 hover:text-amber-300 font-bold underline transition-colors"
                                        >
                                            Shift Closed (Start)
                                        </Link>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                )} */}

                {/* Sidebar Navigation */}
                <nav className="flex-1 overflow-y-auto px-4 py-2 space-y-1 scrollbar-thin">
                    {navItems
                        .filter(item => hasRole(item.roles))
                        .map(item => {
                            const isShiftRestricted = item.requiresShift && !activeShift && user.role !== 'admin';
                            const linkHref = isShiftRestricted ? route('shifts.index') : item.href;
                            const badgeCount = getSidebarBadgeCount(item.name);

                            return (
                                <Link
                                    key={item.name}
                                    href={linkHref}
                                    className={`flex items-center gap-3.5 px-4 py-3 rounded-xl font-medium transition-all duration-200 group relative ${item.current
                                        ? 'bg-brand-600/70 text-slate-50 border border-brand-500/40 shadow-lg shadow-brand-600/20'
                                        : 'text-slate-400 hover:bg-[#334155]/50 hover:text-slate-100'
                                        }`}
                                >
                                    <div className="relative shrink-0">
                                        <item.icon size={20} className={`${item.current ? 'text-slate-50' : 'text-slate-400 group-hover:text-brand-400'}`} />
                                        {badgeCount > 0 && isCollapsed && (
                                            <span className="absolute -top-1.5 -right-1.5 flex h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-slate-900 animate-pulse" />
                                        )}
                                    </div>
                                    {!isCollapsed && <span className="text-sm font-outfit">{item.name}</span>}
                                    {!isCollapsed && badgeCount > 0 && (
                                        <span className="ml-auto flex h-5 min-w-[20px] px-1.5 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white animate-pulse">
                                            {badgeCount}
                                        </span>
                                    )}

                                    {/* Tooltip on collapse */}
                                    {isCollapsed && (
                                        <div className="absolute left-full ml-4 px-2.5 py-1.5 bg-[#0f172a] text-slate-100 text-xs font-semibold rounded-md opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity shadow-lg border border-[#334155] whitespace-nowrap z-50">
                                            {item.name} {isShiftRestricted && '(Requires Active Shift)'}
                                        </div>
                                    )}

                                    {/* Lock badge */}
                                    {!isCollapsed && isShiftRestricted && (
                                        <span className="ml-auto text-[9px] bg-amber-950 border border-amber-600/40 text-amber-400 px-1.5 py-0.5 rounded uppercase font-bold">
                                            Locked
                                        </span>
                                    )}
                                </Link>
                            );
                        })}

                    {/* Admin Settings dropdown */}
                    {user.role === 'admin' && (
                        <div className="space-y-1 pt-2">
                            <button
                                onClick={() => setIsSettingsOpen(!isSettingsOpen)}
                                className={`w-full flex items-center gap-3.5 px-4 py-3 rounded-xl font-medium text-slate-400 hover:bg-[#334155]/50 hover:text-slate-100 transition-all duration-200 ${isSettingsOpen ? 'text-slate-100' : ''
                                    }`}
                            >
                                <Settings size={20} className="text-slate-400" />
                                {!isCollapsed && (
                                    <>
                                        <span className="text-sm font-outfit">Configurations</span>
                                        <ChevronDown size={14} className={`ml-auto transition-transform ${isSettingsOpen ? 'rotate-180' : ''}`} />
                                    </>
                                )}
                            </button>

                            {!isCollapsed && isSettingsOpen && (
                                <motion.div
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: 'auto' }}
                                    className="pl-6 space-y-1 bg-[#1e293b]/40 py-1.5 rounded-lg border-l border-[#334155] ml-4"
                                >
                                    {adminSettings.map(subItem => (
                                        <Link
                                            key={subItem.name}
                                            href={subItem.href}
                                            className={`flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-colors ${subItem.current
                                                ? 'text-brand-400 bg-brand-500/10 font-bold'
                                                : 'text-slate-400 hover:text-slate-200'
                                                }`}
                                        >
                                            <subItem.icon size={14} />
                                            <span>{subItem.name}</span>
                                        </Link>
                                    ))}
                                </motion.div>
                            )}
                        </div>
                    )}
                </nav>

                {/* Footer Account */}
                <div className="p-4 border-t border-[#334155]">
                    <button
                        onClick={handleLogout}
                        className="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-red-950/20 border border-red-900/30 hover:bg-red-900/30 text-red-400 hover:text-red-300 text-sm font-bold tracking-wide transition-all"
                    >
                        <LogOut size={16} />
                        {!isCollapsed && <span className="font-outfit">Logout</span>}
                    </button>
                </div>
            </aside>

            {/* Mobile Sidebar overlay */}
            <AnimatePresence>
                {isMobileOpen && (
                    <>
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 0.5 }}
                            exit={{ opacity: 0 }}
                            onClick={() => setIsMobileOpen(false)}
                            className="fixed inset-0 bg-black z-40 md:hidden"
                        />
                        <motion.aside
                            initial={{ x: '-100%' }}
                            animate={{ x: 0 }}
                            exit={{ x: '-100%' }}
                            transition={{ type: 'spring', damping: 25 }}
                            className="fixed top-0 bottom-0 left-0 w-80 bg-[#1e293b] border-r border-[#334155] shadow-2xl z-50 md:hidden flex flex-col print:hidden"
                        >
                            <div className="h-20 flex items-center justify-between px-6 border-b border-[#334155]">
                                <div className="flex items-center gap-3">
                                    <img
                                        src="/images/logo.jpg"
                                        alt={app_name || 'Uptown Pension House'}
                                        className="h-10 w-10 object-contain rounded-lg shrink-0 bg-white p-0.5"
                                        onError={(e) => {
                                            e.currentTarget.style.display = 'none';
                                        }}
                                    />
                                    <div className="flex flex-col justify-center min-w-0 leading-tight">
                                        <span className="font-outfit font-extrabold text-sm tracking-wider uppercase text-slate-100 truncate">
                                            {firstWord}
                                        </span>
                                        {remainingWords && (
                                            <span className="font-outfit font-bold text-[10px] tracking-widest uppercase text-brand-400 mt-0.5 truncate">
                                                {remainingWords}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <button
                                    onClick={() => setIsMobileOpen(false)}
                                    className="p-1.5 rounded-lg bg-[#0f172a] border border-[#334155] text-slate-400"
                                >
                                    <X size={18} />
                                </button>
                            </div>

                            {/* Staff Card */}
                            <div
                                onClick={() => { setIsMobileOpen(false); setIsProfileOpen(true); }}
                                className="m-4 p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155] hover:border-brand-500/50 flex flex-col gap-3 cursor-pointer transition-all active:scale-[0.98]"
                            >
                                <div className="flex items-center gap-3">
                                    {user.avatar_url ? (
                                        <img
                                            src={user.avatar_url}
                                            alt={user.name}
                                            className="w-10 h-10 rounded-full object-cover border border-brand-500/40 shrink-0"
                                        />
                                    ) : (
                                        <div className="w-10 h-10 rounded-full bg-brand-600/20 border border-brand-500/30 text-brand-400 flex items-center justify-center font-outfit text-sm font-black shrink-0">
                                            {user.name ? user.name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase() : '?'}
                                        </div>
                                    )}
                                    <div className="flex-1 min-w-0">
                                        <div className="font-outfit font-bold text-sm text-slate-200 truncate">{user.name}</div>
                                        <span className="inline-block mt-0.5 text-[9px] bg-[#334155] text-slate-300 px-1.5 py-0.5 rounded font-mono uppercase font-bold">{user.role.replace('_', ' ')}</span>
                                    </div>
                                </div>
                                {user.role !== 'housekeeping' && (
                                    <div className="mt-2 pt-2 border-t border-[#334155]/60 flex items-center gap-2">
                                        {activeShift ? (
                                            <div className="flex items-center gap-1.5 text-xs text-emerald-400 font-extrabold uppercase font-outfit tracking-wider">
                                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                                                <span>{activeShift.shift_code} Shift Active</span>
                                            </div>
                                        ) : (
                                            <Link
                                                href={route('shifts.index')}
                                                onClick={() => setIsMobileOpen(false)}
                                                className="flex items-center gap-1.5 text-xs text-amber-400 font-extrabold uppercase font-outfit tracking-wider hover:text-amber-300 transition-colors"
                                            >
                                                <span className="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                                <span>Shift Closed (Click to Open)</span>
                                            </Link>
                                        )}
                                    </div>
                                )}
                            </div>

                            <nav className="flex-1 overflow-y-auto px-4 py-2 space-y-1">
                                {navItems
                                    .filter(item => hasRole(item.roles))
                                    .map(item => {
                                        const isShiftRestricted = item.requiresShift && !activeShift && user.role !== 'admin';
                                        const linkHref = isShiftRestricted ? route('shifts.index') : item.href;
                                        const badgeCount = getSidebarBadgeCount(item.name);

                                        return (
                                            <Link
                                                key={item.name}
                                                href={linkHref}
                                                onClick={() => setIsMobileOpen(false)}
                                                className={`flex items-center gap-3.5 px-4 py-3 rounded-xl font-medium transition-colors ${item.current
                                                    ? 'bg-brand-600 text-slate-50 shadow-md border border-brand-500/30'
                                                    : 'text-slate-400 hover:bg-[#334155]/50 hover:text-slate-100'
                                                    }`}
                                            >
                                                <div className="relative shrink-0">
                                                    <item.icon size={20} />
                                                </div>
                                                <span className="text-sm font-outfit">{item.name}</span>
                                                {badgeCount > 0 && (
                                                    <span className="ml-auto flex h-5 min-w-[20px] px-1.5 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white">
                                                        {badgeCount}
                                                     </span>
                                                )}
                                                {isShiftRestricted && !item.current && (
                                                    <span className="ml-auto text-[9px] bg-amber-950 border border-amber-600/40 text-amber-400 px-1.5 py-0.5 rounded font-bold">
                                                        Locked
                                                    </span>
                                                )}
                                            </Link>
                                        );
                                    })}

                                {user.role === 'admin' && (
                                    <div className="space-y-1 pt-2">
                                        <div className="px-4 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider font-outfit">Configurations</div>
                                        {adminSettings.map(subItem => (
                                            <Link
                                                key={subItem.name}
                                                href={subItem.href}
                                                onClick={() => setIsMobileOpen(false)}
                                                className={`flex items-center gap-2.5 px-4 py-2 rounded-xl text-sm font-medium transition-colors ${subItem.current
                                                    ? 'text-brand-400 bg-[#334155]'
                                                    : 'text-slate-400 hover:text-slate-200'
                                                    }`}
                                            >
                                                <subItem.icon size={16} />
                                                <span>{subItem.name}</span>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </nav>

                            <div className="p-4 border-t border-[#334155]">
                                <button
                                    onClick={handleLogout}
                                    className="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-red-950/20 border border-red-900/30 text-red-400 text-sm font-bold"
                                >
                                    <LogOut size={16} />
                                    <span>Logout</span>
                                </button>
                            </div>
                        </motion.aside>
                    </>
                )}
            </AnimatePresence>

            {/* Main Application Area */}
            <div className="flex-1 flex flex-col min-w-0 bg-[#0f172a] print:h-auto print:overflow-visible">

                {/* Global Mobile Header / Topbar */}
                <header className="h-16 sm:h-20 bg-[#1e293b] border-b border-[#334155] flex items-center justify-between px-3 sm:px-6 md:px-8 shrink-0 print:hidden">
                    <button
                        onClick={() => setIsMobileOpen(true)}
                        className="p-2 rounded-lg bg-[#0f172a]/60 border border-[#334155]/60 md:hidden text-slate-300"
                    >
                        <Menu size={20} />
                    </button>

                    <div className="hidden md:flex items-center gap-2 text-sm text-slate-400">
                        <span className="font-outfit font-semibold text-slate-300 capitalize">{user.role} Dashboard</span>
                        {/* <span>/</span>
                        <span className="font-outfit text-brand-300 font-medium">Hotel Property Management System</span> */}
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Shift Status Pill */}
                        {activeShift ? (
                            <div className="hidden lg:flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-emerald-950/30 border border-emerald-900/40 text-emerald-400 text-xs font-semibold">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span className="uppercase font-mono tracking-wide">{activeShift.shift_code} REGISTER RUNNING</span>
                            </div>
                        ) : user.role !== 'housekeeping' ? (
                            <div className="hidden lg:flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-950/30 border border-amber-900/40 text-amber-400 text-xs font-semibold">
                                <span className="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                <span className="uppercase tracking-wide">NO ACTIVE SHIFT</span>
                            </div>
                        ) : null}

                        {/* ─── Notification Bell ──────────────────────────────── */}
                        {canSeeNotifications && (
                            <div className="relative" ref={bellDropdownRef}>
                                <button
                                    id="notification-bell-btn"
                                    onClick={() => setIsBellOpen(prev => !prev)}
                                    className={`relative flex items-center justify-center w-10 h-10 rounded-xl border transition-all duration-200 ${totalAlerts > 0
                                        ? 'bg-rose-950/40 border-rose-500/50 text-rose-300 hover:bg-rose-900/50 shadow-lg shadow-rose-950/30'
                                        : 'bg-[#0f172a]/55 border-[#334155] text-slate-400 hover:bg-[#334155] hover:text-slate-200'
                                        }`}
                                    aria-label="Notifications"
                                >
                                    {totalAlerts > 0
                                        ? <BellRing size={18} />
                                        : <Bell size={18} />
                                    }
                                    {totalAlerts > 0 && (
                                        <span className="absolute -top-1.5 -right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-white text-[10px] font-black shadow-lg border border-[#1e293b]">
                                            {totalAlerts > 9 ? '9+' : totalAlerts}
                                        </span>
                                    )}
                                    {totalAlerts > 0 && (
                                        <span className="absolute inset-0 rounded-xl animate-ping bg-rose-500/20 pointer-events-none" />
                                    )}
                                </button>

                                {/* ─── Bell Dropdown Panel ──────────────────── */}
                                <AnimatePresence>
                                    {isBellOpen && (
                                        <motion.div
                                            initial={{ opacity: 0, y: -8, scale: 0.97 }}
                                            animate={{ opacity: 1, y: 0, scale: 1 }}
                                            exit={{ opacity: 0, y: -8, scale: 0.97 }}
                                            transition={{ type: 'spring', damping: 25, stiffness: 350 }}
                                            className="fixed sm:absolute left-2 right-2 sm:left-auto sm:right-0 top-16 sm:top-12 z-[200] sm:w-96 max-w-[calc(100vw-1rem)] sm:max-w-none bg-[#1e293b]/95 backdrop-blur-xl border border-[#334155] rounded-2xl shadow-2xl overflow-hidden"
                                        >
                                            {/* Dropdown Header */}
                                            <div className="px-4 py-3 border-b border-[#334155] flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <BellRing size={15} className="text-brand-400" />
                                                    <span className="font-outfit font-bold text-sm text-slate-200">Notifications</span>
                                                </div>
                                            </div>

                                            <div className="max-h-[calc(100vh-120px)] sm:max-h-[420px] overflow-y-auto scrollbar-thin">
                                                {/* Empty state */}
                                                {totalAlerts === 0 && (
                                                    <div className="flex flex-col items-center justify-center gap-3 py-10 px-4 text-center">
                                                        <div className="h-12 w-12 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                                                            <CheckCircle2 size={22} className="text-emerald-400" />
                                                        </div>
                                                        <div>
                                                            <p className="text-sm font-bold text-slate-300 font-outfit">All Clear</p>
                                                            <p className="text-xs text-slate-500 mt-0.5">No active notifications.</p>
                                                        </div>
                                                    </div>
                                                )}

                                                {/* Inventory Alerts Section */}
                                                {inventoryAlerts.length > 0 && (
                                                    <div>
                                                        {inventoryAlerts.map(item => (
                                                            <Link
                                                                key={item.alert_key}
                                                                href={route('inventory.index')}
                                                                onClick={() => setIsBellOpen(false)}
                                                                className="flex items-start gap-3 px-4 py-3 hover:bg-[#334155]/40 transition-colors border-b border-[#334155]/30 last:border-b-0"
                                                            >
                                                                <div className={`shrink-0 mt-0.5 h-2.5 w-2.5 rounded-full ${item.state === 'out_of_stock' ? 'bg-rose-500' : 'bg-amber-500'}`} />
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="text-xs font-bold text-slate-200 truncate">{item.item_name}</div>
                                                                    <div className="text-[11px] text-slate-400 leading-relaxed mt-0.5">{item.message}</div>
                                                                    <div className="text-[10px] text-slate-500 mt-0.5 capitalize">{item.category} • {item.current_stock} {item.unit} remaining</div>
                                                                </div>
                                                                <span className={`shrink-0 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase ${item.state === 'out_of_stock'
                                                                    ? 'bg-rose-950/60 border-rose-500/40 text-rose-300'
                                                                    : 'bg-amber-950/60 border-amber-500/40 text-amber-300'
                                                                    }`}>
                                                                    {item.state === 'out_of_stock' ? 'Out' : 'Low'}
                                                                </span>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}

                                                {/* Checkout Alerts Section */}
                                                {checkoutAlerts.length > 0 && (
                                                    <div>
                                                        {checkoutAlerts.map(item => (
                                                            <Link
                                                                key={item.alert_key}
                                                                href={route('rooms.index')}
                                                                onClick={() => setIsBellOpen(false)}
                                                                className="flex items-start gap-3 px-4 py-3 hover:bg-[#334155]/40 transition-colors border-b border-[#334155]/30 last:border-b-0"
                                                            >
                                                                <div className={`shrink-0 mt-0.5 h-2.5 w-2.5 rounded-full ${item.state === 'overdue' ? 'bg-rose-500 animate-pulse' : 'bg-amber-400'}`} />
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="text-xs font-bold text-slate-200">
                                                                        Room {item.room_number}
                                                                        <span className="font-normal text-slate-400 ml-1">• {item.guest_name}</span>
                                                                    </div>
                                                                    <div className="text-[11px] text-slate-400 leading-relaxed mt-0.5">{item.message}</div>
                                                                    <div className="text-[10px] text-slate-500 mt-0.5">{item.expected_check_out_label} • {item.room_type}</div>
                                                                </div>
                                                                <span className={`shrink-0 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase ${item.state === 'overdue'
                                                                    ? 'bg-rose-950/60 border-rose-500/40 text-rose-300'
                                                                    : 'bg-amber-950/60 border-amber-500/40 text-amber-300'
                                                                    }`}>
                                                                    {item.state === 'overdue' ? 'Overdue' : 'Upcoming'}
                                                                </span>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}

                                                {/* Cleaning Finished Alerts Section */}
                                                {cleaningAlerts.length > 0 && (
                                                    <div>
                                                        {cleaningAlerts.map(item => (
                                                            <Link
                                                                key={item.alert_key}
                                                                href={route('rooms.index')}
                                                                onClick={() => setIsBellOpen(false)}
                                                                className="flex items-start gap-3 px-4 py-3 hover:bg-[#334155]/40 transition-colors border-b border-[#334155]/30 last:border-b-0"
                                                            >
                                                                <div className="shrink-0 mt-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="text-xs font-bold text-slate-200">Room {item.room_number} Cleaned</div>
                                                                    <div className="text-[11px] text-slate-400 leading-relaxed mt-0.5">{item.message}</div>
                                                                </div>
                                                                <span className="shrink-0 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase bg-emerald-950/60 border-emerald-500/40 text-emerald-300">
                                                                    Clean
                                                                </span>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}

                                                {/* Maintenance Alerts Section */}
                                                {maintenanceAlerts.length > 0 && (
                                                    <div>
                                                        {maintenanceAlerts.map(item => (
                                                            <Link
                                                                key={item.alert_key}
                                                                href={route('maintenance.index')}
                                                                onClick={() => setIsBellOpen(false)}
                                                                className="flex items-start gap-3 px-4 py-3 hover:bg-[#334155]/40 transition-colors border-b border-[#334155]/30 last:border-b-0"
                                                            >
                                                                <div className={`shrink-0 mt-0.5 h-2.5 w-2.5 rounded-full ${item.priority === 'critical' ? 'bg-red-500 animate-pulse' : 'bg-orange-500'}`} />
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="text-xs font-bold text-slate-200">Room {item.room_number} Issue</div>
                                                                    <div className="text-[11px] text-slate-400 leading-relaxed mt-0.5">{item.message}</div>
                                                                </div>
                                                                <span className={`shrink-0 text-[9px] font-black px-1.5 py-0.5 rounded border uppercase ${item.priority === 'critical'
                                                                    ? 'bg-red-950/60 border-red-500/40 text-red-300'
                                                                    : 'bg-orange-950/60 border-orange-500/40 text-orange-300'
                                                                    }`}>
                                                                    {item.priority}
                                                                </span>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                        )}

                        {/* Topbar User Indicator */}
                        <button
                            type="button"
                            onClick={() => setIsProfileOpen(true)}
                            className="flex items-center gap-3 bg-[#0f172a]/55 border border-[#334155] hover:border-brand-500/50 hover:bg-[#1e293b]/65 px-4 py-2 rounded-xl transition-all duration-200 group"
                        >
                            {user.avatar_url ? (
                                <img
                                    src={user.avatar_url}
                                    alt={user.name}
                                    className="w-6 h-6 rounded-full object-cover border border-brand-500/40 shrink-0 group-hover:border-brand-400"
                                />
                            ) : (
                                <div className="w-6 h-6 rounded-full bg-brand-600/20 border border-brand-500/30 text-brand-400 group-hover:bg-brand-600/30 flex items-center justify-center font-outfit text-xs font-black shrink-0 transition-colors">
                                    {user.name ? user.name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase() : '?'}
                                </div>
                            )}
                            <span className="font-outfit font-bold text-sm text-slate-200 group-hover:text-slate-50 hidden sm:inline">{user.name}</span>
                        </button>
                    </div>
                </header>

                {/* Main Content */}
                <main className="flex-1 overflow-y-auto p-3 sm:p-4 md:p-6 lg:p-8 scrollbar-thin relative print:p-0 print:overflow-visible print:bg-white print:h-auto">
                    {children}
                </main>
            </div>

            {/* ─── System Flash Toast (Success / Warning / Error) ──────────────── */}
            <AnimatePresence>
                {toast && (
                    <motion.div
                        initial={{ opacity: 0, y: 50, scale: 0.95 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 20, scale: 0.95 }}
                        className="fixed bottom-6 right-3 sm:right-6 z-[9999] max-w-[calc(100vw-1.5rem)] sm:max-w-md w-full"
                    >
                        <div className={`p-4 rounded-xl border flex gap-3.5 shadow-2xl backdrop-blur-xl ${toast.type === 'success'
                            ? 'bg-emerald-950/90 border-emerald-500/40 text-emerald-100 shadow-emerald-950/30'
                            : toast.type === 'warning'
                                ? 'bg-amber-950/90 border-amber-500/40 text-amber-100 shadow-amber-950/30'
                                : 'bg-red-950/90 border-red-500/40 text-red-100 shadow-red-950/30'
                            }`}>
                            {toast.type === 'success' && <CheckCircle2 className="h-5 w-5 text-emerald-400 shrink-0 mt-0.5" />}
                            {toast.type === 'warning' && <AlertTriangle className="h-5 w-5 text-amber-400 shrink-0 mt-0.5" />}
                            {toast.type === 'error' && <XCircle className="h-5 w-5 text-red-400 shrink-0 mt-0.5" />}

                            <div className="flex-1 flex flex-col gap-0.5">
                                <span className="font-outfit font-extrabold text-sm uppercase tracking-wider">
                                    {toast.type === 'success' ? 'Operation Success' : toast.type === 'warning' ? 'Warning Alert' : 'Error Notice'}
                                </span>
                                <span className="text-xs leading-relaxed text-slate-300 font-medium">{toast.message}</span>
                            </div>

                            <button onClick={() => setToast(null)} className="text-slate-400 hover:text-slate-100 self-start">
                                <X size={14} />
                            </button>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* ─── Sliding Alert Toast Popups (bottom-left) ───────────────────── */}
            <div className="fixed bottom-6 left-3 sm:left-6 z-[9998] flex flex-col gap-3 items-start pointer-events-none print:hidden">
                <AnimatePresence mode="popLayout">
                    {alertToasts.map(item => (
                        <div key={item.id} className="pointer-events-auto">
                            <AlertToastCard item={item} onDismiss={() => dismissAlertToast(item.id)} />
                        </div>
                    ))}
                </AnimatePresence>
            </div>

            {/* ─── Profile Settings Modal ────────────────────────────────────── */}
            <ProfileModal show={isProfileOpen} onClose={() => setIsProfileOpen(false)} />

            {/* ─── Logout Confirmation Modal ─────────────────────────────────── */}
            <ConfirmModal
                isOpen={showLogoutConfirm}
                onClose={() => setShowLogoutConfirm(false)}
                onConfirm={() => {
                    setShowLogoutConfirm(false);
                    router.post(route('logout'));
                }}
                title="Confirm Logout"
                message="Are you sure you want to log out of your session?"
                confirmText="Logout"
                isDanger={true}
            />

        </div>
    );
}
