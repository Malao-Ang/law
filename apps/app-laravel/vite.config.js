import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const port = Number(process.env.PORT || 5173);
const publicHost = process.env.VITE_DEV_SERVER_HOST || process.env.HMR_HOST || 'localhost';
const publicPort = Number(process.env.VITE_HOST_PORT || process.env.HMR_PORT || port);
const cacheDir = process.env.VITE_CACHE_DIR || '/tmp/app-laravel-vite-cache';

export default defineConfig({
    cacheDir,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.ts'],
            // Host-run `npm run dev` writes this; laravel-app reads it via the
            // public/ bind mount. Must match AppServiceProvider::boot().
            hotFile: 'public/hot',
            refresh: [
                'resources/views/app.blade.php',
                'routes/web.php',
            ],
        }),
        vue(),
    ],
    optimizeDeps: {
        noDiscovery: true,
        include: [],
    },
    server: {
        host: '0.0.0.0',
        port,
        strictPort: true,
        origin: `http://${publicHost}:${publicPort}`,
        allowedHosts: [publicHost],
        cors: {
            origin: `http://${publicHost}:${process.env.APP_HOST_PORT || 8000}`,
        },
        hmr: {
            host: publicHost,
            protocol: 'ws',
            port: publicPort,
        },
        watch: {
            // Native fs watch (inotify) fails with EIO over the Docker Desktop
            // (Windows) bind mount. Poll instead when running in the container.
            usePolling: process.env.CHOKIDAR_USEPOLLING === 'true',
            interval: 300,
            ignored: [
                '**/app/**',
                '**/bootstrap/**',
                '**/config/**',
                '**/database/**',
                '**/lang/**',
                '**/public/build/**',
                '**/storage/**',
                '**/tests/**',
                '**/vendor/**',
                '**/node_modules/**',
                '**/.git/**',
            ],
        },
    },
});
