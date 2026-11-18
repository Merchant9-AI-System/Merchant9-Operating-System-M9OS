<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import SpotlightCard from '@/components/inspira/SpotlightCard.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';

// Ranking Staf (universal): ranking jualan staf merentas cawangan dalam skop (bulan ini / bulan lepas) - rujuk
// App\Support\HomeSummary\UniversalRankingFeed. Disusun ikut RM / gram / tiket di sini (client). Setiap staf dengan lencana cawangan.
interface StaffRow {
    name: string;
    store: string;
    rm: number;
    grams: number;
    tickets: number;
    avg_ticket: number | null;
    share_pct: number | null;
    shared: boolean;
}

interface Ranking {
    period: 'this' | 'last';
    month_label: string;
    through: string | null;
    scope_label: string;
    available: boolean;
    total: { rm: number; grams: number; tickets: number; avg_ticket: number | null; staff: number } | null;
    rows: StaffRow[];
}

type Metric = 'rm' | 'grams' | 'tickets';

const page = usePage<{ rankingPeriod?: 'this' | 'last'; universalRanking?: Ranking | null }>();
const { has, orderStyle } = useHomeModules();
const enabled = computed(() => has('u_ranking'));
const data = computed(() => page.props.universalRanking ?? null);
const metric = ref<Metric>('rm');
const loading = ref(false);

const METRICS: { key: Metric; label: string }[] = [
    { key: 'rm', label: 'RM' },
    { key: 'grams', label: 'Gram' },
    { key: 'tickets', label: 'Tiket' },
];

const sorted = computed(() => [...(data.value?.rows ?? [])].sort((a, b) => b[metric.value] - a[metric.value]));
const top = computed(() => Math.max(sorted.value[0]?.[metric.value] ?? 0, 1));

function show(row: StaffRow): string {
    return metric.value === 'rm'
        ? 'RM' + Math.round(row.rm).toLocaleString('en-MY')
        : metric.value === 'grams'
          ? row.grams.toLocaleString('en-MY', { maximumFractionDigits: 1 }) + ' g'
          : String(row.tickets);
}

// Tukar bulan: muat semula ranking universal sahaja (partial reload) dgn ?ranking_period=.
function changePeriod(event: Event): void {
    const period = (event.target as HTMLSelectElement).value;

    router.get(
        '/home',
        { period: (page.props as { period?: string }).period, ranking_period: period },
        {
            only: ['universalRanking', 'rankingPeriod'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}
</script>

<template>
    <section v-if="enabled" id="section-u-ranking" class="flex flex-col gap-3" :style="orderStyle('u_ranking')">
        <BlurFade class="flex items-center gap-3">
            <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                <Users class="size-3.5 text-[var(--h-gold)]" />
                Ranking Staf<template v-if="data"> · {{ data.month_label }}</template>
            </h2>
            <span v-if="data" class="rounded-full bg-[var(--h-fill)] px-2 py-0.5 text-[10px] font-medium text-[var(--h-soft)]">{{ data.scope_label }}</span>
            <span v-if="data?.through" class="rounded-full bg-[var(--h-gold2)]/25 px-2 py-0.5 text-[10px] font-medium text-[var(--h-gold)]">
                Sehingga {{ data.through }}
            </span>
            <span class="h-px flex-1 bg-[var(--h-line)]" />
            <select
                class="rounded-md border border-[var(--h-line)] bg-[var(--h-bg)] px-2 py-1 text-xs text-[var(--h-fg)]"
                :value="data?.period ?? page.props.rankingPeriod ?? 'this'"
                :disabled="loading"
                aria-label="Bulan ranking"
                @change="changePeriod"
            >
                <option value="this">Bulan ini</option>
                <option value="last">Bulan lepas</option>
            </select>
        </BlurFade>

        <Deferred data="universalRanking">
            <template #fallback>
                <div class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] p-5">
                    <Skeleton class="h-8 w-64 bg-[var(--h-line)]" />
                    <Skeleton v-for="n in 5" :key="n" class="h-8 w-full bg-[var(--h-line)]" />
                </div>
            </template>

            <BlurFade v-if="data" :delay="0.05">
                <SpotlightCard class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] bg-[var(--h-panel)] p-5">
                    <p v-if="!data.available" class="text-sm text-[var(--h-muted)]">Data jualan tidak dapat diambil daripada JEMiSys sekarang - cuba lagi sebentar.</p>
                    <p v-else-if="data.rows.length === 0" class="text-sm text-[var(--h-soft)]">Belum ada jualan bagi tempoh ini.</p>

                    <template v-else>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div role="tablist" class="flex gap-1 rounded-full bg-[var(--h-fill)] p-1">
                                <button
                                    v-for="m in METRICS"
                                    :key="m.key"
                                    type="button"
                                    role="tab"
                                    :aria-selected="metric === m.key"
                                    class="rounded-full px-3 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                                    :class="metric === m.key ? 'bg-[var(--h-gold2)]/30 text-[var(--h-fg)]' : 'text-[var(--h-muted)] hover:text-[var(--h-fg)]'"
                                    @click="metric = m.key"
                                >
                                    {{ m.label }}
                                </button>
                            </div>
                            <span v-if="data.total" class="text-xs text-[var(--h-muted)]">
                                {{ data.total.staff }} staf · RM{{ Math.round(data.total.rm).toLocaleString('en-MY') }} · {{ data.total.tickets }} tiket
                            </span>
                        </div>

                        <ScrollArea class="max-h-[28rem]">
                            <ul class="flex flex-col pr-3">
                                <li
                                    v-for="(row, index) in sorted"
                                    :key="row.store + row.name"
                                    class="flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-[var(--h-hover2)]"
                                >
                                    <span class="w-6 text-right text-xs tabular-nums text-[var(--h-muted)]">{{ index + 1 }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-2 text-sm">
                                            <span class="truncate font-medium">{{ row.name }}</span>
                                            <span class="rounded bg-[var(--h-fill)] px-1.5 py-0.5 text-[10px] text-[var(--h-soft)]">{{ row.store }}</span>
                                            <span v-if="row.shared" class="text-[10px] text-[var(--h-muted)]">½</span>
                                        </span>
                                        <span class="mt-1 block h-1 overflow-hidden rounded-full bg-[var(--h-line)]">
                                            <span class="block h-full rounded-full bg-[var(--h-gold2)] transition-[width] duration-500" :style="{ width: (row[metric] / top) * 100 + '%' }" />
                                        </span>
                                    </span>
                                    <span class="w-28 text-right text-sm tabular-nums text-[var(--h-gold)]">{{ show(row) }}</span>
                                </li>
                            </ul>
                        </ScrollArea>
                    </template>
                </SpotlightCard>
            </BlurFade>
        </Deferred>
    </section>
</template>
