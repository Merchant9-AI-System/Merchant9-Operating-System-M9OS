<script setup lang="ts">
import { Deferred, usePage } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import { computed } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import NumberTicker from '@/components/inspira/NumberTicker.vue';
import SpotlightCard from '@/components/inspira/SpotlightCard.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';

// Ringkasan Cawangan (universal): jualan & buyback SEMALAM, permintaan stok dan barang dalam transit bagi banyak cawangan
// (skop ditetapkan pentadbir: semua / dipilih / sendiri) - rujuk App\Support\HomeSummary\UniversalSummaryFeed.
interface Row {
    store: string;
    rm: number;
    pcs: number;
    grams: number;
    buyback_grams: number;
    buyback_rm: number;
    open_requests: number;
    stale_requests: number;
    transit_pieces: number;
    transit_grams: number;
}

interface Summary {
    available: boolean;
    date_label: string;
    scope_label: string;
    totals: {
        rm: number;
        pcs: number;
        grams: number;
        gp_pct: number | null;
        buyback_grams: number;
        buyback_rm: number;
        open_requests: number;
        stale_requests: number;
        transit_pieces: number;
        transit_grams: number;
    };
    rows: Row[];
}

const page = usePage<{ universalSummary?: Summary | null }>();
const { has, orderStyle } = useHomeModules();
const enabled = computed(() => has('u_summary'));
const data = computed(() => page.props.universalSummary ?? null);

const rm = (value: number): string => 'RM' + value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const n2 = (value: number): string => value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <section v-if="enabled" id="section-u-summary" class="flex flex-col gap-3" :style="orderStyle('u_summary')">
        <BlurFade class="flex items-center gap-3">
            <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                <Building2 class="size-3.5 text-[var(--h-gold)]" />
                Ringkasan Cawangan
            </h2>
            <span v-if="data" class="rounded-full bg-[var(--h-fill)] px-2 py-0.5 text-[10px] font-medium text-[var(--h-soft)]">{{ data.scope_label }}</span>
            <span v-if="data?.available" class="rounded-full bg-[var(--h-gold2)]/25 px-2 py-0.5 text-[10px] font-medium text-[var(--h-gold)]">Semalam · {{ data.date_label }}</span>
            <span class="h-px flex-1 bg-[var(--h-line)]" />
        </BlurFade>

        <Deferred data="universalSummary">
            <template #fallback>
                <div class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] p-5">
                    <Skeleton class="h-8 w-64 bg-[var(--h-line)]" />
                    <Skeleton v-for="n in 4" :key="n" class="h-9 w-full bg-[var(--h-line)]" />
                </div>
            </template>

            <BlurFade v-if="data" :delay="0.05">
                <SpotlightCard class="flex flex-col gap-4 rounded-lg border border-[var(--h-line)] bg-[var(--h-panel)] p-5">
                    <p v-if="!data.available" class="text-sm text-[var(--h-muted)]">
                        Data jualan semalam tidak dapat diambil daripada JEMiSys sekarang - cuba lagi sebentar.
                    </p>

                    <template v-else>
                        <div class="grid grid-cols-1 gap-px overflow-hidden rounded-md border border-[var(--h-line)] bg-[var(--h-line)] sm:grid-cols-2 lg:grid-cols-4">
                            <div class="flex flex-col gap-1 bg-[var(--h-panel)] p-4">
                                <span class="text-xs text-[var(--h-muted)]">Jualan semalam</span>
                                <span class="text-xl font-medium"><NumberTicker :value="rm(data.totals.rm)" /></span>
                                <span class="text-xs text-[var(--h-muted)]">
                                    {{ data.totals.pcs }} pcs · {{ n2(data.totals.grams) }} g<template v-if="data.totals.gp_pct !== null"> · GP {{ data.totals.gp_pct }}%</template>
                                </span>
                            </div>
                            <div class="flex flex-col gap-1 bg-[var(--h-panel)] p-4">
                                <span class="text-xs text-[var(--h-muted)]">Buyback / trade-in</span>
                                <span class="text-xl font-medium"><NumberTicker :value="n2(data.totals.buyback_grams) + ' g'" /></span>
                                <span class="text-xs text-[var(--h-muted)]">{{ rm(data.totals.buyback_rm) }} · TI + TC</span>
                            </div>
                            <div class="flex flex-col gap-1 bg-[var(--h-panel)] p-4">
                                <span class="text-xs text-[var(--h-muted)]">Permintaan stok terbuka</span>
                                <span class="text-xl font-medium"><NumberTicker :value="String(data.totals.open_requests)" /></span>
                                <span class="text-xs text-[var(--h-muted)]">
                                    {{ data.totals.stale_requests > 0 ? data.totals.stale_requests + ' lapuk (>14 hari)' : 'Tiada yang lapuk' }}
                                </span>
                            </div>
                            <div class="flex flex-col gap-1 bg-[var(--h-panel)] p-4">
                                <span class="text-xs text-[var(--h-muted)]">Barang dalam transit</span>
                                <span class="text-xl font-medium text-[var(--h-warn)]"><NumberTicker :value="data.totals.transit_pieces + ' keping'" /></span>
                                <span class="text-xs text-[var(--h-muted)]">{{ n2(data.totals.transit_grams) }} g · data cermin JEMiSys</span>
                            </div>
                        </div>

                        <ScrollArea class="max-h-[28rem]">
                            <table class="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-[var(--h-muted)]">
                                        <th class="py-2 pr-3 font-medium">Cawangan</th>
                                        <th class="px-3 py-2 text-right font-medium">Jualan</th>
                                        <th class="px-3 py-2 text-right font-medium">Pcs</th>
                                        <th class="px-3 py-2 text-right font-medium">Gram</th>
                                        <th class="px-3 py-2 text-right font-medium">Buyback (g)</th>
                                        <th class="px-3 py-2 text-right font-medium">Transit</th>
                                        <th class="py-2 pl-3 text-right font-medium">Permintaan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[var(--h-line)]">
                                    <tr v-for="row in data.rows" :key="row.store" class="transition-colors hover:bg-[var(--h-hover2)]">
                                        <td class="py-2 pr-3 font-medium">{{ row.store }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ rm(row.rm) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ row.pcs }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ n2(row.grams) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ n2(row.buyback_grams) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums" :class="row.transit_pieces > 0 ? 'text-[var(--h-warn)]' : 'text-[var(--h-muted)]'">
                                            {{ row.transit_pieces }}
                                        </td>
                                        <td class="py-2 pl-3 text-right tabular-nums">
                                            {{ row.open_requests }}<span v-if="row.stale_requests > 0" class="text-[var(--h-muted)]"> (+{{ row.stale_requests }} lapuk)</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </ScrollArea>
                    </template>
                </SpotlightCard>
            </BlurFade>
        </Deferred>
    </section>
</template>
