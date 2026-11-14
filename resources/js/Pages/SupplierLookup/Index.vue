<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, ChevronRight, Download, FileSpreadsheet, PackageSearch, Printer, Search, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import BranchBreakdown from '@/components/BranchBreakdown.vue';
import CircularProgress from '@/components/CircularProgress.vue';
import { Frame, FrameFooter, FrameHeader, FramePanel } from '@/components/ui/frame';
import ImagePreview from '@/components/ImagePreview.vue';
import SearchAutocomplete from '@/components/SearchAutocomplete.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import SortMenu from '@/components/SortMenu.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { branchOptions as buildBranchOptions, COLUMN_LABELS, DEFAULT_SORTS, nextSorts, PAGE_SIZE, paginate, rollUp, serializeSorts, sortRows } from '@/lib/supplierLookup';
import type { LookupDemand, LookupDesign, SortDirection, SortKey, SortSpec } from '@/lib/supplierLookup';
import { Separator } from '@/components/ui/separator';

// Jadual ini berjalan di PELAYAR: server menghantar semua design supplier sekali (supplier + tempoh), manakala tapisan cawangan,
// susunan & halaman dikira tempatan (lib/supplierLookup.ts) - serta-merta tanpa rangkaian. Definisi nombor: rujuk
// App\Support\SupplierLookup dokblok.
interface LookupResult {
    // false = belum ada supplier dipilih (server tidak mengira apa-apa).
    searched: boolean;
    designs: LookupDesign[];
    // Branch Requested (permintaan cawangan belum selesai) - dikira segar server; kod -> cawangan -> qty.
    demand: LookupDemand;
}

// Nickname, imej & gambar rujukan dimuat utk baris sehalaman sahaja (/supplier-lookup/details).
interface DesignDetail {
    nickname: string | null;
    image_url: string | null;
    // true = gambar rujukan (berat terdekat), bukan gambar tepat kod ini.
    image_reference: boolean;
}

interface Suggestions {
    suppliers: { code: string; name: string }[];
}

const props = defineProps<{
    filters: { supplier: string; days: number };
    // Supplier terpilih (kod + nama) utk medan carian.
    supplier: { code: string; name: string } | null;
    periodOptions: { value: string; label: string }[];
    // DITANGGUH - undefined sehingga siap (skeleton dipaparkan).
    result?: LookupResult;
}>();

const SKELETON_ROWS = 8;
const SUGGEST_DEBOUNCE_MS = 200;
// Lajur yg boleh disusun dlm menu "Susun mengikut" (kunci + tajuk lajur yg sama dgn jadual).
const SORT_OPTIONS: { key: SortKey; label: string }[] = (['code', 'requested', 'buy', 'sold', 'available', 'percent'] as const).map((key) => ({
    key,
    label: COLUMN_LABELS[key],
}));

// Template (chip): satu klik menetapkan susunan berbilang (& kadang-kadang tempoh). Susunan sahaja - tiada tapisan baharu. `days` hanya
// ditetapkan bila template memerlukannya (mesti salah satu pilihan tempoh server). Klik chip aktif = kosongkan susunan.
interface SortTemplate {
    key: string;
    label: string;
    hint: string;
    sorts: SortSpec[];
    days?: string;
}

const SORT_TEMPLATES: SortTemplate[] = [
    { key: 'restock', label: 'Laris, stok tinggal sikit', hint: 'Percentage tinggi, Available sikit - patut direstock', sorts: [{ key: 'percent', dir: 'desc' }, { key: 'available', dir: 'asc' }] },
    { key: 'slow', label: 'Slow', hint: 'Percentage rendah, Available banyak - 180 hari', sorts: [{ key: 'percent', dir: 'asc' }, { key: 'available', dir: 'desc' }], days: '180' },
    { key: 'demand', label: 'Demand cawangan tinggi', hint: 'Branch Requested banyak, Available sikit', sorts: [{ key: 'requested', dir: 'desc' }, { key: 'available', dir: 'asc' }] },
    { key: 'fresh', label: 'Baru diambil, belum laku', hint: 'Total Ambil banyak, Sold sikit - 30 hari', sorts: [{ key: 'buy', dir: 'desc' }, { key: 'sold', dir: 'asc' }], days: '30' },
    { key: 'best', label: 'Paling banyak terjual', hint: 'Total Sold banyak, kemudian Percentage', sorts: [{ key: 'sold', dir: 'desc' }, { key: 'percent', dir: 'desc' }] },
    { key: 'soldout', label: 'Stok habis', hint: 'Available paling sikit dahulu, Sold banyak', sorts: [{ key: 'available', dir: 'asc' }, { key: 'sold', dir: 'desc' }] },
];

