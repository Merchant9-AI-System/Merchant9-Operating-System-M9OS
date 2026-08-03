import vue from '@vitejs/plugin-vue';
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
            // resources/css|js/app.* kekal utk welcome.blade.php sedia ada (vanilla, tiada Vue) -
            // inertia.css/inertia.ts ENTRY BERASINGAN utk permukaan Inertia+Vue+shadcn-vue baharu
            // (rujuk resources/views/app.blade.php), supaya dua "dunia" frontend (Filament/Livewire
            // & Inertia/Vue) tak bercampur dlm satu bundle.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/inertia.css', 'resources/js/inertia.ts'],
            refresh: true,
            // fonts: [
            //     bunny('Instrument Sans', {
            //         weights: [400, 500, 600],
            //     }),
            // ],
        }),
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
