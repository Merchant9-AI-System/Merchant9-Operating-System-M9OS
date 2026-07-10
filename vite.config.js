import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
// import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        // Font dulu diambil dari Bunny CDN semasa build (`fonts: [bunny(...)]`) - gagalkan
        // seluruh deploy Forge bila server build tak dapat capai CDN tsb (timeout rangkaian).
        // Guna @fontsource/instrument-sans (fail font disimpan dlm node_modules) - build tak
        // perlukan akses rangkaian luar langsung, elak kelas kegagalan deploy ni sepenuhnya.
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // fonts: [
            //     bunny('Instrument Sans', {
            //         weights: [400, 500, 600],
            //     }),
            // ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
