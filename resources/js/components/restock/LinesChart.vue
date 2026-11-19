<script setup lang="ts">
import { CurveType } from '@unovis/ts';
import { VisAxis, VisCrosshair, VisLine, VisScatter, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import ChartContainer from '@/components/ui/chart/ChartContainer.vue';
import { tooltipHtml } from '@/lib/chart';
import type { ChartSeries } from '@/lib/chart';

// Carta garis (Unovis) utk trend: satu baris data = satu hari; `xKey` = label hari. Titik kecil ditunjuk juga supaya satu hari data pun kelihatan.
type Row = Record<string, string | number | null>;

const props = withDefaults(
    defineProps<{
        data: Row[];
        xKey: string;
        series: ChartSeries[];
        height?: number;
    }>(),
    { height: 220 },
);

const labels = computed(() => props.data.map((row) => String(row[props.xKey])));
const x = (_: Row, index: number) => index;
const y = computed(() => props.series.map((s) => (row: Row) => Number(row[s.key] ?? 0)));
const colors = computed(() => props.series.map((s) => s.color));
// Paling banyak ~6 label paksi mendatar supaya tak bertindih.
const tickValues = computed(() => {
    const step = Math.max(1, Math.ceil(props.data.length / 6));

    return props.data.map((_, index) => index).filter((index) => index % step === 0 || index === props.data.length - 1);
});
const xTick = (index: number | Date) => labels.value[Number(index)] ?? '';
const yTick = (value: number | Date) => Number(value).toLocaleString('en-MY');
const template = (row: Row) =>
    tooltipHtml(
        String(row[props.xKey]),
        props.series.map((s) => ({ label: s.label, color: s.color, value: Number(row[s.key] ?? 0).toLocaleString('en-MY') })),
    );
</script>

<template>
    <ChartContainer>
        <VisXYContainer :data="data" :height="height" :margin="{ top: 8, bottom: 4, left: 4, right: 8 }" :y-domain-min-constraint="[0, undefined]">
            <VisLine :x="x" :y="y" :color="colors" :curve-type="CurveType.MonotoneX" :line-width="2" />
            <VisScatter v-for="(s, index) in series" :key="s.key" :x="x" :y="y[index]" :color="s.color" :size="6" />
            <VisAxis type="x" :tick-values="tickValues" :tick-format="xTick" :grid-line="false" :domain-line="false" :tick-line="false" />
            <VisAxis type="y" :num-ticks="4" :tick-format="yTick" :domain-line="false" :tick-line="false" />
            <VisCrosshair :template="template" :color="colors" />
            <VisTooltip />
        </VisXYContainer>

        <ul class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li v-for="s in series" :key="s.key" class="flex items-center gap-1.5">
                <span class="size-2 rounded-full" :style="{ background: s.color }" aria-hidden="true" />
                {{ s.label }}
            </li>
        </ul>
    </ChartContainer>
</template>
