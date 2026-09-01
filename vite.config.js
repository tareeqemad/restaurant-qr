import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // Every interactive screen is an Inertia/Vue page. Print and error
            // documents stay intentionally server-rendered and need no bundle.
            input: ['resources/js/app-inertia.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Laravel serves assets; Vue must not rewrite absolute URLs.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
