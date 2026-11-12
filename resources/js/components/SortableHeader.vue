<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronsUpDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';

// Pengepala lajur boleh susun (corak DataTableColumnHeader contoh "tasks" shadcn-vue):
// klik = menaik -> menurun -> tiada susunan.
//
// Susunan BERBILANG (pilihan, hanya bila induk menggunakannya): `priority` = nombor keutamaan (1, 2, 3...) yg dipaparkan bila lebih
// drpd satu lajur disusun. Induk yg tak peduli (cth. Back Office Actions) tak menghantarnya, jadi paparannya tak berubah.
defineProps<{
    title: string;
    direction: 'asc' | 'desc' | null;
    // Nama penuh bila `title` dipendekkan (cth. skrin kecil) - jadi nama aksesibel & tooltip butang.
    fullTitle?: string;
    priority?: number | null;
}>();

defineEmits<{
    sort: [];
}>();
</script>

<template>
    <Button type="button" variant="ghost" size="sm" class="-ml-3 h-8 data-[active=true]:text-foreground"
        :data-active="direction !== null" :aria-label="fullTitle" :title="fullTitle" @click="$emit('sort')">
        <span>{{ title }}</span>
        <ArrowUp v-if="direction === 'asc'" class="size-3.5" />
        <ArrowDown v-else-if="direction === 'desc'" class="size-3.5" />
        <ChevronsUpDown v-else class="size-3.5 opacity-60" />
        <span v-if="priority" aria-hidden="true"
            class="inline-flex size-4 items-center justify-center rounded-full bg-primary text-[10px] font-semibold leading-none text-primary-foreground tabular-nums">
            {{ priority }}
        </span>
    </Button>
</template>
