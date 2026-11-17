<script setup lang="ts">
import { useIntersectionObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import { designImage, designImageLoading, requestDesignImage } from '@/lib/designImages';

// Imej design yg dimuatkan bila kotaknya masuk paparan (scroll): jika server sudah beri `src` ia terus dipaparkan, jika
// tidak kodnya didaftarkan utk diminta secara berkumpulan (lib/designImages). Semasa menunggu: kotak berdenyut; tiada imej
// (kod tak ada gambar): kotak kosong. Imej muncul dgn fade-in.
const props = defineProps<{ code: string; src?: string | null; size?: string }>();

const root = ref<HTMLElement | null>(null);

const url = computed(() => props.src || designImage(props.code));
const pending = computed(() => !url.value && designImageLoading(props.code));

const { stop } = useIntersectionObserver(
    root,
    ([entry]) => {
        if (entry?.isIntersecting) {
            if (!props.src) {
                requestDesignImage(props.code);
            }

            stop();
        }
    },
    { rootMargin: '120px' },
);
</script>

<template>
    <!-- Klik imej = pratonton besar + zum (komponen ImagePreview projek). Kotak berdenyut semasa menunggu imej. -->
    <span ref="root" class="relative block shrink-0 overflow-hidden rounded bg-[var(--h-line)] [&>button]:size-full" :class="size ?? 'size-10'">
        <ImagePreview v-if="url" :src="url" :alt="code" class="size-full animate-in fade-in duration-500" />
        <span v-else-if="pending" class="absolute inset-0 animate-pulse bg-[var(--h-line)]" aria-hidden="true" />
    </span>
</template>
