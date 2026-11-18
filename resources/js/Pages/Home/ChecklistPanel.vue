<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { Check, ClipboardCheck } from '@lucide/vue';
import { Motion } from 'motion-v';
import { computed, ref, watch } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import ConfettiBurst from '@/components/inspira/ConfettiBurst.vue';
import NumberTicker from '@/components/inspira/NumberTicker.vue';
import SpotlightCard from '@/components/inspira/SpotlightCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useHomeModules } from '@/lib/homeModules';

// Daily Checklist cawangan (rujuk App\Support\HomeSummary\ChecklistFeed): satu senarai bagi CAWANGAN, ditanda siap
// hari ini (waktu Malaysia), tiada bukti gambar. Tertunggak = belum siap selepas waktu "sebelum".
interface ChecklistItem {
    id: number;
    title: string;
    description: string | null;
    due: string | null;
    done: boolean;
    done_at: string | null;
    done_by: string | null;
    overdue: boolean;
}

interface Checklist {
    date: string;
    total: number;
    done: number;
    overdue: number;
    items: ChecklistItem[];
}

const page = usePage<{ hasBranch?: boolean; checklist?: Checklist | null }>();
const { has, orderStyle } = useHomeModules();
const hasBranch = computed(() => has('checklist'));
const data = computed(() => page.props.checklist ?? null);
const pct = computed(() => (data.value && data.value.total > 0 ? Math.round((data.value.done / data.value.total) * 100) : 0));
const busy = ref<number | null>(null);
const celebrate = ref(false);

// Letusan confetti sekali bila item terakhir ditanda (bukan semasa muat halaman dgn semua sudah siap).
watch(
    () => data.value?.done,
    (done, previous) => {
        if (data.value && previous !== undefined && done === data.value.total && data.value.total > 0 && done > previous) {
            celebrate.value = true;
            setTimeout(() => (celebrate.value = false), 1400);
        }
    },
);

function toggle(item: ChecklistItem): void {
    router.post(
        '/checklist/' + item.id,
        { done: !item.done },
        {
            only: ['checklist', 'flash'],
            preserveScroll: true,
            onStart: () => {
                busy.value = item.id;
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}
</script>

<template>
    <section v-if="hasBranch" id="section-checklist" class="flex flex-col gap-3" :style="orderStyle('checklist')">
        <Deferred data="checklist">
            <template #fallback>
                <div class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] p-5">
                    <Skeleton class="h-6 w-48 bg-[var(--h-line)]" />
                    <Skeleton v-for="n in 3" :key="n" class="h-10 w-full bg-[var(--h-line)]" />
                </div>
            </template>

            <template v-if="data && data.total > 0">
                <BlurFade class="flex items-center gap-3">
                    <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                        <ClipboardCheck class="size-3.5 text-[var(--h-gold)]" />
                        Daily Checklist
                    </h2>
                    <span class="rounded-full bg-[var(--h-gold2)]/25 px-2 py-0.5 text-[10px] font-medium text-[var(--h-gold)]">Hari ini · {{ data.date }}</span>
                    <span
                        v-if="data.overdue > 0"
                        class="rounded-full bg-[var(--h-bad)]/20 px-2 py-0.5 text-[10px] font-medium text-[var(--h-bad)]"
                    >
                        {{ data.overdue }} tertunggak
                    </span>
                    <span class="h-px flex-1 bg-[var(--h-line)]" />
                    <span class="text-xs text-[var(--h-muted)]"><NumberTicker :value="String(data.done)" :duration="500" /> / {{ data.total }}</span>
                </BlurFade>

                <BlurFade :delay="0.05">
                    <SpotlightCard class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] bg-[var(--h-panel)] p-5">
                        <ConfettiBurst :active="celebrate" />
                        <p v-if="data.done === data.total" class="text-sm font-medium text-[var(--h-good)]">Semua tugasan hari ini siap. Syabas!</p>
                        <div class="h-1.5 overflow-hidden rounded-full bg-[var(--h-line)]">
                            <div
                                class="h-full rounded-full transition-[width] duration-700 ease-out"
                                :class="data.done === data.total ? 'bg-[var(--h-good)]' : 'bg-[var(--h-gold2)]'"
                                :style="{ width: pct + '%' }"
                            />
                        </div>

                        <ul class="flex flex-col divide-y divide-[var(--h-line)]">
                            <li v-for="item in data.items" :key="item.id">
                                <button
                                    type="button"
                                    :disabled="busy === item.id"
                                    :aria-pressed="item.done"
                                    class="flex w-full items-center gap-3 py-2.5 text-left transition-opacity focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)] disabled:opacity-60"
                                    @click="toggle(item)"
                                >
                                    <span
                                        class="flex size-5 shrink-0 items-center justify-center rounded border"
                                        :class="item.done ? 'border-[var(--h-good)] bg-[var(--h-good)]/20 text-[var(--h-good)]' : item.overdue ? 'border-[var(--h-bad)]' : 'border-[var(--h-faint)]'"
                                    >
                                        <Motion v-if="item.done" :initial="{ scale: 0, rotate: -45 }" :animate="{ scale: 1, rotate: 0 }" :transition="{ type: 'spring', stiffness: 500, damping: 18 }">
                                            <Check class="size-3.5" />
                                        </Motion>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm" :class="item.done ? 'text-[var(--h-muted)] line-through' : ''">{{ item.title }}</span>
                                        <span v-if="item.description" class="block text-xs text-[var(--h-muted)]">{{ item.description }}</span>
                                    </span>
                                    <span class="shrink-0 text-right text-xs">
                                        <span v-if="item.done" class="text-[var(--h-good)]">{{ item.done_at }} · {{ item.done_by }}</span>
                                        <span v-else-if="item.overdue" class="text-[var(--h-bad)]">Tertunggak · sebelum {{ item.due }}</span>
                                        <span v-else-if="item.due" class="text-[var(--h-muted)]">Sebelum {{ item.due }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </SpotlightCard>
                </BlurFade>
            </template>
        </Deferred>
    </section>
</template>
