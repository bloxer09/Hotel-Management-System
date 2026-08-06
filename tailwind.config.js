import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import colors from 'tailwindcss/colors';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Mail/resources/views/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                outfit: ['Outfit', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                nano: ['9px', { lineHeight: '1.3' }],
                micro: ['10px', { lineHeight: '1.4' }],
                mini: ['11px', { lineHeight: '1.5' }],
            },
            colors: {
                surface: {
                    base: '#0f172a',
                    raised: '#1e293b',
                    overlay: '#334155',
                    deep: '#070b13',
                },
                slate: {
                    ...colors.slate,
                    350: '#8ea4b8',
                    450: '#6b84a0',
                },
                brand: {
                    50: '#f5f7fa',
                    100: '#eaeef4',
                    200: '#d0dae7',
                    300: '#a7bbd3',
                    400: '#7798bd',
                    500: '#5479a4',
                    600: '#416089',
                    700: '#354e6f',
                    800: '#2e425d',
                    900: '#2b394f',
                    950: '#1d2636',
                }
            }
        },
    },

    plugins: [forms],
};
