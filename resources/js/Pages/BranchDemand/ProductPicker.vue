<script setup lang="ts">
import { Globe, ImageUp, Loader2, Search, SearchX, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { cn } from '@/lib/utils';
import type { RestockSuggestion } from './RestockSuggestions.vue';
import Input from '@/components/ui/input/Input.vue';

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
    // Cadangan Restock (sidebar RestockSuggestions.vue, sumber SAMA) - dipaparkan sbg cadangan
    // AWAL bila dialog carian baru dibuka/teks dikosongkan (query kosong), supaya staf terus
    // nampak apa yg PALING perlu direstock tanpa perlu taip dulu. Opsyenal - kalau parent tak
    // hantar, sekadar tiada cadangan awal (carian remote tetap berfungsi macam biasa).
    restockSuggestions?: RestockSuggestion[];
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

// 6 item PALING perlu direstock (stok 0) drpd Cadangan Restock - cadangan awal bila carian
// kosong (rujuk template, gated `query.trim().length === 0`).
const defaultSuggestions = computed(() => (props.restockSuggestions ?? [])
    .filter((r) => r.current_stock === 0)
    .slice(0, 6));

function selectSuggestion(item: RestockSuggestion): void {
    select({
        internal_code: item.internal_code,
        description: item.description,
        category_name: item.category_name,
        current_stock: item.current_stock,
        size: item.size,
        weight: item.weight,
        nickname: null,
        image_url: item.image_url,
    });
}

const query = ref(props.modelValue);
const results = ref<ProductSearchResult[]>([]);
const open = ref(false);
const loading = ref(false);
// "Muat Lagi" (rujuk BranchDemandEntryController::search() dokblok "MUAT LAGI") - hasMore drpd
// respons server, loadingMore state BERASINGAN drpd `loading` (list SEDIA ADA kekal dipaparkan
// semasa "Muat Lagi" berjalan, bukan digantikan mesej "Mencari..." spt carian baharu).
const hasMore = ref(false);
const loadingMore = ref(false);
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
        hasMore.value = false;

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

// append=false (carian baharu/pertama, offset=0) GANTI `results`; append=true ("Muat Lagi",
// offset=results.value.length) TAMBAH kpd `results` sedia ada - rujuk loadMore() bawah.
async function fetchResults(value: string, append = false) {
    if (!props.storeCode) {
        return;
    }

    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
    }

    try {
        const params = new URLSearchParams({
            q: value,
            store_code: props.storeCode,
            offset: append ? String(results.value.length) : '0',
        });
        (props.goldTypes ?? []).forEach((v) => params.append('gold_types[]', v));
        (props.weightRanges ?? []).forEach((v) => params.append('weight_ranges[]', v));
        (props.sizeRanges ?? []).forEach((v) => params.append('size_ranges[]', v));
        (props.categoryCodes ?? []).forEach((v) => params.append('category_codes[]', v));

        const response = await fetch(`/branch-demand/search?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const data = response.ok ? await response.json() : { results: [], has_more: false };
        results.value = append ? [...results.value, ...data.results] : data.results;
        hasMore.value = data.has_more;
    } finally {
        if (append) {
            loadingMore.value = false;
        } else {
            loading.value = false;
        }
    }
}

function loadMore() {
    if (loadingMore.value || !hasMore.value) {
        return;
    }

    fetchResults(query.value, true);
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
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-start gap-2">

            <div class="relative flex-1">
                <Search class="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input :model-value="query" placeholder="Cari kod design, keterangan atau kategori..."
                    autocomplete="off" :disabled="disabled || !storeCode" @click="open = true" class="pl-10 pr-8" />
                <Button v-show="query.length > 0 && !loading" type="button" variant="ghost" size="icon"
                    class="absolute right-1 top-1 size-7" @click="clear">
                    <X class="size-4" />
                    <span class="sr-only">Buang carian</span>
                </Button>
            </div>

            <Button v-if="storeCode && !disabled" type="button" @mousedown.prevent="selectManual">
                <ImageUp class="size-3.5" />
                Manual Upload?
            </Button>
        </div>

        <!-- :should-filter="false" - carian ni REMOTE/server (padanan across internal_code/
             description/kategori/nickname), BUKAN literal substring teks yg dipaparkan - filter
             tempatan Command (contains() drpd useFilter) akan sorok hasil server yg SAH secara
             senyap kalau dibiar aktif (rujuk Command.vue). -->
        <CommandDialog :should-filter="false" v-model:open="open" title="Cari & Tambah Item"
            description="Taip kod design, keterangan atau kategori utk cari item">
            <div class="relative">
                <CommandInput v-model="query" placeholder="Cari kod design, keterangan atau kategori..." class="pr-8"
                    autocomplete="off" />
                <Button v-show="query.length > 0 && !loading" type="button" variant="ghost" size="icon"
                    class="absolute right-1 top-1 size-7" @click="clear">
                    <X class="size-4" />
                    <span class="sr-only">Buang carian</span>
                </Button>
            </div>

            <CommandList>
                <!-- Cadangan awal (query KOSONG - baru buka dialog / teks dikosongkan) - 6 item
                         paling perlu direstock (stok 0) drpd Cadangan Restock, supaya staf terus
                         nampak cadangan tanpa perlu taip dulu. -->
                <CommandGroup v-if="query.trim().length === 0 && defaultSuggestions.length > 0"
                    heading="Cadangan Restock (Stok Habis)">
                    <CommandItem v-for="item in defaultSuggestions" :key="item.internal_code"
                        :value="item.internal_code" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm"
                        @select="selectSuggestion(item)">
                        <img v-if="item.image_url" :src="item.image_url" class="size-10 shrink-0 rounded object-cover"
                            alt="" loading="lazy">
                        <div v-else class="size-10 shrink-0 rounded bg-muted" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ item.internal_code }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ item.description }} &middot; {{
                                item.category_name }}</p>
                            <p v-if="item.size || item.weight" class="truncate text-xs text-muted-foreground">
                                <span v-if="item.size">Saiz {{ item.size }}</span>
                                <span v-if="item.size && item.weight"> &middot; </span>
                                <span v-if="item.weight">{{ item.weight }}g</span>
                            </p>
                        </div>
                        <span class="shrink-0 text-xs font-medium text-destructive">{{ item.current_stock }} unit</span>
                    </CommandItem>
                </CommandGroup>

                <p v-if="loading" class="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
                    <Loader2 class="size-3.5 animate-spin" /> Mencari...
                </p>
                <CommandEmpty v-if="!loading" class="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
                    <SearchX class="size-3.5" /> Tiada hasil dijumpai.
                </CommandEmpty>
                <!-- CommandGroup WAJIB - CommandItem panggil useCommandGroup() dalaman (context
                         inject, bukan sekadar heading visual), throw error senyap kalau tiada
                         pembalut CommandGroup (disahkan sebenar - "Injection ... not found"). -->
                <CommandGroup>
                    <CommandItem v-for="result in results" :key="result.internal_code" :value="result.internal_code"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm" @select="select(result)">
                        <img v-if="result.image_url" :src="result.image_url"
                            class="size-10 shrink-0 rounded object-cover" alt="" loading="lazy">
                        <div v-else class="size-10 shrink-0 rounded bg-muted" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ result.internal_code }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ result.description }} &middot; {{
                                result.category_name
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
                    </CommandItem>
                </CommandGroup>

                <div v-if="hasMore" class="p-2">
                    <Button v-if="hasMore && !loading" type="button" :disabled="loadingMore"
                        @mousedown.prevent="loadMore" size="sm" class="w-full">
                        <div v-if="loadingMore">
                            <Loader2 class="size-3.5 animate-spin" />
                        </div>
                        <div v-else>
                            <Search class="size-3.5" />
                        </div>
                        {{ loadingMore ? 'Memuatkan...' : 'Muat Lagi' }}
                    </Button>
                </div>

                <div v-if="!loading && query.trim().length >= 2" class="border-t p-2">
                    <Button v-if="!webSearched" type="button" variant="ghost" size="sm"
                        class="w-full justify-start text-muted-foreground hover:text-blue-700"
                        @mousedown.prevent="searchWebsite">
                        <Globe class="size-3.5" />
                        Tak jumpa? Cari di laman web merchant9.com
                    </Button>

                    <p v-else-if="webLoading" class="flex items-center gap-2 px-1 py-1 text-sm text-muted-foreground">
                        <Loader2 class="size-3.5 animate-spin" /> Mencari di laman web...
                    </p>

                    <template v-else>
                        <div v-if="webResults.length === 0" class="flex flex-col gap-1.5 px-1 py-1">
                            <SearchX class="size-3.5" />
                            <p class="text-sm text-muted-foreground">
                                Tiada hasil di laman web juga.
                            </p>
                            <Button type="button" variant="secondary" size="sm" class="w-full justify-start"
                                @mousedown.prevent="selectManual">
                                <ImageUp class="size-3.5" /> Muat naik gambar sendiri
                            </Button>
                        </div>
                        <CommandGroup>
                            <CommandItem v-for="(result, i) in webResults" :key="i" :value="`web-${i}-${result.name}`"
                                class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm"
                                @select="selectWeb(result)">
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
                            </CommandItem>
                        </CommandGroup>
                    </template>
                </div>
            </CommandList>
        </CommandDialog>
    </div>
</template>