// Skrin < sm (640px): keenam-enam lajur mesti muat tanpa tatal ufuk, jadi tajuk nombor dipendekkan (nama penuh kekal sbg nama
// aksesibel & tooltip), padding sel dikecilkan & ikon susunan hanya pd lajur aktif. Selaras dgn breakpoint `sm` Tailwind.
const COMPACT_QUERY = '(max-width: 639px)';
const TABLE_COMPACT =
    'bg-card [&_th]:px-1 [&_td]:px-1 sm:[&_th]:px-2 sm:[&_td]:px-2 [&_th_button]:ml-0 [&_th_button]:px-0 sm:[&_th_button]:-ml-3 sm:[&_th_button]:px-3 [&_th_button:not([data-active=true])_svg]:hidden sm:[&_th_button:not([data-active=true])_svg]:inline-block';

const compact = ref(false);

let compactQuery: MediaQueryList | null = null;
const syncCompact = () => {
    compact.value = compactQuery?.matches ?? false;
};

onMounted(() => {
    compactQuery = window.matchMedia(COMPACT_QUERY);
    syncCompact();
    compactQuery.addEventListener('change', syncCompact);
});

onBeforeUnmount(() => compactQuery?.removeEventListener('change', syncCompact));

// `inputText` = teks medan carian: apa yg sedang ditaip (cadangan supplier muncul), atau nama supplier terpilih (tiada cip
// berasingan); `supplier` = supplier yg DIPILIH dan memuatkan senarai. Supplier & tempoh = permintaan server; selebihnya tempatan.
const inputText = ref(props.supplier?.name ?? '');
const supplier = ref<{ code: string; name: string } | null>(props.supplier);
const days = ref(String(props.filters.days));
const branches = ref<string[]>([]);
// Susunan BERBILANG: senarai kunci (yg pertama paling utama), dikongsi oleh klik tajuk lajur & menu "Susun mengikut" (v-model) - sentiasa bersegerak.
// Setiap klik tajuk menambah / menukar / membuang lajur itu; kosong = kod design menaik.
const sorts = ref<SortSpec[]>([...DEFAULT_SORTS]);
const page = ref(1);
const loading = ref(false);

const suggestions = ref<Suggestions>({ suppliers: [] });
const suggestLoading = ref(false);
const suggestError = ref<string | null>(null);

// Senarai hanya muncul selepas pengguna memilih supplier.
const hasQuery = computed(() => supplier.value !== null);
const branchMode = computed(() => branches.value.length > 0);
const showSkeleton = computed(() => hasQuery.value && (loading.value || !props.result?.searched));
const isFiltered = computed(() => hasQuery.value || inputText.value !== '' || branches.value.length > 0);

// Semua dikira tempatan - tiada permintaan rangkaian bila cawangan / susunan / halaman berubah.
const designs = computed(() => (showSkeleton.value ? [] : (props.result?.designs ?? [])));
const branchOptions = computed(() => buildBranchOptions(designs.value));
const sortedRows = computed(() => sortRows(rollUp(designs.value, branches.value, props.result?.demand ?? {}), sorts.value));
const pageData = computed(() => paginate(sortedRows.value, page.value, PAGE_SIZE));

const rangeLabel = computed(() => {
    const { total, page: current } = pageData.value;

    if (showSkeleton.value || total === 0) {
        return '';
    }

    const from = (current - 1) * PAGE_SIZE + 1;
    const to = Math.min(total, current * PAGE_SIZE);

    return `${from}-${to} daripada ${formatNumber(total)} ${branchMode.value ? 'baris' : 'design'}`;
});

let visitTimer: ReturnType<typeof setTimeout> | null = null;
let suggestTimer: ReturnType<typeof setTimeout> | null = null;
let suggestAbort: AbortController | null = null;
let skipNextSuggest = false;

