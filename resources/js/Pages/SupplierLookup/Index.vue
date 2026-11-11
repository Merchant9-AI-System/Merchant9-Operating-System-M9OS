<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, PackageSearch, Search, X } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import FacetedFilter from '@/components/FacetedFilter.vue';
import ImagePreview from '@/components/ImagePreview.vue';
import SearchAutocomplete from '@/components/SearchAutocomplete.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { branchOptions as buildBranchOptions, PAGE_SIZE, paginate, rollUp, sortRows } from '@/lib/supplierLookup';
import type { LookupDesign, SortDirection, SortKey } from '@/lib/supplierLookup';

// Jadual ini berjalan di PELAYAR: server menghantar semua design supplier sekali (supplier + tempoh), manakala tapisan cawangan,
// susunan & halaman dikira tempatan (lib/supplierLookup.ts) - serta-merta tanpa rangkaian. Definisi nombor: rujuk
// App\Support\SupplierLookup dokblok.
interface LookupResult {
    // false = belum ada supplier dipilih (server tidak mengira apa-apa).
    searched: boolean;
    designs: LookupDesign[];
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
const DEFAULT_SORT: SortKey = 'code';

const SORT_OPTIONS: { value: string; label: string }[] = [
    { value: 'code:asc', label: 'Design Code (A - Z)' },
    { value: 'code:desc', label: 'Design Code (Z - A)' },
    { value: 'percent:desc', label: 'Percentage (tinggi - rendah)' },
    { value: 'percent:asc', label: 'Percentage (rendah - tinggi)' },
    { value: 'buy:desc', label: 'Total Ambil (banyak - sikit)' },
    { value: 'buy:asc', label: 'Total Ambil (sikit - banyak)' },
    { value: 'sold:desc', label: 'Total Sold (banyak - sikit)' },
    { value: 'sold:asc', label: 'Total Sold (sikit - banyak)' },
    { value: 'available:desc', label: 'Total Available (banyak - sikit)' },
    { value: 'available:asc', label: 'Total Available (sikit - banyak)' },
];

// `inputText` = teks medan carian: apa yg sedang ditaip (cadangan supplier muncul), atau nama supplier terpilih (tiada cip
// berasingan); `supplier` = supplier yg DIPILIH dan memuatkan senarai. Supplier & tempoh = permintaan server; selebihnya tempatan.
const inputText = ref(props.supplier?.name ?? '');
const supplier = ref<{ code: string; name: string } | null>(props.supplier);
const days = ref(String(props.filters.days));
const branches = ref<string[]>([]);
const sortKey = ref<SortKey>(DEFAULT_SORT);
const sortDirection = ref<SortDirection>('asc');
const page = ref(1);
const loading = ref(false);

const suggestions = ref<Suggestions>({ suppliers: [] });
const suggestLoading = ref(false);
const suggestError = ref<string | null>(null);

// Senarai hanya muncul selepas pengguna memilih supplier.
const hasQuery = computed(() => supplier.value !== null);
const branchMode = computed(() => branches.value.length > 0);
const sortValue = computed(() => `${sortKey.value}:${sortDirection.value}`);
const showSkeleton = computed(() => hasQuery.value && (loading.value || !props.result?.searched));
const isFiltered = computed(() => hasQuery.value || inputText.value !== '' || branches.value.length > 0);

// Semua dikira tempatan - tiada permintaan rangkaian bila cawangan / susunan / halaman berubah.
const designs = computed(() => (showSkeleton.value ? [] : (props.result?.designs ?? [])));
const branchOptions = computed(() => buildBranchOptions(designs.value));
const sortedRows = computed(() => sortRows(rollUp(designs.value, branches.value), sortKey.value, sortDirection.value));
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
watch([branches, sortKey, sortDirection], () => {
    page.value = 1;
});

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

function toggleSort(key: SortKey) {
    if (sortKey.value !== key) {
        sortKey.value = key;
        sortDirection.value = 'asc';

        return;
    }

    if (sortDirection.value === 'asc') {
        sortDirection.value = 'desc';

        return;
    }

    // Kitaran ketiga = kembali ke susunan asal.
    sortKey.value = DEFAULT_SORT;
    sortDirection.value = 'asc';
}

function headerDirection(key: SortKey): SortDirection | null {
    return sortKey.value === key ? sortDirection.value : null;
}

function onSortSelect(value: unknown) {
    const [key, direction] = String(value).split(':');

    sortKey.value = key as SortKey;
    sortDirection.value = direction as SortDirection;
}

function resetFilters() {
    skipNextSuggest = inputText.value !== '';
    inputText.value = '';
    supplier.value = null;
    branches.value = [];
}

function percentWidth(percent: number): string {
    return `${Math.min(100, Math.max(0, percent))}%`;
}

function formatNumber(value: number): string {
    return value.toLocaleString('en-MY');
}
</script>

<template>

    <Head title="Supplier Lookup" />

    <div class="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Supplier Lookup</h1>
            <p class="text-sm text-muted-foreground">
                Jualan setiap design daripada piece yang diambil daripada satu supplier dalam tempoh dipilih.
            </p>
        </div>

        <div>
            <div class="flex flex-col gap-4">
                <!-- Carian supplier, penapis & susunan -->
                <div class="flex flex-wrap items-center gap-2">
                    <SearchAutocomplete v-model="inputText" placeholder="Taip kod atau nama supplier..."
                        :suppliers="suggestions.suppliers" :loading="suggestLoading" :error="suggestError"
                        @pick="pickSupplier" @clear="clearSupplier" />

