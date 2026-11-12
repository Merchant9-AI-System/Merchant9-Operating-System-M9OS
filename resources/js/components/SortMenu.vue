<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown, Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { MAX_SORTS, nextSorts } from '@/lib/supplierLookup';
import type { SortSpec } from '@/lib/supplierLookup';

// Menu "Susun mengikut" BERBILANG - menggunakan keadaan (v-model) yg SAMA dgn klik pada tajuk lajur jadual, jadi keduanya sentiasa
// bersegerak: lajur yg dipilih di sini muncul pada tajuk jadual (dgn nombor keutamaan) dan sebaliknya. Klik baris = masuk/keluar senarai
// (urutan dipilih = keutamaan, kunci pertama paling utama); butang anak panah = menaik/menurun.
const props = defineProps<{
    // Lajur yg boleh disusun: key = kunci susunan, label = tajuk lajur.
    options: { key: string; label: string }[];
}>();

const sorts = defineModel<SortSpec[]>({ required: true });

const open = ref(false);

const labelOf = (key: string) => props.options.find((option) => option.key === key)?.label ?? key;

const summary = computed(() => {
    if (sorts.value.length === 0) {
        return 'Susun mengikut';
    }

    if (sorts.value.length === 1) {
        return labelOf(sorts.value[0].key);
    }

    return `Susunan (${sorts.value.length})`;
});

function specOf(key: string): SortSpec | undefined {
    return sorts.value.find((spec) => spec.key === key);
}

function priorityOf(key: string): number {
    return sorts.value.findIndex((spec) => spec.key === key) + 1;
}

// Klik baris: belum dipilih -> tambah (menaik); sudah dipilih -> buang.
function toggle(key: string) {
    if (specOf(key)) {
        sorts.value = sorts.value.filter((spec) => spec.key !== key);

        return;
    }

    sorts.value = nextSorts(sorts.value, key as SortSpec['key']);
}

function flip(key: string) {
    sorts.value = sorts.value.map((spec) => (spec.key === key ? { ...spec, dir: spec.dir === 'asc' ? 'desc' : 'asc' } : spec));
}

function clear() {
    sorts.value = [];
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" variant="outline" size="sm" class="bg-card font-normal border-dashed"
                aria-label="Susun mengikut">
                <span class="flex min-w-0 items-center gap-1.5">
                    <ArrowUpDown class="size-4 shrink-0" />
                    <span class="truncate">{{ summary }}</span>
                    <template v-if="sorts.length === 1">
                        <ArrowUp v-if="sorts[0].dir === 'asc'" class="size-3.5 shrink-0" />
                        <ArrowDown v-else class="size-3.5 shrink-0" />
                    </template>
                </span>
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-72 p-1" align="start">
            <p class="px-2 py-1.5 text-xs text-muted-foreground">
                Pilih beberapa lajur. Yang dipilih dahulu paling utama; seri disusun oleh lajur seterusnya.
            </p>

            <div v-for="option in options" :key="option.key"
                class="flex items-center gap-1 rounded-sm hover:bg-accent hover:text-accent-foreground">
                <button type="button" role="menuitemcheckbox" :aria-checked="!!specOf(option.key)"
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm focus-visible:bg-accent focus-visible:outline-none"
                    @click="toggle(option.key)">
                    <span :class="cn(
                        'flex size-4 shrink-0 items-center justify-center rounded-sm border border-primary',
                        specOf(option.key) ? 'bg-primary text-primary-foreground' : 'opacity-50 [&_svg]:invisible',
                    )">
                        <Check class="size-3.5" />
                    </span>
                    <span class="truncate">{{ option.label }}</span>
                    <span v-if="specOf(option.key) && sorts.length > 1" aria-hidden="true"
                        class="ml-auto inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] font-semibold leading-none text-primary-foreground tabular-nums">
                        {{ priorityOf(option.key) }}
                    </span>
                </button>

                <Button v-if="specOf(option.key)" type="button" variant="ghost" size="icon" class="mr-1 size-7 shrink-0"
                    :aria-label="`Tukar arah susunan ${option.label} (kini ${specOf(option.key)?.dir === 'asc' ? 'menaik' : 'menurun'})`"
                    @click="flip(option.key)">
                    <ArrowUp v-if="specOf(option.key)?.dir === 'asc'" class="size-4" />
                    <ArrowDown v-else class="size-4" />
                </Button>
            </div>

            <p v-if="sorts.length >= MAX_SORTS" class="px-2 py-1 text-xs text-muted-foreground">Had {{ MAX_SORTS }} lajur dicapai.</p>

            <template v-if="sorts.length > 0">
                <Separator class="my-1" />
                <button type="button"
                    class="w-full rounded-sm px-2 py-1.5 text-center text-sm hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:outline-none"
                    @click="clear">
                    Kosongkan susunan
                </button>
            </template>
        </PopoverContent>
    </Popover>
</template>
