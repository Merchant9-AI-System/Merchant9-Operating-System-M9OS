<script setup lang="ts">
import { InfiniteScroll, router } from '@inertiajs/vue3';
import { Loader2, Plus, Search, TrendingUp } from '@lucide/vue';
import { ref, watch } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectNative } from '@/components/ui/select';

export interface RestockSuggestion {
    internal_code: string;
    description: string;
    category_name: string;
    current_stock: number;
    qty_sold: number;
    size: string;
    weight: number;
    image_url: string | null;
}

// Tempoh KEKAL kawalan tersendiri di sini (BUKAN dikongsi via ProductFilters.vue "Tapis
// Carian") - carian item umum (search()) tiada konsep tempoh langsung, hanya
// restockSuggestions() guna param ni, jadi ia bukan tapisan "kongsi" spt gold type/berat/
// saiz/kategori (rujuk BranchDemandEntryController::RESTOCK_PERIODS).
const PERIODS = [
    { value: '1w', label: '1 Minggu' },
    { value: '1m', label: '1 Bulan' },
    { value: '3m', label: '3 Bulan' },
    { value: '6m', label: '6 Bulan' },
    { value: '1y', label: '1 Tahun' },
];

const props = defineProps<{
    storeCode: string | null;
    goldTypes: string[];
    weightRanges: string[];
    sizeRanges: string[];
    categoryCodes: string[];
    // Prop halaman Inertia::scroll() (rujuk Create.vue ScrollProp<T> & BranchDemandEntryController
    // ::restockSuggestionsPaginator()) - dihantar TURUN drpd Create.vue (Page component) SBB
    // <InfiniteScroll data="restockSuggestions"> bawah baca terus drpd usePage() page-level
    // props (padanan nama kunci), BUKAN prop Vue biasa - tapi kita tetap perlukan .data di sini
    // utk render v-for.
    restockSuggestions: { data: RestockSuggestion[] };
}>();

const emit = defineEmits<{
    (e: 'add', item: RestockSuggestion): void;
}>();

const period = ref('1w');
const loading = ref(false);

