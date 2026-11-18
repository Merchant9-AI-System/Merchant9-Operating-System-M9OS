<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { Gem } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';
import BranchOverviewCard from './BranchOverviewCard.vue';
import ChecklistPanel from './ChecklistPanel.vue';
import RearrangePanel from './RearrangePanel.vue';
import MonthlyTargetPanel from './MonthlyTargetPanel.vue';
import StaffRankingPanel from './StaffRankingPanel.vue';
import UniversalRankingPanel from './UniversalRankingPanel.vue';
import UniversalStockPanel from './UniversalStockPanel.vue';
import UniversalSummaryPanel from './UniversalSummaryPanel.vue';
import UniversalTargetPanel from './UniversalTargetPanel.vue';
import type { BranchOverview, PeriodOption } from './types';

// Jualan & stok cawangan pada /home (ketua cawangan sahaja): satu kad gabungan Terlaris + Perlu restock.
// Data ialah deferred prop (branchOverview) supaya tak menyekat ringkasan. Komponen ni baca prop halaman terus
// (usePage) - Index.vue hanya perlu merendernya. Reka bentuk ikut token tema --h-* drpd .home-root induknya.
const page = usePage<{
    hasBranch?: boolean;
    requestUrl: string;
    periods: PeriodOption[];
    period: string;
    rankingPeriod?: string;
    branchOverview?: BranchOverview | null;
}>();

// Tab aktif drpd Index.vue: 'all' = semua modul; selainnya hanya modul berkunci sama (checklist/target/ranking/rearrange/stock).
const props = withDefaults(defineProps<{ tab?: string }>(), { tab: 'all' });
const shows = (key: string): boolean => props.tab === 'all' || props.tab === key;

const { has, orderStyle } = useHomeModules();
const hasBranch = computed(() => has('stock'));
const loading = ref(false);

// Tukar tempoh: muat semula prop branchOverview sahaja (partial reload) dgn ?period= baharu.
function changePeriod(period: string): void {
    router.get(
        '/home',
        // ranking_period dihantar sekali supaya pilihan modul ranking kekal dlm URL.
        { period, ranking_period: page.props.rankingPeriod },
        {
            only: ['branchOverview', 'period'],
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
    <!-- Sasaran bulanan (di atas jualan & stok). Komponen ni bahagian root (fragment) - kedua-duanya dirender hanya bila hasBranch. -->
    <ChecklistPanel v-if="shows('checklist')" />
    <MonthlyTargetPanel v-if="shows('target')" />
    <StaffRankingPanel v-if="shows('ranking')" />
    <RearrangePanel v-if="shows('rearrange')" />
    <!-- Modul universal (skop cawangan ditetapkan pentadbir; sesuai utk peranan tanpa cawangan). -->
    <UniversalSummaryPanel v-if="shows('u_summary')" />
    <UniversalTargetPanel v-if="shows('u_target')" />
    <UniversalRankingPanel v-if="shows('u_ranking')" />
    <UniversalStockPanel v-if="shows('u_stock')" />

    <section v-if="hasBranch && shows('stock')" id="section-branch-modules" class="flex flex-col gap-3" :style="orderStyle('stock')">
        <BlurFade class="flex items-center gap-3">
            <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                <Gem class="size-3.5 text-[var(--h-gold)]" />
                Jualan &amp; Stok Cawangan
            </h2>
            <span class="h-px flex-1 bg-[var(--h-line)]" />
        </BlurFade>

        <Deferred data="branchOverview">
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

            <BlurFade v-if="page.props.branchOverview" :delay="0.05">
                <BranchOverviewCard
                    :data="page.props.branchOverview"
                    :periods="page.props.periods"
                    :period="page.props.period"
                    :request-url="page.props.requestUrl"
                    :loading="loading"
                    @change="changePeriod"
                />
            </BlurFade>
        </Deferred>
    </section>
</template>
