<script setup lang="ts">
import { ChevronDown, Filter } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { CheckboxNative } from '@/components/ui/checkbox';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { GOLD_TYPES, SIZE_RANGES, WEIGHT_RANGES } from './productFilterOptions';

// Kategori DINAMIK drpd server (rujuk BranchDemandEntryController::categoriesForSelect()) -
// HANYA kategori yg ada inventori sebenar, bukan senarai statik spt di atas.
defineProps<{
    categories: { value: string; label: string }[];
}>();

const goldTypes = defineModel<string[]>('goldTypes', { required: true });
const weightRanges = defineModel<string[]>('weightRanges', { required: true });
const sizeRanges = defineModel<string[]>('sizeRanges', { required: true });
const categoryCodes = defineModel<string[]>('categoryCodes', { required: true });

const open = ref(false);

const activeFilterCount = computed(() => goldTypes.value.length + weightRanges.value.length
    + sizeRanges.value.length + categoryCodes.value.length);

function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" variant="outline" size="sm">
                <div class="flex items-start gap-2">
                    <Filter class="size-4" />
                    Filter{{ activeFilterCount > 0 ? ` (${activeFilterCount})` : '' }}
                </div>
                <div class="flex items-center gap-1">
                    <ChevronDown class="size-4 text-muted-foreground transition-transform"
                        :class="open ? 'rotate-180' : ''" />
                </div>
            </Button>
        </PopoverTrigger>

        <PopoverContent class="flex w-full flex-col gap-3" align="end">
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
        </PopoverContent>
    </Popover>
</template>
