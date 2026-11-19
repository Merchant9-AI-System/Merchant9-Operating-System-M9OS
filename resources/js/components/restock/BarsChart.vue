<script setup lang="ts">
import { VisAxis, VisCrosshair, VisGroupedBar, VisStackedBar, VisTooltip, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import ChartContainer from '@/components/ui/chart/ChartContainer.vue';
import { tooltipHtml } from '@/lib/chart';
import type { ChartSeries } from '@/lib/chart';

// Carta bar (Unovis): satu atau beberapa siri, bertindan atau berkumpulan, satu bar/kumpulan bagi setiap baris data.
// `xKey` = medan label kategori (paksi mendatar).
type Row = Record<string, string | number | null>;

const props = withDefaults(
    defineProps<{
        data: Row[];
        xKey: string;
        series: ChartSeries[];
        stacked?: boolean;
        height?: number;
        legend?: boolean;
    }>(),
    { stacked: false, height: 220, legend: true },
);

const labels = computed(() => props.data.map((row) => String(row[props.xKey])));
const x = (_: Row, index: number) => index;
const y = computed(() => props.series.map((s) => (row: Row) => Number(row[s.key] ?? 0)));
const colors = computed(() => props.series.map((s) => s.color));
const tickValues = computed(() => props.data.map((_, index) => index));
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
        <VisXYContainer :data="data" :height="height" :margin="{ top: 8, bottom: 4, left: 4, right: 4 }">
            <VisStackedBar v-if="stacked" :x="x" :y="y" :color="colors" :rounded-corners="3" :bar-padding="0.3" />
            <VisGroupedBar v-else :x="x" :y="y" :color="colors" :rounded-corners="3" :bar-padding="0.2" :group-padding="0.25" />
            <VisAxis type="x" :tick-values="tickValues" :tick-format="xTick" :grid-line="false" :domain-line="false" :tick-line="false" />
            <VisAxis type="y" :num-ticks="4" :tick-format="yTick" :domain-line="false" :tick-line="false" />
            <VisCrosshair :template="template" :color="colors" />
            <VisTooltip />
        </VisXYContainer>

        <ul v-if="legend && series.length > 1" class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li v-for="s in series" :key="s.key" class="flex items-center gap-1.5">
                <span class="size-2 rounded-sm" :style="{ background: s.color }" aria-hidden="true" />
                {{ s.label }}
            </li>
        </ul>
    </ChartContainer>
</template>
