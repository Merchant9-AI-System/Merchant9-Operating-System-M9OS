<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { Trophy } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Skeleton } from '@/components/ui/skeleton';
import DonutChart from './DonutChart.vue';

// Ranking jualan staf cawangan ketua yg log masuk (rujuk App\Support\HomeSummary\StaffSalesRanking): carta donut
// (sumbangan setiap staf) + senarai ranking, ikut RM, gram, tiket (bil. invois) atau purata tiket - disusun di sini
// (client) drpd satu set data. Jualan yg dikongsi (SalesMan2) dibahagi sama rata (tiket boleh 12.5). Staf cawangan
// sendiri sahaja. Reka bentuk ikut token tema --h-*; warna carta --sc-* (blok <style> di bawah).
interface StaffRow {
    name: string;
    rm: number;
    grams: number;
    tickets: number;
    avg_ticket: number | null;
    share_pct: number | null;
    shared: boolean;
}

interface StaffRanking {
    period: 'this' | 'last';
    month_label: string;
    through: string | null;
    available: boolean;
    total: { rm: number; grams: number; tickets: number; avg_ticket: number | null; staff: number } | null;
    rows: StaffRow[];
}

type MetricKey = 'rm' | 'grams' | 'tickets' | 'avg_ticket';
type ShareKey = 'rm' | 'grams' | 'tickets';

const page = usePage<{ hasBranch?: boolean; period?: string; rankingPeriod: 'this' | 'last'; staffRanking?: StaffRanking | null }>();

const hasBranch = computed(() => page.props.hasBranch === true);
const data = computed(() => page.props.staffRanking ?? null);

const METRICS: { key: MetricKey; label: string }[] = [
    { key: 'rm', label: 'RM' },
    { key: 'grams', label: 'Gram' },
    { key: 'tickets', label: 'Tiket' },
    { key: 'avg_ticket', label: 'Purata tiket' },
];

const metric = ref<MetricKey>('rm');
const loading = ref(false);
const active = ref<string | null>(null);

const OTHER_KEY = '__other__';
const MAX_SLICES = 8;

// Senarai ringkas: lapan teratas dahulu (sepadan kepingan carta), selebihnya dibuka dgn butang.
const showAll = ref(false);

const fmt: Record<MetricKey, (v: number | null) => string> = {
    rm: (v) => (v === null ? '-' : 'RM' + v.toLocaleString('en-MY', { maximumFractionDigits: 0 })),
    grams: (v) => (v === null ? '-' : v.toLocaleString('en-MY', { maximumFractionDigits: 1 }) + ' g'),
    tickets: (v) => (v === null ? '-' : v.toLocaleString('en-MY', { maximumFractionDigits: 1 })),
    avg_ticket: (v) => (v === null ? '-' : 'RM' + v.toLocaleString('en-MY', { maximumFractionDigits: 0 })),
};

// Susun menurun ikut metrik terpilih; nilai kosong (purata tanpa tiket) di hujung.
const sorted = computed(() => {
    const key = metric.value;

    return [...(data.value?.rows ?? [])].sort((a, b) => (b[key] ?? -1) - (a[key] ?? -1));
});

const visibleRows = computed(() => (showAll.value ? sorted.value : sorted.value.slice(0, MAX_SLICES)));

const top = computed(() => Math.max(...sorted.value.map((row) => row[metric.value] ?? 0), 0));

// Kedudukan sebenar staf dlm susunan semasa (utk HoverCard) - baris tersorok tetap ada kedudukannya.
function rankOf(row: StaffRow): number {
    return sorted.value.findIndex((candidate) => candidate.name === row.name) + 1;
}

function barWidth(row: StaffRow): string {
    return top.value > 0 ? ((row[metric.value] ?? 0) / top.value) * 100 + '%' : '0%';
}

// Carta menunjukkan SUMBANGAN (jumlah): purata tiket bukan jumlah, jadi carta kekal pada RM bila tab itu dipilih.
const shareKey = computed<ShareKey>(() => (metric.value === 'avg_ticket' ? 'rm' : metric.value));

