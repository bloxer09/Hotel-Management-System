import { useState, useRef, useCallback, useEffect } from 'react';

/**
 * Polls /api/notifications every 30s and returns notification state along with alert toasts.
 */
export function useNotifications({ enabled = true, chime }) {
    const [notifications, setNotifications] = useState([]);
    const [counts, setCounts] = useState({ total: 0, checkout: 0, inventory: 0, overdue: 0, out_of_stock: 0 });
    const [alertToasts, setAlertToasts] = useState([]);
    const seenKeysRef = useRef(new Set());
    const initializedRef = useRef(false);

    const dismissAlertToast = useCallback((id) => {
        setAlertToasts(prev => prev.filter(t => t.id !== id));
    }, []);

    const loadNotifications = useCallback(async () => {
        if (!enabled) return;
        try {
            const res = await fetch('/api/notifications', { cache: 'no-store' });
            if (!res.ok) return;
            const payload = await res.json();
            if (!payload.success) return;

            const items = Array.isArray(payload.items) ? payload.items : [];
            setNotifications(items);
            setCounts(payload.counts || {});

            const newItems = items.filter(item => !seenKeysRef.current.has(item.alert_key));
            newItems.forEach(item => seenKeysRef.current.add(item.alert_key));

            if (initializedRef.current && newItems.length > 0) {
                chime?.();
                setAlertToasts(prev => {
                    const incoming = newItems.slice(0, 3).map((item, i) => ({ ...item, id: Date.now() + i }));
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

    return { notifications, counts, alertToasts, dismissAlertToast };
}
