<script setup lang="ts">
import { useIntersectionObserver } from '@vueuse/core';
import { ArrowRightLeft, ChevronsDown, Eye, Hourglass, Ruler, Scale, ShoppingCart, Truck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import BarsChart from '@/components/restock/BarsChart.vue';
import RequestPopover from '@/components/restock/RequestPopover.vue';
import TransferPopover from '@/components/restock/TransferPopover.vue';
import { Badge, badgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Item, ItemActions, ItemContent, ItemDescription, ItemMedia, ItemTitle } from '@/components/ui/item';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CHART_COLORS } from '@/lib/chart';
import { cn } from '@/lib/utils';
import { fmt, formatDateTime, NON_BRANCH_STORES, styleFor, VERDICT_NO_DATA, VERDICT_OK, VERDICT_OVERSTOCK, VERDICT_RESTOCK, VERDICT_SOLD_OUT } from '@/lib/restock';
import type { DesignDetail, RequestLine, StockMovement } from '@/lib/restock';

// Panel kefahaman: mod `design` = sebab Cadangan, stok setiap cawangan (calon transfer) & jualan 12 bulan satu design; mod `bucket` =
// senarai design dlm satu bucket saiz/berat (klik satu design utk buka panel design). Data dimuat bila panel dibuka.
interface BucketDesign {
    internal_code: string;
    description: string | null;
    vendor_name: string | null;
    image_url: string | null;
    current_stock: number;
    pieces_sold: number;
    sold_this_month: number;
}

const props = defineProps<{
    mode: 'design' | 'bucket';
    // Mod design.
    code?: string;
    store?: string;
    // Mod bucket.
    view?: 'size' | 'weight';
    categoryCode?: string;
    categoryName?: string;
    bucket?: string;
    // Paparan Saiz/Berat yg boleh dilihat pengguna (kebenaran) - menentukan butang ke senarai design dlm bucket sama.
    canSize?: boolean;
    canWeight?: boolean;
}>();

const emit = defineEmits<{
    'open-design': [code: string, store: string];
    'open-bucket': [view: 'size' | 'weight', bucket: string, categoryCode: string, categoryName: string];
}>();

const open = defineModel<boolean>('open', { required: true });

const loading = ref(false);
const error = ref<string | null>(null);
const detail = ref<DesignDetail | null>(null);
// Permintaan aktif cawangan dipilih (dimuat bersama panel; baris baharu ditambah serta-merta selepas dihantar). Diisytihar di sini kerana load() dipanggil serta-merta oleh watch.
const requests = ref<RequestLine[]>([]);
const bucketDesigns = ref<BucketDesign[]>([]);
const bucketTotal = ref(0);
const bucketLoadingMore = ref(false);
const bucketLoadError = ref(false);
// Halaman pertama tambahan dimuat automatik bila pengguna scroll ke bawah; selepas itu butang "Muat Lagi" (elak muat berpuluh halaman tanpa sedar).
const AUTO_LOAD_PAGES = 1;
let autoLoaded = 0;
let token = 0;

const bucketHasMore = computed(() => bucketDesigns.value.length < bucketTotal.value);

const bucketUrl = (offset = 0) =>
    `/restock-analysis/designs?${new URLSearchParams({ view: props.view ?? 'size', category: props.categoryCode ?? '', store: props.store ?? '', bucket: props.bucket ?? '', offset: String(offset) })}`;

async function load() {
    const current = ++token;
    loading.value = true;
    error.value = null;
    detail.value = null;
    requests.value = [];
    bucketDesigns.value = [];
    bucketTotal.value = 0;
    bucketLoadError.value = false;
    autoLoaded = 0;

    const url = props.mode === 'design' ? `/restock-analysis/design?${new URLSearchParams({ code: props.code ?? '', store: props.store ?? '' })}` : bucketUrl();

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();

        if (current !== token) {
            return;
        }

        if (props.mode === 'design') {
            detail.value = data;
            requests.value = data.requests ?? [];
        } else {
            bucketDesigns.value = data.designs;
            bucketTotal.value = data.total;
        }
    } catch {
        if (current === token) {
            error.value = 'Butiran tidak dapat dimuatkan. Cuba lagi.';
        }
    } finally {
        if (current === token) {
            loading.value = false;
        }
    }
}

