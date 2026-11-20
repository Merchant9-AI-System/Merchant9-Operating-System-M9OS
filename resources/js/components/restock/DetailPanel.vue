<script setup lang="ts">
import { useIntersectionObserver } from '@vueuse/core';
import { ChevronsDown, Ruler, Scale } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import BarsChart from '@/components/restock/BarsChart.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CHART_COLORS } from '@/lib/chart';
import { cn } from '@/lib/utils';
import { fmt, formatDateTime, styleFor, VERDICT_NO_DATA, VERDICT_OK, VERDICT_OVERSTOCK, VERDICT_RESTOCK, VERDICT_SOLD_OUT } from '@/lib/restock';
import type { DesignDetail } from '@/lib/restock';

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

// Cawangan lain yg stoknya melebihi paras disyorkan - calon transfer.
const surplusStores = computed(() => (detail.value?.stores ?? []).filter((s) => s.surplus > 0 && s.store_code !== detail.value?.selected_store).sort((a, b) => b.surplus - a.surplus));

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
                        <template v-if="detail"> &middot; {{ detail.category_name }}<template v-if="detail.vendor_name"> &middot; {{ detail.vendor_name }}</template></template>
                    </SheetDescription>
                </template>
                <SheetDescription v-else-if="!loading && bucketTotal > 0">
                    Menunjukkan {{ bucketDesigns.length }} daripada {{ bucketTotal }} design (disusun ikut terjual bulan ini).
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
                        <ImagePreview :src="detail.image_url" :alt="detail.code" class="size-20 shrink-0" />
                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            <div v-if="selected" class="flex flex-wrap items-center gap-2">
                                <Badge :class="styleFor(selected.verdict).badge">{{ selected.verdict }}</Badge>
                                <Badge variant="outline">{{ selected.store_code }}</Badge>
                            </div>
                            <p v-if="reason" class="text-sm">{{ reason }}</p>
                            <p v-if="selected?.last_sold_at" class="text-xs text-muted-foreground">Jualan terakhir di {{ selected.store_code }}: {{ selected.last_sold_at }}</p>
                        </div>
                    </div>

                    <p v-if="needsStock && surplusStores.length > 0" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200">
                        Boleh dipertimbangkan transfer daripada:
                        <strong v-for="(s, i) in surplusStores" :key="s.store_code" class="font-semibold">{{ i > 0 ? ', ' : ' ' }}{{ s.store_code }} (+{{ s.surplus }})</strong>.
                    </p>

                    <!-- Pintasan ke senarai design dlm bucket saiz/berat yg sama (panel bucket) -->
                    <section v-if="(canSize && detail.buckets.size) || (canWeight && detail.buckets.weight)" class="flex flex-col gap-2">
                        <h3 class="text-sm font-medium">Design lain dalam bucket yang sama</h3>
                        <div class="flex flex-wrap gap-2">
                            <Button v-if="canSize && detail.buckets.size" type="button" variant="outline" size="sm" @click="emit('open-bucket', 'size', detail.buckets.size, detail.category_code, detail.category_name)">
                                <Ruler class="size-4" />
                                {{ detail.buckets.size === '(tiada)' ? 'Tiada saiz' : `Saiz ${detail.buckets.size}` }}
                            </Button>
                            <Button v-if="canWeight && detail.buckets.weight" type="button" variant="outline" size="sm" @click="emit('open-bucket', 'weight', detail.buckets.weight, detail.category_code, detail.category_name)">
                                <Scale class="size-4" />
                                {{ detail.buckets.weight === '(tiada)' ? 'Tiada berat' : `Berat ${detail.buckets.weight}` }}
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ detail.category_name }} di {{ detail.selected_store }}.</p>
                    </section>

                    <!-- Stok ikut saiz & berat bagi cawangan yg diklik: saiz mana ada / habis -->
                    <section class="flex flex-col gap-2">
                        <h3 class="text-sm font-medium">Stok ikut saiz dan berat{{ detail.selected_store ? ` - ${detail.selected_store}` : '' }}</h3>
                        <p v-if="sizeRows.length === 0" class="text-sm text-muted-foreground">Tiada stok atau jualan 3 bulan terkini{{ detail.selected_store ? ` di ${detail.selected_store}` : '' }}.</p>
                        <ScrollArea v-else horizontal class="max-h-80 rounded-lg border [&_[data-slot=scroll-area-viewport]>div]:!block">
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
                                        <TableCell class="whitespace-nowrap font-medium">{{ r.size === '(tiada)' ? 'Tiada saiz' : r.size }}</TableCell>
                                        <TableCell class="whitespace-nowrap tabular-nums">{{ weightText(r.weight_min, r.weight_max) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">
                                            <Badge v-if="r.stock === 0 && r.sold_3m > 0" :class="styleFor(VERDICT_SOLD_OUT).badge">Habis</Badge>
                                            <template v-else>{{ fmt(r.stock) }}</template>
                                        </TableCell>
                                        <TableCell class="text-right tabular-nums">{{ fmt(r.sold_3m) }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </ScrollArea>
                        <p class="text-xs text-muted-foreground">Saiz yang habis tetapi masih laku ditanda "Habis" - itulah yang patut dipertimbangkan untuk restock.</p>
                    </section>

                    <!-- Stok berbanding disyorkan setiap cawangan -->
                    <section class="flex flex-col gap-2">
                        <h3 class="text-sm font-medium">Stok berbanding disyorkan</h3>
                        <BarsChart :data="storeStockData" x-key="label" :series="STOCK_SERIES" :height="170" />
                    </section>

                    <!-- Stok setiap cawangan -->
                    <section class="flex flex-col gap-2">
                        <h3 class="text-sm font-medium">Stok dan jualan setiap cawangan</h3>
                        <ScrollArea horizontal class="rounded-lg border [&_[data-slot=scroll-area-viewport]>div]:!block">
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
                                    <TableRow v-for="s in detail.stores" :key="s.store_code" :class="s.store_code === detail.selected_store && 'bg-muted/50'">
                                        <TableCell class="font-medium">
                                            {{ s.store_code }}
                                            <Badge v-if="s.surplus > 0" variant="outline" class="ml-1 text-[10px]">lebihan {{ s.surplus }}</Badge>
                                        </TableCell>
                                        <TableCell class="text-right tabular-nums">{{ fmt(s.stock) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ fmt(s.sold_1w) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ fmt(s.sold_1m) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ fmt(s.sold_3m) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ fmt(s.cover_months, 1) }}</TableCell>
                                        <TableCell><Badge :class="styleFor(s.verdict).badge">{{ styleFor(s.verdict).short }}</Badge></TableCell>
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
                            <li
                                v-for="m in detail.monthly"
                                :key="m.month"
                                class="flex flex-col items-center gap-1.5 rounded-xl border bg-card p-1.5"
                                :title="`${monthLabel(m.month, true)}: ${m.store + m.others} terjual (${detail.selected_store || 'cawangan dipilih'}: ${m.store})`"
                            >
                                <span class="text-[10px] font-medium leading-none text-muted-foreground" aria-hidden="true">{{ monthLabel(m.month) }}</span>
                                <div class="relative h-24 w-full overflow-hidden rounded-lg bg-muted/70">
                                    <div class="absolute inset-x-0 bottom-0 flex flex-col justify-end overflow-hidden rounded-lg" :style="{ height: `${((m.store + m.others) / monthlyMax) * 100}%` }">
                                        <div class="w-full bg-foreground/35" :style="{ height: `${(m.others / Math.max(1, m.store + m.others)) * 100}%` }" />
                                        <div class="w-full bg-foreground/85" :style="{ height: `${(m.store / Math.max(1, m.store + m.others)) * 100}%` }" />
                                    </div>
                                </div>
                                <span class="sr-only">{{ monthLabel(m.month, true) }}: {{ m.store + m.others }} terjual</span>
                            </li>
                        </ul>
                        <p class="flex items-center gap-3 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-foreground/85" /> {{ detail.selected_store || 'Cawangan dipilih' }}</span>
                            <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-foreground/35" /> Cawangan lain</span>
                        </p>
                    </section>

                    <p class="text-xs text-muted-foreground">Data dikemas kini {{ formatDateTime(detail.computed_at) }}.</p>
                </template>

                <!-- Mod bucket -->
                <template v-else-if="mode === 'bucket'">
                    <p v-if="bucketDesigns.length === 0" class="text-sm text-muted-foreground">Tiada design dijumpai dalam bucket ini.</p>
                    <!-- Skrol dikendalikan ScrollArea panel (header kekal kelihatan); "Muat Lagi" di hujung. -->
                    <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="d in bucketDesigns"
                        :key="d.internal_code"
                        role="button"
                        tabindex="0"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border bg-card p-2 text-left transition hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="emit('open-design', d.internal_code, store ?? '')"
                        @keydown.enter="emit('open-design', d.internal_code, store ?? '')"
                    >
                        <ImagePreview :src="d.image_url" :alt="d.internal_code" class="size-12 shrink-0" @click.stop />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ d.internal_code }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ [d.description, d.vendor_name].filter(Boolean).join(' · ') }}</p>
                        </div>
                        <dl class="grid shrink-0 grid-cols-3 gap-x-3 text-center text-xs tabular-nums">
                            <div><dt class="text-muted-foreground">Stok</dt><dd class="font-medium">{{ d.current_stock }}</dd></div>
                            <div><dt class="text-muted-foreground">Terjual</dt><dd class="font-medium">{{ d.pieces_sold }}</dd></div>
                            <div><dt class="text-muted-foreground">Bulan ini</dt><dd class="font-medium">{{ d.sold_this_month }}</dd></div>
                        </dl>
                    </div>

                    <div v-if="bucketHasMore" ref="sentinel" class="flex flex-col gap-1.5 pb-2 pt-1">
                        <Button type="button" variant="outline" size="sm" class="w-full" :disabled="bucketLoadingMore" @click="loadMore">
                            <Spinner v-if="bucketLoadingMore" class="size-3.5" />
                            <ChevronsDown v-else class="size-3.5" />
                            {{ bucketLoadingMore ? 'Memuatkan...' : `Muat Lagi (${bucketTotal - bucketDesigns.length} lagi)` }}
                        </Button>
                        <p v-if="bucketLoadError" class="text-center text-xs text-destructive" role="alert">Gagal memuatkan. Tekan butang untuk cuba lagi.</p>
                    </div>
                    </div>
                </template>
            </div>
            </ScrollArea>
        </SheetContent>
    </Sheet>
</template>
