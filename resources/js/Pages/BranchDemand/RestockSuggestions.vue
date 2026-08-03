<script setup lang="ts">
import { Loader2, Plus, TrendingUp } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';

export interface RestockSuggestion {
    internal_code: string;
    description: string;
    category_name: string;
    current_stock: number;
    qty_sold_3m: number;
    image_url: string | null;
}

const props = defineProps<{ storeCode: string | null }>();

const emit = defineEmits<{
    (e: 'add', item: RestockSuggestion): void;
}>();

const items = ref<RestockSuggestion[]>([]);
const loading = ref(false);

watch(() => props.storeCode, fetchSuggestions, { immediate: true });

async function fetchSuggestions() {
    if (!props.storeCode) {
        items.value = [];

        return;
    }

    loading.value = true;

    try {
        const params = new URLSearchParams({ store_code: props.storeCode });
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
            <CardDescription>Item paling banyak terjual 3 bulan lepas di cawangan ini.</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <p v-if="!storeCode" class="text-sm text-muted-foreground">Pilih cawangan dahulu.</p>
            <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 class="size-3.5 animate-spin" /> Memuatkan...
            </p>
            <p v-else-if="items.length === 0" class="text-sm text-muted-foreground">
                Tiada rekod jualan dlm 3 bulan kebelakangan di cawangan ini.
            </p>
            <!-- Tinggi terhad ~10 baris, dikunci relatif viewport (min(...)) supaya panel ni
                 TIDAK PERNAH lebih tinggi drpd skrin yg boleh nampak - skrol DALAM panel sahaja
                 (ScrollArea shadcn-vue), bukan panjangkan/skrol seluruh halaman. -->
            <ScrollArea v-else class="h-[min(500px,calc(100vh-14rem))]">
                <div class="flex flex-col gap-2 pr-3">
                    <div
                        v-for="item in items"
                        :key="item.internal_code"
                        class="flex items-center gap-3 rounded-md border p-2 text-sm"
                    >
                        <img
                            v-if="item.image_url"
                            :src="item.image_url"
                            class="size-10 shrink-0 rounded object-cover"
                            alt=""
                            loading="lazy"
                        >
                        <div v-else class="size-10 shrink-0 rounded bg-muted" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ item.internal_code }}</p>
                            <p class="truncate text-muted-foreground">{{ item.description }}</p>
                            <p class="text-xs text-muted-foreground">
                                Terjual {{ item.qty_sold_3m }}x &middot;
                                <span :class="item.current_stock === 0 ? 'text-destructive' : ''">Stok {{ item.current_stock }}</span>
                            </p>
                        </div>
                        <Button size="icon" variant="outline" class="shrink-0" @click="emit('add', item)">
                            <Plus class="size-3" />
                            <span class="sr-only">Tambah ke senarai</span>
                        </Button>
                    </div>
                </div>
            </ScrollArea>
        </CardContent>
    </Card>
</template>
