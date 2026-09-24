import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                serif: ['"DM Serif Display"', 'Georgia', 'serif'],
            },
            colors: {
                // Warm stone — replaces cool slate as the neutral scale (text/borders/surfaces)
                // to match the warm cream background of the reference "Northstar" mockup.
                slate: {
                    50: '#fafaf9',
                    100: '#f5f5f4',
                    200: '#e7e5e4',
                    300: '#d6d3d1',
                    400: '#a8a29e',
                    500: '#78716c',
                    600: '#57534e',
                    700: '#44403c',
                    800: '#292524',
                    900: '#1c1917',
                    950: '#0c0a09',
                },
                // Deep evergreen — primary brand color for buttons, links, active states.
                primary: {
                    50: '#f1f7f4',
                    100: '#dcece2',
                    200: '#b9d9c8',
                    300: '#8fc0a7',
                    400: '#5fa07f',
                    500: '#3f8266',
                    600: '#2f6850',
                    700: '#26543f',
                    800: '#1e4232',
                    900: '#173327',
                },
                // Mint — accent for icon badges, brand mark, chart bars, hero highlights.
                accent: {
                    50: '#effcf6',
                    100: '#d7f8e8',
                    200: '#aff0d1',
                    300: '#7ce3b7',
                    400: '#48cf99',
                    500: '#26b57e',
                    600: '#1a9566',
                    700: '#167a54',
                    800: '#146144',
                    900: '#114f39',
                },
            },
        },
    },

    plugins: [forms],
};
