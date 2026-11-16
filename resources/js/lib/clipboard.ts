// Salin teks ke papan keratan. Guna Clipboard API (perlukan konteks selamat/HTTPS & fokus halaman); jika tak tersedia atau ditolak,
// jatuh balik ke textarea tersembunyi + execCommand('copy') supaya tetap berfungsi pada HTTP atau pelayar lama. Pulangkan true bila berjaya.
export async function copyText(text: string): Promise<boolean> {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch {
        // Ditolak (kebenaran/fokus) - cuba kaedah sandaran di bawah.
    }

    const field = document.createElement('textarea');

    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        field.remove();
    }
}
