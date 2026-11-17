<script setup lang="ts">
import { Deferred, usePage } from '@inertiajs/vue3';
import { ArrowRightLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import NumberTicker from '@/components/inspira/NumberTicker.vue';
import SpotlightCard from '@/components/inspira/SpotlightCard.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import LazyDesignImage from './LazyDesignImage.vue';

// Susun semula stok (rujuk App\Support\HomeSummary\RearrangeFeed): transfer m9os (stock_transfers) disemak silang dgn
// jadual cermin JEMiSys (jemisys_inventory_mirror) kerana staf bekerja dlm JEMiSys sebenar - status transfer m9os tak
// dikemas kini. Tiga tab (kotak ringkasan boleh diklik): design perlu DIHANTAR (cawangan ini sumber), akan DITERIMA
// (cawangan ini destinasi) dan LAPUK (sejarah, >14 hari tanpa bukti). Status setiap design drpd cermin.
interface RearrangeItem {
    direction?: 'hantar' | 'terima' | null;
    requested_at?: string | null;
    requested_by?: string | null;
    // Baris tanpa transfer m9os (keping Transit di JEMiSys, cth. daripada HQ): id string, tiada nombor transfer/umur.
    id: number | string;
    source?: 'jemisys';
    number: string | null;
    code: string;
    desc: string | null;
    from: string;
    to: string;
    qty: number;
    outstanding: number;
    in_transit: number;
    state: string;
    age_days: number | null;
    stock: number;
    image: string | null;
}

interface Rearrange {
    available: boolean;
    to_send: number;
    to_send_pieces: number;
    incoming: number;
    incoming_pieces: number;
    stale: number;
    items: RearrangeItem[];
    receive_items: RearrangeItem[];
    stale_items: RearrangeItem[];
}

type TabKey = 'send' | 'receive' | 'stale';

const page = usePage<{ hasBranch?: boolean; rearrange?: Rearrange | null }>();
const hasBranch = computed(() => page.props.hasBranch === true);
const data = computed(() => page.props.rearrange ?? null);

// Tab lalai = yg ada kerja: hantar dahulu, kemudian terima, kemudian lapuk. Pilihan pengguna dikekalkan selepas data dimuat semula.
const tab = ref<TabKey | null>(null);
const activeTab = computed<TabKey>(() => {
    if (tab.value) {
        return tab.value;
    }

    if (data.value && data.value.to_send === 0 && data.value.incoming > 0) {
        return 'receive';
    }

    if (data.value && data.value.to_send === 0 && data.value.incoming === 0 && data.value.stale > 0) {
        return 'stale';
    }

    return 'send';
});

const tabs = computed(() => [
    {
        key: 'send' as TabKey,
        title: 'Design perlu dihantar',
        count: data.value?.to_send ?? 0,
        sub: (data.value?.to_send_pieces ?? 0) + ' unit · cawangan ini sumber',
        accent: 'text-[var(--h-gold)]',
    },
    {
        key: 'receive' as TabKey,
        title: 'Design akan diterima',
        count: data.value?.incoming ?? 0,
        sub: (data.value?.incoming_pieces ?? 0) + ' unit · termasuk daripada HQ',
        accent: '',
    },
    {
        key: 'stale' as TabKey,
        title: 'Lapuk (sejarah)',
        count: data.value?.stale ?? 0,
        sub: '>14 hari, tiada bukti di JEMiSys',
        accent: 'text-[var(--h-muted)]',
    },
]);

const list = computed<RearrangeItem[]>(() => {
    if (!data.value) {
        return [];
    }

    return activeTab.value === 'send' ? data.value.items : activeTab.value === 'receive' ? data.value.receive_items : data.value.stale_items;
});

const EMPTY: Record<TabKey, string> = {
    send: 'Tiada design tertunggak untuk dihantar.',
    receive: 'Tiada design yang sedang ditunggu.',
    stale: 'Tiada sejarah lapuk.',
};

function age(days: number | null): string {
    return days === null ? '' : days <= 0 ? 'hari ini' : days + ' hari lepas';
}

function route(item: RearrangeItem): string {
    return (activeTab.value === 'send' ? 'ke ' + item.to : 'daripada ' + item.from) + ' · ' + (item.number ?? 'tiada transfer m9os');
}

// Status design drpd bukti cermin.
function status(item: RearrangeItem): { text: string; cls: string } {
    if (activeTab.value === 'send') {
        return {
            text: (item.state === 'sebahagian' ? 'Sebahagian sudah bergerak' : 'Belum dihantar') + ' · stok sini ' + item.stock,
            cls: item.stock >= item.outstanding ? 'text-[var(--h-good)]' : 'text-[var(--h-bad)]',
        };
    }

    if (activeTab.value === 'receive') {
        return item.in_transit > 0
            ? {
                  text: 'Dalam transit (' + item.in_transit + ')',
                  cls: 'text-[var(--h-good)]',
              }
            : {
                  text: 'Belum dihantar oleh ' + item.from,
                  cls: 'text-[var(--h-warn)]',
              };
    }

    return {
        text: item.direction === 'terima' ? 'Untuk diterima' : 'Untuk dihantar',
        cls: 'text-[var(--h-muted)]',
    };
}

function detail(item: RearrangeItem): string {
    if (activeTab.value === 'stale') {
        return route(item) + ' · diminta ' + (item.requested_at ?? '') + (item.requested_by ? ' oleh ' + item.requested_by : '');
    }

    return item.source === 'jemisys' ? route(item) : route(item) + ' · ' + age(item.age_days);
}

function qtyText(item: RearrangeItem): string {
    const partial = item.outstanding !== item.qty && activeTab.value !== 'receive';

    return (partial ? item.outstanding + ' / ' + item.qty : String(item.qty)) + ' unit' + (activeTab.value === 'stale' ? ' · ' + item.age_days + ' hari' : '');
}
</script>

<template>
    <section v-if="hasBranch" id="section-rearrange" class="flex flex-col gap-3">
        <BlurFade class="flex items-center gap-3">
            <h2 class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">
                <ArrowRightLeft class="size-3.5 text-[var(--h-gold)]" />
                Susun Semula Stok
            </h2>
            <span class="h-px flex-1 bg-[var(--h-line)]" />
        </BlurFade>

        <Deferred data="rearrange">
            <template #fallback>
                <div class="flex flex-col gap-3 rounded-lg border border-[var(--h-line)] p-5">
                    <Skeleton class="h-8 w-64 bg-[var(--h-line)]" />
                    <Skeleton v-for="n in 3" :key="n" class="h-10 w-full bg-[var(--h-line)]" />
                </div>
            </template>

            <BlurFade v-if="data" :delay="0.05">
                <SpotlightCard class="flex flex-col gap-4 rounded-lg border border-[var(--h-line)] bg-[var(--h-panel)] p-5">
                    <p v-if="!data.available" class="text-sm text-[var(--h-muted)]">
                        Data JEMiSys tidak dapat dicapai sekarang - bilangan susun semula tidak dipaparkan supaya tidak mengelirukan.
                    </p>

                    <template v-else>
                        <div
                            role="tablist"
                            aria-label="Susun semula stok"
                            class="grid grid-cols-1 gap-px overflow-hidden rounded-md border border-[var(--h-line)] bg-[var(--h-line)] sm:grid-cols-3"
                        >
                            <button
                                v-for="t in tabs"
                                :key="t.key"
                                type="button"
                                role="tab"
                                :aria-selected="activeTab === t.key"
                                class="relative flex flex-col gap-1 p-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-[var(--h-gold)]"
                                :class="activeTab === t.key ? 'bg-[var(--h-sel)]' : 'bg-[var(--h-panel)] hover:bg-[var(--h-hover2)]'"
                                @click="tab = t.key"
                            >
                                <span class="text-xs" :class="activeTab === t.key ? 'text-[var(--h-fg)]' : 'text-[var(--h-muted)]'">{{ t.title }}</span>
                                <span class="text-2xl font-medium tabular-nums" :class="t.accent"
                                    ><NumberTicker :value="String(t.count)" :duration="700"
                                /></span>
                                <span class="text-xs text-[var(--h-muted)]">{{ t.sub }}</span>
                                <span v-if="activeTab === t.key" class="absolute inset-x-0 bottom-0 h-0.5 bg-[var(--h-gold)]" />
                            </button>
                        </div>

                        <p v-if="list.length === 0" class="text-sm text-[var(--h-soft)]">
                            {{ EMPTY[activeTab] }}
                        </p>

                        <ScrollArea v-else :key="activeTab" class="max-h-[28rem]">
                            <ul role="tabpanel" class="flex flex-col divide-y divide-[var(--h-line)] pr-3">
                                <li
                                    v-for="(item, index) in list"
                                    :key="item.id"
                                    class="flex items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-[var(--h-hover2)]"
                                    :class="activeTab === 'stale' ? 'text-[var(--h-muted)]' : ''"
                                    :style="{
                                        animation: `h-row-in 0.4s ease-out ${Math.min(index, 10) * 0.04}s both`,
                                    }"
                                >
                                    <LazyDesignImage :code="item.code" :src="item.image" :class="activeTab === 'stale' ? 'opacity-70' : ''" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium" :class="activeTab === 'stale' ? 'text-[var(--h-soft)]' : ''">
                                            {{ item.code }}
                                        </p>
                                        <p class="truncate text-xs text-[var(--h-muted)]">
                                            {{ detail(item) }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm tabular-nums">
                                            {{ qtyText(item) }}
                                        </p>
                                        <p class="text-xs" :class="status(item).cls">
                                            {{ status(item).text }}
                                        </p>
                                    </div>
                                </li>
                            </ul>
                        </ScrollArea>
                        <p v-if="activeTab === 'send' && data.to_send > data.items.length" class="text-xs text-[var(--h-muted)]">
                            Menunjukkan {{ data.items.length }} yang paling lama menunggu daripada {{ data.to_send }}.
                        </p>
                        <p v-if="activeTab === 'stale' && data.stale > data.stale_items.length" class="text-xs text-[var(--h-muted)]">
                            Menunjukkan {{ data.stale_items.length }} terbaharu daripada {{ data.stale }}.
                        </p>

                        <p class="text-[11px] text-[var(--h-muted)]">
                            Transfer m9os disemak silang dengan status setiap design dalam cermin JEMiSys (sudah diterima / dalam transit / belum bergerak). Barang
                            dalam transit tanpa transfer m9os (cth. terus daripada HQ di JEMiSys) turut disenaraikan, jadi jumlah unit sama dengan kad "Barang dalam
                            transit" di atas. Padanan peringkat design, jadi unit yang sama diterima daripada sumber lain mungkin dikira sudah selesai.
                        </p>
                    </template>
                </SpotlightCard>
            </BlurFade>
        </Deferred>
    </section>
</template>

<style>
@keyframes h-row-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    [style*='h-row-in'] {
        animation: none !important;
    }
}
</style>
