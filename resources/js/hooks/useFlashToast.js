import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

export function useFlashToast() {
    const { flash } = usePage().props;
    const [toast, setToast] = useState(null);

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

    return { toast, setToast, dismissToast: () => setToast(null) };
}
