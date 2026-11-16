import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, Fragment, h, type DefineComponent } from 'vue';
import { Moon, Sun } from '@lucide/vue';
import { toast, Toaster } from 'vue-sonner';
import { initAppearance, registerAppearanceShortcut } from '@/lib/appearance';

// Jambatan flash session (`session()->flash('success', ...)`) -> toast Sonner, didaftar SEKALI
// di sini (bukan per-page) supaya terpakai app-wide tanpa perlu komponen banner berasingan tiap
// page. `router.on('success')` fire PADA SETIAP visit siap (POST/PUT/DELETE), tak kira sama ke
// tak teks flash drpd sebelumnya - watch reaktif Vue biasa pd `page.props.flash` TAK boleh
// pakai (2 mesej TEKS SAMA berturut2 dianggap "xtiada perubahan", diam je). Semakan sekali di
// luar event pula tangkap flash yg dah sedia ada masa load pertama (cth. lepas redirect POST).
function bridgeFlashToToast(initialFlash: unknown) {
    const showIfPresent = (success: string | null | undefined) => {
        if (success) {
            toast.success(success);
        }
    };

    showIfPresent((initialFlash as { success?: string | null } | undefined)?.success);

    router.on('success', (event) => {
        showIfPresent((event.detail.page.props as { flash?: { success?: string | null } }).flash?.success);
    });
}

// Mod cerah/gelap: pulihkan pilihan tersimpan (skrip awal dlm app.blade.php sudah menetapkan kelas) & Ctrl + D utk menukar.
initAppearance();
registerAppearanceShortcut((appearance) =>
    toast(appearance === 'dark' ? 'Mod gelap' : 'Mod cerah', { icon: appearance === 'dark' ? Moon : Sun, duration: 1200 }),
);

createInertiaApp({
    title: (title) => (title ? `${title} - M9OS` : 'M9OS'),
    progress: {
        color: '#f59e0b', // contoh: amber, tukar ikut warna brand
    },
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        bridgeFlashToToast((props.initialPage.props as { flash?: unknown }).flash);

        createApp({ render: () => h(Fragment, [h(App, props), h(Toaster, { richColors: false })]) })
            .use(plugin)
            .mount(el);
    },
});
