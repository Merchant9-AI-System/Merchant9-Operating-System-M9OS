<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { GOLD_TYPES, SIZE_RANGES, WEIGHT_RANGES } from './productFilterOptions';

// Badge boleh-buang per nilai tapisan AKTIF drpd ProductFilters.vue (rujuk keperluan pengguna:
// dipaparkan di CardContent, ATAS ProductPicker - BUKAN lagi di dalam ProductFilters.vue sendiri,
// yg kini cuma butang+popover). `key` kenal pasti model mana nilai tsb tergolong - removeFilter()
// bawah buang terus drpd array v-model berkenaan.
interface ActiveFilterBadge {
    key: 'goldTypes' | 'weightRanges' | 'sizeRanges' | 'categoryCodes';
    value: string;
    label: string;
}

const props = defineProps<{
    categories: { value: string; label: string }[];
}>();

const goldTypes = defineModel<string[]>('goldTypes', { required: true });
const weightRanges = defineModel<string[]>('weightRanges', { required: true });
const sizeRanges = defineModel<string[]>('sizeRanges', { required: true });
const categoryCodes = defineModel<string[]>('categoryCodes', { required: true });

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
    <div v-if="activeFilterBadges.length > 0" class="flex flex-wrap gap-1.5">
        <Badge v-for="b in activeFilterBadges" :key="`${b.key}-${b.value}`" variant="outline"
            class="gap-1 border-amber-300 bg-amber-100 py-0.5 pl-2 pr-1 text-xs text-amber-700">
            {{ b.label }}
            <button type="button" class="rounded-full p-0.5 hover:bg-muted-foreground/20" @click="removeFilter(b)">
                <X class="size-3" />
                <span class="sr-only">Buang tapisan {{ b.label }}</span>
            </button>
        </Badge>
    </div>
</template>
