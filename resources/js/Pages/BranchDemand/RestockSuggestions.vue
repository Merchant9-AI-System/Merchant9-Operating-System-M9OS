<script setup lang="ts">
import { Loader2, Plus, TrendingUp } from '@lucide/vue';
import { ref, watch } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectNative } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge'

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
}>();

const emit = defineEmits<{
    (e: 'add', item: RestockSuggestion): void;
}>();

const period = ref('1w');
const items = ref<RestockSuggestion[]>([]);
const loading = ref(false);

// Tapisan (goldTypes/weightRanges/sizeRanges) DIKONGSI drpd parent (Create.vue via
// ProductFilters.vue) - panel ni "ikut" tapisan tsb (rujuk keperluan pengguna), bukan urus
// keadaan sendiri lagi.
watch(
    [() => props.storeCode, period, () => props.goldTypes, () => props.weightRanges, () => props.sizeRanges, () => props.categoryCodes],
    fetchSuggestions,
    { immediate: true, deep: true },
);

async function fetchSuggestions() {
    if (!props.storeCode) {
        items.value = [];

        return;
    }

    loading.value = true;

    try {
        const params = new URLSearchParams({ store_code: props.storeCode, period: period.value });
        props.goldTypes.forEach((v) => params.append('gold_types[]', v));
        props.weightRanges.forEach((v) => params.append('weight_ranges[]', v));
        props.sizeRanges.forEach((v) => params.append('size_ranges[]', v));
        props.categoryCodes.forEach((v) => params.append('category_codes[]', v));

        const response = await fetch(`/branch-demand/restock-suggestions?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        items.value = response.ok ? await response.json() : [];
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <TrendingUp class="size-4" /> Cadangan Restock
            </CardTitle>
            <CardDescription>Item paling banyak terjual di cawangan ini (ikut Tapis Carian di atas).</CardDescription>
            <SelectNative v-model="period" class="mt-2 h-8 text-xs">
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
            <p v-else-if="items.length === 0" class="text-sm text-muted-foreground">
                Tiada rekod jualan dlm tempoh/tapisan ini di cawangan ini.
            </p>
            <!-- Tinggi terhad ~10 baris, dikunci relatif viewport (min(...)) supaya panel ni
                 TIDAK PERNAH lebih tinggi drpd skrin yg boleh nampak - skrol DALAM panel sahaja
                 (ScrollArea shadcn-vue), bukan panjangkan/skrol seluruh halaman. -->
            <ScrollArea v-else class="h-[min(500px,calc(100vh-14rem))]">
                <div class="flex flex-col gap-2 pr-3">
                    <div v-for="item in items" :key="item.internal_code"
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
                </div>
            </ScrollArea>
        </CardContent>
    </Card>
</template>
