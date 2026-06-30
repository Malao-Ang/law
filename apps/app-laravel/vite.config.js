import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

const port = Number(process.env.PORT || 5173);
const hmrPort = Number(process.env.HMR_PORT || port);
const cacheDir = process.env.VITE_CACHE_DIR || '/tmp/app-laravel-vite-cache';

export default defineConfig({
    cacheDir,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.ts'],
            hotFile: 'storage/app/poc/vite.hot',
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
        hmr: {
            host: process.env.HMR_HOST || 'localhost',
            protocol: 'ws',
            port: hmrPort,
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
                '**/public/**',
                '**/storage/**',
                '**/tests/**',
                '**/vendor/**',
                '**/node_modules/**',
                '**/.git/**',
            ],
        },
    },
});
