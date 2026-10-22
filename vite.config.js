import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/pages/library.js',
                'resources/js/pages/song.js',
                'resources/js/pages/setlists.js',
                'resources/js/pages/setlist.js',
                'resources/js/pages/medley.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
