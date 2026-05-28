import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

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
                sans: ['Inter', 'Outfit', ...defaultTheme.fontFamily.sans],
            },
            colors: {
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
