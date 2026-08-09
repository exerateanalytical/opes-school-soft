import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // SKIP_REMOTE_FONTS=1 lets sandboxed/offline environments build without fetching from fonts.bunny.net
            fonts: process.env.SKIP_REMOTE_FONTS
                ? []
                : [
                      bunny('Instrument Sans', {
                          weights: [400, 500, 600],
                      }),
                  ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
