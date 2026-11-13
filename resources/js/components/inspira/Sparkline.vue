<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

// Garis trend mini (SVG) utk kad ringkasan - hiasan + arah sahaja, tiada paksi. Titik terakhir ditandakan.
// Kurang 2 titik = tiada apa dilukis. Warna ikut `currentColor` (kelas text-*).
interface Props {
    values: number[];
    class?: string;
}

const props = withDefaults(defineProps<Props>(), { class: undefined });

const WIDTH = 120;
const HEIGHT = 36;
const PAD = 3;

const points = computed(() => {
    const values = props.values;

    if (values.length < 2) {
        return [];
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;

    return values.map((value, index) => ({
        x: PAD + (index / (values.length - 1)) * (WIDTH - PAD * 2),
        y: HEIGHT - PAD - ((value - min) / span) * (HEIGHT - PAD * 2),
    }));
});

const line = computed(() => points.value.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));
const area = computed(() => (points.value.length ? `${PAD},${HEIGHT} ${line.value} ${WIDTH - PAD},${HEIGHT}` : ''));
const last = computed(() => points.value[points.value.length - 1]);
</script>

<template>
    <svg
        v-if="points.length"
        :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
        :class="cn('h-9 w-28 overflow-visible text-amber-400', props.class)"
        role="img"
        aria-label="Trend 8 hari"
    >
        <polygon :points="area" fill="currentColor" fill-opacity="0.12" />
        <polyline :points="line" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
        <circle v-if="last" :cx="last.x" :cy="last.y" r="2.75" fill="currentColor" />
    </svg>
</template>
