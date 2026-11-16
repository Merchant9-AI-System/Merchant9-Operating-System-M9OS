// Mod cerah / gelap utk halaman Inertia (BUKAN panel Filament - ia ada togol sendiri). Kelas `dark` pada <html> mengaktifkan token gelap
// dlm css/inertia.css. Pilihan pengguna disimpan dlm localStorage; tanpa pilihan, ikut tetapan sistem. Skrip kecil dlm app.blade.php
// menetapkan kelas SEBELUM paparan supaya tiada kerlipan - logik di sini mesti selaras dgn skrip itu (kunci & nilai sama).
export const APPEARANCE_STORAGE_KEY = 'm9os-appearance';

export type Appearance = 'light' | 'dark';

function storedAppearance(): Appearance | null {
    try {
        const value = window.localStorage.getItem(APPEARANCE_STORAGE_KEY);

        return value === 'light' || value === 'dark' ? value : null;
    } catch {
        // localStorage tak tersedia (mod peribadi / disekat) - guna tetapan sistem.
        return null;
    }
}

export function currentAppearance(): Appearance {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

export function applyAppearance(appearance: Appearance): void {
    document.documentElement.classList.toggle('dark', appearance === 'dark');
}

export function initAppearance(): void {
    applyAppearance(storedAppearance() ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
}

// Tukar & simpan; pulangkan mod baharu.
export function toggleAppearance(): Appearance {
    const next: Appearance = currentAppearance() === 'dark' ? 'light' : 'dark';

    applyAppearance(next);

    try {
        window.localStorage.setItem(APPEARANCE_STORAGE_KEY, next);
    } catch {
        // Tidak dapat disimpan - perubahan kekal utk sesi halaman ini sahaja.
    }

    return next;
}

// Ctrl + D = tukar cerah/gelap. preventDefault menghalang "tambah penanda halaman" pelayar (Ctrl + D) - berfungsi di kebanyakan pelayar.
// Didaftar SEKALI (app-wide). Pulangkan fungsi utk membuang pendengar.
export function registerAppearanceShortcut(onToggle: (appearance: Appearance) => void): () => void {
    const handler = (event: KeyboardEvent) => {
        if (event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'd') {
            event.preventDefault();

            if (!event.repeat) {
                onToggle(toggleAppearance());
            }
        }
    };

    window.addEventListener('keydown', handler);

    return () => window.removeEventListener('keydown', handler);
}
