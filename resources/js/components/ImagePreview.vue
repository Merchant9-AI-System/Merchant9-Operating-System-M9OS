<script setup lang="ts">
import { ZoomIn } from '@lucide/vue';
import { ref } from 'vue';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * Thumbnail yg boleh diklik utk lihat imej produk lebih besar (+ zum), gaya
 * https://primevue.dev/image/ - dibina atas Dialog shadcn-vue sedia ada drpd tarik pustaka
 * UI kedua (PrimeVue) yg akan bertembung/berlapis dgn shadcn-vue yg dah dipilih utk seluruh
 * permukaan Inertia ni. Klik sekali thumbnail = buka pratonton besar; klik imej dlm dialog
 * = toggle zum masuk/keluar.
 */
const props = defineProps<{
    src: string | null;
    alt?: string;
    class?: string;
}>();

const open = ref(false);
const zoomed = ref(false);

function openPreview() {
    if (props.src) {
        zoomed.value = false;
        open.value = true;
    }
}
</script>

<template>
    <button
        v-if="src"
        type="button"
        class="group relative shrink-0 overflow-hidden rounded"
        @click="openPreview"
    >
        <img :src="src" :alt="alt ?? ''" :class="cn('object-cover', props.class)" loading="lazy">
        <span
            class="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition group-hover:bg-black/30 group-hover:opacity-100"
        >
            <ZoomIn class="size-4 text-white" />
        </span>
    </button>
    <div v-else :class="cn('shrink-0 rounded bg-muted', props.class)" />

    <Dialog v-model:open="open">
        <DialogContent class="flex max-w-2xl items-center justify-center p-2">
            <img
                :src="src ?? ''"
                :alt="alt ?? ''"
                :class="cn(
                    'max-h-[75vh] rounded transition-transform duration-200',
                    zoomed ? 'max-w-none scale-150 cursor-zoom-out' : 'max-w-full cursor-zoom-in',
                )"
                @click="zoomed = !zoomed"
            >
        </DialogContent>
    </Dialog>
</template>
