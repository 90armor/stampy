import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/dashboard-chart.js'],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // Lets the browser (running on the host) connect back to the Vite
        // dev server exposed by the `vite` Docker Compose service. The
        // container always listens on 5173 internally; VITE_HMR_CLIENT_PORT
        // lets docker-compose.yml tell the browser which *host* port that's
        // published on, in case 5173 is already taken on the host.
        hmr: {
            host: 'localhost',
            clientPort: process.env.VITE_HMR_CLIENT_PORT
                ? Number(process.env.VITE_HMR_CLIENT_PORT)
                : undefined,
        },
    },
});
