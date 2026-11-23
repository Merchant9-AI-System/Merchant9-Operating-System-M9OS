<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Boxes, Check, CircleCheck, Copy, Eye, ListFilter, PackageSearch, PackageX, Search, Sparkles, TriangleAlert, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CircularProgress from '@/components/CircularProgress.vue';
import FacetedFilter from '@/components/FacetedFilter.vue';
import ImagePreview from '@/components/ImagePreview.vue';
import BarsChart from '@/components/restock/BarsChart.vue';
import LinesChart from '@/components/restock/LinesChart.vue';
import DetailPanel from '@/components/restock/DetailPanel.vue';
import PresetChips from '@/components/restock/PresetChips.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Frame, FrameFooter, FrameHeader, FramePanel } from '@/components/ui/frame';
import { Input } from '@/components/ui/input';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CHART_COLORS } from '@/lib/chart';
import { copyText } from '@/lib/clipboard';
import { nextSort } from '@/lib/multiSort';
import type { Sort } from '@/lib/multiSort';
import { coverage, fmt, formatDateTime, gapClass, styleFor, SUMMARY_VERDICTS, VERDICT_OK, VERDICT_OVERSTOCK, VERDICT_RESTOCK, VERDICT_SOLD_OUT } from '@/lib/restock';
import type { BucketRow, DesignRow, Option, Page } from '@/lib/restock';
import { cn } from '@/lib/utils';

// Halaman Restock Analysis (rujuk App\Http\Controllers\RestockAnalysisController dokblok): data dibaca drpd jadual ringkasan malam di server;
// penapis, susunan & halaman dilakukan di database. Kategori/Cawangan/Cadangan/carian = DRAF sehingga "Cari" ditekan; susunan, halaman,
// paparan & kad ringkasan terus menggerakkan permintaan. Semua keadaan ada dlm URL (boleh dikongsi).
type View = 'design' | 'summary' | 'size' | 'weight';
type SortKey = string;

interface Filters {
    view: View;
    category: string[];
    store: string[];
    verdict: string[];
    q: string;
    new: boolean;
    sort: Sort<SortKey>[];
    per_page: number;
    page: number;
}

interface BreakdownRow {
    code: string;
    label: string;
    total: number;
    sold_out: number;
    restock: number;
    shortfall: number;
}

const props = defineProps<{
    filters: Filters;
    viewOptions: Option[];
    categories: Option[];
    stores: Option[];
    verdicts: string[];
    perPageOptions: number[];
    updatedAt: string | null;
    changes: { at: string | null; sold_out: number; restock: number };
    summary: { counts: Record<string, number>; shortfalls?: Record<string, number>; shortfall: number; total: number };
    rows: Page<DesignRow | BucketRow> | null;
    breakdown: { stores: BreakdownRow[]; categories: BreakdownRow[] } | null;
    // Trend harian Sold Out/Perlu Restock (30 hari, paparan Ringkasan) & lengkung saiz/berat satu kategori (paparan Saiz/Berat).
    trend: { date: string; sold_out: number; restock: number }[] | null;
    curve: { data: { bucket: string; stock: number; sold_3m: number }[]; total: number } | null;
    // Butang Kembali ke dashboard panel admin.
    dashboardUrl: string;
}>();

const MAX_SORTS = 4;
const SKELETON_ROWS = 8;

// Mana kunci susunan dipakai lajur Cadangan (kemendesakan).
const URGENCY = 'urgency';

const loading = ref(false);

const isDesign = computed(() => props.filters.view === 'design');
const isBucket = computed(() => props.filters.view === 'size' || props.filters.view === 'weight');
const isSummary = computed(() => props.filters.view === 'summary');
const bucketHeader = computed(() => (props.filters.view === 'size' ? 'Saiz' : 'Berat'));
const rowList = computed<(DesignRow & BucketRow)[]>(() => (props.rows?.data ?? []) as (DesignRow & BucketRow)[]);

// --- Permintaan ke server ---
function serializeSorts(sorts: Sort<SortKey>[]): string {
    return sorts.map((s) => `${s.key}:${s.dir}`).join(',');
}

type Change = Partial<Omit<Filters, 'sort'>> & { sort?: Sort<SortKey>[] };

