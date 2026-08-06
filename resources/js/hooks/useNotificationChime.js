import { useRef, useCallback, useEffect } from 'react';

export function useNotificationChime() {
    const audioCtxRef = useRef(null);

    const unlockAudio = useCallback(() => {
        if (!audioCtxRef.current) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) audioCtxRef.current = new Ctx();
        }
        if (audioCtxRef.current?.state === 'suspended') {
            audioCtxRef.current.resume().catch(() => {});
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

    return { playAlertChime };
}
