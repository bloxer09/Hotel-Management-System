import { useState, useEffect } from 'react';

export function useTheme() {
    const [theme, setTheme] = useState(() =>
        document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'
    );

    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        try {
            localStorage.setItem('pms-theme', theme);
        } catch (error) {
            // Storage access blocked or unavailable
        }
    }, [theme]);

    const toggleTheme = () => setTheme(t => t === 'dark' ? 'light' : 'dark');

    return { theme, setTheme, toggleTheme };
}