function visit(change: Change = {}) {
    const next = { ...props.filters, ...change };
    const params: Record<string, unknown> = {
        view: next.view,
        // `all` = semua (tiada parameter = lalai cawangan sendiri / Cadangan mendesak, rujuk controller).
        store: next.store.length > 0 ? next.store : 'all',
        verdict: next.verdict.length > 0 ? next.verdict : 'all',
        sort: serializeSorts(next.sort),
        per_page: next.per_page,
        page: next.page,
    };

    if (next.category.length > 0) {
        params.category = next.category;
    }

    if (next.q) {
        params.q = next.q;
    }

    if (next.new) {
        params.new = 1;
    }

    router.get('/restock-analysis', params, {
        only: ['filters', 'rows', 'summary', 'changes', 'breakdown', 'updatedAt', 'trend', 'curve'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function changeView(view: View) {
    // Lajur boleh disusun berbeza antara paparan - mulakan dgn susunan lalai.
    visit({ view, sort: [], page: 1 });
}

// --- Draf penapis ---
const categorySel = ref<string[]>([...props.filters.category]);
const storeSel = ref<string[]>([...props.filters.store]);
const verdictSel = ref<string[]>([...props.filters.verdict]);
const qSel = ref(props.filters.q);
const newSel = ref(props.filters.new);

watch(
    () => props.filters,
    (f) => {
        categorySel.value = [...f.category];
        storeSel.value = [...f.store];
        verdictSel.value = [...f.verdict];
        qSel.value = f.q;
        newSel.value = f.new;
    },
);

const sameSet = (a: string[], b: string[]) => a.length === b.length && a.every((v) => b.includes(v));

const isDirty = computed(
    () =>
        !sameSet(categorySel.value, props.filters.category) ||
        !sameSet(storeSel.value, props.filters.store) ||
        !sameSet(verdictSel.value, props.filters.verdict) ||
        qSel.value.trim() !== props.filters.q ||
        newSel.value !== props.filters.new,
);

function runSearch() {
    visit({ category: categorySel.value, store: storeSel.value, verdict: verdictSel.value, q: qSel.value.trim(), new: newSel.value, page: 1 });
}

function resetFilters() {
    visit({ category: [], store: [], verdict: [], q: '', new: false, page: 1 });
}

const hasFilters = computed(() => props.filters.category.length > 0 || props.filters.q !== '' || props.filters.new);

// --- Kad ringkasan ---
const verdictOptions = computed(() => props.verdicts.map((name) => ({ value: name, label: name, count: props.summary.counts[name] ?? 0 })));

function toggleVerdict(name: string) {
    const current = props.filters.verdict;
    const verdict = current.includes(name) ? current.filter((v) => v !== name) : [...current, name];

    // Di Ringkasan, klik kad membuka senarai design bagi Cadangan itu.
    visit({ view: isSummary.value ? 'design' : props.filters.view, verdict: isSummary.value ? [name] : verdict, sort: isSummary.value ? [] : props.filters.sort, page: 1 });
}

function openFromBreakdown(kind: 'store' | 'category', code: string) {
    visit({
        view: 'design',
        sort: [],
        page: 1,
        ...(kind === 'store' ? { store: [code] } : { category: [code] }),
    });
}

function toggleNew() {
    visit({ new: !props.filters.new, verdict: props.filters.new ? props.filters.verdict : [], page: 1 });
}

// --- Pintasan (badge bulat): satu klik menetapkan Cadangan + susunan (+ "baharu") sekali gus. Kategori, cawangan & carian kekal. Hanya paparan design. ---
interface Preset {
    key: string;
    label: string;
    hint: string;
    verdict: string[];
    sort: Sort<SortKey>[];
    new: boolean;
}

const DEFAULT_PRESET = 'urgent';

const PRESETS: Preset[] = [
    { key: DEFAULT_PRESET, label: 'Paling mendesak', hint: 'Sold Out dan Perlu Restock - kekurangan terbesar dahulu', verdict: [VERDICT_SOLD_OUT, VERDICT_RESTOCK], sort: [{ key: URGENCY, dir: 'asc' }], new: false },
    { key: 'selling-fast', label: 'Laris, stok tinggal sikit', hint: 'Perlu Restock - jualan 1 bulan tertinggi, stok paling singkat cukup', verdict: [VERDICT_RESTOCK], sort: [{ key: 'sold_1m', dir: 'desc' }, { key: 'cover_months', dir: 'asc' }], new: false },
    { key: 'running-out', label: 'Laju habis', hint: 'Perlu Restock - stok paling singkat cukup (bulan) dahulu', verdict: [VERDICT_RESTOCK], sort: [{ key: 'cover_months', dir: 'asc' }, { key: 'sold_1m', dir: 'desc' }], new: false },
    { key: 'new-sold-out', label: 'Baru Sold Out', hint: 'Menjadi Sold Out pada kemas kini terakhir', verdict: [VERDICT_SOLD_OUT], sort: [{ key: URGENCY, dir: 'asc' }], new: true },
    { key: 'old-sold-out', label: 'Sold Out lama', hint: 'Sold Out - jualan terakhir paling lama dahulu (mungkin design mati)', verdict: [VERDICT_SOLD_OUT], sort: [{ key: 'last_sold_at', dir: 'asc' }], new: false },
    { key: 'overstock', label: 'Lebihan stok', hint: 'Overstock - stok paling banyak dahulu (calon dipindahkan)', verdict: [VERDICT_OVERSTOCK], sort: [{ key: 'stock', dir: 'desc' }], new: false },
];

// Pintasan aktif = Cadangan, "baharu" & susunan semasa sama (ikut urutan). Ubah penapis/susunan sendiri = tiada badge aktif ("tersuai").
const activePreset = computed(
    () =>
        PRESETS.find(
            (preset) =>
                sameSet(preset.verdict, props.filters.verdict) &&
                preset.new === props.filters.new &&
                serializeSorts(preset.sort) === serializeSorts(props.filters.sort),
        )?.key ?? null,
);

// Klik badge aktif = kembali ke pintasan lalai (Paling mendesak).
function applyPreset(key: string) {
    const preset = PRESETS.find((p) => p.key === key);

    if (!preset) {
        return;
    }

    const target = activePreset.value === preset.key ? (PRESETS.find((p) => p.key === DEFAULT_PRESET) ?? preset) : preset;

    visit({ verdict: [...target.verdict], sort: target.sort.map((spec) => ({ ...spec })), new: target.new, page: 1 });
}

// --- Susunan (di server) ---
function toggleSort(key: SortKey) {
    visit({ sort: nextSort<SortKey>(props.filters.sort, key, MAX_SORTS), page: 1 });
}

const direction = (key: SortKey) => props.filters.sort.find((s) => s.key === key)?.dir ?? null;

function priority(key: SortKey): number | null {
    const index = props.filters.sort.findIndex((s) => s.key === key);

    return props.filters.sort.length > 1 && index !== -1 ? index + 1 : null;
}

// --- Halaman ---
function setPage(page: number) {
    visit({ page });
}

function setPerPage(size: number) {
    visit({ per_page: size, page: 1 });
}

// --- Panel butiran ---
const panelOpen = ref(false);
const panel = ref<{ mode: 'design' | 'bucket'; code?: string; store: string; view?: 'size' | 'weight'; categoryCode?: string; categoryName?: string; bucket?: string } | null>(null);

// Pengguna hanya nampak butang ke bucket bagi paparan yg dibenarkan (rujuk kebenaran Shield).
const canSize = computed(() => props.viewOptions.some((o) => o.value === 'size'));
const canWeight = computed(() => props.viewOptions.some((o) => o.value === 'weight'));

function openRow(row: DesignRow & BucketRow) {
    panel.value = isDesign.value
        ? { mode: 'design', code: row.internal_code, store: row.store_code }
        : { mode: 'bucket', store: row.store_code, view: props.filters.view === 'weight' ? 'weight' : 'size', categoryCode: row.category_code, categoryName: row.category_name, bucket: row.bucket };
    panelOpen.value = true;
}

// Dari senarai bucket, klik satu design -> panel design (cawangan sama).
function openDesignFromBucket(code: string, store: string) {
    panel.value = { mode: 'design', code, store };
}

// Dari panel design, butang ke senarai design dlm bucket saiz/berat yg sama (cawangan sama).
function openBucketFromDesign(view: 'size' | 'weight', bucket: string, categoryCode: string, categoryName: string) {
    panel.value = { mode: 'bucket', store: panel.value?.store ?? '', view, categoryCode, categoryName, bucket };
}

// --- Salin senarai (halaman semasa, lajur dipisah tab - sedia ditampal ke Excel/WhatsApp) ---
async function copyList() {
    const header = isDesign.value
        ? ['Kod Design', 'Penerangan', 'Kategori', 'Cawangan', 'Stok', 'Terjual 1 Minggu', 'Terjual 1 Bulan', 'Terjual 3 Bulan', 'Cukup (bulan)', 'Gap', 'Cadangan']
        : ['Kategori', 'Cawangan', bucketHeader.value, 'Stok', 'Terjual 1 Minggu', 'Terjual 1 Bulan', 'Terjual 3 Bulan', 'Cukup (bulan)', 'Gap', 'Cadangan'];

    const lines = rowList.value.map((r) =>
        (isDesign.value
            ? [r.internal_code, r.description ?? '', r.category_name, r.store_code, r.stock, r.sold_1w, r.sold_1m, r.sold_3m, r.cover_months ?? '', r.gap, r.verdict]
            : [r.category_name, r.store_code, r.bucket, r.stock, r.sold_1w, r.sold_1m, r.sold_3m, r.cover_months ?? '', r.gap, r.verdict]
        ).join('\t'),
    );

    if (await copyText([header.join('\t'), ...lines].join('\n'))) {
        toast.success(`${lines.length} baris disalin`);
    } else {
        toast.error('Senarai tidak dapat disalin');
    }
}

// --- Jadual = ScrollArea dgn kesan pudar (scroll-fade) atas/bawah hanya bila ada baris lagi ---
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

watch(frame, () => nextTick(bindViewport), { flush: 'post' });

// Halaman / susunan / tapisan baharu = senarai baharu: mulakan dari atas.
watch(
    () => props.rows,
    () => {
        if (viewport) {
            viewport.scrollTop = 0;
        }
    },
);

onBeforeUnmount(unbindViewport);

const showSkeleton = computed(() => loading.value);

// --- Kad ringkasan ---
const STAT_ICONS: Record<string, unknown> = {
    [VERDICT_SOLD_OUT]: PackageX,
    [VERDICT_RESTOCK]: TriangleAlert,
    [VERDICT_OK]: CircleCheck,
    [VERDICT_OVERSTOCK]: Boxes,
};
const STAT_HINTS: Record<string, string> = {
    [VERDICT_SOLD_OUT]: 'Stok kosong, masih ada jualan',
    [VERDICT_RESTOCK]: 'Di bawah paras disyorkan',
    [VERDICT_OK]: 'Dalam paras disyorkan',
    [VERDICT_OVERSTOCK]: 'Lebih 2x paras disyorkan',
};

// Peratus (nombor) bahagian Cadangan daripada jumlah baris dlm skop, utk lebar bar; `share` = teks siap papar.
const sharePercent = (name: string) => (props.summary.total > 0 ? ((props.summary.counts[name] ?? 0) / props.summary.total) * 100 : 0);
const share = (name: string) => {
    const percent = sharePercent(name);

    return `${percent >= 10 ? Math.round(percent) : percent.toFixed(1)}%`;
};

// Baharu pada kemas kini terkini (hanya Sold Out & Perlu Restock direkod).
const newCount = (name: string) => (name === VERDICT_SOLD_OUT ? props.changes.sold_out : name === VERDICT_RESTOCK ? props.changes.restock : 0);

// --- Carta ---
const PRIORITY_SERIES = [
    { key: 'sold_out', label: 'Sold Out', color: CHART_COLORS.soldOut },
    { key: 'restock', label: 'Perlu Restock', color: CHART_COLORS.restock },
];
const SHORTFALL_SERIES = [{ key: 'shortfall', label: 'Kekurangan (pcs)', color: CHART_COLORS.soldOut }];
const CURVE_SERIES = [
    { key: 'stock', label: 'Stok', color: CHART_COLORS.stock },
    { key: 'sold_3m', label: 'Terjual 3 bulan', color: CHART_COLORS.sold },
];
const CHART_TOP_CATEGORIES = 10;

const chartsVisible = computed(() => isSummary.value && props.breakdown !== null && !showSkeleton.value && props.updatedAt !== null);
const storeChartData = computed(() => (props.breakdown?.stores ?? []).map((s) => ({ label: s.label, sold_out: s.sold_out, restock: s.restock })));
const categoryChartData = computed(() => (props.breakdown?.categories ?? []).slice(0, CHART_TOP_CATEGORIES).map((c) => ({ label: c.label, shortfall: c.shortfall })));
const trendChartData = computed(() =>
    (props.trend ?? []).map((t) => {
        const [, month, day] = t.date.split('-');

        return { label: `${Number(day)}/${Number(month)}`, sold_out: t.sold_out, restock: t.restock };
    }),
);
const curveChartData = computed(() => (props.curve?.data ?? []).map((c) => ({ label: c.bucket === '(tiada)' ? 'Tiada' : c.bucket, stock: c.stock, sold_3m: c.sold_3m })));
const curveCategory = computed(() => props.categories.find((c) => c.value === props.filters.category[0])?.label ?? props.filters.category[0] ?? '');
const curveVisible = computed(() => isBucket.value && !showSkeleton.value && props.updatedAt !== null && props.curve !== null && props.curve.data.length > 0);
</script>

<template>

    <Head title="Restock Analysis" />

    <div class="mx-auto flex max-w-7xl min-w-0 flex-col gap-4 px-3 py-6 sm:px-6 sm:py-8">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="flex items-start gap-3">
                <Button as="a" :href="dashboardUrl" variant="outline" size="icon-sm" class="mt-0.5 bg-card" aria-label="Kembali ke dashboard" title="Kembali ke dashboard">
                    <ArrowLeft class="size-4" />
                </Button>
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Restock Analysis</h1>
                    <p class="text-sm text-muted-foreground">Fahami stok dan jualan setiap design di setiap cawangan, dan
                        apa yang perlu diberi perhatian hari ini.</p>
                </div>
            </div>

            <!-- Paparan -->
            <div class="inline-flex flex-wrap rounded-lg bg-muted p-1" role="tablist" aria-label="Paparan">
                <button v-for="option in viewOptions" :key="option.value" type="button" role="tab"
                    :aria-selected="filters.view === option.value" :class="cn(
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        filters.view === option.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )" @click="changeView(option.value as View)">
                    {{ option.label }}
                </button>
            </div>
        </div>

        <!-- Penapis: semuanya draf sehingga "Cari" ditekan (atau Enter di kotak tapis) -->
        <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="runSearch">
            <div class="relative min-w-44 flex-1 sm:max-w-64">
                <ListFilter
                    class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="qSel" class="h-8 pl-8 pr-8"
                    :placeholder="isBucket ? 'Cari saiz / berat / kategori...' : 'Cari kod design / penerangan...'"
                    aria-label="Carian" />
                <button v-if="qSel" type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                    aria-label="Kosongkan carian" @click="qSel = ''">
                    <X class="size-4" />
                </button>
            </div>

            <FacetedFilter title="Kategori" :options="categories" v-model="categorySel" />
            <FacetedFilter title="Cawangan" :options="stores" v-model="storeSel" />
            <div class="hidden md:block">
                <FacetedFilter title="Cadangan" :options="verdictOptions" v-model="verdictSel" />
            </div>

            <Button type="submit" size="sm" class="h-8" :variant="isDirty ? 'default' : 'secondary'"
                :disabled="loading">
                <Spinner v-if="loading" class="size-4" />
                <Search v-else class="size-4" />
                Cari
                <span v-if="isDirty && !loading" class="size-1.5 rounded-full bg-amber-400"
                    aria-label="Ada perubahan belum dicari" />
            </Button>

            <Button v-if="hasFilters || filters.store.length > 0 || filters.verdict.length > 0" type="button"
                variant="ghost" size="sm" class="h-8 px-2" @click="resetFilters">
                Tunjuk semua
                <X class="size-4" />
            </Button>
        </form>

        <!-- Kesegaran data & apa yang baharu sejak semalam -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <span>Data dikemas kini <strong class="font-medium text-foreground">{{ formatDateTime(updatedAt)
                    }}</strong></span>

            <button v-if="changes.sold_out + changes.restock > 0 && !isBucket" type="button" :aria-pressed="filters.new"
                :class="cn(
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filters.new ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent',
                )" :title="`Perubahan Cadangan pada kemas kini ${formatDateTime(changes.at)}`" @click="toggleNew">
                <Sparkles class="size-3" />
                Baharu: {{ fmt(changes.sold_out) }} Sold Out, {{ fmt(changes.restock) }} Perlu Restock
            </button>

            <span v-if="!isSummary">Jumlah kekurangan stok: <strong class="font-medium tabular-nums text-foreground">{{
                fmt(summary.shortfall) }} pcs</strong></span>
        </div>

        <!-- Kad ringkasan: bilangan, kadar daripada jumlah, kekurangan & baharu. Klik kad = tapis Cadangan itu -->
        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <template v-if="showSkeleton">
                <Skeleton v-for="i in 4" :key="i" class="h-[118px] rounded-xl" />
            </template>
            <button
                v-for="name in showSkeleton ? [] : SUMMARY_VERDICTS"
                :key="name"
                type="button"
                :aria-pressed="filters.verdict.includes(name)"
                :title="name"
                :class="cn(
                    'group flex flex-col gap-3 rounded-xl border bg-accent p-3.5 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filters.verdict.includes(name) ? cn('ring-2 ring-ring', styleFor(name).tint) : 'hover:bg-accent/40',
                    filters.verdict.length > 0 && !filters.verdict.includes(name) && 'opacity-60',
                )"
                @click="toggleVerdict(name)"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2 text-sm font-medium">
                        <span :class="cn('flex size-7 items-center justify-center rounded-lg', styleFor(name).chip)">
                            <component :is="STAT_ICONS[name]" class="size-4" aria-hidden="true" />
                        </span>
                        {{ styleFor(name).short }}
                    </span>

                    <span v-if="filters.verdict.includes(name)" class="flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground" aria-hidden="true">
                        <Check class="size-3" />
                    </span>
                    <span v-else-if="newCount(name) > 0" class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground" title="Baharu pada kemas kini terkini">
                        +{{ fmt(newCount(name)) }} baharu
                    </span>
                </div>

                <div class="flex items-end justify-between gap-2">
                    <span class="text-3xl font-semibold leading-none tabular-nums">{{ fmt(summary.counts[name] ?? 0) }}</span>
                    <span class="text-xs tabular-nums text-muted-foreground">{{ share(name) }}</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="img" :aria-label="`${share(name)} daripada ${fmt(summary.total)} baris`">
                        <div :class="cn('h-full rounded-full', styleFor(name).bar)" :style="{ width: sharePercent(name) + '%' }" />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="(summary.shortfalls?.[name] ?? 0) > 0">
                            Kurang <strong class="font-semibold tabular-nums text-foreground">{{ fmt(summary.shortfalls?.[name]) }}</strong> pcs
                        </template>
                        <template v-else>{{ STAT_HINTS[name] }}</template>
                    </p>
                </div>
            </button>
        </div>

        <!-- Carta Ringkasan: trend 30 hari, campuran Sold Out/Perlu Restock mengikut cawangan, kategori teratas -->
        <section v-if="chartsVisible" class="grid gap-4 lg:grid-cols-2" aria-label="Carta ringkasan">
            <div class="rounded-lg border bg-card p-3">
                <h2 class="mb-2 text-sm font-medium">Trend 30 hari</h2>
                <LinesChart v-if="trendChartData.length >= 2" :data="trendChartData" x-key="label" :series="PRIORITY_SERIES" :height="200" />
                <p v-else class="py-8 text-center text-sm text-muted-foreground">
                    Trend akan muncul selepas beberapa hari. Kiraan harian mula direkod
                    {{ trend && trend.length > 0 ? trend[0].date : 'hari ini' }}.
                </p>
            </div>

            <div class="rounded-lg border bg-card p-3">
                <h2 class="mb-2 text-sm font-medium">Sold Out dan Perlu Restock mengikut cawangan</h2>
                <BarsChart :data="storeChartData" x-key="label" :series="PRIORITY_SERIES" stacked :height="200" />
            </div>

            <div class="rounded-lg border bg-card p-3 lg:col-span-2">
                <h2 class="mb-2 text-sm font-medium">Kekurangan stok (pcs): {{ CHART_TOP_CATEGORIES }} kategori teratas</h2>
                <BarsChart :data="categoryChartData" x-key="label" :series="SHORTFALL_SERIES" :height="200" />
            </div>
        </section>

        <!-- Lengkung saiz/berat satu kategori: stok berbanding jualan 3 bulan setiap bucket -->
        <div v-if="curveVisible" class="rounded-lg border bg-card p-3">
            <h2 class="mb-2 text-sm font-medium">Lengkung {{ bucketHeader.toLowerCase() }}: {{ curveCategory }}</h2>
            <BarsChart :data="curveChartData" x-key="label" :series="CURVE_SERIES" :height="200" />
            <p class="mt-1 text-xs text-muted-foreground">
                Bar Stok lebih rendah daripada Terjual 3 bulan = {{ bucketHeader.toLowerCase() }} yang laris tetapi stoknya sedikit.
                <template v-if="curve && curve.total > curve.data.length"> Menunjukkan {{ curve.data.length }} {{ bucketHeader.toLowerCase() }} terlaris daripada {{ curve.total }}.</template>
            </p>
        </div>
        <p v-else-if="isBucket && curve === null && !showSkeleton && updatedAt !== null" class="text-xs text-muted-foreground">
            Pilih satu kategori untuk melihat lengkung {{ bucketHeader.toLowerCase() }} (stok berbanding jualan).
        </p>

        <!-- Data belum dibina -->
        <Empty v-if="updatedAt === null" class="border border-dashed">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <PackageSearch />
                </EmptyMedia>
                <EmptyTitle>Ringkasan belum dibina</EmptyTitle>
                <EmptyDescription>
                    Data dibina selepas segerak JEMiSys harian. Untuk membinanya sekarang, jalankan <code>php artisan
                app:build-restock-snapshot</code>.
                </EmptyDescription>
            </EmptyHeader>
        </Empty>

        <!-- Memuatkan: bentuk sama dgn jadual sebenar supaya tiada lompatan susun atur -->
        <Frame v-else-if="showSkeleton" spacing="sm" aria-busy="true" aria-label="Memuatkan data">
            <!-- Pintasan: badge bulat di atas jadual (satu klik = Cadangan + susunan) -->
            <FrameHeader v-if="isDesign" class="py-2">
                <PresetChips :chips="PRESETS" :active="activePreset" :disabled="loading" @select="applyPreset" />
            </FrameHeader>

            <FramePanel class="overflow-hidden p-2 md:p-0">
                <div class="flex flex-col gap-2 md:hidden">
                    <div v-for="i in SKELETON_ROWS" :key="i"
                        class="flex items-center gap-3 rounded-lg border bg-card p-3">
                        <Skeleton class="size-12 shrink-0 rounded-md" />
                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            <Skeleton class="h-4 w-3/4" />
                            <Skeleton class="h-3 w-1/2" />
                        </div>
                        <Skeleton class="h-6 w-14 rounded-full" />
                    </div>
                </div>

                <div class="hidden md:block">
                    <Table>
                        <TableBody>
                            <TableRow v-for="i in SKELETON_ROWS" :key="i" class="h-[68px] hover:bg-transparent">
                                <TableCell class="w-[34%]">
                                    <div class="flex items-center gap-3">
                                        <Skeleton class="size-11 shrink-0 rounded-md" />
                                        <div class="flex flex-col gap-1.5">
                                            <Skeleton class="h-4 w-32" />
                                            <Skeleton class="h-3 w-44" />
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="h-4 w-24" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="h-5 w-14 rounded-full" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="ml-auto size-11 rounded-full" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="ml-auto h-4 w-8" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="ml-auto h-4 w-8" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="ml-auto h-4 w-8" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="ml-auto h-4 w-10" />
                                </TableCell>
                                <TableCell>
                                    <Skeleton class="h-6 w-24 rounded-full" />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </FramePanel>
        </Frame>

        <!-- Ringkasan CEO: kekurangan mengikut cawangan & kategori -->
        <div v-else-if="isSummary && breakdown" class="grid gap-4 lg:grid-cols-2">
            <section
                v-for="group in [{ title: 'Mengikut cawangan', kind: 'store' as const, rows: breakdown.stores }, { title: `Kategori teratas (kekurangan)`, kind: 'category' as const, rows: breakdown.categories }]"
                :key="group.kind" class="flex flex-col gap-2">
                <h2 class="text-sm font-medium">{{ group.title }}</h2>
                <Frame spacing="sm">
                    <FramePanel class="overflow-hidden p-0">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead>{{ group.kind === 'store' ? 'Cawangan' : 'Kategori' }}</TableHead>
                                    <TableHead class="text-right">Sold Out</TableHead>
                                    <TableHead class="text-right">Perlu Restock</TableHead>
                                    <TableHead class="text-right">Kekurangan (pcs)</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="row in group.rows" :key="row.code" class="cursor-pointer"
                                    @click="openFromBreakdown(group.kind, row.code)">
                                    <TableCell class="font-medium">{{ row.label }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ fmt(row.sold_out) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ fmt(row.restock) }}</TableCell>
                                    <TableCell
                                        class="text-right font-semibold tabular-nums text-red-600 dark:text-red-400">{{
                                        fmt(row.shortfall) }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </FramePanel>
                </Frame>
                <p class="text-xs text-muted-foreground">Klik satu baris untuk membuka senarai design.</p>
            </section>
        </div>

        <Empty v-else-if="!rows || rows.total === 0" class="border border-dashed">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <PackageSearch />
                </EmptyMedia>
                <EmptyTitle>Tiada baris</EmptyTitle>
                <EmptyDescription>Tiada data sepadan dengan penapis semasa.</EmptyDescription>
            </EmptyHeader>
            <Button type="button" variant="outline" size="sm" @click="resetFilters">Tunjuk semua</Button>
        </Empty>

        <Frame v-else spacing="sm" class="min-w-0">
            <!-- Pintasan: badge bulat di atas jadual (satu klik = Cadangan + susunan) -->
            <FrameHeader v-if="isDesign" class="py-2">
                <PresetChips :chips="PRESETS" :active="activePreset" :disabled="loading" @select="applyPreset" />
            </FrameHeader>

            <FramePanel class="overflow-hidden p-2 md:p-0">
                <!-- Telefon: kad -->
                <div class="flex flex-col gap-2 md:hidden">
                    <div v-for="(row, i) in rowList"
                        :key="`m|${row.internal_code ?? row.bucket}|${row.store_code}|${i}`" role="button" tabindex="0"
                        class="flex cursor-pointer flex-col gap-2 rounded-lg border bg-card p-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="openRow(row)" @keydown.enter="openRow(row)">
                        <div class="flex items-start gap-3">
                            <ImagePreview v-if="isDesign" :src="row.image_url" :alt="row.internal_code"
                                class="size-12 shrink-0" @click.stop />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ isDesign ? row.internal_code :
                                    `${row.category_name}
                                    · ${bucketHeader} ${row.bucket}` }}</p>
                                <p class="text-xs leading-snug text-balance break-words text-muted-foreground">{{ isDesign ? [row.nickname ?? row.description,
                                row.category_name].filter(Boolean).join(' · ') : bucketHeader }}</p>
                            </div>
                            <Badge variant="secondary">{{ row.store_code }}</Badge>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <Badge :class="styleFor(row.verdict).badge">{{ styleFor(row.verdict).short }}</Badge>
                            <span :class="cn('text-sm font-semibold tabular-nums', gapClass(row.gap))">Gap {{
                                fmt(row.gap)
                                }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="img"
                            :aria-label="`Stok ${coverage(row.stock, row.target_stock)}% daripada disyorkan`">
                            <div :class="cn('h-full rounded-full', styleFor(row.verdict).bar)"
                                :style="{ width: coverage(row.stock, row.target_stock) + '%' }" />
                        </div>
                        <dl class="grid grid-cols-5 gap-2 text-center text-xs tabular-nums">
                            <div>
                                <dt class="text-muted-foreground">Stok</dt>
                                <dd class="font-medium">{{ fmt(row.stock) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">1 Mgg</dt>
                                <dd class="font-medium">{{ fmt(row.sold_1w) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">1 Bln</dt>
                                <dd class="font-medium">{{ fmt(row.sold_1m) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">3 Bln</dt>
                                <dd class="font-medium">{{ fmt(row.sold_3m) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Cukup</dt>
                                <dd class="font-medium">{{ row.cover_months === null ? '-' : fmt(row.cover_months, 1) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Desktop: jadual dlm ScrollArea dgn pengepala melekat & scroll-fade -->
                <div ref="frame" class="relative hidden md:block">
                    <ScrollArea horizontal
                        class="max-h-[68vh] rounded-[inherit] [&_[data-slot=scroll-area-viewport]>div]:!block">
                        <Table>
                            <TableHeader class="sticky top-0 z-10 bg-card shadow-[0_1px_0_var(--border)]">
                                <TableRow class="hover:bg-transparent">
                                    <TableHead v-if="isDesign">
                                        <SortableHeader title="Design" :direction="direction('internal_code')"
                                            :priority="priority('internal_code')" @sort="toggleSort('internal_code')" />
                                    </TableHead>
                                    <TableHead>
                                        <SortableHeader title="Kategori" :direction="direction('category')"
                                            :priority="priority('category')" @sort="toggleSort('category')" />
                                    </TableHead>
                                    <TableHead>
                                        <SortableHeader title="Cawangan" :direction="direction('store')"
                                            :priority="priority('store')" @sort="toggleSort('store')" />
                                    </TableHead>
                                    <TableHead v-if="isBucket">
                                        <SortableHeader :title="bucketHeader" :direction="direction('bucket')"
                                            :priority="priority('bucket')" @sort="toggleSort('bucket')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="Stok"
                                            full-title="Stok semasa (bulatan = peratus daripada disyorkan)"
                                            :direction="direction('stock')" :priority="priority('stock')"
                                            @sort="toggleSort('stock')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="1 Minggu" full-title="Terjual dalam 1 minggu"
                                            :direction="direction('sold_1w')" :priority="priority('sold_1w')"
                                            @sort="toggleSort('sold_1w')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="1 Bulan" full-title="Terjual dalam 1 bulan"
                                            :direction="direction('sold_1m')" :priority="priority('sold_1m')"
                                            @sort="toggleSort('sold_1m')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="3 Bulan"
                                            full-title="Terjual dalam 3 bulan (asas Cadangan)"
                                            :direction="direction('sold_3m')" :priority="priority('sold_3m')"
                                            @sort="toggleSort('sold_3m')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="Cukup"
                                            full-title="Stok semasa cukup untuk berapa bulan pada kadar jualan 3 bulan terkini"
                                            :direction="direction('cover_months')" :priority="priority('cover_months')"
                                            @sort="toggleSort('cover_months')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="Gap"
                                            full-title="Stok disyorkan (1.5 bulan jualan) tolak stok semasa. Positif = kurang stok."
                                            :direction="direction('gap')" :priority="priority('gap')"
                                            @sort="toggleSort('gap')" />
                                    </TableHead>
                                    <TableHead>
                                        <SortableHeader title="Cadangan" :direction="direction(URGENCY)"
                                            :priority="priority(URGENCY)" @sort="toggleSort(URGENCY)" />
                                    </TableHead>
                                    <TableHead class="w-12"><span class="sr-only">Butiran</span></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="(row, i) in rowList"
                                    :key="`${row.internal_code ?? row.bucket}|${row.category_code}|${row.store_code}|${i}`"
                                    class="cursor-pointer" @click="openRow(row)">
                                    <TableCell v-if="isDesign" @click.stop>
                                        <div class="flex items-center gap-3">
                                            <ImagePreview :src="row.image_url" :alt="row.internal_code"
                                                class="size-11 shrink-0" />
                                            <button type="button" class="flex min-w-0 flex-col text-left"
                                                @click="openRow(row)">
                                                <span class="whitespace-nowrap font-medium">{{ row.internal_code
                                                    }}</span>
                                                <!-- Nama ringkas: nickname, jika tiada DiamondDesc (penerangan sandaran jika kedua-duanya tiada); teks dilipat, tidak dipotong -->
                                                <span v-if="row.nickname ?? row.description"
                                                    class="w-44 max-w-56 whitespace-normal text-xs leading-snug text-balance break-words text-muted-foreground"
                                                    :title="row.description ?? undefined">{{ row.nickname ?? row.description }}</span>
                                            </button>
                                        </div>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap">{{ row.category_name }}</TableCell>
                                    <TableCell>
                                        <Badge variant="secondary">{{ row.store_code }}</Badge>
                                    </TableCell>
                                    <TableCell v-if="isBucket" class="whitespace-nowrap font-medium">
                                        <span v-if="row.bucket === '(tiada)'"
                                            class="font-normal italic text-muted-foreground">Tiada {{
                                            bucketHeader.toLowerCase()
                                            }}</span>
                                        <template v-else>{{ row.bucket }}</template>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <CircularProgress :value="coverage(row.stock, row.target_stock)"
                                            :text="fmt(row.stock)" :ring-class="styleFor(row.verdict).ring"
                                            :label="`Stok ${coverage(row.stock, row.target_stock)}% daripada disyorkan`"
                                            class="ml-auto size-11" />
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums">{{ fmt(row.sold_1w) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ fmt(row.sold_1m) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ fmt(row.sold_3m) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ row.cover_months === null ? '-' :
                                        fmt(row.cover_months, 1) }}</TableCell>
                                    <TableCell :class="cn('text-right font-semibold tabular-nums', gapClass(row.gap))">
                                        {{
                                        fmt(row.gap) }}</TableCell>
                                    <TableCell>
                                        <Badge :class="styleFor(row.verdict).badge" :title="row.verdict">{{
                                            styleFor(row.verdict).short }}</Badge>
                                    </TableCell>
                                    <TableCell @click.stop>
                                        <Button type="button" variant="ghost" size="icon" class="size-8"
                                            aria-label="Lihat butiran" @click="openRow(row)">
                                            <Eye class="size-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </ScrollArea>

                    <div aria-hidden="true"
                        class="pointer-events-none absolute inset-x-0 top-10 h-8 bg-linear-to-b from-card to-transparent transition-opacity duration-200"
                        :class="fadeTop ? 'opacity-100' : 'opacity-0'" />
                    <div aria-hidden="true"
                        class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-linear-to-t from-card to-transparent transition-opacity duration-200"
                        :class="fadeBottom ? 'opacity-100' : 'opacity-0'" />
                </div>
            </FramePanel>

            <FrameFooter class="justify-between gap-3">
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    {{ fmt(rows.total) }} baris
                    <button type="button"
                        class="inline-flex items-center gap-1 text-xs underline underline-offset-2 hover:text-foreground"
                        @click="copyList">
                        <Copy class="size-3" /> Salin senarai
                    </button>
                </span>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <Select :model-value="String(filters.per_page)" @update:model-value="(v) => setPerPage(Number(v))">
                        <SelectTrigger class="h-8 w-40" aria-label="Baris sehalaman">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="size in perPageOptions" :key="size" :value="String(size)">{{ size }} /
                                halaman
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Pagination v-if="rows.last_page > 1" class="mx-0 w-auto" :page="rows.page" :total="rows.total"
                        :items-per-page="rows.per_page" :sibling-count="1" show-edges @update:page="setPage">
                        <PaginationContent v-slot="{ items }">
                            <PaginationPrevious />
                            <span class="px-2 text-sm tabular-nums sm:hidden">{{ rows.page }} / {{ rows.last_page
                                }}</span>
                            <template v-for="(item, index) in items" :key="index">
                                <PaginationItem v-if="item.type === 'page'" class="hidden sm:inline-flex"
                                    :value="item.value" :is-active="item.value === rows.page">
                                    {{ item.value }}
                                </PaginationItem>
                                <PaginationEllipsis v-else class="hidden sm:flex" />
                            </template>
                            <PaginationNext />
                        </PaginationContent>
                    </Pagination>
                </div>
            </FrameFooter>
        </Frame>
    </div>

    <DetailPanel v-if="panel" v-model:open="panelOpen" :mode="panel.mode" :code="panel.code" :store="panel.store"
        :view="panel.view ?? (filters.view === 'weight' ? 'weight' : 'size')" :can-size="canSize" :can-weight="canWeight"
        :category-code="panel.categoryCode"
        :category-name="panel.categoryName" :bucket="panel.bucket" @open-design="openDesignFromBucket" @open-bucket="openBucketFromDesign" />
</template>
