<script setup lang="ts">
import { Deferred, useForm, usePage } from '@inertiajs/vue3';
import { Target } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import NumberTicker from '@/components/inspira/NumberTicker.vue';
import SpotlightCard from '@/components/inspira/SpotlightCard.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';

// Sasaran bulan semasa vs jualan sebenar (RM & gram) cawangan ketua yg log masuk (rujuk MonthlyTargetFeed).
// Ketua cawangan boleh menetapkan sasaran jika belum ada, dan mengemas kini jika sudah ada (catatan WAJIB) -
// disahkan semula di server (BranchTargetController). Kad ikut token tema --h-*; dialog ialah shadcn (portal).
interface Metric {
    target: number;
    actual: number;
    pct: number | null;
    remaining: number;
    run_rate: number | null;
    needed_per_day: number | null;
    forecast: number | null;
    forecast_pct: number | null;
    on_track: boolean | null;
}

interface MonthlyTarget {
    month: string;
    month_label: string;
    through: string | null;
    target: { rm: number; grams: number; remark: string | null; source: 'hq' | 'branch'; set_by: string; updated_at: string } | null;
    actual: { rm: number; grams: number } | null;
    progress: { days_in_month: number; days_elapsed: number; days_left: number; rm: Metric; grams: Metric } | null;
    requires_remark: boolean;
}

const page = usePage<{ hasBranch?: boolean; monthlyTarget?: MonthlyTarget | null }>();

const { has, orderStyle } = useHomeModules();
const hasBranch = computed(() => has('target'));
const data = computed(() => page.props.monthlyTarget ?? null);

const open = ref(false);
const form = useForm({ target_rm: '' as string | number, target_grams: '' as string | number, remark: '' });

function openDialog(): void {
    form.reset();
    form.clearErrors();
    form.target_rm = data.value?.target?.rm ?? '';
    form.target_grams = data.value?.target?.grams ?? '';
    open.value = true;
}

function submit(): void {
    form.post('/branch-targets', {
        preserveScroll: true,
        only: ['monthlyTarget', 'flash', 'errors'],
        onSuccess: () => {
            open.value = false;
        },
    });
}

watch(open, (isOpen) => {
    if (!isOpen) {
        form.clearErrors();
    }
});

const rm = (value: number | null) => (value === null ? '-' : 'RM' + value.toLocaleString('en-MY', { maximumFractionDigits: 0 }));
const grams = (value: number | null) => (value === null ? '-' : value.toLocaleString('en-MY', { maximumFractionDigits: 1 }) + ' g');

const cards = computed(() => {
    const progress = data.value?.progress;

    return progress
        ? [
              { key: 'rm', title: 'Sasaran jualan (RM)', metric: progress.rm, fmt: rm },
              { key: 'grams', title: 'Sasaran berat (gram)', metric: progress.grams, fmt: grams },
          ]
        : [];
});

function barWidth(metric: Metric): string {
    return Math.min(metric.pct ?? 0, 100) + '%';
}
</script>

