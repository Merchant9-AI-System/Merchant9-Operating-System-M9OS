<script setup lang="ts">
import { Deferred, usePage } from '@inertiajs/vue3';
import { Crosshair } from '@lucide/vue';
import { computed } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import SpotlightCard from '@/components/inspira/SpotlightCard.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';

// Sasaran Cawangan (universal): sasaran bulan semasa (RM & gram) vs jualan sebenar bagi setiap cawangan dalam skop -
// rujuk App\Support\HomeSummary\UniversalTargetFeed. Paparan sahaja; sasaran ditetapkan di Filament atau oleh ketua cawangan.
interface Row {
    store: string;
    has_target: boolean;
    target_rm: number | null;
    actual_rm: number | null;
    pct_rm: number | null;
    on_track_rm: boolean | null;
    target_grams: number | null;
    actual_grams: number | null;
    pct_grams: number | null;
    on_track_grams: boolean | null;
}

interface Targets {
    available: boolean;
    month_label: string;
    through: string | null;
    scope_label: string;
    totals: {
        target_rm: number;
        actual_rm: number;
        pct_rm: number | null;
        target_grams: number;
        actual_grams: number;
        pct_grams: number | null;
        with_target: number;
        missing: number;
    };
    rows: Row[];
}

const page = usePage<{ universalTarget?: Targets | null }>();
const { has, orderStyle } = useHomeModules();
const enabled = computed(() => has('u_target'));
const data = computed(() => page.props.universalTarget ?? null);

const rm = (value: number | null): string => (value === null ? '-' : 'RM' + Math.round(value).toLocaleString('en-MY'));
const g = (value: number | null): string => (value === null ? '-' : value.toLocaleString('en-MY', { maximumFractionDigits: 1 }) + ' g');
const width = (pct: number | null): string => Math.min(Math.max(pct ?? 0, 0), 100) + '%';

function barClass(onTrack: boolean | null): string {
    return onTrack === null ? 'bg-[var(--h-gold2)]' : onTrack ? 'bg-[var(--h-good)]' : 'bg-[var(--h-warn)]';
}
</script>

<template>
    <section v-if="enabled" id="section-u-target" class="flex flex-col gap-3" :style="orderStyle('u_target')">
        <BlurFade class="flex items-center gap-3">
            <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                <Crosshair class="size-3.5 text-[var(--h-gold)]" />
                Sasaran Cawangan<template v-if="data"> · {{ data.month_label }}</template>
            </h2>
            <span v-if="data" class="rounded-full bg-[var(--h-fill)] px-2 py-0.5 text-[10px] font-medium text-[var(--h-soft)]">{{ data.scope_label }}</span>
            <span v-if="data?.through" class="rounded-full bg-[var(--h-gold2)]/25 px-2 py-0.5 text-[10px] font-medium text-[var(--h-gold)]">
                Sehingga semalam · {{ data.through }}
            </span>
            <span class="h-px flex-1 bg-[var(--h-line)]" />
        </BlurFade>

        <Deferred data="universalTarget">
            <template #fallback>
                <div class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] p-5">
                    <Skeleton class="h-8 w-64 bg-[var(--h-line)]" />
                    <Skeleton v-for="n in 4" :key="n" class="h-9 w-full bg-[var(--h-line)]" />
                </div>
            </template>

            <BlurFade v-if="data" :delay="0.05">
                <SpotlightCard class="flex flex-col gap-4 rounded-lg border border-[var(--h-line)] bg-[var(--h-panel)] p-5">
                    <p v-if="!data.available" class="rounded-md border border-[var(--h-gold2)]/30 bg-[var(--h-gold2)]/10 px-4 py-2 text-sm text-[var(--h-note)]">
                        Jualan sebenar tidak dapat diambil daripada JEMiSys sekarang - sasaran tetap dipaparkan.
                    </p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div v-for="metric in [
                            { label: 'RM', actual: rm(data.totals.actual_rm), target: rm(data.totals.target_rm), pct: data.totals.pct_rm },
                            { label: 'Gram', actual: g(data.totals.actual_grams), target: g(data.totals.target_grams), pct: data.totals.pct_grams },
                        ]" :key="metric.label" class="flex flex-col gap-2">
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs text-[var(--h-muted)]">Jumlah {{ metric.label }}</span>
                                <span class="text-xl font-medium tabular-nums">{{ metric.actual }}</span>
                                <span class="text-sm text-[var(--h-muted)]">/ {{ metric.target }}</span>
                                <span v-if="metric.pct !== null" class="ml-auto text-sm font-medium tabular-nums text-[var(--h-gold)]">{{ metric.pct }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-[var(--h-line)]">
                                <div class="h-full rounded-full bg-[var(--h-gold2)] transition-[width] duration-700" :style="{ width: width(metric.pct) }" />
                            </div>
                        </div>
                    </div>
                    <p v-if="data.totals.missing > 0" class="text-xs text-[var(--h-warn)]">
                        {{ data.totals.missing }} cawangan belum ada sasaran bulan ini (tidak dikira dalam jumlah).
                    </p>

                    <ScrollArea class="max-h-[28rem]">
                        <table class="w-full min-w-[620px] text-sm">
                            <thead>
                                <tr class="text-left text-xs text-[var(--h-muted)]">
                                    <th class="py-2 pr-3 font-medium">Cawangan</th>
                                    <th class="px-3 py-2 font-medium">RM</th>
                                    <th class="py-2 pl-3 font-medium">Gram</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--h-line)]">
                                <tr v-for="row in data.rows" :key="row.store" class="align-top transition-colors hover:bg-[var(--h-hover2)]">
                                    <td class="py-2 pr-3 font-medium">{{ row.store }}</td>
                                    <template v-if="row.has_target">
                                        <td class="px-3 py-2">
                                            <div class="flex items-baseline justify-between gap-2 text-xs tabular-nums">
                                                <span>{{ rm(row.actual_rm) }} / {{ rm(row.target_rm) }}</span>
                                                <span class="font-medium text-[var(--h-gold)]">{{ row.pct_rm === null ? '-' : row.pct_rm + '%' }}</span>
                                            </div>
                                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-[var(--h-line)]">
                                                <div class="h-full rounded-full" :class="barClass(row.on_track_rm)" :style="{ width: width(row.pct_rm) }" />
                                            </div>
                                        </td>
                                        <td class="py-2 pl-3">
                                            <div class="flex items-baseline justify-between gap-2 text-xs tabular-nums">
                                                <span>{{ g(row.actual_grams) }} / {{ g(row.target_grams) }}</span>
                                                <span class="font-medium text-[var(--h-gold)]">{{ row.pct_grams === null ? '-' : row.pct_grams + '%' }}</span>
                                            </div>
                                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-[var(--h-line)]">
                                                <div class="h-full rounded-full" :class="barClass(row.on_track_grams)" :style="{ width: width(row.pct_grams) }" />
                                            </div>
                                        </td>
                                    </template>
                                    <td v-else colspan="2" class="px-3 py-2 text-xs text-[var(--h-muted)]">Belum ada sasaran bulan ini</td>
                                </tr>
                            </tbody>
                        </table>
                    </ScrollArea>
                </SpotlightCard>
            </BlurFade>
        </Deferred>
    </section>
</template>
