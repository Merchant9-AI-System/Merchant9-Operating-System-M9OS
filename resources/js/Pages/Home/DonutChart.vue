<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

// Carta donut (SVG) - setiap kepingan ialah bulatan bergaris dgn stroke-dasharray, jadi animasi lukis & hover cuma
// peralihan CSS. Warna ikut pembolehubah --sc-0..7 / --sc-other (ditakrif dlm blok <style> StaffRankingPanel, ada
// varian gelap & cerah). Hover/fokus kepingan atau baris sepadan disegerakkan melalui `active` (v-model).
interface Slice {
    key: string;
    label: string;
    value: number;
    // Indeks warna 0..7; selainnya (null) = warna "lain-lain".
    color: number | null;
    // Teks nilai utk tengah carta bila kepingan aktif (cth. "RM451,248").
    display: string;
    pct: number | null;
}

const props = defineProps<{
    slices: Slice[];
    active: string | null;
    // Teks tengah bila tiada kepingan aktif.
    centerTitle: string;
    centerSub: string;
}>();

const emit = defineEmits<{ 'update:active': [key: string | null] }>();

const RADIUS = 80;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const GAP = 2;

// Lukis dari sifar sekali sahaja semasa mula (animasi), kemudian ikut data.
const drawn = ref(false);

onMounted(() => {
    requestAnimationFrame(() => {
        drawn.value = true;
    });
});

const total = computed(() => props.slices.reduce((sum, slice) => sum + slice.value, 0));

const arcs = computed(() => {
    let cumulative = 0;
    const single = props.slices.length === 1;

    return props.slices.map((slice) => {
        const fraction = total.value > 0 ? slice.value / total.value : 0;
        const length = Math.max(fraction * CIRCUMFERENCE - (single ? 0 : GAP), 0);
        const offset = -cumulative * CIRCUMFERENCE;
        cumulative += fraction;

        return { slice, length, offset };
    });
});

const activeSlice = computed(() => props.slices.find((slice) => slice.key === props.active) ?? null);

function colorVar(slice: Slice): string {
    return slice.color === null ? 'var(--sc-other)' : 'var(--sc-' + slice.color + ')';
}
</script>

<template>
    <div class="relative mx-auto aspect-square w-full max-w-[10.5rem]">
        <svg viewBox="0 0 200 200" role="img" :aria-label="'Pecahan: ' + slices.map((s) => s.label + ' ' + (s.pct ?? 0) + '%').join(', ')" class="size-full -rotate-90">
            <circle cx="100" cy="100" :r="RADIUS" fill="none" stroke="var(--h-line)" stroke-width="26" />
            <circle
                v-for="arc in arcs"
                :key="arc.slice.key"
                cx="100"
                cy="100"
                :r="RADIUS"
                fill="none"
                :stroke="colorVar(arc.slice)"
                :stroke-width="active === arc.slice.key ? 32 : 26"
                :stroke-dasharray="(drawn ? arc.length : 0) + ' ' + CIRCUMFERENCE"
                :stroke-dashoffset="arc.offset"
                class="donut-arc cursor-pointer"
                :style="{ opacity: active !== null && active !== arc.slice.key ? 0.35 : 1 }"
                @pointerenter="emit('update:active', arc.slice.key)"
                @pointerleave="emit('update:active', null)"
            />
        </svg>

        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-0.5 px-6 text-center">
            <template v-if="activeSlice">
                <span class="line-clamp-1 text-[11px] text-[var(--h-muted)]">{{ activeSlice.label }}</span>
                <span class="text-base font-medium tabular-nums">{{ activeSlice.display }}</span>
                <span v-if="activeSlice.pct !== null" class="text-xs tabular-nums text-[var(--h-gold)]">{{ activeSlice.pct }}%</span>
            </template>
            <template v-else>
                <span class="text-[11px] text-[var(--h-muted)]">{{ centerSub }}</span>
                <span class="text-base font-medium tabular-nums">{{ centerTitle }}</span>
            </template>
        </div>
    </div>
</template>

<style>
.donut-arc {
    transition:
        stroke-dasharray 0.8s cubic-bezier(0.22, 1, 0.36, 1),
        stroke-width 0.15s ease,
        opacity 0.15s ease;
}

@media (prefers-reduced-motion: reduce) {
    .donut-arc {
        transition: none;
    }
}
</style>
