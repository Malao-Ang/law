import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // HMR: ต้องชี้ไปที่ localhost เพราะ browser เชื่อมผ่าน nginx → node
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        // Polling จำเป็นสำหรับ Docker volume mounts บน Windows/Mac
        watch: {
            usePolling: true,
            interval: 1000,
        },
        // Allow nginx origin (CORS for HMR)
        cors: true,
    },
});
