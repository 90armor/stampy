import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                serif: ['"DM Serif Display"', 'Georgia', 'serif'],
            },
            // The one control height (docs/DESIGN_SYSTEM.md, Control height):
            // every text input, select, date/time field and button is h-control,
            // so a button sits level with the inputs beside it.
            height: {
                control: '2.375rem',
            },
            colors: {
                // The neutral scale, as CSS variables (resources/css/app.css):
                // warm stone in light mode, matching the warm cream of the
                // reference "Northstar" mockup; a neutral, slightly cool zinc
                // in dark mode, where stone's red-orange cast read as brown
                // (dark option C). The `slate` name is kept so no class changes.
                // Plus the two line tokens (docs/DESIGN_SYSTEM.md, Lines):
                // `divider` for lines inside a surface, `border` for surface
                // edges and controls. Each carries its own opacity, so they
                // take no opacity modifier.
                slate: {
                    ...Object.fromEntries(
                        [50, 100, 200, 300, 400, 500, 600, 700, 750, 800, 900, 950]
                            .map((step) => [step, `rgb(var(--slate-${step}) / <alpha-value>)`]),
                    ),
                    divider: 'rgb(var(--slate-divider))',
                    border: 'rgb(var(--slate-border))',
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
