<script setup lang="ts">
import { computed, ref } from 'vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import type { BranchFigures } from '@/lib/supplierLookup';

// Angka jumlah (cth. Total Sold) yg, bila ada pecahan cawangan, boleh diklik utk papar pecahan ikut cawangan dlm popover
// (DAMAI 3, TTDI 2...). Hanya cawangan yg ada nilai (> 0). Tiada pecahan (mod cawangan: baris itu sendiri satu cawangan) = angka biasa.
const props = defineProps<{
    value: number;
    figures: BranchFigures[];
    field: 'buy' | 'sold' | 'available' | 'requested';
    // Tajuk lajur - dipaparkan sbg tajuk popover & nama aksesibel butang.
    title: string;
}>();

// Popover.vue mengisytiharkan `open` sbg prop boolean - tanpa v-model:open ia terkunci tertutup (sama spt FacetedFilter).
const open = ref(false);

const breakdown = computed(() => props.figures.filter((figure) => figure[props.field] > 0));

const format = (number: number) => number.toLocaleString('en-MY');
</script>

<template>
    <Popover v-if="breakdown.length > 0" v-model:open="open">
        <PopoverTrigger as-child>
            <button type="button" :aria-label="`${title}: ${format(value)}. Lihat pecahan ikut cawangan`"
                class="cursor-pointer rounded-sm px-0 tabular-nums sm:px-1 underline decoration-muted-foreground/60 decoration-dotted underline-offset-4 hover:bg-accent hover:decoration-foreground focus-visible:outline-2 focus-visible:outline-ring data-[state=open]:bg-accent">
                {{ format(value) }}
            </button>
        </PopoverTrigger>

        <PopoverContent class="w-52 p-2" align="end">
            <p class="px-1 pb-1.5 text-xs font-medium text-muted-foreground">{{ title }} ikut cawangan</p>

            <ul class="flex flex-col">
                <li v-for="figure in breakdown" :key="figure.store"
                    class="flex items-center justify-between gap-3 rounded-sm px-1 py-1 text-sm">
                    <span class="truncate">{{ figure.store }}</span>
                    <span class="font-medium tabular-nums">{{ format(figure[field]) }}</span>
                </li>
            </ul>

            <div v-if="breakdown.length > 1"
                class="mt-1 flex items-center justify-between gap-3 border-t px-1 pt-1.5 text-sm font-semibold">
                <span>Jumlah</span>
                <span class="tabular-nums">{{ format(value) }}</span>
            </div>
        </PopoverContent>
    </Popover>

    <span v-else class="px-0 tabular-nums sm:px-1">{{ format(value) }}</span>
</template>
