<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// Badge bulat pintasan senarai (satu klik = Cadangan + susunan) - gaya sama dgn "Template susunan" Supplier Lookup. Satu baris bertatal ufuk
// pada skrin kecil. Definisi pintasan & logik aktif berada di halaman (Index.vue); komponen ini hanya memaparkan & memberitahu pilihan.
interface Chip {
    key: string;
    label: string;
    hint: string;
}

defineProps<{
    chips: Chip[];
    active: string | null;
    disabled?: boolean;
}>();

defineEmits<{ select: [key: string] }>();
</script>

<template>
    <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0" role="group" aria-label="Pintasan senarai">
        <Button
            v-for="chip in chips"
            :key="chip.key"
            type="button"
            size="sm"
            :variant="active === chip.key ? 'default' : 'outline'"
            :class="cn('h-7 shrink-0 rounded-full px-3 text-xs font-medium', active !== chip.key && 'bg-card')"
            :aria-pressed="active === chip.key"
            :title="chip.hint"
            :disabled="disabled"
            @click="$emit('select', chip.key)"
        >
            {{ chip.label }}
        </Button>
    </div>
</template>