const shareLabel = computed(() => ({ rm: 'Sumbangan jualan (RM)', grams: 'Sumbangan berat (gram)', tickets: 'Sumbangan tiket' })[shareKey.value]);

const shareRows = computed(() => [...(data.value?.rows ?? [])].sort((a, b) => b[shareKey.value] - a[shareKey.value]));

const shareTotal = computed(() => shareRows.value.reduce((sum, row) => sum + row[shareKey.value], 0));

// Lapan staf teratas ada warna sendiri; selebihnya digabung sbg "Lain-lain" supaya carta tak sesak.
const slices = computed(() => {
    const rows = shareRows.value.filter((row) => row[shareKey.value] > 0);
    const pct = (value: number) => (shareTotal.value > 0 ? Math.round((value / shareTotal.value) * 1000) / 10 : null);

    const named = rows.slice(0, MAX_SLICES).map((row, index) => ({
        key: row.name,
        label: row.name,
        value: row[shareKey.value],
        color: index as number | null,
        display: fmt[shareKey.value](row[shareKey.value]),
        pct: pct(row[shareKey.value]),
    }));

    const rest = rows.slice(MAX_SLICES);

    if (rest.length > 0) {
        const value = rest.reduce((sum, row) => sum + row[shareKey.value], 0);

        named.push({ key: OTHER_KEY, label: 'Lain-lain (' + rest.length + ' staf)', value, color: null, display: fmt[shareKey.value](value), pct: pct(value) });
    }

    return named;
});

// Warna & kunci kepingan bagi seorang staf (untuk titik pada senarai & penyegerakan hover).
const sliceOf = computed(() => {
    const map = new Map<string, { key: string; color: number | null }>();

    shareRows.value.forEach((row, index) => {
        map.set(row.name, index < MAX_SLICES ? { key: row.name, color: index } : { key: OTHER_KEY, color: null });
    });

    return map;
});

function dotColor(row: StaffRow): string {
    const slice = sliceOf.value.get(row.name);

    if (!slice || row[shareKey.value] <= 0) {
        return 'transparent';
    }

    return slice.color === null ? 'var(--sc-other)' : 'var(--sc-' + slice.color + ')';
}

function isActive(row: StaffRow): boolean {
    return active.value !== null && sliceOf.value.get(row.name)?.key === active.value;
}

const centerTitle = computed(() => fmt[shareKey.value](shareTotal.value));
const centerSub = computed(() => ({ rm: 'Jumlah jualan', grams: 'Jumlah berat', tickets: 'Jumlah tiket' })[shareKey.value]);

