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
 *
 * `reference` = gambar rujukan (dipinjam drpd berat terdekat bagi design yg sama, bukan gambar tepat kod ini - rujuk
 * App\Support\DesignFamily): label "Rujukan" di thumbnail & nota di dlm pratonton.
 */
const props = defineProps<{
    src: string | null;
    alt?: string;
    class?: string;
    reference?: boolean;
}>();

const referenceNote = 'Gambar rujukan: dipinjam daripada berat terdekat bagi reka bentuk yang sama.';

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
            v-if="reference"
            class="absolute inset-x-0 bottom-0 bg-black/60 text-center text-[8px] leading-tight text-white"
        >
            Rujukan
        </span>
        <span
            class="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition group-hover:bg-black/30 group-hover:opacity-100"
        >
            <ZoomIn class="size-4 text-white" />
        </span>
    </button>
    <div v-else :class="cn('shrink-0 rounded bg-muted', props.class)" />

    <Dialog v-model:open="open">
        <DialogContent class="mx-auto flex max-w-xl flex-col items-center justify-center gap-2 p-2">
            <img
                :src="src ?? ''"
                :alt="alt ?? ''"
                :class="cn(
                    'max-h-[75vh] rounded transition-transform duration-200',
                    zoomed ? 'max-w-none scale-150 cursor-zoom-out' : 'max-w-full cursor-zoom-in',
                )"
                @click="zoomed = !zoomed"
            >
            <p v-if="reference" class="text-xs text-muted-foreground">
                {{ referenceNote }}
            </p>
        </DialogContent>
    </Dialog>
</template>
