import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],

    resolve: {
        alias: {
            // Preserves the "@/..." import prefix used throughout the original
            // Next.js source, so ported components need no import rewrites.
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },

    // Leaflet ships its CSS and marker images from the package; pre-bundling it
    // avoids the dev-server reload loop the Next.js build worked around.
    optimizeDeps: {
        include: ['leaflet', 'react-leaflet'],
    },

    build: {
        sourcemap: process.env.NODE_ENV !== 'production',
    },
});
