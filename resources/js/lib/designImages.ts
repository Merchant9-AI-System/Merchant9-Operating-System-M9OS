import { reactive } from 'vue';

// Imej design dimuatkan ikut keperluan (lazy) utk senarai /home: baris yg kelihatan mendaftar kodnya, kod dikumpul
// seketika (debounce) lalu diminta sekali gus (GET /home/design-images). Hasil dicache dlm memori sepanjang sesi halaman;
// kod tanpa imej ditanda `null` supaya tak diminta semula.
const CHUNK = 40;
const DELAY_MS = 150;

const images = reactive<Record<string, string | null>>({});
const loading = reactive<Record<string, boolean>>({});
const queued = new Set<string>();
let timer: ReturnType<typeof setTimeout> | null = null;

async function flush(): Promise<void> {
    timer = null;
    const codes = [...queued];
    queued.clear();

    for (let i = 0; i < codes.length; i += CHUNK) {
        const chunk = codes.slice(i, i + CHUNK);
        const query = chunk.map((code) => 'codes[]=' + encodeURIComponent(code)).join('&');

        try {
            const response = await fetch('/home/design-images?' + query, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const body = response.ok ? ((await response.json()) as { images: Record<string, string> }) : { images: {} };

            for (const code of chunk) {
                images[code] = body.images[code] ?? null;
                loading[code] = false;
            }
        } catch {
            // Gagal rangkaian: biarkan tanpa imej (tidak ditanda supaya boleh dicuba semula bila muncul lagi).
            for (const code of chunk) {
                delete images[code];
                loading[code] = false;
            }
        }
    }
}

/** Daftarkan kod yg perlu imej (diabaikan jika sudah dimuat / sedang diminta). */
export function requestDesignImage(code: string): void {
    if (code in images || queued.has(code) || loading[code]) {
        return;
    }

    queued.add(code);
    loading[code] = true;
    timer ??= setTimeout(flush, DELAY_MS);
}

/** Imej yg sudah dimuat (null = tiada / belum). */
export function designImage(code: string): string | null {
    return images[code] ?? null;
}

/** Sedang menunggu imej diminta/diterima. */
export function designImageLoading(code: string): boolean {
    return loading[code] === true;
}
