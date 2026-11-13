<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { cn } from '@/lib/utils';

// Carta kawasan trend (SVG) utk kad hero: garis + isian gradien, titik terakhir ditandakan, dan tooltip tarikh/nilai
// bila kursor (atau jari) melintas. Hanya garis & isian yg dilukis dlm SVG (viewBox diregang); titik & tooltip ialah
// elemen HTML berkedudukan peratus supaya bulatan tak herot. Kurang 2 titik = tiada apa dilukis.
interface Props {
    values: number[];
    labels?: string[];
    unit?: string;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), { labels: () => [], unit: '', class: undefined });

const WIDTH = 400;
const HEIGHT = 100;
const PAD_Y = 10;

const hovered = ref<number | null>(null);
const gradientId = `trend-fill-${useId()}`;

const coords = computed(() => {
    const values = props.values;

    if (values.length < 2) {
        return [];
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;

    return values.map((value, index) => ({
        x: (index / (values.length - 1)) * WIDTH,
        y: HEIGHT - PAD_Y - ((value - min) / span) * (HEIGHT - PAD_Y * 2),
    }));
});

const line = computed(() => coords.value.map((c) => `${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' '));
const area = computed(() => (coords.value.length ? `0,${HEIGHT} ${line.value} ${WIDTH},${HEIGHT}` : ''));

const active = computed(() => hovered.value ?? coords.value.length - 1);
const activePoint = computed(() => coords.value[active.value]);

function format(value: number): string {
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (props.unit ? ` ${props.unit}` : '');
}

function onMove(event: PointerEvent): void {
    const el = event.currentTarget as HTMLElement;
    const rect = el.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));

    hovered.value = Math.round(ratio * (props.values.length - 1));
}
</script>

<template>
    <div
        v-if="coords.length"
        :class="cn('relative h-28 w-full select-none touch-pan-y', props.class)"
        role="img"
        aria-label="Trend 8 hari"
        @pointermove="onMove"
        @pointerleave="hovered = null"
    >
        <svg :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" preserveAspectRatio="none" class="absolute inset-0 size-full text-[var(--h-gold2)]">
            <defs>
                <linearGradient :id="gradientId" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="currentColor" stop-opacity="0.28" />
                    <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                </linearGradient>
            </defs>
            <polygon :points="area" :fill="`url(#${gradientId})`" />
            <polyline
                :points="line"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
            <line
                v-if="hovered !== null && activePoint"
                :x1="activePoint.x"
                :x2="activePoint.x"
                y1="0"
                :y2="HEIGHT"
                stroke="currentColor"
                stroke-opacity="0.35"
                stroke-width="1"
                vector-effect="non-scaling-stroke"
            />
        </svg>

        <span
            v-if="activePoint"
            class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[var(--h-gold2)] ring-4 ring-[var(--h-gold2)]/20"
            :style="{ left: `${(activePoint.x / WIDTH) * 100}%`, top: `${(activePoint.y / HEIGHT) * 100}%` }"
        />

        <span
            v-if="hovered !== null && activePoint"
            class="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md border border-[var(--h-line)] bg-[var(--h-panel)] px-2 py-1 text-xs text-[var(--h-fg)] shadow-lg"
            :style="{ left: `${Math.min(88, Math.max(12, (activePoint.x / WIDTH) * 100))}%` }"
        >
            <span v-if="labels[active]" class="mr-1.5 text-[var(--h-muted)]">{{ labels[active] }}</span>
            <span class="font-medium tabular-nums">{{ format(values[active]) }}</span>
        </span>
    </div>
</template>