// Muat halaman seterusnya senarai design bucket (tambah, tanpa pertindihan).
async function loadMore() {
    if (props.mode !== 'bucket' || bucketLoadingMore.value || !bucketHasMore.value) {
        return;
    }

    const current = token;
    bucketLoadingMore.value = true;
    bucketLoadError.value = false;

    try {
        const response = await fetch(bucketUrl(bucketDesigns.value.length), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = await response.json();

        if (current !== token) {
            return;
        }

        const seen = new Set(bucketDesigns.value.map((d) => d.internal_code));
        bucketDesigns.value = [...bucketDesigns.value, ...(data.designs as BucketDesign[]).filter((d) => !seen.has(d.internal_code))];
        bucketTotal.value = data.total;
    } catch {
        if (current === token) {
            bucketLoadError.value = true;
        }
    } finally {
        if (current === token) {
            bucketLoadingMore.value = false;
        }
    }
}

// Sentinel di hujung senarai: bila kelihatan (scroll hampir habis), muat halaman automatik sekali sahaja.
const sentinel = ref<HTMLElement | null>(null);

useIntersectionObserver(sentinel, ([entry]) => {
    if (entry?.isIntersecting && autoLoaded < AUTO_LOAD_PAGES && bucketHasMore.value && !loading.value && !bucketLoadError.value) {
        autoLoaded++;
        loadMore();
    }
});

watch(
    () => [open.value, props.mode, props.code, props.store, props.view, props.categoryCode, props.bucket] as const,
    ([isOpen]) => {
        if (isOpen) {
            load();
        }
    },
    { immediate: true },
);

const selected = computed(() => detail.value?.stores.find((s) => s.store_code === detail.value?.selected_store) ?? null);

// Penjelasan ringkas SEBAB Cadangan bagi cawangan dipilih - angka sama spt jadual (tetingkap 3 bulan).
const reason = computed(() => {
    const s = selected.value;

    if (!s) {
        return null;
    }

    const rate = fmt(s.velocity_per_month, 1);

    switch (s.verdict) {
        case VERDICT_SOLD_OUT:
            return `Stok 0 di ${s.store_code}. Terjual ${s.sold_3m} dalam 3 bulan (kira-kira ${rate} sebulan) - perlu stok semula. Disyorkan ${s.target_stock} (1.5 bulan jualan).`;
        case VERDICT_RESTOCK:
            return `Stok ${s.stock} cukup untuk ${fmt(s.cover_months, 1)} bulan sahaja (jualan kira-kira ${rate} sebulan). Disyorkan ${s.target_stock} (1.5 bulan jualan), kurang ${s.gap}.`;
        case VERDICT_OVERSTOCK:
            return `Stok ${s.stock} melebihi dua kali ganda paras disyorkan (${s.target_stock}); jualan kira-kira ${rate} sebulan.`;
        case VERDICT_OK:
            return `Stok ${s.stock} mencukupi (disyorkan ${s.target_stock}; jualan kira-kira ${rate} sebulan).`;
        case VERDICT_NO_DATA:
            return 'Data belum mencukupi (kurang 3 penerimaan keseluruhan) untuk memberi cadangan.';
        default:
            return null;
    }
});

const needsStock = computed(() => selected.value !== null && [VERDICT_SOLD_OUT, VERDICT_RESTOCK].includes(selected.value.verdict));

// --- Transit & status lain (maklumat tambahan - TIDAK mengubah stok/Cadangan; piece transit masih dikira di cawangan penghantar) ---
const MOVEMENT_LABEL: Record<StockMovement['type'], string> = { transit: 'Transit', loan_transit: 'Loan transit' };
const MOVEMENT_BADGE: Record<StockMovement['type'], string> = {
    transit: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    loan_transit: 'border-transparent bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
};

const movements = computed(() => detail.value?.movements ?? []);

// Piece yg sedang menuju cawangan dipilih, dikumpul ikut jenis (transit dan loan transit diasingkan).
const incomingToSelected = computed(() =>
    (['transit', 'loan_transit'] as const)
        .map((type) => {
            const rows = movements.value.filter((m) => m.type === type && m.to_store === detail.value?.selected_store);

            return { type, qty: rows.reduce((sum, m) => sum + m.qty, 0), sizes: [...new Set(rows.map((m) => (m.size === '(tiada)' ? 'tiada saiz' : m.size)))] };
        })
        .filter((group) => group.qty > 0),
);

// Pergerakan sebuah cawangan: masuk (menuju) & keluar (dikira sbg stok cawangan ini tetapi sedang dihantar), setiap jenis berasingan.
function flowOf(store: string) {
    const sum = (type: StockMovement['type'], key: 'from_store' | 'to_store') => movements.value.filter((m) => m.type === type && m[key] === store).reduce((total, m) => total + m.qty, 0);

    return { transitIn: sum('transit', 'to_store'), transitOut: sum('transit', 'from_store'), loanIn: sum('loan_transit', 'to_store'), loanOut: sum('loan_transit', 'from_store') };
}

// Cip kecil pada baris cawangan: pergerakan masuk/keluar & piece dikhaskan.
function storeChips(store: string): { label: string; title: string; class: string }[] {
    const flow = flowOf(store);
    const holds = detail.value?.holds[store];
    const chips: { label: string; title: string; class: string }[] = [];

    if (flow.transitIn > 0) chips.push({ label: `masuk ${flow.transitIn}`, title: `${flow.transitIn} piece transit sedang menuju ${store} (belum dikira dalam stok)`, class: MOVEMENT_BADGE.transit });
    if (flow.transitOut > 0) chips.push({ label: `keluar ${flow.transitOut}`, title: `${flow.transitOut} piece transit dikira sebagai stok ${store} tetapi sedang dihantar keluar`, class: 'border-border text-muted-foreground' });
    if (flow.loanIn > 0) chips.push({ label: `loan masuk ${flow.loanIn}`, title: `${flow.loanIn} piece loan transit sedang menuju ${store}`, class: MOVEMENT_BADGE.loan_transit });
    if (flow.loanOut > 0) chips.push({ label: `loan keluar ${flow.loanOut}`, title: `${flow.loanOut} piece loan transit dikira sebagai stok ${store} tetapi sedang dihantar keluar`, class: 'border-border text-muted-foreground' });

    if (holds) {
        const total = holds.reserved + holds.booked + holds.loaned;
        const parts = [holds.reserved > 0 && `Reserve ${holds.reserved}`, holds.booked > 0 && `Booked ${holds.booked}`, holds.loaned > 0 && `Loan ${holds.loaned}`].filter(Boolean);

        chips.push({ label: `dikhaskan ${total}`, title: `${parts.join(', ')} - masih dikira sebagai stok`, class: 'border-border text-muted-foreground' });
    }

    return chips;
}

// Popover butiran stok sebuah cawangan lebihan: satu popover dibuka pada satu masa (Popover.vue perlukan keadaan `open` dikawal induk).
const openSurplusStore = ref<string | null>(null);

watch(() => [props.code, props.store], () => (openSurplusStore.value = null));

// Saiz & berat piece yg MASIH ADA STOK di sebuah cawangan (daripada senarai saiz panel ini - tiada permintaan tambahan).
const stockedSizes = (store: string) => (detail.value?.sizes ?? []).filter((r) => r.store_code === store && r.stock > 0);

// Stok minimum sebuah cawangan utk disenaraikan sbg sumber (1 piece biasanya stok paparan - tak dicadangkan).
const MIN_SOURCE_STOCK = 2;

const STOCK_SERIES = [
    { key: 'stock', label: 'Stok', color: CHART_COLORS.stock },
    { key: 'target_stock', label: 'Disyorkan', color: CHART_COLORS.target },
];
const storeStockData = computed(() => (detail.value?.stores ?? []).map((s) => ({ label: s.store_code, stock: s.stock, target_stock: s.target_stock })));

// --- Stok ikut saiz & berat: HANYA cawangan yg diklik (saiz menaik, susunan dari server). Cawangan lain ada dlm jadual & carta cawangan. ---
const sizeRows = computed(() => {
    const store = detail.value?.selected_store;

    return (detail.value?.sizes ?? []).filter((r) => !store || r.store_code === store);
});

const weightText = (min: number | null, max: number | null) => (min === null ? '-' : min === max ? `${fmt(min, 2)} g` : `${fmt(min, 2)} - ${fmt(max, 2)} g`);

// --- Kotak cadangan: SATU cadangan tindakan bagi cawangan dipilih (maklumat sahaja - kuantiti ditentukan Back Office) ---
// Jualan 3 bulan di bawah paras ini dianggap perlahan: semak dahulu sebelum order.
const SLOW_SALES_3M = 3;

// Piece transit (Status 5) yg sedang menuju cawangan dipilih - belum dikira dalam stok, jadi menjelaskan kenapa tak perlu request lagi.
const transitIncoming = computed(() => movements.value.filter((m) => m.type === 'transit' && m.to_store === detail.value?.selected_store));
const transitIncomingQty = computed(() => transitIncoming.value.reduce((sum, m) => sum + m.qty, 0));

// Saiz yg habis di cawangan dipilih tetapi masih laku (3 bulan) - saiz yg paling wajar dicari di cawangan lain.
const soldOutSizes = computed(() => sizeRows.value.filter((r) => r.stock === 0 && r.sold_3m > 0 && r.size !== '(tiada)').map((r) => r.size));

// Cadangan saiz utk borang request: saiz laku 3 bulan di cawangan dipilih, yg habis dahulu.
const requestSizeHints = computed(() =>
    sizeRows.value
        .filter((r) => r.sold_3m > 0 && r.size !== '(tiada)')
        .map((r) => ({
            size: r.size,
            stock: r.stock,
            soldOut: r.stock === 0,
            weight: r.weight_min === null ? '-' : r.weight_min === r.weight_max ? fmt(r.weight_min, 2) : `${fmt(r.weight_min, 2)} - ${fmt(r.weight_max, 2)}`,
        }))
        .sort((a, b) => Number(b.soldOut) - Number(a.soldOut))
        .slice(0, 8),
);

// Cawangan yg sedang Sold Out / Perlu Restock (calon penerima bila cawangan dipilih Overstock), kekurangan terbesar dahulu.
const needyStores = computed(() =>
    (detail.value?.stores ?? [])
        .filter((s) => [VERDICT_SOLD_OUT, VERDICT_RESTOCK].includes(s.verdict) && s.gap > 0 && s.store_code !== detail.value?.selected_store && !NON_BRANCH_STORES.includes(s.store_code))
        .sort((a, b) => b.gap - a.gap),
);

type SuggestionKey = 'waiting' | 'transfer' | 'check' | 'order' | 'give' | 'watch';

interface Suggestion {
    key: SuggestionKey;
    icon: typeof Truck;
    title: string;
    text: string;
    box: string;
    iconClass: string;
    titleClass: string;
    textClass: string;
    // Tindakan pada kotak: request (stok boleh diambil dari cawangan lain), order (tiada sumber), transfer (cawangan Overstock memberi) atau tiada.
    action: 'request' | 'order' | 'transfer' | 'transfer-request' | null;
    actionLabel?: string;
    initialRemark?: string;
}

const SUGGESTION_TONES: Record<'sky' | 'amber' | 'violet' | 'muted', Pick<Suggestion, 'box' | 'iconClass' | 'titleClass' | 'textClass'>> = {
    sky: {
        box: 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950',
        iconClass: 'border-sky-200 bg-sky-100 text-sky-700 dark:border-sky-800 dark:bg-sky-900 dark:text-sky-300',
        titleClass: 'text-sky-900 dark:text-sky-100',
        textClass: 'text-sky-900/80 dark:text-sky-200/80',
    },
    amber: {
        box: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950',
        iconClass: 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900 dark:text-amber-300',
        titleClass: 'text-amber-900 dark:text-amber-100',
        textClass: 'text-amber-900/80 dark:text-amber-200/80',
    },
    violet: {
        box: 'border-violet-200 bg-violet-50 dark:border-violet-900 dark:bg-violet-950',
        iconClass: 'border-violet-200 bg-violet-100 text-violet-700 dark:border-violet-800 dark:bg-violet-900 dark:text-violet-300',
        titleClass: 'text-violet-900 dark:text-violet-100',
        textClass: 'text-violet-900/80 dark:text-violet-200/80',
    },
    muted: { box: 'bg-muted/50', iconClass: 'bg-muted text-muted-foreground', titleClass: '', textClass: 'text-muted-foreground' },
};

// Cawangan sumber (calon transfer): cawangan lain Stok Cukup / Overstock. `available` = stok tolak piece yg sedang dihantar keluar (transit & loan transit) -
// piece itu masih dikira stok penghantar tetapi tidak lagi boleh diambil. `matching` = saiz habis di cawangan dipilih yg ada di cawangan sumber.
// Cawangan yg kehabisan stok (Sold Out) boleh juga meminta daripada cawangan Perlu Restock yg masih ada stok; cawangan lain hanya daripada Stok Cukup / Overstock.
const donorVerdicts = computed(() => (selected.value?.verdict === VERDICT_SOLD_OUT ? [VERDICT_OK, VERDICT_OVERSTOCK, VERDICT_RESTOCK] : [VERDICT_OK, VERDICT_OVERSTOCK]));

const sourceStores = computed(() =>
    (detail.value?.stores ?? [])
        .filter((s) => donorVerdicts.value.includes(s.verdict) && s.store_code !== detail.value?.selected_store && !NON_BRANCH_STORES.includes(s.store_code))
        .map((s) => {
            const flow = flowOf(s.store_code);
            const available = s.stock - flow.transitOut - flow.loanOut;

            return {
                ...s,
                available,
                // Cawangan Perlu Restock yg menyumbang dibiarkan sekurang-kurangnya 1 piece.
                takeable: available - (s.verdict === VERDICT_RESTOCK ? 1 : 0),
                matching: stockedSizes(s.store_code).filter((r) => soldOutSizes.value.includes(r.size)).map((r) => r.size),
            };
        })
        .filter((s) => s.available >= MIN_SOURCE_STOCK)
        .sort((a, b) => b.available - a.available),
);

const suggestion = computed<Suggestion | null>(() => {
    const s = selected.value;

    if (!s) {
        return null;
    }

    const store = s.store_code;

    if (s.verdict === VERDICT_NO_DATA) {
        return { key: 'watch', icon: Eye, title: 'Pantau dahulu', text: `Data ${store} belum mencukupi untuk memberi cadangan - pantau sehingga ada lebih banyak penerimaan dan jualan.`, ...SUGGESTION_TONES.muted, action: null };
    }

    if (s.verdict === VERDICT_OVERSTOCK) {
        return needyStores.value.length === 0
            ? null
            : { key: 'give', icon: ArrowRightLeft, title: 'Boleh transfer keluar', text: `Stok ${store} berlebihan. Cawangan lain yang sedang kekurangan stok (tekan untuk lihat kekurangan):`, ...SUGGESTION_TONES.sky, action: 'transfer', actionLabel: 'Transfer' };
    }

    if (!needsStock.value) {
        return null;
    }

    const shortfall = Math.max(0, s.gap);
    const incoming = transitIncomingQty.value;

    if (incoming > 0 && incoming >= shortfall) {
        const from = [...new Set(transitIncoming.value.map((m) => m.from_store))].join(', ');

        return { key: 'waiting', icon: Hourglass, title: 'Menunggu stok dalam perjalanan', text: `Menunggu ${incoming} piece dalam perjalanan daripada ${from} - tak perlu request sekarang.`, ...SUGGESTION_TONES.amber, action: null };
    }

    const partial = incoming > 0 ? ` ${incoming} piece sudah dalam perjalanan tetapi masih kurang ${shortfall - incoming}.` : '';

    if (sourceStores.value.length > 0) {
        // const hint = soldOutSizes.value.length > 0 ? ` Saiz yang habis di ${store}: ${soldOutSizes.value.join(', ')}.` : '';

        return { key: 'transfer', icon: ArrowRightLeft, title: 'Boleh minta transfer', text: `Cawangan lain ada stok lebih (${MIN_SOURCE_STOCK} piece ke atas yang boleh diambil).${partial}`, ...SUGGESTION_TONES.sky, action: 'transfer-request', actionLabel: 'Request' };
    }

    const lastSold = s.last_sold_at ? `, jualan terakhir ${s.last_sold_at}` : '';

    if (s.sold_3m < SLOW_SALES_3M) {
        return { key: 'check', icon: Eye, title: 'Semak dahulu', text: `Tiada cawangan lain berstok lebih dan jualan perlahan (${s.sold_3m} piece dalam 3 bulan${lastSold}). Semak sama ada design ini perlu di-restock.${partial}`, ...SUGGESTION_TONES.amber, action: 'request', actionLabel: 'Request', initialRemark: 'Semak dahulu - jualan perlahan' };
    }

    return {
        key: 'order',
        icon: ShoppingCart,
        title: 'Perlu order ke HQ',
        text: `Tiada cawangan lain berstok lebih. Terjual ${s.sold_3m} piece dalam 3 bulan${lastSold}.${partial}`,
        ...SUGGESTION_TONES.violet,
        action: 'order',
        actionLabel: 'Order',
    };
});

// Bila cawangan lain boleh menyumbang, order sentiasa ditawarkan sebagai pilihan kedua: saiz atau berat yg dicari mungkin tiada di cawangan penyumbang
// (pengguna menaip sendiri saiz/berat dlm borang), jadi dua kotak keluar serentak - transfer dan order.
const orderAlongsideTransfer = computed<Suggestion | null>(() => {
    if (suggestion.value?.key !== 'transfer') {
        return null;
    }

    return {
        key: 'order',
        icon: ShoppingCart,
        title: 'Atau order ke HQ',
        text: '',
        ...SUGGESTION_TONES.violet,
        action: 'order',
        actionLabel: 'Order',
    };
});

const suggestions = computed<Suggestion[]>(() => [suggestion.value, orderAlongsideTransfer.value].filter((x): x is Suggestion => x !== null));

// Butang transfer (cawangan Overstock memberi): stok boleh diambil = stok tolak piece yg sedang dihantar keluar; lebihan = stok melebihi paras disyorkan.
const transferAvailable = computed(() => {
    const store = selected.value;

    if (!store) {
        return 0;
    }

    const flow = flowOf(store.store_code);

    return Math.max(0, store.stock - flow.transitOut - flow.loanOut);
});

// Saiz (masih ada stok) sebuah cawangan utk popover transfer; berat = julat piece saiz itu.
const stockedSizeRows = (store: string) =>
    stockedSizes(store).map((r) => ({
        size: r.size === '(tiada)' ? 'tiada' : r.size,
        stock: r.stock,
        weight: r.weight_min === null ? '-' : r.weight_min === r.weight_max ? fmt(r.weight_min, 2) : `${fmt(r.weight_min, 2)} - ${fmt(r.weight_max, 2)}`,
    }));

// Pilihan popover Transfer (cawangan Overstock memberi): cawangan penerima; kuantiti dicadangkan = yang terkecil antara kekurangan penerima & lebihan.
const giveOptions = computed(() => {
    const store = selected.value;
    const surplus = store ? Math.max(0, store.stock - store.target_stock) : 0;

    return needyStores.value.map((n) => ({
        store_code: n.store_code,
        label: `kurang ${n.gap}`,
        title: `${n.verdict} - disyorkan ${n.target_stock}, stok ${n.stock}`,
        max: transferAvailable.value,
        suggested: Math.min(n.gap, surplus > 0 ? surplus : transferAvailable.value),
    }));
});

// Pilihan popover Request (cawangan kekurangan meminta): cawangan penyumbang; kuantiti dicadangkan = yang terkecil antara kekurangan sendiri & stok boleh diambil.
const requestOptions = computed(() =>
    sourceStores.value.map((s) => ({
        store_code: s.store_code,
        label: `boleh ${s.takeable}`,
        title: `${s.takeable} piece boleh diambil di ${s.store_code}${s.matching.length > 0 ? `; saiz padan ${s.matching.join(', ')}` : ''}`,
        max: s.takeable,
        suggested: Math.min(s.takeable, Math.max(1, selected.value?.gap ?? 1)),
    })),
);

function onRequestSubmitted(line: RequestLine) {
    requests.value = [line, ...requests.value];
}

// Semua gambar satu design (carousel pratonton) - dimuat hanya bila gambar ditekan.
const galleryUrl = (code: string) => `/restock-analysis/images?code=${encodeURIComponent(code)}`;

const monthlyMax = computed(() => Math.max(1, ...(detail.value?.monthly ?? []).map((m) => m.store + m.others)));

// `full` = nama bulan + tahun (tooltip/pembaca skrin); lalai = singkatan 3 huruf utk pil.
function monthLabel(month: string, full = false): string {
    return new Date(`${month}-01T00:00:00`).toLocaleString('ms-MY', full ? { month: 'long', year: 'numeric' } : { month: 'short' });
}

const title = computed(() => (props.mode === 'design' ? (props.code ?? '') : `${props.categoryName ?? ''} · ${props.store ?? ''} · ${props.view === 'size' ? 'Saiz' : 'Berat'} ${props.bucket ?? ''}`));
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="w-full gap-0 sm:max-w-xl">
            <SheetHeader>
                <SheetTitle class="break-words">{{ title }}</SheetTitle>

                <template v-if="mode === 'design'">
                    <SheetDescription>
                        {{ detail?.description ?? (loading ? 'Memuatkan...' : '') }}
                        <template v-if="detail"> &middot; {{ detail.category_name }}<template v-if="detail.vendor_name">
                                &middot; {{ detail.vendor_name }}</template></template>
                    </SheetDescription>
                </template>
                <SheetDescription v-else-if="!loading && bucketTotal > 0">
                    Menunjukkan {{ bucketDesigns.length }} daripada {{ bucketTotal }} design (disusun ikut terjual bulan
                    ini).
                </SheetDescription>
                <SheetDescription v-else>Design dalam bucket ini, disusun ikut terjual bulan ini.</SheetDescription>
            </SheetHeader>

            <!-- Badan panel = ScrollArea (header kekal kelihatan). [&_[data-slot=scroll-area-viewport]>div]:!block: pembungkus dlm viewport reka-ui asalnya display:table & melebar ikut kandungan. -->
            <ScrollArea class="min-h-0 flex-1 [&_[data-slot=scroll-area-viewport]>div]:!block">
                <div class="flex flex-col gap-4 px-4 pb-6">
                    <div v-if="loading" class="flex flex-col gap-2" aria-busy="true">
                        <Skeleton v-for="i in 6" :key="i" class="h-12 w-full" />
                    </div>

                    <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>

                    <!-- Mod design -->
                    <template v-else-if="mode === 'design' && detail">
                        <div class="flex items-start gap-3">
                            <ImagePreview :src="detail.image_url" :alt="detail.code" :gallery-url="galleryUrl(detail.code)" class="size-20 shrink-0" />
                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                <div v-if="selected" class="flex flex-wrap items-center gap-2">
                                    <Badge :class="styleFor(selected.verdict).badge">{{ selected.verdict }}</Badge>
                                    <Badge variant="outline">{{ selected.store_code }}</Badge>
                                </div>
                                <p v-if="reason" class="text-sm">{{ reason }}</p>
                                <p v-if="selected?.last_sold_at" class="text-xs text-muted-foreground">Jualan terakhir
                                    di {{ selected.store_code }}: {{ selected.last_sold_at }}</p>
                            </div>
                        </div>

                        <!-- Dalam perjalanan ke cawangan dipilih (maklumat tambahan): belum dikira dalam stok cawangan ini -->
                        <Item v-if="incomingToSelected.length > 0" variant="outline" size="sm"
                            class="border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950">
                            <ItemMedia variant="icon"
                                class="border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900 dark:text-amber-300">
                                <Truck />
                            </ItemMedia>
                            <ItemContent>
                                <ItemTitle class="text-amber-900 dark:text-amber-100">Dalam perjalanan ke {{
                                    detail.selected_store }}</ItemTitle>
                                <ItemDescription class="line-clamp-none text-amber-900/80 dark:text-amber-200/80">
                                    <span v-for="group in incomingToSelected" :key="group.type"
                                        class="mr-3 inline-flex flex-wrap items-center gap-1.5">
                                        <Badge :class="MOVEMENT_BADGE[group.type]">{{ MOVEMENT_LABEL[group.type] }}
                                        </Badge>
                                        {{ group.qty }} piece (saiz {{ group.sizes.join(', ') }})
                                    </span>
                                </ItemDescription>
                                <p class="text-xs text-amber-900/70 dark:text-amber-200/70">Belum dikira dalam stok {{
                                    detail.selected_store }} di atas.</p>
                            </ItemContent>
                        </Item>

                        <!-- Kotak cadangan: satu tindakan disyorkan utk cawangan dipilih (kuantiti ditentukan Back Office) -->
                        <Item v-for="suggestion in suggestions" :key="suggestion.key" variant="outline" size="sm" :class="suggestion.box">
                            <ItemMedia variant="icon" :class="suggestion.iconClass">
                                <component :is="suggestion.icon" />
                            </ItemMedia>
                            <ItemContent>
                                <ItemTitle :class="suggestion.titleClass">{{ suggestion.title }}</ItemTitle>
                                <ItemDescription v-if="suggestion.text" :class="cn('line-clamp-none', suggestion.textClass)">{{ suggestion.text
                                }}</ItemDescription>

                                <!-- Calon transfer masuk: tekan cawangan = popover saiz & berat stok yg ada di cawangan itu (popover, bukan hover, supaya berfungsi pada skrin sentuh) -->
                                <div v-if="suggestion.key === 'transfer'" class="mt-1 flex flex-wrap gap-1.5">
                                    <Popover v-for="s in sourceStores" :key="s.store_code"
                                        :open="openSurplusStore === s.store_code"
                                        @update:open="(isOpen: boolean) => (openSurplusStore = isOpen ? s.store_code : null)">
                                        <PopoverTrigger as-child>
                                            <button type="button"
                                                :class="cn(badgeVariants({ variant: 'outline' }), 'cursor-pointer border-sky-300 bg-background/60 transition-colors hover:bg-accent focus-visible:outline-none dark:border-sky-700')"
                                                :title="`Lihat saiz dan berat stok di ${s.store_code} (${s.takeable} piece boleh diambil)`">
                                                {{ s.store_code }}
                                                <strong class="font-semibold tabular-nums">{{ s.takeable }}</strong>
                                                <span v-if="s.matching.length > 0"
                                                    class="text-emerald-700 dark:text-emerald-400">padan {{
                                                        s.matching.join(', ') }}</span>
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverContent align="start" class="w-72 p-0">
                                            <div class="flex items-center justify-between gap-2 border-b px-3 py-2">
                                                <span class="text-sm font-medium">{{ s.store_code }}: stok yang
                                                    ada</span>
                                                <span class="text-xs text-muted-foreground tabular-nums">{{ fmt(s.stock)
                                                }} piece</span>
                                            </div>
                                            <p v-if="s.stock !== s.available"
                                                class="border-b bg-amber-50 px-3 py-1.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                                {{ s.stock - s.available }} piece sedang dihantar keluar (transit) -
                                                termasuk dalam jadual di bawah tetapi tidak dikira pada badge ({{
                                                    s.available }} boleh diambil).
                                            </p>
                                            <p v-if="stockedSizes(s.store_code).length === 0"
                                                class="px-3 py-3 text-xs text-muted-foreground">Tiada butiran saiz bagi
                                                stok ini.</p>
                                            <ScrollArea v-else
                                                class="max-h-60 [&_[data-slot=scroll-area-viewport]>div]:!block">
                                                <table class="w-full text-xs">
                                                    <thead>
                                                        <tr class="text-left text-muted-foreground">
                                                            <th class="px-3 py-1.5 font-medium">Saiz</th>
                                                            <th class="px-3 py-1.5 font-medium">Berat</th>
                                                            <th class="px-3 py-1.5 text-right font-medium">Stok</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="r in stockedSizes(s.store_code)" :key="r.size"
                                                            class="border-t">
                                                            <td class="px-3 py-1.5 font-medium">{{ r.size === '(tiada)'
                                                                ? 'Tiada saiz' : r.size }}</td>
                                                            <td class="whitespace-nowrap px-3 py-1.5 tabular-nums">{{
                                                                weightText(r.weight_min, r.weight_max) }}</td>
                                                            <td class="px-3 py-1.5 text-right font-medium tabular-nums">
                                                                {{ fmt(r.stock) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </ScrollArea>
                                        </PopoverContent>
                                    </Popover>
                                </div>

                                <!-- Cawangan penerima bila stok cawangan dipilih berlebihan -->
                                <div v-else-if="suggestion.key === 'give'" class="mt-1 flex flex-wrap gap-1.5">
                                    <Badge v-for="n in needyStores" :key="n.store_code" variant="outline"
                                        class="border-sky-300 bg-background/60 dark:border-sky-700"
                                        :title="`${n.verdict} - disyorkan ${n.target_stock}, stok ${n.stock}`">
                                        {{ n.store_code }}
                                        <strong class="font-semibold tabular-nums">kurang {{ n.gap }}</strong>
                                    </Badge>
                                </div>

                            </ItemContent>
                            <!-- Cawangan kekurangan stok: Request transfer (ada cawangan penyumbang; jika pengguna tiada kebenaran StockTransfer, jatuh ke Request biasa) atau Order (tiada sumber atau pilihan tambahan).
                                 Cawangan Overstock: Transfer. Disembunyi utk HQ/Security & pengguna tanpa kebenaran (server menentukan). -->
                            <ItemActions>
                                <TransferPopover
                                    v-if="suggestion.action === 'transfer-request' && detail.can_transfer"
                                    :key="`request|${detail.selected_store}`" mode="request" :code="detail.code"
                                    :self-store="detail.selected_store" :options="requestOptions"
                                    :sizes-for="stockedSizeRows" />
                                <TransferPopover
                                    v-else-if="suggestion.action === 'transfer' && detail.can_transfer"
                                    :key="`give|${detail.selected_store}`" mode="give" :code="detail.code"
                                    :self-store="detail.selected_store" :options="giveOptions"
                                    :sizes-for="() => stockedSizeRows(detail?.selected_store ?? '')" />
                                <RequestPopover
                                    v-else-if="['request', 'order', 'transfer-request'].includes(suggestion.action ?? '') && detail.can_request"
                                    :key="`${suggestion.key}|${detail.selected_store}`" :code="detail.code"
                                    :store="detail.selected_store" :size-hints="requestSizeHints"
                                    :type="suggestion.action === 'order' ? 'order' : 'request'"
                                    :label="suggestion.actionLabel" :initial-remark="suggestion.initialRemark"
                                    @submitted="onRequestSubmitted" />
                            </ItemActions>
                        </Item>

                        <!-- Permintaan cawangan dipilih yg masih diproses (supaya tidak diminta dua kali) -->
                        <Item v-if="requests.length > 0" variant="muted" size="sm">
                            <ItemContent>
                                <ItemTitle>Permintaan sedia ada untuk {{ detail.selected_store }}</ItemTitle>
                                <ItemDescription class="line-clamp-none">
                                    <span class="flex flex-wrap gap-1.5">
                                        <Badge v-for="r in requests" :key="r.id" variant="outline"
                                            :title="r.remark ?? undefined">
                                            {{ r.qty }} pcs &middot; {{ r.size ? `saiz ${r.size}` : 'tiada saiz' }}<template v-if="r.weight">
                                                &middot; {{ r.weight }} g</template>
                                            &middot; {{ r.label }}
                                        </Badge>
                                    </span>
                                </ItemDescription>
                            </ItemContent>
                        </Item>

                        <!-- Stok ikut saiz & berat bagi cawangan yg diklik: saiz mana ada / habis -->
                        <section class="flex flex-col gap-2">
                            <h3 class="text-sm font-medium">Stok ikut saiz dan berat{{ detail.selected_store ? ` -
                                ${detail.selected_store}` : '' }}</h3>
                            <p v-if="sizeRows.length === 0" class="text-sm text-muted-foreground">Tiada stok atau jualan
                                3 bulan
                                terkini{{ detail.selected_store ? ` di ${detail.selected_store}` : '' }}.</p>
                            <ScrollArea v-else horizontal
                                class="max-h-80 rounded-lg border [&_[data-slot=scroll-area-viewport]>div]:!block">
                                <Table>
                                    <TableHeader class="sticky top-0 z-10 bg-card shadow-[0_1px_0_var(--border)]">
                                        <TableRow class="hover:bg-transparent">
                                            <TableHead>Saiz</TableHead>
                                            <TableHead>Berat</TableHead>
                                            <TableHead class="text-right">Stok</TableHead>
                                            <TableHead class="text-right">Terjual 3 bulan</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="r in sizeRows" :key="`${r.store_code}|${r.size}`">
                                            <TableCell class="whitespace-nowrap font-medium">
                                                <button v-if="canSize" type="button"
                                                    class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-sm font-medium transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                    :title="`Lihat design lain ${r.size === '(tiada)' ? 'tanpa saiz' : `saiz ${r.size}`} di ${r.store_code}`"
                                                    @click="emit('open-bucket', 'size', r.size, detail.category_code, detail.category_name)">
                                                    <Ruler class="size-3 text-muted-foreground" aria-hidden="true" />
                                                    {{ r.size === '(tiada)' ? 'Tiada saiz' : r.size }}
                                                </button>
                                                <template v-else>{{ r.size === '(tiada)' ? 'Tiada saiz' : r.size
                                                }}</template>
                                            </TableCell>
                                            <TableCell class="tabular-nums">
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="whitespace-nowrap">{{ weightText(r.weight_min,
                                                        r.weight_max) }}</span>
                                                    <template v-if="canWeight">
                                                        <button v-for="bucket in r.weight_buckets" :key="bucket"
                                                            type="button"
                                                            class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-medium transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                            :title="`Lihat design lain berat ${bucket === '(tiada)' ? 'tiada' : bucket} di ${r.store_code}`"
                                                            @click="emit('open-bucket', 'weight', bucket, detail.category_code, detail.category_name)">
                                                            <Scale class="size-3 text-muted-foreground"
                                                                aria-hidden="true" />
                                                            {{ bucket === '(tiada)' ? 'Tiada berat' : bucket }}
                                                        </button>
                                                    </template>
                                                </div>
                                            </TableCell>
                                            <TableCell class="text-right tabular-nums">
                                                <Badge v-if="r.stock === 0 && r.sold_3m > 0"
                                                    :class="styleFor(VERDICT_SOLD_OUT).badge">
                                                    Habis</Badge>
                                                <template v-else>{{ fmt(r.stock) }}</template>
                                            </TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(r.sold_3m) }}</TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </ScrollArea>
                            <p class="text-xs text-muted-foreground">
                                Saiz yang habis tetapi masih laku ditanda "Habis" - itulah yang patut dipertimbangkan
                                untuk restock.
                                <template v-if="canSize || canWeight">Tekan saiz atau julat berat untuk melihat design
                                    lain dalam bucket itu di {{ detail.selected_store || 'cawangan ini' }}.</template>
                            </p>
                        </section>

                        <!-- Dalam perjalanan antara cawangan: transit & loan transit diasingkan (maklumat tambahan) -->
                        <section v-if="movements.length > 0" class="flex flex-col gap-2">
                            <h3 class="text-sm font-medium">Dalam perjalanan antara cawangan</h3>
                            <ScrollArea horizontal
                                class="max-h-60 rounded-lg border [&_[data-slot=scroll-area-viewport]>div]:!block">
                                <Table>
                                    <TableHeader class="sticky top-0 z-10 bg-card shadow-[0_1px_0_var(--border)]">
                                        <TableRow class="hover:bg-transparent">
                                            <TableHead>Jenis</TableHead>
                                            <TableHead>Dari</TableHead>
                                            <TableHead>Ke</TableHead>
                                            <TableHead>Saiz</TableHead>
                                            <TableHead class="text-right">Piece</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="(m, i) in movements"
                                            :key="`${m.type}|${m.from_store}|${m.to_store}|${m.size}|${i}`"
                                            :class="cn((m.from_store === detail.selected_store || m.to_store === detail.selected_store) && 'bg-muted/50')">
                                            <TableCell>
                                                <Badge :class="MOVEMENT_BADGE[m.type]">{{ MOVEMENT_LABEL[m.type] }}
                                                </Badge>
                                            </TableCell>
                                            <TableCell class="font-medium">{{ m.from_store }}</TableCell>
                                            <TableCell class="font-medium">{{ m.to_store ?? '-' }}</TableCell>
                                            <TableCell class="whitespace-nowrap">{{ m.size === '(tiada)' ? 'Tiada saiz'
                                                : m.size }}
                                            </TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(m.qty) }}</TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </ScrollArea>
                            <p class="text-xs text-muted-foreground">
                                Piece dalam perjalanan masih dikira sebagai stok cawangan <strong
                                    class="font-medium text-foreground">Dari</strong> (penghantar) dan belum masuk stok
                                cawangan <strong class="font-medium text-foreground">Ke</strong>. Maklumat ini tidak
                                mengubah Cadangan.
                            </p>
                        </section>

                        <!-- Stok berbanding disyorkan setiap cawangan -->
                        <section class="flex flex-col gap-2">
                            <h3 class="text-sm font-medium">Stok berbanding disyorkan</h3>
                            <BarsChart :data="storeStockData" x-key="label" :series="STOCK_SERIES" :height="170" />
                        </section>

                        <!-- Stok setiap cawangan -->
                        <section class="flex flex-col gap-2">
                            <h3 class="text-sm font-medium">Stok dan jualan setiap cawangan</h3>
                            <ScrollArea horizontal
                                class="rounded-lg border [&_[data-slot=scroll-area-viewport]>div]:!block">
                                <Table>
                                    <TableHeader>
                                        <TableRow class="hover:bg-transparent">
                                            <TableHead>Cawangan</TableHead>
                                            <TableHead class="text-right">Stok</TableHead>
                                            <TableHead class="text-right">1 Minggu</TableHead>
                                            <TableHead class="text-right">1 Bulan</TableHead>
                                            <TableHead class="text-right">3 Bulan</TableHead>
                                            <TableHead class="text-right">Cukup (bln)</TableHead>
                                            <TableHead>Cadangan</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="s in detail.stores" :key="s.store_code"
                                            :class="s.store_code === detail.selected_store && 'bg-muted/50'">
                                            <TableCell class="font-medium">
                                                {{ s.store_code }}
                                                <Badge v-if="s.surplus > 0" variant="outline" class="ml-1 text-[10px]">
                                                    {{ s.surplus }} +
                                                </Badge>
                                                <Badge v-for="chip in storeChips(s.store_code)" :key="chip.label"
                                                    variant="outline" :class="cn('ml-1 text-[10px]', chip.class)"
                                                    :title="chip.title">{{ chip.label }}
                                                </Badge>
                                            </TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(s.stock) }}</TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(s.sold_1w) }}</TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(s.sold_1m) }}</TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(s.sold_3m) }}</TableCell>
                                            <TableCell class="text-right tabular-nums">{{ fmt(s.cover_months, 1) }}
                                            </TableCell>
                                            <TableCell>
                                                <Badge :class="styleFor(s.verdict).badge">{{ styleFor(s.verdict).short
                                                }}</Badge>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </ScrollArea>
                        </section>

                        <!-- Jualan 12 bulan -->
                        <section class="flex flex-col gap-2">
                            <h3 class="text-sm font-medium">Jualan 12 bulan terkini</h3>
                            <!-- Satu "pil" setiap bulan: kad bulat bersempadan, label bulan di atas, lorong kelabu lembut dgn isi bar naik dari bawah -->
                            <ul class="grid grid-cols-12 gap-1.5" role="list" aria-label="Jualan bulanan">
                                <li v-for="m in detail.monthly" :key="m.month"
                                    class="flex flex-col items-center gap-1.5 rounded-lg shadow-xs border bg-card p-1.5"
                                    :title="`${monthLabel(m.month, true)}: ${m.store + m.others} terjual (${detail.selected_store || 'cawangan dipilih'}: ${m.store})`">
                                    <span class="text-[10px] font-medium leading-none text-muted-foreground"
                                        aria-hidden="true">{{
                                            monthLabel(m.month) }}</span>
                                    <div class="relative h-24 w-full overflow-hidden rounded-md bg-muted/70">
                                        <div class="absolute inset-x-0 bottom-0 flex flex-col justify-end overflow-hidden rounded-md"
                                            :style="{ height: `${((m.store + m.others) / monthlyMax) * 100}%` }">
                                            <div class="w-full bg-foreground/35"
                                                :style="{ height: `${(m.others / Math.max(1, m.store + m.others)) * 100}%` }" />
                                            <div class="w-full bg-foreground/85"
                                                :style="{ height: `${(m.store / Math.max(1, m.store + m.others)) * 100}%` }" />
                                        </div>
                                    </div>
                                    <span class="sr-only">{{ monthLabel(m.month, true) }}: {{ m.store + m.others }}
                                        terjual</span>
                                </li>
                            </ul>
                            <p class="flex items-center gap-3 text-xs text-muted-foreground">
                                <span class="flex items-center gap-1"><span
                                        class="size-2 rounded-sm bg-foreground/85" /> {{
                                            detail.selected_store || 'Cawangan dipilih' }}</span>
                                <span class="flex items-center gap-1"><span
                                        class="size-2 rounded-sm bg-foreground/35" /> Cawangan
                                    lain</span>
                            </p>
                        </section>

                        <p class="text-xs text-muted-foreground">Data dikemas kini {{ formatDateTime(detail.computed_at)
                        }}.</p>
                    </template>

                    <!-- Mod bucket -->
                    <template v-else-if="mode === 'bucket'">
                        <p v-if="bucketDesigns.length === 0" class="text-sm text-muted-foreground">Tiada design dijumpai
                            dalam bucket ini.</p>
                        <!-- Skrol dikendalikan ScrollArea panel (header kekal kelihatan); "Muat Lagi" di hujung. -->
                        <div v-else class="flex flex-col gap-2">
                            <div v-for="d in bucketDesigns" :key="d.internal_code" role="button" tabindex="0"
                                class="flex cursor-pointer items-center gap-3 rounded-lg border bg-card p-2 text-left transition hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                @click="emit('open-design', d.internal_code, store ?? '')"
                                @keydown.enter="emit('open-design', d.internal_code, store ?? '')">
                                <ImagePreview :src="d.image_url" :alt="d.internal_code" :gallery-url="galleryUrl(d.internal_code)" class="size-12 shrink-0"
                                    @click.stop />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ d.internal_code }}</p>
                                    <p class="truncate text-xs text-muted-foreground">{{ [d.description,
                                    d.vendor_name].filter(Boolean).join(' · ') }}</p>
                                </div>
                                <dl class="grid shrink-0 grid-cols-3 gap-x-3 text-center text-xs tabular-nums">
                                    <div>
                                        <dt class="text-muted-foreground">Stok</dt>
                                        <dd class="font-medium">{{ d.current_stock }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">Terjual</dt>
                                        <dd class="font-medium">{{ d.pieces_sold }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">Bulan ini</dt>
                                        <dd class="font-medium">{{ d.sold_this_month }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div v-if="bucketHasMore" ref="sentinel" class="flex flex-col gap-1.5 pb-2 pt-1">
                                <Button type="button" variant="outline" size="sm" class="w-full"
                                    :disabled="bucketLoadingMore" @click="loadMore">
                                    <Spinner v-if="bucketLoadingMore" class="size-3.5" />
                                    <ChevronsDown v-else class="size-3.5" />
                                    {{ bucketLoadingMore ? 'Memuatkan...' : `Muat Lagi (${bucketTotal -
                                        bucketDesigns.length} lagi)` }}
                                </Button>
                                <p v-if="bucketLoadError" class="text-center text-xs text-destructive" role="alert">
                                    Gagal memuatkan. Tekan butang untuk cuba lagi.</p>
                            </div>
                        </div>
                    </template>
                </div>
            </ScrollArea>
        </SheetContent>
    </Sheet>
</template>