function changePeriod(value: string): void {
    router.get(
        '/home',
        { ranking_period: value, period: page.props.period },
        {
            only: ['staffRanking', 'rankingPeriod'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
</script>

<template>
    <Deferred v-if="hasBranch" data="staffRanking">
        <template #fallback>
            <div class="flex flex-col gap-4 rounded-lg border border-[var(--h-line)] p-5">
                <Skeleton class="h-4 w-48 bg-[var(--h-line)]" />
                <Skeleton v-for="n in 4" :key="n" class="h-8 w-full bg-[var(--h-line)]" />
            </div>
        </template>

        <section v-if="data" id="section-staff-ranking" class="staff-chart flex flex-col gap-3">
            <BlurFade class="flex items-center gap-3">
                <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                    <Trophy class="size-3.5 text-[var(--h-gold)]" />
                    Ranking Jualan Staf · {{ data.month_label }}
                </h2>
                <span class="h-px flex-1 bg-[var(--h-line)]" />
                <select
                    :value="page.props.rankingPeriod"
                    :disabled="loading"
                    aria-label="Bulan ranking"
                    class="rounded-md bg-[var(--h-fill)] px-3 py-1 text-xs text-[var(--h-soft)] outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)] disabled:opacity-50"
                    @change="changePeriod(($event.target as HTMLSelectElement).value)"
                >
                    <option value="this" class="bg-[var(--h-bg)]">Bulan ini</option>
                    <option value="last" class="bg-[var(--h-bg)]">Bulan lepas</option>
                </select>
            </BlurFade>

            <BlurFade :delay="0.05">
                <p v-if="!data.available" class="rounded-md border border-[var(--h-gold2)]/30 bg-[var(--h-gold2)]/10 px-4 py-3 text-sm text-[var(--h-note)]">
                    Data jualan staf tidak dapat diambil drpd JEMiSys sekarang - cuba lagi sebentar.
                </p>

                <p v-else-if="data.rows.length === 0" class="rounded-lg border border-dashed border-[var(--h-line)] px-5 py-4 text-sm text-[var(--h-muted)]">
                    Belum ada jualan staf untuk {{ data.month_label }}<template v-if="!data.through"> (belum ada hari lengkap bulan ini)</template>.
                </p>

                <div v-else class="flex flex-col rounded-lg border border-[var(--h-line)]" :class="{ 'opacity-60': loading }">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--h-line)] px-4 py-2">
                        <div role="tablist" aria-label="Susun ikut" class="flex flex-wrap gap-1 rounded-full bg-[var(--h-fill)] p-1">
                            <button
                                v-for="m in METRICS"
                                :key="m.key"
                                type="button"
                                role="tab"
                                :aria-selected="metric === m.key"
                                class="rounded-full px-3 py-1 text-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                                :class="metric === m.key ? 'bg-[var(--h-gold2)]/25 text-[var(--h-fg)]' : 'text-[var(--h-muted)] hover:text-[var(--h-fg)]'"
                                @click="metric = m.key"
                            >
                                {{ m.label }}
                            </button>
                        </div>
                        <p v-if="data.total" class="text-xs tabular-nums text-[var(--h-muted)]">
                            {{ data.total.staff }} staf · {{ fmt.rm(data.total.rm) }} · {{ fmt.tickets(data.total.tickets) }} tiket
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-[12.5rem_1fr]">
                        <div class="flex flex-col items-center gap-1.5 border-b border-[var(--h-line)] px-4 py-4 sm:border-b-0 sm:border-r">
                            <DonutChart v-model:active="active" :slices="slices" :center-title="centerTitle" :center-sub="centerSub" />
                            <p class="text-center text-[11px] leading-tight text-[var(--h-muted)]">
                                {{ shareLabel }}
                                <template v-if="metric === 'avg_ticket'"><br />(purata tiket bukan jumlah)</template>
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-col" @pointerleave="active = null">
                            <!-- Butiran staf dalam HoverCard shadcn (muncul bila kursor/fokus pada baris). Kandungannya dirender dlm
                                 portal di luar .home-root, jadi guna warna shadcn (popover) - sentiasa selaras dgn mod gelap/cerah html. -->
                            <HoverCard v-for="(row, index) in visibleRows" :key="row.name" :open-delay="120" :close-delay="60">
                                <HoverCardTrigger as-child>
                                    <div
                                        tabindex="0"
                                        class="flex items-center gap-2.5 border-b border-[var(--h-line)] px-4 py-1.5 text-sm outline-none transition-colors last:border-b-0 focus-visible:bg-[var(--h-gold2)]/[0.07]"
                                        :class="isActive(row) ? 'bg-[var(--h-gold2)]/[0.07]' : ''"
                                        @pointerenter="active = sliceOf.get(row.name)?.key ?? null"
                                        @focus="active = sliceOf.get(row.name)?.key ?? null"
                                        @blur="active = null"
                                    >
                                        <span class="w-4 shrink-0 text-right text-xs tabular-nums" :class="index < 3 ? 'font-medium text-[var(--h-gold)]' : 'text-[var(--h-faint)]'">
                                            {{ index + 1 }}
                                        </span>
                                        <span class="size-2.5 shrink-0 rounded-full" :style="{ background: dotColor(row) }" aria-hidden="true" />
                                        <span class="w-24 shrink-0 truncate font-medium sm:w-28">
                                            {{ row.name }}<span v-if="row.shared" class="ml-1 text-[11px] font-normal text-[var(--h-faint)]">½</span>
                                        </span>
                                        <div class="h-1 min-w-8 flex-1 overflow-hidden rounded-full bg-[var(--h-line)]">
                                            <div class="h-full rounded-full bg-[var(--h-gold2)] transition-[width] duration-500" :style="{ width: barWidth(row) }" />
                                        </div>
                                        <span class="w-20 shrink-0 text-right tabular-nums text-[var(--h-gold)]">{{ fmt[metric](row[metric]) }}</span>
                                        <span class="hidden w-12 shrink-0 text-right text-xs tabular-nums text-[var(--h-faint)] md:inline">
                                            {{ row.share_pct === null ? '' : row.share_pct + '%' }}
                                        </span>
                                    </div>
                                </HoverCardTrigger>

                                <HoverCardContent side="top" align="center" :side-offset="6" class="w-64 p-3">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-sm font-medium">{{ row.name }}</span>
                                        <span class="text-xs text-muted-foreground">#{{ rankOf(row) }} daripada {{ sorted.length }}</span>
                                    </div>
                                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                                        <div class="flex flex-col">
                                            <dt class="text-muted-foreground">Jualan</dt>
                                            <dd class="font-medium tabular-nums">{{ fmt.rm(row.rm) }}</dd>
                                        </div>
                                        <div class="flex flex-col">
                                            <dt class="text-muted-foreground">Berat</dt>
                                            <dd class="font-medium tabular-nums">{{ fmt.grams(row.grams) }}</dd>
                                        </div>
                                        <div class="flex flex-col">
                                            <dt class="text-muted-foreground">Tiket</dt>
                                            <dd class="font-medium tabular-nums">{{ fmt.tickets(row.tickets) }}</dd>
                                        </div>
                                        <div class="flex flex-col">
                                            <dt class="text-muted-foreground">Purata tiket</dt>
                                            <dd class="font-medium tabular-nums">{{ fmt.avg_ticket(row.avg_ticket) }}</dd>
                                        </div>
                                    </dl>
                                    <p v-if="row.share_pct !== null" class="mt-2 text-xs text-muted-foreground">{{ row.share_pct }}% daripada jualan cawangan</p>
                                    <p v-if="row.shared" class="mt-1 text-[11px] text-muted-foreground">Termasuk jualan dikongsi (SalesMan2), dibahagi sama rata.</p>
                                </HoverCardContent>
                            </HoverCard>

                            <button
                                v-if="sorted.length > MAX_SLICES"
                                type="button"
                                class="border-t border-[var(--h-line)] px-4 py-1.5 text-left text-xs text-[var(--h-muted)] transition-colors hover:bg-[var(--h-gold2)]/[0.07] hover:text-[var(--h-fg)] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-[var(--h-gold)]"
                                @click="showAll = !showAll"
                            >
                                {{ showAll ? 'Tunjuk ' + MAX_SLICES + ' teratas sahaja' : 'Tunjuk semua ' + sorted.length + ' staf' }}
                            </button>
                        </div>
                    </div>

                    <p class="border-t border-[var(--h-line)] px-4 py-1.5 text-[11px] text-[var(--h-faint)]">
                        Sehingga {{ data.through ?? '-' }} · letak kursor pada staf utk butiran (kad hover) · ½ = jualan dikongsi (dibahagi sama rata) · tiket = invois, pemulangan tak ditolak
                    </p>
                </div>
            </BlurFade>
        </section>
    </Deferred>
</template>

<style>
/* Warna kepingan carta (8 staf teratas + lain-lain): kategori yg selaras dgn tema emas, kontras cukup pd latar gelap & cerah. */
.staff-chart {
    --sc-0: #e8c46a;
    --sc-1: #d98a3d;
    --sc-2: #c4584a;
    --sc-3: #9b74c2;
    --sc-4: #5a97c4;
    --sc-5: #4fb08f;
    --sc-6: #a8b256;
    --sc-7: #c9b58f;
    --sc-other: #7d7463;
}

.home-root[data-theme='light'] .staff-chart {
    --sc-0: #b8892a;
    --sc-1: #c2662a;
    --sc-2: #b23a3a;
    --sc-3: #7a4fa6;
    --sc-4: #2f6f9f;
    --sc-5: #2d8a6b;
    --sc-6: #7d8a2a;
    --sc-7: #8a7348;
    --sc-other: #9a917d;
}
</style>
