<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

// Kemajuan bulat (cincin SVG) dgn nilai % di tengah. `value` 0-100 (dihadkan). Saiz dikawal melalui class (cth. size-12); teks
// mengikut saiz melalui `text-*`. pathLength=100 menjadikan dasharray = peratus terus (tiada kiraan lilitan).
const props = withDefaults(
    defineProps<{
        value: number;
        // Bilangan titik perpuluhan pd teks tengah.
        decimals?: number;
        label?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { decimals: 1, label: undefined, class: undefined },
);

const clamped = computed(() => Math.min(100, Math.max(0, props.value)));
const text = computed(() => `${props.value.toFixed(props.decimals)}%`);
</script>

<template>
    <div
        role="progressbar"
        :aria-valuenow="Math.round(clamped)"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="label"
        :class="cn('relative inline-flex size-12 shrink-0 items-center justify-center', props.class)"
    >
        <svg viewBox="0 0 36 36" class="size-full -rotate-90" aria-hidden="true">
            <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="3" class="stroke-muted" />
            <circle
                cx="18"
                cy="18"
                r="15.9155"
                fill="none"
                stroke-width="3"
                stroke-linecap="round"
                pathLength="100"
                :stroke-dasharray="`${clamped} 100`"
                class="stroke-primary transition-[stroke-dasharray] duration-300"
            />
        </svg>
        <span class="absolute inset-0 flex items-center justify-center text-[9px] leading-none font-medium tabular-nums sm:text-[10px]">{{ text }}</span>
    </div>
</template>