                    <Select v-model="days">
                        <SelectTrigger class="h-7 w-44 bg-card" aria-label="Tempoh">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in periodOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <FacetedFilter v-model="branches" title="Cawangan" :options="branchOptions" />

                    <Select :model-value="sortValue" @update:model-value="onSortSelect">
                        <SelectTrigger class="h-7 w-56 bg-card" aria-label="Susun mengikut">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in SORT_OPTIONS" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button v-if="isFiltered" type="button" variant="outline" size="sm" class="h-8 px-2 border-dashed"
                        @click="resetFilters">
                        Reset
                        <X class="size-4" />
                    </Button>
                </div>

                <!-- Belum pilih supplier: tiada senarai -->
                <Empty v-if="!hasQuery" class="rounded-md border border-dashed bg-card">
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

                <template v-else>
                    <!-- Jadual -->
                    <div class="overflow-x-auto rounded-md border shadow-sm">
                        <Table class="bg-card">
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead class="w-20"><span class="text-md font-medium tracking-wide">Image</span></TableHead>
                                    <TableHead>
                                        <SortableHeader title="Design Code" :direction="headerDirection('code')"
                                            @sort="toggleSort('code')" />
                                    </TableHead>
                                    <TableHead v-if="branchMode" class="w-28">Cawangan</TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="Total Ambil/Buy" :direction="headerDirection('buy')"
                                            @sort="toggleSort('buy')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="Total Sold" :direction="headerDirection('sold')"
                                            @sort="toggleSort('sold')" />
                                    </TableHead>
                                    <TableHead class="text-right">
                                        <SortableHeader title="Total Available"
                                            :direction="headerDirection('available')" @sort="toggleSort('available')" />
                                    </TableHead>
                                    <TableHead class="w-44">
                                        <SortableHeader title="Percentage" :direction="headerDirection('percent')"
                                            @sort="toggleSort('percent')" />
                                    </TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <!-- Skeleton: hanya semasa supplier / tempoh dimuatkan dari server -->
                                <template v-if="showSkeleton">
                                    <TableRow v-for="n in SKELETON_ROWS" :key="`sk-${n}`" class="hover:bg-transparent">
                                        <TableCell>
                                            <Skeleton class="size-12 rounded-md" />
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
                                        <TableCell v-for="c in 3" :key="`skn-${n}-${c}`">
                                            <Skeleton class="ml-auto h-4 w-10" />
                                        </TableCell>
                                        <TableCell>
                                            <Skeleton class="h-4 w-28" />
                                        </TableCell>
                                    </TableRow>
                                </template>

                                <template v-else>
                                    <TableRow v-for="row in pageData.rows" :key="`${row.code}|${row.store ?? ''}`">
                                        <TableCell>
                                            <!-- Imej/nickname dimuat utk baris sehalaman (sebentar sahaja) -->
                                            <Skeleton v-if="!details[row.code]" class="size-12 rounded-md" />
                                            <ImagePreview v-else :src="details[row.code].image_url"
                                                :reference="details[row.code].image_reference"
                                                :alt="details[row.code].nickname ?? row.code" class="size-12 rounded-md" />
                                        </TableCell>
                                        <TableCell>
                                            <div class="font-medium">{{ row.code }}</div>
                                            <Skeleton v-if="!details[row.code]" class="mt-1 h-3 w-40" />
                                            <div v-else-if="details[row.code].nickname"
                                                class="max-w-72 truncate text-xs text-muted-foreground">
                                                {{ details[row.code].nickname }}
                                            </div>
                                        </TableCell>
                                        <TableCell v-if="branchMode" class="text-sm">{{ row.store }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ formatNumber(row.buy) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ formatNumber(row.sold) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{ formatNumber(row.available) }}
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex items-center gap-2">
                                                <span class="w-12 text-right tabular-nums">{{ row.percent.toFixed(1)
                                                }}%</span>
                                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                                    <div class="h-full rounded-full bg-primary"
                                                        :style="{ width: percentWidth(row.percent) }" />
                                                </div>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>
                            </TableBody>
                        </Table>

                        <Empty v-if="!showSkeleton && pageData.total === 0" class="border-0 bg-card">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <PackageSearch />
                                </EmptyMedia>
                                <EmptyTitle>Tiada design ditemui</EmptyTitle>
                                <EmptyDescription>
                                    Supplier ini tidak mengambil sebarang piece dalam tempoh atau cawangan yang dipilih.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    </div>

                    <!-- Halaman -->
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm text-muted-foreground">
                            <Skeleton v-if="showSkeleton" class="h-4 w-40" />
                            <template v-else>{{ rangeLabel }}</template>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button type="button" variant="outline" size="sm"
                                :disabled="showSkeleton || pageData.page <= 1" @click="page = pageData.page - 1">
                                <ChevronLeft class="size-4" />
                            </Button>
                            <span class="text-sm tabular-nums text-muted-foreground">
                                {{ pageData.page }} / {{ pageData.lastPage }}
                            </span>
                            <Button type="button" variant="outline" size="sm"
                                :disabled="showSkeleton || pageData.page >= pageData.lastPage"
                                @click="page = pageData.page + 1">
                                <ChevronRight class="size-4" />
                            </Button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
