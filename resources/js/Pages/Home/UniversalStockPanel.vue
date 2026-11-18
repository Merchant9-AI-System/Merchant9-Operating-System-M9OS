<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { Boxes } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';
import BranchOverviewCard from './BranchOverviewCard.vue';
import type { BranchOverview, PeriodOption } from './types';

// Jualan & Stok (universal): Terlaris / Perlu restock bagi GABUNGAN cawangan dalam skop (semua / dipilih / sendiri) -
// kad yang sama dgn modul cawangan (BranchOverviewCard), tanpa "Minta stok". "Stok" = stok gabungan cawangan dlm skop.
const page = usePage<{
    periods: PeriodOption[];
    period: string;
    rankingPeriod?: string;
    universalStock?: (BranchOverview & { scope_label: string }) | null;
}>();

const { has, orderStyle } = useHomeModules();
const enabled = computed(() => has('u_stock'));
const data = computed(() => page.props.universalStock ?? null);
const loading = ref(false);

// Tukar tempoh: muat semula modul ini sahaja (partial reload) dgn ?period= baharu.
function changePeriod(period: string): void {
    router.get(
        '/home',
        { period, ranking_period: page.props.rankingPeriod },
        {
            only: ['universalStock', 'period'],
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
    <section v-if="enabled" id="section-u-stock" class="flex flex-col gap-3" :style="orderStyle('u_stock')">
        <BlurFade class="flex items-center gap-3">
            <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                <Boxes class="size-3.5 text-[var(--h-gold)]" />
                Jualan &amp; Stok
            </h2>
            <span v-if="data" class="rounded-full bg-[var(--h-fill)] px-2 py-0.5 text-[10px] font-medium text-[var(--h-soft)]">{{ data.scope_label }}</span>
            <span class="h-px flex-1 bg-[var(--h-line)]" />
        </BlurFade>

        <Deferred data="universalStock">
            <template #fallback>
                <div class="flex flex-col gap-4 rounded-lg border border-[var(--h-line)] p-5">
                    <Skeleton class="h-8 w-72 bg-[var(--h-line)]" />
                    <div v-for="n in 5" :key="n" class="flex items-center gap-3">
                        <Skeleton class="size-12 rounded bg-[var(--h-line)]" />
                        <div class="flex flex-1 flex-col gap-2">
                            <Skeleton class="h-3 w-48 bg-[var(--h-line)]" />
                            <Skeleton class="h-3 w-32 bg-[var(--h-line)]" />
                        </div>
                    </div>
                </div>
            </template>

            <BlurFade v-if="data" :delay="0.05">
                <BranchOverviewCard :data="data" :periods="page.props.periods" :period="page.props.period" :loading="loading" @change="changePeriod" />
            </BlurFade>
        </Deferred>
    </section>
</template>
