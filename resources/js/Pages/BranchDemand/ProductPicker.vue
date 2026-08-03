<script setup lang="ts">
import { Loader2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface ProductSearchResult {
    internal_code: string;
    description: string;
    category_name: string;
    current_stock: number;
    image_url: string | null;
}

const props = defineProps<{
    modelValue: string;
    storeCode: string | null;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'select', result: ProductSearchResult): void;
}>();

const query = ref(props.modelValue);
const results = ref<ProductSearchResult[]>([]);
const open = ref(false);
const loading = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

watch(query, (value) => {
    emit('update:modelValue', value);

    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    if (value.trim().length < 2 || !props.storeCode) {
        results.value = [];
        open.value = false;

        return;
    }

    debounceTimer = setTimeout(() => fetchResults(value), 300);
});

async function fetchResults(value: string) {
    if (!props.storeCode) {
        return;
    }

    loading.value = true;
    open.value = true;

    try {
        const params = new URLSearchParams({ q: value, store_code: props.storeCode });
        const response = await fetch(`/branch-demand/search?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        results.value = response.ok ? await response.json() : [];
    } finally {
        loading.value = false;
    }
}

function select(result: ProductSearchResult) {
    query.value = `${result.internal_code} - ${result.description}`;
    open.value = false;
    emit('select', result);
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
        <Input
            v-model="query"
            :disabled="disabled || !storeCode"
            :placeholder="storeCode ? 'Cari kod design, keterangan atau kategori...' : 'Pilih cawangan dahulu'"
            autocomplete="off"
            @focus="open = results.length > 0"
            @blur="onBlur"
        />
        <div
            v-if="open"
            class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow-md"
        >
            <p v-if="loading" class="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
                <Loader2 class="size-3.5 animate-spin" /> Mencari...
            </p>
            <p v-else-if="results.length === 0" class="px-3 py-2 text-sm text-muted-foreground">
                Tiada hasil dijumpai.
            </p>
            <button
                v-for="result in results"
                :key="result.internal_code"
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-accent"
                @mousedown.prevent="select(result)"
            >
                <img
                    v-if="result.image_url"
                    :src="result.image_url"
                    class="size-10 shrink-0 rounded object-cover"
                    alt=""
                    loading="lazy"
                >
                <div v-else class="size-10 shrink-0 rounded bg-muted" />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ result.internal_code }}</p>
                    <p class="truncate text-muted-foreground">{{ result.description }} &middot; {{ result.category_name }}</p>
                </div>
                <span
                    :class="cn(
                        'shrink-0 text-xs font-medium',
                        result.current_stock === 0 ? 'text-destructive' : 'text-success',
                    )"
                >
                    {{ result.current_stock }} unit
                </span>
            </button>
        </div>
    </div>
</template>
