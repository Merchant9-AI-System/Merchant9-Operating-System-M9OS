<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronsUpDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';

// Pengepala lajur boleh susun (corak DataTableColumnHeader contoh "tasks" shadcn-vue):
// klik = menaik -> menurun -> tiada susunan.
defineProps<{
    title: string;
    direction: 'asc' | 'desc' | null;
}>();

defineEmits<{
    sort: [];
}>();
</script>

<template>
    <Button type="button" variant="ghost" size="sm" class="-ml-3 h-8 data-[active=true]:text-foreground"
        :data-active="direction !== null" @click="$emit('sort')">
        <span>{{ title }}</span>
        <ArrowUp v-if="direction === 'asc'" class="size-3.5" />
        <ArrowDown v-else-if="direction === 'desc'" class="size-3.5" />
        <ChevronsUpDown v-else class="size-3.5 opacity-60" />
    </Button>
</template>
