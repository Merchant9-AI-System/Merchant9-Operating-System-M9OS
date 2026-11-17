<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { Motion } from 'motion-v';
import { computed } from 'vue';

// Gaya Inspira UI "Confetti": letusan kepingan emas dari tengah elemen induk (induk mesti `relative`). Dipaparkan
// apabila `active` true (paparan sekali; pemanggil menutupnya semula). Tiada animasi bila prefers-reduced-motion.
interface Props {
    active: boolean;
    count?: number;
}

const props = withDefaults(defineProps<Props>(), { count: 26 });

const reduceMotion = useMediaQuery('(prefers-reduced-motion: reduce)');
const COLORS = ['var(--h-gold)', 'var(--h-gold2)', 'var(--h-good)', 'var(--h-warn)', 'var(--h-soft)'];

// Sudut/jarak dijana deterministik (indeks) supaya letusan kelihatan sekata & tak berubah pada render semula.
const pieces = computed(() =>
    Array.from({ length: props.count }, (_, i) => {
        const angle = (i / props.count) * Math.PI * 2 + (i % 3) * 0.2;
        const distance = 70 + ((i * 37) % 90);

        return {
            id: i,
            x: Math.cos(angle) * distance,
            y: Math.sin(angle) * distance - 30,
            rotate: ((i * 83) % 360) - 180,
            color: COLORS[i % COLORS.length],
            size: 5 + (i % 3) * 2,
            delay: (i % 5) * 0.015,
        };
    }),
);
</script>

<template>
    <div v-if="active && !reduceMotion" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center" aria-hidden="true">
        <Motion
            v-for="piece in pieces"
            :key="piece.id"
            :initial="{ x: 0, y: 0, opacity: 1, scale: 1, rotate: 0 }"
            :animate="{ x: piece.x, y: piece.y + 60, opacity: 0, scale: 0.6, rotate: piece.rotate }"
            :transition="{ duration: 1.1, delay: piece.delay, ease: 'easeOut' }"
            class="absolute rounded-[1px]"
            :style="{ width: piece.size + 'px', height: piece.size * 1.6 + 'px', background: piece.color }"
        />
    </div>
</template>
