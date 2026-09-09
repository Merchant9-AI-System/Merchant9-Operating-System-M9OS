<script setup lang="ts">
import { ChevronDown, Filter, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { CheckboxNative } from '@/components/ui/checkbox';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';

// Nilai/label WAJIB padan dgn BranchDemandEntryController::GOLD_TYPES / WEIGHT_RANGES / SIZE_RANGES.
const GOLD_TYPES = [
    { value: '9999', label: '9999' },
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
const props = defineProps<{
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

// Badge boleh-buang per nilai tapisan AKTIF - dipaparkan LUAR popover (kekal nampak walaupun
// popover tertutup, rujuk keperluan pengguna) supaya staf boleh buang SATU tapisan terus tanpa
// buka popover & cari checkbox tercatat. `key` kenal pasti model mana nilai tsb tergolong -
// removeFilter() bawah guna semula toggle() (sama logik dgn checkbox, cuma arah SEBALIK).
interface ActiveFilterBadge {
    key: 'goldTypes' | 'weightRanges' | 'sizeRanges' | 'categoryCodes';
    value: string;
    label: string;
}

const activeFilterBadges = computed<ActiveFilterBadge[]>(() => [
    ...goldTypes.value.map((v): ActiveFilterBadge => ({
        key: 'goldTypes', value: v, label: GOLD_TYPES.find((g) => g.value === v)?.label ?? v,
    })),
    ...weightRanges.value.map((v): ActiveFilterBadge => ({
        key: 'weightRanges', value: v, label: WEIGHT_RANGES.find((w) => w.value === v)?.label ?? v,
    })),
    ...sizeRanges.value.map((v): ActiveFilterBadge => ({
        key: 'sizeRanges', value: v, label: SIZE_RANGES.find((s) => s.value === v)?.label ?? v,
    })),
    ...categoryCodes.value.map((v): ActiveFilterBadge => ({
        key: 'categoryCodes', value: v, label: props.categories.find((c) => c.value === v)?.label ?? v,
    })),
]);

function removeFilter(badge: ActiveFilterBadge) {
    switch (badge.key) {
        case 'goldTypes':
            goldTypes.value = goldTypes.value.filter((v) => v !== badge.value);
            break;
        case 'weightRanges':
            weightRanges.value = weightRanges.value.filter((v) => v !== badge.value);
            break;
        case 'sizeRanges':
            sizeRanges.value = sizeRanges.value.filter((v) => v !== badge.value);
            break;
        case 'categoryCodes':
            categoryCodes.value = categoryCodes.value.filter((v) => v !== badge.value);
            break;
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button type="button" variant="ghost" size="sm" class="flex w-full justify-between">
                    <div class="flex items-start gap-2">
                        <Filter class="size-4" />
                        Tapis Carian{{ activeFilterCount > 0 ? ` (${activeFilterCount})` : '' }}
                    </div>
                    <div class="flex items-center gap-1">
                        <ChevronDown class="size-4 text-muted-foreground transition-transform"
                            :class="open ? 'rotate-180' : ''" />
                    </div>
                </Button>
            </PopoverTrigger>

            <PopoverContent class="flex w-full flex-col gap-3">
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

        <div v-if="activeFilterBadges.length > 0" class="flex flex-wrap gap-1.5">
            <Badge v-for="b in activeFilterBadges" :key="`${b.key}-${b.value}`" variant="outline"
                class="gap-1 py-0.5 pl-2 pr-1 text-xs text-amber-700 bg-amber-100 border-amber-300">
                {{ b.label }}
                <button type="button" class="rounded-full p-0.5 hover:bg-muted-foreground/20" @click="removeFilter(b)">
                    <X class="size-3" />
                    <span class="sr-only">Buang tapisan {{ b.label }}</span>
                </button>
            </Badge>
        </div>
    </div>
</template>
