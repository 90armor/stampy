import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const hmrHost = process.env.VITE_HMR_HOST || 'localhost';
const hmrClientPort = process.env.VITE_HMR_CLIENT_PORT
    ? Number(process.env.VITE_HMR_CLIENT_PORT)
    : 5173;

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/dashboard-chart.js',
            ],
            refresh: true,
        }),
    ],

    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,

        origin: `http://${hmrHost}:${hmrClientPort}`,

        // Setting `origin` makes laravel-vite-plugin allow CORS from that one
        // origin only — the Vite server itself — so the browser blocked every
        // script requested by the app's own pages (served from :8000), on
        // localhost and on the LAN address alike. Allow the pages' origins:
        // localhost, and the LAN host on any port.
        cors: {
            origin: [
                /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
                new RegExp(`^https?://${hmrHost.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?::\\d+)?$`),
            ],
        },

        hmr: {
            host: hmrHost,
            clientPort: hmrClientPort,
        },
    },
});