<template>
    <Deferred v-if="hasBranch" data="monthlyTarget">
        <template #fallback>
            <div class="flex flex-col gap-4 rounded-lg border border-[var(--h-line)] p-5">
                <Skeleton class="h-4 w-48 bg-[var(--h-line)]" />
                <Skeleton class="h-16 w-full bg-[var(--h-line)]" />
            </div>
        </template>

        <section v-if="data" id="section-monthly-target" class="flex flex-col gap-3" :style="orderStyle('target')">
            <BlurFade class="flex items-center gap-3">
                <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                    <Target class="size-3.5 text-[var(--h-gold)]" />
                    Sasaran Bulanan · {{ data.month_label }}
                </h2>
                <span
                    v-if="data.through"
                    class="rounded-full bg-[var(--h-gold2)]/25 px-2 py-0.5 text-[10px] font-medium text-[var(--h-gold)]"
                >
                    Sehingga semalam · {{ data.through }}
                </span>
                <span class="h-px flex-1 bg-[var(--h-line)]" />
                <button
                    type="button"
                    class="rounded-full bg-[var(--h-fill)] px-3 py-1 text-xs text-[var(--h-soft)] transition-colors hover:bg-[var(--h-gold2)]/20 hover:text-[var(--h-fg)] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                    @click="openDialog"
                >
                    {{ data.target ? 'Kemas kini sasaran' : 'Tetapkan sasaran' }}
                </button>
            </BlurFade>

            <BlurFade :delay="0.05">
                <p
                    v-if="!data.target"
                    class="rounded-lg border border-dashed border-[var(--h-line)] px-5 py-4 text-sm text-[var(--h-muted)]"
                >
                    Belum ada sasaran untuk {{ data.month_label }}. Tekan "Tetapkan sasaran" untuk mengisi sasaran jualan (RM) dan berat (gram) cawangan anda.
                </p>

                <div v-else class="flex flex-col gap-3">
                    <p v-if="!data.actual" class="rounded-md border border-[var(--h-gold2)]/30 bg-[var(--h-gold2)]/10 px-4 py-2 text-sm text-[var(--h-note)]">
                        Jualan sebenar tidak dapat diambil drpd JEMiSys sekarang - sasaran tetap dipaparkan.
                    </p>

                    <div v-if="cards.length > 0" class="grid gap-4 lg:grid-cols-2">
                        <SpotlightCard v-for="card in cards" :key="card.key" class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-xs text-[var(--h-muted)]">{{ card.title }}</span>
                                <span
                                    v-if="card.metric.on_track !== null"
                                    class="rounded px-1.5 py-0.5 text-xs font-medium"
                                    :class="card.metric.on_track ? 'bg-[var(--h-good)]/20 text-[var(--h-good)]' : 'bg-[var(--h-warn)]/20 text-[var(--h-warn)]'"
                                >
                                    {{ card.metric.on_track ? 'Di landasan' : 'Di bawah sasaran' }}
                                </span>
                            </div>

                            <div class="flex items-baseline gap-2">
                                <span class="text-2xl font-medium"><NumberTicker :value="card.fmt(card.metric.actual)" /></span>
                                <span class="text-sm text-[var(--h-muted)]">/ {{ card.fmt(card.metric.target) }}</span>
                                <span v-if="card.metric.pct !== null" class="ml-auto text-sm font-medium tabular-nums text-[var(--h-gold)]">
                                    {{ card.metric.pct }}%
                                </span>
                            </div>

                            <div class="h-1.5 overflow-hidden rounded-full bg-[var(--h-line)]">
                                <div class="h-full rounded-full bg-[var(--h-gold2)] transition-[width] duration-700" :style="{ width: barWidth(card.metric) }" />
                            </div>

                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                                <div class="flex flex-col">
                                    <dt class="text-[var(--h-muted)]">Baki sasaran</dt>
                                    <dd class="tabular-nums text-[var(--h-soft)]">{{ card.fmt(card.metric.remaining) }}</dd>
                                </div>
                                <div class="flex flex-col">
                                    <dt class="text-[var(--h-muted)]">Purata harian semasa</dt>
                                    <dd class="tabular-nums text-[var(--h-soft)]">{{ card.fmt(card.metric.run_rate) }}</dd>
                                </div>
                                <div class="flex flex-col">
                                    <dt class="text-[var(--h-muted)]">Perlu sehari (baki {{ data.progress?.days_left }} hari)</dt>
                                    <dd class="tabular-nums text-[var(--h-soft)]">{{ card.fmt(card.metric.needed_per_day) }}</dd>
                                </div>
                                <div class="flex flex-col">
                                    <dt class="text-[var(--h-muted)]">Ramalan hujung bulan</dt>
                                    <dd class="tabular-nums text-[var(--h-soft)]">
                                        {{ card.fmt(card.metric.forecast) }}
                                        <span v-if="card.metric.forecast_pct !== null" class="text-[var(--h-muted)]">({{ card.metric.forecast_pct }}%)</span>
                                    </dd>
                                </div>
                            </dl>
                        </SpotlightCard>
                    </div>

                    <p class="text-[11px] text-[var(--h-faint)]">
                        <template v-if="data.through">Sebenar sehingga {{ data.through }} ({{ data.progress?.days_elapsed }} / {{ data.progress?.days_in_month }} hari). </template>
                        Ditetapkan oleh {{ data.target.source === 'hq' ? 'HQ' : 'cawangan' }} ({{ data.target.set_by }}) · {{ data.target.updated_at }}
                        <template v-if="data.target.remark"> · Catatan: {{ data.target.remark }}</template>
                    </p>
                </div>
            </BlurFade>

            <Dialog v-model:open="open">
                <DialogContent class="sm:max-w-md">
                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <DialogHeader>
                            <DialogTitle>{{ data.target ? 'Kemas kini' : 'Tetapkan' }} sasaran {{ data.month_label }}</DialogTitle>
                            <DialogDescription>
                                <template v-if="data.target?.source === 'hq'">
                                    Sasaran ini ditetapkan oleh HQ. Perubahan anda direkod bersama catatan.
                                </template>
                                <template v-else>Sasaran jualan bulan ini bagi cawangan anda.</template>
                            </DialogDescription>
                        </DialogHeader>

                        <div class="flex flex-col gap-1.5">
                            <Label for="target_rm">Sasaran jualan (RM)</Label>
                            <Input id="target_rm" v-model="form.target_rm" type="number" min="0" step="0.01" inputmode="decimal" required />
                            <p v-if="form.errors.target_rm" class="text-xs text-destructive">{{ form.errors.target_rm }}</p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="target_grams">Sasaran berat (gram)</Label>
                            <Input id="target_grams" v-model="form.target_grams" type="number" min="0" step="0.01" inputmode="decimal" required />
                            <p v-if="form.errors.target_grams" class="text-xs text-destructive">{{ form.errors.target_grams }}</p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="remark">Catatan <span v-if="data.requires_remark" class="text-destructive">*</span></Label>
                            <textarea
                                id="remark"
                                v-model="form.remark"
                                rows="3"
                                maxlength="500"
                                :required="data.requires_remark"
                                :placeholder="data.requires_remark ? 'Sebab sasaran dikemas kini (wajib)' : 'Pilihan'"
                                class="border-input bg-transparent dark:bg-input/30 min-h-16 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3"
                            />
                            <p v-if="form.errors.remark" class="text-xs text-destructive">{{ form.errors.remark }}</p>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" @click="open = false">Batal</Button>
                            <Button type="submit" :disabled="form.processing">{{ form.processing ? 'Menyimpan...' : 'Simpan' }}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    </Deferred>
</template>
