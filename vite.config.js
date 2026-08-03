import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// No remote font fetching at build time. The build has to work on the VPS and
// in CI without reaching out to a third-party CDN, and the admin panel ships
// its own compiled Filament assets in public/ regardless.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
