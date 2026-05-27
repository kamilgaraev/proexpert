import defaultTheme from 'tailwindcss/defaultTheme';

const filamentColorNames = ['danger', 'gray', 'info', 'primary', 'success', 'warning'];
const filamentColorShades = ['0', '50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
const filamentBackgroundShades = filamentColorShades.filter((shade) => shade !== '0');
const filamentStatePrefixes = ['', 'hover:', 'dark:', 'dark:hover:'];
const filamentComponentSafelist = ['fi-sidebar-close-sidebar-btn'];
const filamentColorUtilitySafelist = [
    ...filamentComponentSafelist,
    ...filamentColorNames.map((color) => `fi-color-${color}`),
    ...filamentBackgroundShades.flatMap((shade) =>
        filamentStatePrefixes.map((prefix) => `${prefix}fi-bg-color-${shade}`),
    ),
    ...filamentColorShades.flatMap((shade) =>
        filamentStatePrefixes.map((prefix) => `${prefix}fi-text-color-${shade}`),
    ),
];

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './app/Filament/**/*.php',
        './vendor/filament/**/*.blade.php',
        './vendor/filament/**/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    safelist: filamentColorUtilitySafelist,
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },
    plugins: [],
};
