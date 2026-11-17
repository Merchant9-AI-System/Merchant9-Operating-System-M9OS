<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { Check, Megaphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';

// Arahan HQ utk cawangan ketua yg log masuk (rujuk App\Support\HomeSummary\HqDirectionFeed): kelihatan selagi belum
// diakui; selepas "Faham" kekal pada hari itu sahaja. `html` dihantar SUDAH DISANITASI oleh server (Filament
// sanitizeHtml) - itulah satu-satunya sebab v-html selamat di sini. Reka bentuk ikut token tema --h-*.
interface Direction {
    id: number;
    title: string;
    html: string;
    effective_from: string;
    effective_to: string | null;
    created_by: string;
    created_at: string;
    acknowledged: boolean;
    acknowledged_at: string | null;
    acknowledged_by: string | null;
}

const page = usePage<{ hasBranch?: boolean; hqDirections?: Direction[] | null }>();

const hasBranch = computed(() => page.props.hasBranch === true);
const directions = computed(() => page.props.hqDirections ?? []);
const pendingCount = computed(() => directions.value.filter((d) => !d.acknowledged).length);

const submitting = ref<number | null>(null);

function acknowledge(direction: Direction): void {
    router.post(
        '/hq-directions/' + direction.id + '/acknowledge',
        {},
        {
            only: ['hqDirections', 'flash'],
            preserveScroll: true,
            onStart: () => {
                submitting.value = direction.id;
            },
            onFinish: () => {
                submitting.value = null;
            },
        },
    );
}

function period(direction: Direction): string {
    return direction.effective_to ? direction.effective_from + ' hingga ' + direction.effective_to : 'dari ' + direction.effective_from;
}
</script>

<template>
    <Deferred v-if="hasBranch" data="hqDirections">
        <template #fallback><span /></template>

        <section v-if="directions.length > 0" id="section-hq-directions" class="flex flex-col gap-3">
            <BlurFade class="flex items-center gap-3">
                <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                    <Megaphone class="size-3.5 text-[var(--h-gold)]" />
                    Arahan HQ
                    <span
                        v-if="pendingCount > 0"
                        class="rounded-full bg-[var(--h-gold2)]/25 px-2 py-0.5 text-[11px] font-medium normal-case tracking-normal text-[var(--h-gold)]"
                    >
                        {{ pendingCount }} belum faham
                    </span>
                </h2>
                <span class="h-px flex-1 bg-[var(--h-line)]" />
            </BlurFade>

            <BlurFade v-for="(direction, index) in directions" :key="direction.id" :delay="0.05 + index * 0.05">
                <article
                    class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] border-l-2 bg-[var(--h-panel)] p-5"
                    :class="direction.acknowledged ? 'border-l-[var(--h-good)]' : 'border-l-[var(--h-gold)]'"
                >
                    <div class="flex flex-col gap-1">
                        <h3 class="text-base font-medium">{{ direction.title }}</h3>
                        <p class="text-xs text-[var(--h-muted)]">
                            {{ direction.created_by }} · {{ direction.created_at }} · berkuat kuasa {{ period(direction) }}
                        </p>
                    </div>

                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div class="hq-rich text-sm leading-relaxed text-[var(--h-soft)]" v-html="direction.html" />

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--h-line)] pt-3">
                        <p v-if="direction.acknowledged" class="flex items-center gap-1.5 text-xs text-[var(--h-good)]">
                            <Check class="size-3.5" />
                            Difahami {{ direction.acknowledged_at }} oleh {{ direction.acknowledged_by }} · arahan ini kelihatan hari ini sahaja
                        </p>
                        <p v-else class="text-xs text-[var(--h-muted)]">Tekan "Faham" selepas membaca - arahan akan hilang esok.</p>

                        <button
                            v-if="!direction.acknowledged"
                            type="button"
                            :disabled="submitting === direction.id"
                            class="rounded-full bg-[var(--h-gold2)]/25 px-4 py-1.5 text-sm font-medium text-[var(--h-fg)] transition-colors hover:bg-[var(--h-gold2)]/40 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)] disabled:opacity-50"
                            @click="acknowledge(direction)"
                        >
                            {{ submitting === direction.id ? 'Menyimpan...' : 'Faham' }}
                        </button>
                    </div>
                </article>
            </BlurFade>
        </section>
    </Deferred>
</template>

<style>
/* Gaya kandungan rich editor (HTML disanitasi server). Tailwind preflight membuang gaya asas senarai/tajuk. */
.hq-rich p {
    margin: 0 0 0.6rem;
}
.hq-rich p:last-child {
    margin-bottom: 0;
}
.hq-rich ul {
    margin: 0 0 0.6rem;
    padding-left: 1.25rem;
    list-style: disc;
}
.hq-rich ol {
    margin: 0 0 0.6rem;
    padding-left: 1.25rem;
    list-style: decimal;
}
.hq-rich h2 {
    margin: 0.75rem 0 0.35rem;
    font-size: 1rem;
    font-weight: 600;
    color: var(--h-fg);
}
.hq-rich h3 {
    margin: 0.6rem 0 0.3rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--h-fg);
}
.hq-rich a {
    color: var(--h-gold);
    text-decoration: underline;
}
.hq-rich strong {
    color: var(--h-fg);
}
</style>
