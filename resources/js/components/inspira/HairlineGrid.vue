<script setup lang="ts">
import { ref } from 'vue';
import { cn } from '@/lib/utils';

// Grid garis halus bergaya laman dokumentasi Inspira UI: sel-sel bersebelahan dipisahkan garis nipis (gap-px di atas
// latar garis), dan garis itu menyala emas di sekitar kursor. Sel mesti berlatar legap supaya hanya jurang yg nampak.
// Pada skrin sentuh (tiada kursor) garis kekal malap.
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

    if (!rect) {
        return;
    }

    x.value = event.clientX - rect.left;
    y.value = event.clientY - rect.top;
}

function onLeave(): void {
    x.value = -999;
    y.value = -999;
}
</script>

<template>
    <div
        ref="element"
        :class="cn('grid gap-px overflow-hidden rounded-lg border border-[var(--h-line)]', props.class)"
        :style="{ background: `radial-gradient(280px circle at ${x}px ${y}px, var(--h-glow), var(--h-line) 70%)` }"
        @pointermove="onMove"
        @pointerleave="onLeave"
    >
        <slot />
    </div>
</template>
