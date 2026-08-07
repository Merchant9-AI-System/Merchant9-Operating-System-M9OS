<script setup lang="ts">
import { Globe, ImageUp, Loader2, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface ProductSearchResult {
    internal_code: string;
    description: string;
    category_name: string;
    current_stock: number;
    size: string | null;
    weight: number | null;
    // Nama gaya/nickname tak formal drpd merchant9.com (rujuk App\Jobs\
    // SyncMerchantNicknamesAndImages) - MUNGKIN null utk design yg blm disegerak/tiada padanan.
    nickname: string | null;
    image_url: string | null;
}

// Cadangan carian laman web merchant9.com (rujuk App\Support\MerchantWebsiteSearch) - fallback
// bila staf cari guna nickname tak formal (cth. "COCO PASIR") yg TIADA dlm katalog dalaman.
// TIADA internal_code boleh dipercayai - HQ padankan ke stok sebenar semasa semakan.
export interface WebSearchResult {
    name: string;
    website_code: string | null;
    category_label: string | null;
    price_label: string | null;
    image_url: string | null;
    product_url: string | null;
}

const props = defineProps<{
    modelValue: string;
    storeCode: string | null;
    disabled?: boolean;
    goldTypes?: string[];
    weightRanges?: string[];
    sizeRanges?: string[];
    categoryCodes?: string[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'select', result: ProductSearchResult): void;
    (e: 'selectWeb', result: WebSearchResult): void;
    // Staf langkau carian terus, ATAU carian dalaman+laman web dua2 TIADA hasil - muat naik
    // gambar sendiri (rujuk BranchDemandRequestLine::SOURCE_UPLOAD). Hantar teks carian semasa
    // (jika ada) sbg cadangan keterangan awal, supaya apa yg staf dah taip tak hilang sia-sia.
    (e: 'selectManual', descriptionHint: string): void;
}>();

const query = ref(props.modelValue);
const results = ref<ProductSearchResult[]>([]);
const open = ref(false);
const loading = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const webResults = ref<WebSearchResult[]>([]);
const webLoading = ref(false);
const webSearched = ref(false);

watch(query, (value) => {
    emit('update:modelValue', value);

    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    webResults.value = [];
    webSearched.value = false;

    if (value.trim().length < 2 || !props.storeCode) {
        results.value = [];
        open.value = false;

        return;
    }

    debounceTimer = setTimeout(() => fetchResults(value), 300);
});

// Kalau tapisan (goldTypes/weightRanges/sizeRanges, dikongsi drpd parent) berubah SEMASA
// carian aktif dah tunjuk hasil, cari semula guna carian yg sama - elak hasil lapuk (tak
// ikut tapisan terkini) terpampang sampai staf taip semula.
watch(
    () => [props.goldTypes, props.weightRanges, props.sizeRanges, props.categoryCodes],
    () => {
        if (query.value.trim().length >= 2 && props.storeCode) {
            fetchResults(query.value);
        }
    },
    { deep: true },
);

async function fetchResults(value: string) {
    if (!props.storeCode) {
        return;
    }

    loading.value = true;
    open.value = true;

    try {
        const params = new URLSearchParams({ q: value, store_code: props.storeCode });
        (props.goldTypes ?? []).forEach((v) => params.append('gold_types[]', v));
        (props.weightRanges ?? []).forEach((v) => params.append('weight_ranges[]', v));
        (props.sizeRanges ?? []).forEach((v) => params.append('size_ranges[]', v));
        (props.categoryCodes ?? []).forEach((v) => params.append('category_codes[]', v));

        const response = await fetch(`/branch-demand/search?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        results.value = response.ok ? await response.json() : [];
    } finally {
        loading.value = false;
    }
}

function select(result: ProductSearchResult) {
    query.value = ''; // `${result.internal_code} - ${result.description}`;
    open.value = false;
    emit('select', result);
}

async function searchWebsite() {
    if (query.value.trim().length < 2) {
        return;
    }

    webLoading.value = true;
    webSearched.value = true;

    try {
        const params = new URLSearchParams({ q: query.value });
        const response = await fetch(`/branch-demand/search-website?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        webResults.value = response.ok ? await response.json() : [];
    } finally {
        webLoading.value = false;
    }
}

function selectWeb(result: WebSearchResult) {
    query.value = ''; // result.name;
    open.value = false;
    emit('selectWeb', result);
}

function selectManual() {
    const hint = query.value.trim();
    open.value = false;
    emit('selectManual', hint);
}

function clear() {
    query.value = '';
    results.value = [];
    webResults.value = [];
    webSearched.value = false;
    open.value = false;
}

function onBlur() {
    // Delay sikit supaya klik pd hasil (mousedown -> click) sempat jalan sblm senarai ditutup.
    setTimeout(() => {
        open.value = false;
    }, 150);
}
</script>

<template>
    <div class="relative">
        <div class="flex items-center gap-2">
            <Input v-model="query" :disabled="disabled || !storeCode"
                :placeholder="storeCode ? 'Cari kod design, keterangan atau kategori...' : 'Pilih cawangan dahulu'"
                class="pr-8" autocomplete="off" @focus="open = results.length > 0" @blur="onBlur" />
            <Button v-show="query.length > 0 && !loading && !disabled" type="button" variant="ghost" size="icon"
                class="absolute right-1 top-1 size-7" @click="clear">
                <X class="size-4" />
                <span class="sr-only">Buang carian</span>
            </Button>
    
            <Button v-if="storeCode && !disabled" type="button"
                @mousedown.prevent="selectManual">
                <ImageUp class="size-3.5" />
                Manual Upload?
            </Button>
        </div>

        <div v-if="open"
            class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow-md">
            <p v-if="loading" class="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
                <Loader2 class="size-3.5 animate-spin" /> Mencari...
            </p>
            <p v-else-if="results.length === 0" class="px-3 py-2 text-sm text-muted-foreground">
                Tiada hasil dijumpai.
            </p>
            <button v-for="result in results" :key="result.internal_code" type="button"
                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-accent"
                @mousedown.prevent="select(result)">
                <img v-if="result.image_url" :src="result.image_url" class="size-10 shrink-0 rounded object-cover"
                    alt="" loading="lazy">
                <div v-else class="size-10 shrink-0 rounded bg-muted" />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ result.internal_code }}</p>
                    <p class="truncate text-muted-foreground">{{ result.description }} &middot; {{ result.category_name
                    }}</p>
                    <p v-if="result.nickname" class="truncate text-xs italic text-muted-foreground">
                        a.k.a. "{{ result.nickname }}"
                    </p>
                    <p v-if="result.size || result.weight" class="truncate text-xs text-muted-foreground">
                        <span v-if="result.size">Saiz {{ result.size }}</span>
                        <span v-if="result.size && result.weight"> &middot; </span>
                        <span v-if="result.weight">{{ result.weight }}g</span>
                    </p>
                </div>
                <span :class="cn(
                    'shrink-0 text-xs font-medium',
                    result.current_stock === 0 ? 'text-destructive' : 'text-success',
                )">
                    {{ result.current_stock }} unit
                </span>
            </button>

            <div v-if="!loading && query.trim().length >= 2" class="border-t p-2">
                <Button v-if="!webSearched" type="button" variant="ghost" size="sm"
                    class="w-full justify-start text-muted-foreground hover:text-blue-700" @mousedown.prevent="searchWebsite">
                    <Globe class="size-3.5" />
                    Tak jumpa? Cari di laman web merchant9.com
                </Button>

                <p v-else-if="webLoading" class="flex items-center gap-2 px-1 py-1 text-sm text-muted-foreground">
                    <Loader2 class="size-3.5 animate-spin" /> Mencari di laman web...
                </p>

                <template v-else>
                    <div v-if="webResults.length === 0" class="flex flex-col gap-1.5 px-1 py-1">
                        <p class="text-sm text-muted-foreground">Tiada hasil di laman web juga.</p>
                        <Button type="button" variant="secondary" size="sm" class="w-full justify-start"
                            @mousedown.prevent="selectManual">
                            <ImageUp class="size-3.5" /> Muat naik gambar sendiri
                        </Button>
                    </div>
                    <button v-for="(result, i) in webResults" :key="i" type="button"
                        class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm hover:bg-accent"
                        @mousedown.prevent="selectWeb(result)">
                        <img v-if="result.image_url" :src="result.image_url"
                            class="size-10 shrink-0 rounded object-cover" alt="" loading="lazy">
                        <div v-else class="size-10 shrink-0 rounded bg-muted" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ result.name }}</p>
                            <p v-if="result.category_label" class="truncate text-muted-foreground">
                                {{ result.category_label }}
                            </p>
                        </div>
                        <Badge variant="outline" class="shrink-0 gap-1 text-xs">
                            <Globe class="size-3" /> Laman Web
                        </Badge>
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
