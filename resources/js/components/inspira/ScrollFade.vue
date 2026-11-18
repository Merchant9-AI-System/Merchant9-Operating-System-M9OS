<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';

// Gaya "Scroll Fade": kawasan menatal mendatar (shadcn ScrollArea) yang MEMUDARKAN tepi kiri/kanan bila masih ada kandungan
// di luar paparan - tepi hanya memudar di sebelah yang boleh ditatal, dan hilang bila sudah sampai hujung. Bar tatal nipis
// dan hanya muncul bila kursor di atasnya (ScrollArea). Pudar guna CSS mask supaya sesuai dgn apa-apa latar.
interface Props {
    /** Lebar pudar (px). */
    size?: number;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), { size: 40, class: undefined });

const root = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

let viewport: HTMLElement | null = null;
let observer: ResizeObserver | null = null;

function update(): void {
    if (!viewport) {
        return;
    }

    canScrollLeft.value = viewport.scrollLeft > 1;
    canScrollRight.value = viewport.scrollLeft + viewport.clientWidth < viewport.scrollWidth - 1;
}

onMounted(() => {
    viewport = root.value?.querySelector<HTMLElement>('[data-slot="scroll-area-viewport"]') ?? null;

    if (!viewport) {
        return;
    }

    viewport.addEventListener('scroll', update, { passive: true });
    observer = new ResizeObserver(update);
    observer.observe(viewport);

    if (viewport.firstElementChild) {
        observer.observe(viewport.firstElementChild);
    }

    update();
});

onBeforeUnmount(() => {
    viewport?.removeEventListener('scroll', update);
    observer?.disconnect();
});

const mask = computed(() => {
    const left = canScrollLeft.value ? `transparent 0px, black ${props.size}px` : 'black 0px';
    const right = canScrollRight.value ? `black calc(100% - ${props.size}px), transparent 100%` : 'black 100%';

    return `linear-gradient(to right, ${left}, ${right})`;
});
</script>

<template>
    <div ref="root" :class="cn('relative', props.class)">
        <ScrollArea
            horizontal
            class="w-full [&_[data-slot=scroll-area-scrollbar]]:absolute [&_[data-slot=scroll-area-scrollbar]]:inset-x-0 [&_[data-slot=scroll-area-scrollbar]]:bottom-0 [&_[data-slot=scroll-area-scrollbar]]:h-1.5 [&_[data-slot=scroll-area-scrollbar]]:p-0 [&_[data-slot=scroll-area-thumb]]:bg-[var(--h-line)]"
            :style="{ maskImage: mask, WebkitMaskImage: mask }"
        >
            <slot />
        </ScrollArea>
    </div>
</template>