// Hanya supplier / tempoh yg menggerakkan permintaan ke server.
function visit() {
    router.get(
        '/supplier-lookup',
        { supplier: supplier.value?.code || undefined, days: days.value },
        {
            only: ['result', 'filters', 'supplier'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}

watch([supplier, days], () => {
    page.value = 1;
    branches.value = [];

    // Skeleton serta-merta (sebelum permintaan bermula) - jangan tunjuk data supplier lama.
    loading.value = true;

    if (visitTimer) {
        clearTimeout(visitTimer);
    }

    visitTimer = setTimeout(visit, 0);
});

// Cawangan / susunan berubah = kembali ke halaman 1 (tempatan).
watch([branches, sorts], () => {
    page.value = 1;
});

// Bingkai jadual = ScrollArea. Kesan pudar (scroll-fade) di tepi atas/bawah HANYA bila ada kandungan lagi dlm arah itu (bukan sentiasa);
// pudar atas diletakkan di bawah pengepala melekat. Dikira drpd viewport ScrollArea (tatal & saiz berubah).
const frame = ref<HTMLElement | null>(null);
const fadeTop = ref(false);
const fadeBottom = ref(false);

let viewport: HTMLElement | null = null;
let viewportObserver: ResizeObserver | null = null;

function updateFades() {
    if (!viewport) {
        return;
    }

    const maxScroll = viewport.scrollHeight - viewport.clientHeight;

    fadeTop.value = viewport.scrollTop > 1;
    fadeBottom.value = maxScroll > 1 && viewport.scrollTop < maxScroll - 1;
}

function unbindViewport() {
    viewport?.removeEventListener('scroll', updateFades);
    viewportObserver?.disconnect();
    viewport = null;
}

function bindViewport() {
    const element = frame.value?.querySelector<HTMLElement>('[data-slot="scroll-area-viewport"]') ?? null;

    if (element === viewport) {
        return;
    }

    unbindViewport();
    viewport = element;

    if (!element) {
        fadeTop.value = false;
        fadeBottom.value = false;

        return;
    }

    element.addEventListener('scroll', updateFades, { passive: true });
    viewportObserver ??= new ResizeObserver(updateFades);
    viewportObserver.observe(element);

    if (element.firstElementChild) {
        viewportObserver.observe(element.firstElementChild);
    }

    updateFades();
}

// Bingkai wujud hanya selepas supplier dipilih (v-else) - ikat bila ia muncul.
watch(frame, () => nextTick(bindViewport), { flush: 'post' });

// Halaman / susunan / cawangan baharu = senarai baharu: mulakan dari atas (jika tidak tatal kekal di bawah halaman sebelumnya).
watch([() => pageData.value.page, sorts, branches], () => {
    if (viewport) {
        viewport.scrollTop = 0;
    }
});

onBeforeUnmount(unbindViewport);

// Nickname & imej utk baris yg kelihatan: dimuat sekali bagi setiap kod, kekal dlm memori (tukar halaman/susunan tak muat semula).
const details = reactive<Record<string, DesignDetail>>({});
const detailsRequested = new Set<string>();

async function loadDetails(codes: string[]) {
    const missing = codes.filter((code) => !detailsRequested.has(code));

    if (missing.length === 0) {
        return;
    }

    missing.forEach((code) => detailsRequested.add(code));

    try {
        const query = missing.map((code) => `codes[]=${encodeURIComponent(code)}`).join('&');
        const response = await fetch(`/supplier-lookup/details?${query}`, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        Object.assign(details, ((await response.json()) as { details: Record<string, DesignDetail> }).details);
    } catch {
        // Boleh dicuba semula bila halaman dilawat lagi.
        missing.forEach((code) => detailsRequested.delete(code));
    }
}

watch(
    () => pageData.value.rows.map((row) => row.code),
    (codes) => loadDetails([...new Set(codes)]),
    { immediate: true },
);

async function fetchSuggestions(term: string) {
    suggestAbort = new AbortController();
    const signal = suggestAbort.signal;

    try {
        const response = await fetch(`/supplier-lookup/suggestions?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            signal,
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        suggestions.value = (await response.json()) as Suggestions;
        suggestError.value = null;
    } catch {
        // Dibatalkan = carian baharu menggantikan; selain itu paparkan ralat pd baris status.
        if (!signal.aborted) {
            suggestions.value = { suppliers: [] };
            suggestError.value = 'Gagal memuatkan cadangan. Cuba lagi.';
        }
    } finally {
        if (!signal.aborted) {
            suggestLoading.value = false;
        }
    }
}

watch(inputText, (value) => {
    if (suggestTimer) {
        clearTimeout(suggestTimer);
    }

    suggestAbort?.abort();

    // Teks diisi oleh pilihan cadangan - jangan buka cadangan baharu.
    if (skipNextSuggest) {
        skipNextSuggest = false;
        suggestLoading.value = false;

        return;
    }

    const term = value.trim();

    suggestError.value = null;

    if (term === '') {
        suggestions.value = { suppliers: [] };
        suggestLoading.value = false;

        return;
    }

    suggestLoading.value = true;
    suggestTimer = setTimeout(() => fetchSuggestions(term), SUGGEST_DEBOUNCE_MS);
});

onBeforeUnmount(() => {
    [visitTimer, suggestTimer].forEach((timer) => timer && clearTimeout(timer));
    suggestAbort?.abort();
});

function pickSupplier(code: string, name: string) {
    // Nama terpilih dipaparkan dlm medan itu sendiri - jangan buka cadangan baharu utknya.
    skipNextSuggest = inputText.value !== name;
    inputText.value = name;
    supplier.value = { code, name };
}

// Butang X pd medan: buang supplier terpilih (teks sudah dikosongkan komponen) - senarai disorokkan semula.
function clearSupplier() {
    supplier.value = null;
}

// Klik tajuk: lajur itu ditambah (menaik) -> menurun -> dibuang; kunci lain kekal, jadi klik berturutan = susunan berbilang.
function toggleSort(key: SortKey) {
    sorts.value = nextSorts(sorts.value, key);
}

function headerDirection(key: SortKey): SortDirection | null {
    return sorts.value.find((spec) => spec.key === key)?.dir ?? null;
}

// Nombor keutamaan (1, 2, 3...) hanya bila lebih drpd satu lajur disusun.
function headerPriority(key: SortKey): number | null {
    const index = sorts.value.findIndex((spec) => spec.key === key);

    return sorts.value.length > 1 && index !== -1 ? index + 1 : null;
}

// Template aktif = susunan semasa sama (kunci & arah, ikut urutan) dan tempoh sama (jika template menetapkannya). Ubah susunan/tempoh
// sendiri = tiada chip aktif ("tersuai").
const activeTemplate = computed(
    () =>
        SORT_TEMPLATES.find(
            (template) =>
                (template.days === undefined || template.days === days.value) &&
                template.sorts.length === sorts.value.length &&
                template.sorts.every((spec, index) => spec.key === sorts.value[index].key && spec.dir === sorts.value[index].dir),
        )?.key ?? null,
);

function applyTemplate(template: SortTemplate) {
    if (activeTemplate.value === template.key) {
        sorts.value = [...DEFAULT_SORTS];

        return;
    }

    sorts.value = template.sorts.map((spec) => ({ ...spec }));

    if (template.days !== undefined) {
        days.value = template.days;
    }
}

function toggleBranch(value: string) {
    branches.value = branches.value.includes(value) ? branches.value.filter((branch) => branch !== value) : [...branches.value, value];
}

function resetFilters() {
    skipNextSuggest = inputText.value !== '';
    inputText.value = '';
    supplier.value = null;
    branches.value = [];
}

// Eksport mencerminkan apa yg kelihatan: supplier, tempoh, cawangan & susunan semasa (SEMUA baris, bukan satu halaman). Server membina
// baris yg sama (App\Support\SupplierLookup::exportRows) dgn tajuk lajur COLUMN_LABELS. Menyediakan eksport boleh mengambil beberapa saat
// (butiran semua baris), jadi butang menunjukkan loading sehingga siap.
type ExportKind = 'export' | 'print';

const canExport = computed(() => hasQuery.value && !showSkeleton.value && pageData.value.total > 0);
const exporting = ref<ExportKind | null>(null);
const exportError = ref<string | null>(null);
const exportMenuOpen = ref(false);

function exportUrl(path: ExportKind): string {
    const query = new URLSearchParams({
        supplier: supplier.value?.code ?? '',
        days: days.value,
        sort: serializeSorts(sorts.value),
    });

    branches.value.forEach((branch) => query.append('branches[]', branch));

    return `/supplier-lookup/${path}?${query.toString()}`;
}

function downloadBlob(blob: Blob, filename: string) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

async function runExport(kind: ExportKind) {
    exportMenuOpen.value = false;

    if (exporting.value !== null) {
        return;
    }

    exportError.value = null;

    // PDF: tab dibuka SEKARANG (selagi klik masih dikira tindakan pengguna - elak penyekat pop-up) dan diisi bila halaman cetak siap.
    const tab = kind === 'print' ? window.open('', '_blank') : null;

    if (kind === 'print' && !tab) {
        exportError.value = 'Pop-up disekat. Benarkan pop-up untuk eksport PDF.';

        return;
    }

    tab?.document.write('<title>Supplier Lookup</title><p style="font:14px system-ui;padding:24px">Menyediakan PDF...</p>');
    exporting.value = kind;

    try {
        const response = await fetch(exportUrl(kind), { credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        if (kind === 'export') {
            const filename = /filename="?([^";]+)"?/.exec(response.headers.get('content-disposition') ?? '')?.[1] ?? 'Supplier-Lookup.xlsx';

            downloadBlob(await response.blob(), filename);
        } else {
            const html = await response.text();

            tab?.document.open();
            tab?.document.write(html);
            tab?.document.close();
        }
    } catch {
        tab?.close();
        exportError.value = 'Eksport gagal. Cuba lagi.';
    } finally {
        exporting.value = null;
    }
}

function formatNumber(value: number): string {
    return value.toLocaleString('en-MY');
}
</script>

<template>

    <Head title="Supplier Lookup" />

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-3 py-6 sm:px-6 sm:py-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Supplier Lookup</h1>
            <p class="text-sm text-muted-foreground">
                Jualan setiap design daripada piece yang diambil daripada satu supplier dalam tempoh dipilih.
            </p>
        </div>

        <!-- Susun atur gaya e-commerce: penapis di kiri (sidebar), senarai (Frame + jadual) di kanan. Di bawah xl sidebar di atas senarai. -->
        <div>
            <div class="grid gap-4 xl:grid-cols-[16.5rem_minmax(0,1fr)] xl:items-start">
                <aside class="xl:sticky xl:top-4" aria-label="Penapis">
                    <Frame spacing="sm">
                        <FramePanel class="flex flex-col gap-4 p-3">
                            <div class="flex flex-col gap-1.5">
                                <p class="text-xs font-medium text-muted-foreground">Supplier</p>
                                <SearchAutocomplete v-model="inputText" placeholder="Taip kod atau nama supplier..."
                                    :suppliers="suggestions.suppliers" :loading="suggestLoading" :error="suggestError"
                                    @pick="pickSupplier" @clear="clearSupplier" />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <p class="text-xs font-medium text-muted-foreground">Tempoh</p>
                                <Select v-model="days">
                                    <SelectTrigger class="h-8 w-full bg-card" size="sm" aria-label="Tempoh">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="option in periodOptions" :key="option.value"
                                            :value="option.value">
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-medium text-muted-foreground">Cawangan</p>
                                    <button v-if="branches.length > 0" type="button"
                                        class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                        @click="branches = []">
                                        Kosongkan
                                    </button>
                                </div>
                                <p v-if="branchOptions.length === 0" class="text-xs text-muted-foreground">
                                    Pilih supplier dahulu.
                                </p>
                                <ul v-else class="flex max-h-56 flex-col overflow-y-auto" role="group"
                                    aria-label="Cawangan">
                                    <li v-for="option in branchOptions" :key="option.value">
                                        <label
                                            class="flex cursor-pointer items-center gap-2 rounded-sm px-1 py-1.5 text-sm hover:bg-accent">
                                            <Checkbox :model-value="branches.includes(option.value)"
                                                @update:model-value="toggleBranch(option.value)" />
                                            <span class="truncate">{{ option.label }}</span>
                                            <span class="ml-auto font-mono text-xs text-muted-foreground">{{
                                                option.count }}</span>
                                        </label>
                                    </li>
                                </ul>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <p class="text-xs font-medium text-muted-foreground">Susunan</p>
                                <SortMenu v-model="sorts" :options="SORT_OPTIONS" />
                            </div>

                            <!-- Eksport: satu butang dropdown (Excel / PDF), loading semasa disediakan -->
                            <div v-if="hasQuery" class="flex flex-col gap-1.5">
                                <p v-if="exportError" class="text-xs text-destructive" role="alert">{{ exportError }}
                                </p>

                                <Popover v-model:open="exportMenuOpen">
                                    <PopoverTrigger as-child>
                                        <Button type="button" variant="outline" size="sm" class="h-8 w-full bg-card"
                                            :disabled="!canExport || exporting !== null"
                                            :aria-busy="exporting !== null">
                                            <Spinner v-if="exporting" class="size-4" />
                                            <Download v-else class="size-4" />
                                            <template v-if="exporting === 'export'">Menyediakan Excel...</template>
                                            <template v-else-if="exporting === 'print'">Menyediakan PDF...</template>
                                            <template v-else>Eksport</template>
                                            <ChevronDown v-if="!exporting" class="size-4" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent class="w-44 p-1" align="start">
                                        <button type="button"
                                            class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:outline-none"
                                            @click="runExport('export')">
                                            <FileSpreadsheet class="size-4" /> Excel (.xlsx)
                                        </button>
                                        <button type="button"
                                            class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:outline-none"
                                            @click="runExport('print')">
                                            <Printer class="size-4" /> PDF / Cetak
                                        </button>
                                    </PopoverContent>
                                </Popover>
                            </div>

                            <Button v-if="isFiltered" type="button" variant="ghost" size="sm"
                                class="h-8 text-muted-foreground" @click="resetFilters">
                                <X class="size-4" /> Reset penapis
                            </Button>
                        </FramePanel>
                    </Frame>
                </aside>

                <div class="flex min-w-0 flex-col gap-4">
                    <!-- Belum pilih supplier: tiada senarai -->
                    <Frame spacing="sm" v-if="!hasQuery">
                        <FramePanel>
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Search />
                                    </EmptyMedia>
                                    <EmptyTitle>Cari untuk memulakan</EmptyTitle>
                                    <EmptyDescription>
                                        Taip kod atau nama supplier, kemudian pilih daripada cadangan.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        </FramePanel>
                    </Frame>

                    <template v-else>
                        <!-- Frame: pengepala = template (chip), panel = jadual, pengaki = halaman. -->
                        <!-- Mudah alih: Frame sampai tepi skrin (-mx-3 = gutter halaman) supaya semua lajur muat tanpa tatal ufuk. -->
                        <Frame dense class="-mx-3 rounded-none border-x-0 sm:mx-0 sm:rounded-2xl sm:border-x">
                            <!-- Template (chip): satu klik = susunan berbilang (+ tempoh). Satu baris bertatal ufuk pada skrin kecil. -->
                            <FrameHeader class="py-2">
                                <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0"
                                    role="group" aria-label="Template susunan">
                                    <Button v-for="template in SORT_TEMPLATES" :key="template.key" type="button"
                                        size="sm" :variant="activeTemplate === template.key ? 'default' : 'outline'"
                                        class="h-7 shrink-0 rounded-full px-3 text-xs font-medium"
                                        :class="activeTemplate === template.key ? '' : 'bg-card'"
                                        :aria-pressed="activeTemplate === template.key" :title="template.hint"
                                        @click="applyTemplate(template)">
                                        {{ template.label }}
                                    </Button>
                                </div>
                            </FrameHeader>

                            <!-- Jadual: pada skrin >= sm bingkai bertinggi tetap (data bertatal, pengepala melekat, butang halaman sentiasa
                         kelihatan). Pada mudah alih halaman itu sendiri yg bertatal supaya tiada tatal dalam tatal. -->
                            <FramePanel class="overflow-hidden border border-border shadow-xs p-0">
                                <div ref="frame" class="relative">
                                    <!-- [&_[data-slot=scroll-area-viewport]>div]:!block - pembungkus dalam viewport reka-ui asalnya display:table & melebar
                           ikut kandungan (sama spt Back Office Actions). -->
                                    <ScrollArea horizontal
                                        class="rounded-[inherit] sm:max-h-[calc(100dvh-17rem)] sm:min-h-72 [&_[data-slot=scroll-area-viewport]>div]:!block">
                                        <Table :class="TABLE_COMPACT">
                                            <TableHeader
                                                class="sticky top-0 z-10 bg-card shadow-[0_1px_0_var(--border)]">
                                                <TableRow class="hover:bg-transparent">
                                                    <TableHead class="w-[3.25rem] sm:w-20 text-sm">{{
                                                        COLUMN_LABELS.image }}
                                                    </TableHead>
                                                    <TableHead>
                                                        <SortableHeader :title="COLUMN_LABELS.code"
                                                            :direction="headerDirection('code')"
                                                            @sort="toggleSort('code')"
                                                            :priority="headerPriority('code')" />
                                                    </TableHead>
                                                    <TableHead v-if="branchMode" class="w-28">{{ COLUMN_LABELS.store }}
                                                    </TableHead>
                                                    <TableHead class="text-right">
                                                        <SortableHeader
                                                            :title="compact ? 'Req.' : COLUMN_LABELS.requested"
                                                            :full-title="COLUMN_LABELS.requested"
                                                            :direction="headerDirection('requested')"
                                                            @sort="toggleSort('requested')"
                                                            :priority="headerPriority('requested')" />
                                                    </TableHead>
                                                    <TableHead class="text-right">
                                                        <SortableHeader :title="compact ? 'Ambil' : COLUMN_LABELS.buy"
                                                            :full-title="COLUMN_LABELS.buy"
                                                            :direction="headerDirection('buy')"
                                                            @sort="toggleSort('buy')"
                                                            :priority="headerPriority('buy')" />
                                                    </TableHead>
                                                    <TableHead class="text-right">
                                                        <SortableHeader :title="compact ? 'Sold' : COLUMN_LABELS.sold"
                                                            :full-title="COLUMN_LABELS.sold"
                                                            :direction="headerDirection('sold')"
                                                            @sort="toggleSort('sold')"
                                                            :priority="headerPriority('sold')" />
                                                    </TableHead>
                                                    <TableHead class="text-right">
                                                        <SortableHeader
                                                            :title="compact ? 'Avail.' : COLUMN_LABELS.available"
                                                            :full-title="COLUMN_LABELS.available"
                                                            :direction="headerDirection('available')"
                                                            @sort="toggleSort('available')"
                                                            :priority="headerPriority('available')" />
                                                    </TableHead>
                                                    <TableHead class="text-center sm:w-28">
                                                        <SortableHeader :title="compact ? '%' : COLUMN_LABELS.percent"
                                                            :full-title="COLUMN_LABELS.percent"
                                                            :direction="headerDirection('percent')"
                                                            @sort="toggleSort('percent')"
                                                            :priority="headerPriority('percent')" />
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>

                                            <TableBody>
                                                <!-- Skeleton: hanya semasa supplier / tempoh dimuatkan dari server -->
                                                <template v-if="showSkeleton">
                                                    <TableRow v-for="n in SKELETON_ROWS" :key="`sk-${n}`"
                                                        class="hover:bg-transparent">
                                                        <TableCell>
                                                            <Skeleton class="size-10 rounded-md sm:size-12" />
                                                        </TableCell>
                                                        <TableCell>
                                                            <div class="flex flex-col gap-1.5">
                                                                <Skeleton class="h-4 w-32" />
                                                                <Skeleton class="h-3 w-44" />
                                                            </div>
                                                        </TableCell>
                                                        <TableCell v-if="branchMode">
                                                            <Skeleton class="h-4 w-16" />
                                                        </TableCell>
                                                        <TableCell v-for="c in 4" :key="`skn-${n}-${c}`">
                                                            <Skeleton class="ml-auto h-4 w-10" />
                                                        </TableCell>
                                                        <TableCell>
                                                            <Skeleton class="h-4 w-12 sm:w-28" />
                                                        </TableCell>
                                                    </TableRow>
                                                </template>

                                                <template v-else>
                                                    <TableRow v-for="row in pageData.rows"
                                                        :key="`${row.code}|${row.store ?? ''}`">
                                                        <TableCell>
                                                            <!-- Imej/nickname dimuat utk baris sehalaman (sebentar sahaja) -->
                                                            <Skeleton v-if="!details[row.code]"
                                                                class="size-10 rounded-md sm:size-12" />
                                                            <ImagePreview v-else :src="details[row.code].image_url"
                                                                :reference="details[row.code].image_reference"
                                                                :alt="details[row.code].nickname ?? row.code"
                                                                class="size-10 rounded-md sm:size-12" />
                                                        </TableCell>
                                                        <TableCell>
                                                            <div
                                                                class="break-all text-[13px] font-medium sm:break-normal sm:text-sm">
                                                                {{ row.code }}</div>
                                                            <Skeleton v-if="!details[row.code]"
                                                                class="mt-1 h-3 w-16 sm:w-40" />
                                                            <div v-else-if="details[row.code].nickname"
                                                                class="max-w-[4.5rem] text-xs leading-snug text-balance break-words text-muted-foreground sm:max-w-60">
                                                                {{ details[row.code].nickname }}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell v-if="branchMode" class="text-sm">{{ row.store }}
                                                        </TableCell>
                                                        <!-- Branch Requested: tanpa tapisan cawangan = klik angka utk popover cawangan yg minta -->
                                                        <TableCell class="text-right tabular-nums">
                                                            <BranchBreakdown :value="row.requested"
                                                                :figures="row.branches" field="requested"
                                                                :title="COLUMN_LABELS.requested" />
                                                        </TableCell>
                                                        <TableCell class="text-right tabular-nums">{{
                                                            formatNumber(row.buy)
                                                            }}</TableCell>
                                                        <!-- Tanpa tapisan cawangan: klik angka = popover pecahan ikut cawangan -->
                                                        <TableCell class="text-right tabular-nums">
                                                            <BranchBreakdown :value="row.sold" :figures="row.branches"
                                                                field="sold" :title="COLUMN_LABELS.sold" />
                                                        </TableCell>
                                                        <TableCell class="text-right tabular-nums">
                                                            <BranchBreakdown :value="row.available"
                                                                :figures="row.branches" field="available"
                                                                :title="COLUMN_LABELS.available" />
                                                        </TableCell>
                                                        <TableCell>
                                                            <div class="flex justify-center">
<CircularProgress :value="row.percent" :label="COLUMN_LABELS.percent" class="size-10 sm:size-12" />
</div>
                                                        </TableCell>
                                                    </TableRow>
                                                </template>
                                            </TableBody>
                                        </Table>

                                        <Empty v-if="!showSkeleton && pageData.total === 0" class="rounded-xl border-0 bg-card">
                                            <EmptyHeader>
                                                <EmptyMedia variant="icon">
                                                    <PackageSearch />
                                                </EmptyMedia>
                                                <EmptyTitle>Tiada design ditemui</EmptyTitle>
                                                <EmptyDescription>
                                                    Supplier ini tidak mengambil sebarang piece dalam tempoh
                                                    atau cawangan
                                                    yang dipilih.
                                                </EmptyDescription>
                                            </EmptyHeader>
                                        </Empty>
                                    </ScrollArea>

                                    <!-- Scroll-fade: pudar lembut menandakan ada baris lagi di atas/bawah (di bawah pengepala 2.5rem; tidak boleh diklik) -->
                                    <div aria-hidden="true"
                                        class="pointer-events-none absolute inset-x-0 top-10 h-8 bg-linear-to-b from-card to-transparent transition-opacity duration-200"
                                        :class="fadeTop ? 'opacity-100' : 'opacity-0'" />
                                    <div aria-hidden="true"
                                        class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-linear-to-t from-card to-transparent transition-opacity duration-200"
                                        :class="fadeBottom ? 'opacity-100' : 'opacity-0'" />
                                </div>
                            </FramePanel>

                            <!-- Halaman -->
                            <FrameFooter class="justify-between gap-3">
                                <div class="flex flex-col gap-0.5 text-sm text-muted-foreground">
                                    <Skeleton v-if="showSkeleton" class="h-4 w-40" />
                                    <template v-else>{{ rangeLabel }}</template>
                                    <span class="text-xs">Klik beberapa tajuk lajur berturutan untuk susunan berbilang
                                        (nombor = keutamaan).</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Button type="button" variant="outline" size="icon-sm" class="bg-card" aria-label="Halaman sebelum"
                                        :disabled="showSkeleton || pageData.page <= 1"
                                        @click="page = pageData.page - 1">
                                        <ChevronLeft class="size-4" />
                                    </Button>
                                    <span class="text-sm tabular-nums text-muted-foreground">
                                        {{ pageData.page }} / {{ pageData.lastPage }}
                                    </span>
                                    <Button type="button" variant="outline" size="icon-sm" class="bg-card"
                                        aria-label="Halaman seterusnya"
                                        :disabled="showSkeleton || pageData.page >= pageData.lastPage"
                                        @click="page = pageData.page + 1">
                                        <ChevronRight class="size-4" />
                                    </Button>
                                </div>
                            </FrameFooter>
                        </Frame>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
