import { useState, useRef, useCallback, useEffect } from 'react';

const SEEN_STORAGE_KEY = 'hotel.notification.seen';
const TIME_SENSITIVE_TYPES = new Set([
    'checkout_upcoming',
    'checkout_overdue',
    'cleaning_required',
]);

function readSeenKeys() {
    try {
        const parsed = JSON.parse(sessionStorage.getItem(SEEN_STORAGE_KEY) || '[]');
        return new Set(Array.isArray(parsed) ? parsed : []);
    } catch {
        return new Set();
    }
}

function persistSeenKeys(keys) {
    try {
        sessionStorage.setItem(SEEN_STORAGE_KEY, JSON.stringify([...keys]));
    } catch {
        // Ignore quota / private-mode failures.
    }
}

/**
 * Polls /api/notifications every 30s and returns notification state along with alert toasts.
 * Time-sensitive checkout/cleaning alerts surface once per browser session on first load.
 * Stable alert_key values prevent repeat chimes on later polls.
 * cash_variance_banner is a sibling field (not a bell item) so Front Desk layout can refresh
 * without changing chime/toast behavior.
 */
export function useNotifications({ enabled = true, chime, cashVarianceBanner: pageBanner = null, pageUrl = '' }) {
    const [notifications, setNotifications] = useState([]);
    const [counts, setCounts] = useState({ total: 0, checkout: 0, inventory: 0, overdue: 0, out_of_stock: 0, rooms_attention: 0 });
    const [alertToasts, setAlertToasts] = useState([]);
    const [polledBanner, setPolledBanner] = useState(undefined);
    const seenKeysRef = useRef(null);
    const initializedRef = useRef(false);
    const requestIdRef = useRef(0);

    const dismissAlertToast = useCallback((id) => {
        setAlertToasts(prev => prev.filter(t => t.id !== id));
    }, []);

    useEffect(() => {
        setPolledBanner(undefined);
    }, [pageUrl]);

    const loadNotifications = useCallback(async () => {
        if (!enabled) return;
        const requestId = ++requestIdRef.current;
        try {
            const res = await fetch('/api/notifications', { cache: 'no-store' });
            if (!res.ok || requestId !== requestIdRef.current) return;
            const payload = await res.json();
            if (!payload.success || requestId !== requestIdRef.current) return;

            const items = Array.isArray(payload.items) ? payload.items : [];
            setNotifications(items);
            setCounts(payload.counts || {});
            if (Object.prototype.hasOwnProperty.call(payload, 'cash_variance_banner')) {
                setPolledBanner(payload.cash_variance_banner ?? null);
            }

            if (!seenKeysRef.current) {
                seenKeysRef.current = readSeenKeys();
            }

            const newItems = items.filter(item => item.alert_key && !seenKeysRef.current.has(item.alert_key));
            newItems.forEach(item => seenKeysRef.current.add(item.alert_key));
            if (newItems.length > 0) {
                persistSeenKeys(seenKeysRef.current);
            }

            const toastables = (initializedRef.current
                ? newItems
                : newItems.filter(item => TIME_SENSITIVE_TYPES.has(item.type) || item.type === 'checkout')
            ).filter(item => item.type !== 'cleaning_finished');

            if (toastables.length > 0) {
                chime?.();
                setAlertToasts(prev => {
                    const incoming = toastables.slice(0, 3).map((item, i) => ({ ...item, id: Date.now() + i }));
                    return [...prev, ...incoming].slice(-5);
                });
            }
            initializedRef.current = true;
        } catch (e) {
            console.warn('Notifications failed to load:', e);
        }
    }, [enabled, chime]);

    useEffect(() => {
        if (!enabled) return;
        loadNotifications();
        const interval = setInterval(loadNotifications, 30000);
        return () => clearInterval(interval);
    }, [enabled, loadNotifications]);

    // Auto-dismiss alert toasts after 7s
    useEffect(() => {
        if (alertToasts.length === 0) return;
        const timers = alertToasts.map(t => setTimeout(() => dismissAlertToast(t.id), 7000));
        return () => timers.forEach(clearTimeout);
    }, [alertToasts, dismissAlertToast]);

    const cashVarianceBanner = polledBanner !== undefined ? polledBanner : (pageBanner ?? null);

    return { notifications, counts, alertToasts, dismissAlertToast, cashVarianceBanner };
}
