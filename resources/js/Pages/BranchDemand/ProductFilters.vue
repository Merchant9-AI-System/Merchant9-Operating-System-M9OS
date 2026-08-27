<script setup lang="ts">
import { ChevronDown, ChevronUp, Filter } from '@lucide/vue';
import { computed, ref } from 'vue';
import { CheckboxNative } from '@/components/ui/checkbox';

// Nilai/label WAJIB padan dgn BranchDemandEntryController::GOLD_TYPES / WEIGHT_RANGES / SIZE_RANGES.
const GOLD_TYPES = [
    { value: '999', label: '999' },
    { value: '916', label: '916' },
    { value: '750', label: '750' },
    { value: '585', label: '585' },
    { value: '375', label: '375' },
    { value: '925', label: '925 (Perak)' },
];

const WEIGHT_RANGES = [
    { value: 'w_0_5', label: '< 5g' },
    { value: 'w_5_10', label: '5-10g' },
    { value: 'w_10_20', label: '10-20g' },
    { value: 'w_20_50', label: '20-50g' },
    { value: 'w_50_100', label: '50-100g' },
    { value: 'w_100_plus', label: '> 100g' },
];

const SIZE_RANGES = [
    { value: 's_0_10', label: '≤ 10' },
    { value: 's_10_15', label: '10-15' },
    { value: 's_15_20', label: '15-20' },
    { value: 's_20_plus', label: '> 20' },
];

// Kategori DINAMIK drpd server (rujuk BranchDemandEntryController::categoriesForSelect()) -
// HANYA kategori yg ada inventori sebenar, bukan senarai statik spt di atas.
defineProps<{
    categories: { value: string; label: string }[];
}>();

const goldTypes = defineModel<string[]>('goldTypes', { required: true });
const weightRanges = defineModel<string[]>('weightRanges', { required: true });
const sizeRanges = defineModel<string[]>('sizeRanges', { required: true });
const categoryCodes = defineModel<string[]>('categoryCodes', { required: true });

const filtersOpen = ref(false);

const activeFilterCount = computed(() => goldTypes.value.length + weightRanges.value.length
    + sizeRanges.value.length + categoryCodes.value.length);

function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <button type="button" class="flex px-2 rounded-md w-full items-center justify-between text-sm font-medium cursor-pointer hover:underline hover:bg-muted/50"
            @click="filtersOpen = !filtersOpen">
            <span class="flex items-center gap-2">
                <Filter class="size-4" />
                Tapis Carian{{ activeFilterCount > 0 ? ` (${activeFilterCount})` : '' }}
            </span>
            <ChevronUp v-if="filtersOpen" class="size-4 text-muted-foreground" />
            <ChevronDown v-else class="size-4 text-muted-foreground" />
        </button>

        <div v-if="filtersOpen" class="flex flex-col gap-3 rounded-md border p-3">
            <p class="text-xs text-muted-foreground">
                Tapisan ni dikongsi antara carian item &amp; Cadangan Restock.
            </p>

            <div>
                <p class="mb-1 text-xs font-medium text-muted-foreground">Jenis Emas/Perak</p>
                <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <label v-for="g in GOLD_TYPES" :key="g.value" class="flex items-center gap-1 text-xs">
                        <CheckboxNative :model-value="goldTypes.includes(g.value)"
                            @update:model-value="goldTypes = toggle(goldTypes, g.value)" />
                        {{ g.label }}
                    </label>
                </div>
            </div>

            <div>
                <p class="mb-1 text-xs font-medium text-muted-foreground">Julat Berat</p>
                <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <label v-for="w in WEIGHT_RANGES" :key="w.value" class="flex items-center gap-1 text-xs">
                        <CheckboxNative :model-value="weightRanges.includes(w.value)"
                            @update:model-value="weightRanges = toggle(weightRanges, w.value)" />
                        {{ w.label }}
                    </label>
                </div>
            </div>

            <div>
                <p class="mb-1 text-xs font-medium text-muted-foreground">Julat Saiz</p>
                <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <label v-for="s in SIZE_RANGES" :key="s.value" class="flex items-center gap-1 text-xs">
                        <CheckboxNative :model-value="sizeRanges.includes(s.value)"
                            @update:model-value="sizeRanges = toggle(sizeRanges, s.value)" />
                        {{ s.label }}
                    </label>
                </div>
            </div>

            <div v-if="categories.length > 0">
                <p class="mb-1 text-xs font-medium text-muted-foreground">Kategori</p>
                <div class="grid max-h-32 grid-cols-2 gap-x-3 gap-y-1 overflow-y-auto pr-1 sm:grid-cols-3">
                    <label v-for="c in categories" :key="c.value" class="flex items-center gap-1 text-xs">
                        <CheckboxNative :model-value="categoryCodes.includes(c.value)"
                            @update:model-value="categoryCodes = toggle(categoryCodes, c.value)" />
                        <span class="truncate" :title="c.label">{{ c.label }}</span>
                    </label>
                </div>
            </div>
        </div>
    </div>
</template>
