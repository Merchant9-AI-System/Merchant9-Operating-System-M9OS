<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { Motion } from 'motion-v';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

// Gaya Inspira UI "Blur Fade": elemen muncul dgn kabur -> jelas + naik sedikit. Berhenti beranimasi bila
// pengguna minta kurang gerakan (prefers-reduced-motion) - dirender sbg div biasa.
interface Props {
    delay?: number;
    duration?: number;
    offset?: number;
    blur?: number;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    delay: 0,
    duration: 0.5,
    offset: 10,
    blur: 8,
    class: undefined,
});

const reduceMotion = useMediaQuery('(prefers-reduced-motion: reduce)');

const initial = computed(() => ({ opacity: 0, y: props.offset, filter: `blur(${props.blur}px)` }));
</script>

<template>
    <div v-if="reduceMotion" :class="cn(props.class)">
        <slot />
    </div>
    <Motion
        v-else
        :initial="initial"
        :animate="{ opacity: 1, y: 0, filter: 'blur(0px)' }"
        :transition="{ delay: props.delay, duration: props.duration, ease: 'easeOut' }"
        :class="cn(props.class)"
    >
        <slot />
    </Motion>
</template>
