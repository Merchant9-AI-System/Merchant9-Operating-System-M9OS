<script setup lang="ts">
import { ref } from 'vue';
import { cn } from '@/lib/utils';

// Gaya Inspira UI "Spotlight Card": cahaya emas lembut mengikut kursor di dalam kad + garis tepi menyala berhampiran
// kursor. Kad berlatar sendiri (class dihantar pemanggil). Skrin sentuh tiada cahaya. Warna ikut token --h-glow.
interface Props {
    class?: string;
}

const props = withDefaults(defineProps<Props>(), { class: undefined });

const element = ref<HTMLElement | null>(null);
const x = ref(-999);
const y = ref(-999);

function onMove(event: PointerEvent): void {
    if (event.pointerType === 'touch') {
        return;
    }

    const rect = element.value?.getBoundingClientRect();

    if (rect) {
        x.value = event.clientX - rect.left;
        y.value = event.clientY - rect.top;
    }
}

function onLeave(): void {
    x.value = -999;
    y.value = -999;
}
</script>

<template>
    <div ref="element" :class="cn('group/spot relative overflow-hidden', props.class)" @pointermove="onMove" @pointerleave="onLeave">
        <div
            class="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 group-hover/spot:opacity-100"
            :style="{ background: `radial-gradient(260px circle at ${x}px ${y}px, var(--h-glow-soft), transparent 70%)` }"
            aria-hidden="true"
        />
        <div class="relative flex flex-col gap-[inherit]">
            <slot />
        </div>
    </div>
</template>