// Tapisan (goldTypes/weightRanges/sizeRanges/period) DIKONGSI drpd parent (Create.vue via
// ProductFilters.vue) - bila SEBARANG berubah, minta SEMULA prop 'restockSuggestions' drpd
// server (Inertia::scroll(), rujuk restockSuggestionsPaginator()) via router.reload() - BUKAN
// fetch() mentah lagi (WAJIB utk <InfiniteScroll> berfungsi, ia baca drpd page props Inertia,
// bukan state Vue tempatan). reset:['restockSuggestions'] WAJIB - tanpanya hasil baharu akan
// digabung (merge) dgn senarai lama, bukan ganti bersih (rujuk protokol Inertia "Resetting").
watch(
    [() => props.storeCode, period, () => props.goldTypes, () => props.weightRanges, () => props.sizeRanges, () => props.categoryCodes],
    () => {
        if (!props.storeCode) {
            return;
        }

        loading.value = true;

        router.reload({
            only: ['restockSuggestions'],
            reset: ['restockSuggestions'],
            data: {
                store_code: props.storeCode,
                period: period.value,
                gold_types: props.goldTypes,
                weight_ranges: props.weightRanges,
                size_ranges: props.sizeRanges,
                category_codes: props.categoryCodes,
                // WAJIB paksa balik ke m/s 1 di sini - router.reload() guna semula URL semasa,
                // jadi kalau staf dah scroll jauh (cth. ?page=30 tersegerak URL drpd InfiniteScroll
                // sebelum tapisan ditukar), tanpa ni page=30 lapuk terbawa terus ke tapisan
                // BAHARU - boleh terlepas julat (keputusan KOSONG) kalau tapisan baharu kurang
                // padanan drpd yg lama (disahkan sebenar - rujuk BranchDemandEntryController::
                // restockSuggestionsPaginator() dokblok utk kes larian PENUH/reload browser).
                page: 1,
            },
            onFinish: () => {
                loading.value = false;
            },
        });
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <TrendingUp class="size-4" /> Cadangan Restock
            </CardTitle>
            <CardAction>
                <Badge class="h-5 min-w-5 rounded-full px-1 tabular-nums">{{ restockSuggestions.data.length }}</Badge>
            </CardAction>
            <CardDescription>Item paling banyak terjual di cawangan ini (ikut Tapis Carian di atas).</CardDescription>
            <SelectNative v-model="period" class="mt-2 w-full h-8 text-xs">
                <option v-for="p in PERIODS" :key="p.value" :value="p.value">
                    {{ p.label }}
                </option>
            </SelectNative>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <p v-if="!storeCode" class="text-sm text-muted-foreground">Pilih cawangan dahulu.</p>
            <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 class="size-3.5 animate-spin" /> Memuatkan...
            </p>
            <p v-else-if="restockSuggestions.data.length === 0" class="text-sm text-muted-foreground">
                Tiada rekod jualan dlm tempoh/tapisan ini di cawangan ini.
            </p>
            <!-- Tinggi terhad ~10 baris, dikunci relatif viewport (min(...)) supaya panel ni
                 TIDAK PERNAH lebih tinggi drpd skrin yg boleh nampak - skrol DALAM panel sahaja
                 (ScrollArea shadcn-vue), bukan panjangkan/skrol seluruh halaman. InfiniteScroll
                 (rujuk dokblok <script>) mengesan scroll DALAM ScrollArea ni sendiri (bukan
                 dokumen penuh) - minta "halaman" seterusnya drpd server bila pengguna scroll
                 hampir bawah, hasil digabung terus ke restockSuggestions.data. manual-after="2" -
                 2 halaman PERTAMA auto-scroll (mulus), lepas tu TUKAR ke butang "Muat Lagi"
                 (slot #next) - elak scroll tanpa henti muatkan berpuluh2 halaman tanpa disedari
                 staf (rujuk perbincangan pengguna: infinite scroll vs butang "Load More" - dua2
                 mekanisme SAMA, cuma pencetus berbeza; InfiniteScroll Inertia sokong kedua2 serentak). -->
            <ScrollArea v-else class="h-[min(500px,calc(100vh-14rem))]">
                <InfiniteScroll data="restockSuggestions" as="div" class="flex flex-col gap-2 pr-3" :manual-after="1">
                    <div v-for="item in restockSuggestions.data" :key="item.internal_code"
                        class="flex items-center gap-3 rounded-md border p-2 text-sm">
                        <ImagePreview :src="item.image_url" :alt="item.description" class="size-10" />
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2">
                                <span class="truncate font-medium text-sm">
                                    {{ item.internal_code }}
                                </span>
                                <Badge variant="secondary"
                                    class="bg-amber-50 border border-amber-300 text-amber-700 text-[10px] px-1 py-0">
                                    Terjual: {{ item.qty_sold }}
                                </Badge>
                            </p>
                            <p class="truncate text-muted-foreground text-xs">{{ item.description }}</p>
                            <p class="text-xs text-muted-foreground tracking-wide">
                                <span>
                                    Saiz {{ item.size }}
                                </span>
                                &middot;
                                <span>
                                    {{ item.weight }}g
                                </span>
                                &middot;
                                <span :class="item.current_stock === 0 ? 'text-destructive font-medium' : ''">
                                    Stok {{ item.current_stock }}
                                </span>
                            </p>
                        </div>
                        <Button size="icon" variant="ghost" @click="emit('add', item)">
                            <Plus class="size-2" />
                            <span class="sr-only">Tambah ke senarai</span>
                        </Button>
                    </div>

                    <template #next="{ loading: loadingMore, fetch, hasMore }">
                        <div class="mt-2 pr-3">
                            <Button v-if="hasMore" size="sm" class="w-full" :disabled="loadingMore"
                                @click="fetch">
                                <div v-if="loadingMore">
                                    <Loader2 class="size-3.5 animate-spin" />
                                </div>
                                <div v-else>
                                    <Search class="size-3.5" />
                                </div>
                                {{ loadingMore ? 'Memuatkan...' : 'Muat Lagi' }}
                            </Button>
                        </div>
                    </template>
                </InfiniteScroll>
            </ScrollArea>
        </CardContent>
    </Card>
</template>
