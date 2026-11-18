<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import type { BranchOverview, PeriodOption } from './types';
import { SOURCE_BADGE_CLASS, STOCK_BADGE_CLASS, sourceLabel, stockLabel } from './types';

// Satu kad utk jualan & stok cawangan: togol "Terlaris" (ikut jualan) dan "Perlu restock" (ikut keperluan),
// satu dropdown tempoh. Setiap baris bawa jualan, stok semasa, transit dan - bila patut direstock - jumlah
// cadangan + sumber stok (HQ / cawangan lain / perlu order). Warna ikut token tema /home (--h-*).
const props = defineProps<{
    data: BranchOverview;
    periods: PeriodOption[];
    period: string;
    // Pautan "Minta stok" - tiada utk modul universal (banyak cawangan).
    requestUrl?: string | null;
    loading?: boolean;
}>();

const emit = defineEmits<{ change: [period: string] }>();

type View = 'top' | 'restock';

const view = ref<View>('top');

const rows = computed(() => (view.value === 'top' ? props.data.top : props.data.restock));

const rm = (value: number) => 'RM' + value.toLocaleString('en-MY', { maximumFractionDigits: 0 });

const tabs = computed(() => [
    { key: 'top' as View, label: 'Terlaris' },
    { key: 'restock' as View, label: 'Perlu restock (' + props.data.restock_total + ')' },
]);
</script>

<template>
    <div class="flex flex-col rounded-lg border border-[var(--h-line)]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--h-line)] px-5 py-3">
            <div role="tablist" aria-label="Paparan jualan dan stok" class="flex gap-1 rounded-full bg-[var(--h-fill)] p-1">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="view === tab.key"
                    class="rounded-full px-4 py-1.5 text-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                    :class="view === tab.key ? 'bg-[var(--h-gold2)]/25 text-[var(--h-fg)]' : 'text-[var(--h-muted)] hover:text-[var(--h-fg)]'"
                    @click="view = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>

            <div class="flex items-center gap-2">
                <select
                    :value="period"
                    :disabled="loading"
                    aria-label="Tempoh jualan"
                    class="rounded-md bg-[var(--h-fill)] px-3 py-1.5 text-sm text-[var(--h-soft)] outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)] disabled:opacity-50"
                    @change="emit('change', ($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="p in periods" :key="p.value" :value="p.value" class="bg-[var(--h-bg)]">{{ p.label }}</option>
                </select>
                <Link
                    v-if="requestUrl"
                    :href="requestUrl"
                    class="rounded-full bg-[var(--h-fill)] px-3 py-1.5 text-sm text-[var(--h-soft)] transition-colors hover:bg-[var(--h-gold2)]/20 hover:text-[var(--h-fg)] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                >
                    Minta stok
                </Link>
            </div>
        </div>

        <p class="px-5 pt-3 text-xs text-[var(--h-muted)]">
            <template v-if="view === 'top'">{{ data.period_label }} · sejak {{ data.since }} · maksimum 20 design terlaris</template>
            <template v-else>
                Laris dalam {{ data.period_label.toLowerCase() }} terakhir, stok (termasuk transit) di bawah sasaran 1.5 bulan jualan
            </template>
        </p>

        <div :class="{ 'opacity-60': loading }" class="flex flex-col transition-opacity">
            <p v-if="rows.length === 0" class="px-5 py-4 text-sm text-[var(--h-muted)]">
                <template v-if="view === 'top'">Tiada jualan design dalam tempoh ini.</template>
                <template v-else>Tiada cadangan restock - stok design laris mencukupi.</template>
            </p>

            <div
                v-for="(row, index) in rows"
                :key="view + row.code"
                class="flex items-center gap-3 border-b border-[var(--h-line)] px-5 py-3 last:border-b-0"
            >
                <span class="w-5 shrink-0 text-right text-xs tabular-nums text-[var(--h-faint)]">{{ index + 1 }}</span>

                <ImagePreview v-if="row.image_url" :src="row.image_url" :alt="row.name" class="size-12 rounded" />
                <div v-else class="flex size-12 shrink-0 items-center justify-center rounded bg-[var(--h-fill)] text-[10px] text-[var(--h-faint)]">
                    Tiada imej
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="truncate text-sm font-medium">{{ row.name }}</span>
                    <span class="truncate text-xs text-[var(--h-muted)]">
                        {{ row.code }}<template v-if="row.category"> · {{ row.category }}</template>
                    </span>
                    <span class="text-xs tabular-nums text-[var(--h-soft)]">
                        <strong class="font-medium text-[var(--h-fg)]">{{ row.pieces }} pcs</strong> · {{ row.grams.toFixed(2) }} g · {{ rm(row.rm) }}
                        <template v-if="view === 'restock'"> · sasaran {{ row.target }}</template>
                    </span>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1">
                    <span v-if="row.suggest" class="text-sm font-medium tabular-nums text-[var(--h-gold)]">Restock +{{ row.need }}</span>
                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="STOCK_BADGE_CLASS[row.stock_tone]">
                        {{ stockLabel(row.stock_here) }}
                    </span>
                    <span v-if="row.incoming > 0" class="text-[11px] text-[var(--h-muted)]">+{{ row.incoming }} transit</span>
                    <span v-if="row.suggest" class="rounded px-1.5 py-0.5 text-xs font-medium" :class="SOURCE_BADGE_CLASS[row.source]">
                        {{ sourceLabel(row) }}
                    </span>
                </div>
            </div>
        </div>

        <p v-if="data.synced_at" class="border-t border-[var(--h-line)] px-5 py-2 text-[11px] text-[var(--h-faint)]">
            Data JEMiSys setakat {{ data.synced_at }}
        </p>
    </div>
</template>
