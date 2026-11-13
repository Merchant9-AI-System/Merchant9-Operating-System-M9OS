<script setup lang="ts">
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Banknote,
    ClipboardList,
    Coins,
    Gem,
    Landmark,
    Lock,
    Recycle,
    Scale,
    TrendingUp,
    Truck,
    Wallet,
    Moon,
    Sun,
    X,
} from '@lucide/vue';
import { Motion } from 'motion-v';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import BlurFade from '@/components/inspira/BlurFade.vue';
import FlickeringGrid from '@/components/inspira/FlickeringGrid.vue';
import HairlineGrid from '@/components/inspira/HairlineGrid.vue';
import NumberTicker from '@/components/inspira/NumberTicker.vue';
import Sparkline from '@/components/inspira/Sparkline.vue';
import TrendChart from '@/components/inspira/TrendChart.vue';
import { Skeleton } from '@/components/ui/skeleton';

type Tone = 'default' | 'good' | 'warn' | 'bad';

interface SummaryItem {
    key?: string;
    // Kad hero (paling besar) - ditentukan server (config dashboard.home.ceo_hero_key utk CEO).
    hero?: boolean;
    // Siri harian utk garis trend mini (kosong = tiada trend) & label tarikh setiap titik.
    trend?: number[];
    trend_labels?: string[];
    // Cip perbandingan di sebelah nombor (cth. +1.3%).
    delta?: { text: string; tone: Tone } | null;
    label: string;
    value: string;
    hint?: string | null;
    tone?: Tone;
}

interface SummaryListItem {
    tag: string;
    text: string;
    tone?: Tone;
    // Pautan ke halaman berkaitan (tiada = tidak boleh diklik).
    href?: string | null;
}

interface RankingRow {
    label: string;
    value: string;
    sub?: string | null;
    tone?: Tone;
    // Panjang bar 0-100 relatif kepada baris teratas.
    bar: number;
}

// Satu seksyen ringkasan (rujuk App\Support\HomeSummary\SummarySection) - halaman hanya memaparkan apa yang
// server hantar utk peranan pengguna, tiada logik peranan di frontend.
interface Section {
    key: string;
    title: string;
    items: SummaryItem[];
    list: SummaryListItem[];
    ranking?: { title: string; rows: RankingRow[] } | null;
    note: string | null;
}

const props = defineProps<{
    date: string;
    dateLabel: string;
    // `inertia` = halaman Inertia (guna <Link>, tanpa muat semula penuh); selainnya pautan biasa (cth. Filament).
    links: { label: string; href: string; inertia?: boolean }[];
    // Deferred prop - `undefined` sehingga siap (rujuk fallback Skeleton).
    sections?: Section[];
}>();

const page = usePage<{ auth: { user: { name: string } | null } }>();

const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'pagi' : hour < 15 ? 'tengah hari' : hour < 19 ? 'petang' : 'malam';

    return `Selamat ${part}, ${page.props.auth.user?.name ?? ''}`;
});

// Dirujuk melalui pemboleh ubah supaya Vite tak cuba menyelesaikannya sbg modul (fail ada di public/).
const LOGO_DARK_URL = '/images/brand/merchant9-lockup-horizontal-dark.svg';
const LOGO_LIGHT_URL = '/images/brand/merchant9-lockup-horizontal.svg';

const reduceMotion = useMediaQuery('(prefers-reduced-motion: reduce)');
const isLarge = useMediaQuery('(min-width: 1024px)');
const isMedium = useMediaQuery('(min-width: 640px)');

const TONE_TEXT: Record<Tone, string> = {
    default: 'text-[var(--h-fg)]',
    good: 'text-[var(--h-good)]',
    warn: 'text-[var(--h-warn)]',
    bad: 'text-[var(--h-bad)]',
};

const BAR_CLASS: Record<Tone, string> = {
    default: 'bg-[var(--h-gold2)]',
    good: 'bg-[var(--h-good)]',
    warn: 'bg-[var(--h-warn)]',
    bad: 'bg-[var(--h-bad)]',
};

const TAG_CLASS: Record<Tone, string> = {
    default: 'bg-[var(--h-line)] text-[var(--h-soft)]',
    good: 'bg-[var(--h-good)]/20 text-[var(--h-good)]',
    warn: 'bg-[var(--h-warn)]/20 text-[var(--h-warn)]',
    bad: 'bg-[var(--h-bad)]/20 text-[var(--h-bad)]',
};

const DELTA_CLASS: Record<Tone, string> = {
    default: 'bg-[var(--h-line)] text-[var(--h-soft)]',
    good: 'bg-[var(--h-good)]/15 text-[var(--h-good)]',
    warn: 'bg-[var(--h-warn)]/15 text-[var(--h-warn)]',
    bad: 'bg-[var(--h-bad)]/15 text-[var(--h-bad)]',
};

// Ikon ikut kata kunci label - hiasan sahaja; label tak dikenali guna ikon lalai.
const ICONS: [RegExp, Component][] = [
    [/lock/i, Lock],
    [/transit/i, Truck],
    [/permintaan/i, ClipboardList],
    [/buyback|trade/i, Recycle],
    [/hedge|physical/i, Scale],
    [/cash/i, Banknote],
    [/asset/i, Landmark],
    [/used gold/i, Coins],
    [/nilai/i, Wallet],
    [/jualan/i, TrendingUp],
];

function iconFor(label: string): Component {
    return ICONS.find(([pattern]) => pattern.test(label))?.[1] ?? Gem;
}

// Unit utk tooltip carta trend: ambil dari nilai kad (cth. "1,939.22 g" -> "g") bila sesuai.
function trendUnit(item: SummaryItem): string {
    return /g/.test(item.value) ? 'g' : '';
}

function itemId(item: SummaryItem): string {
    return item.key ?? item.label;
}

function hasTrend(item: SummaryItem): boolean {
    return (item.trend?.length ?? 0) > 1;
}

// Tab penapis seksyen: 'all' = semua seksyen disusun menurun. Jika seksyen hilang (data dimuat semula), kembali ke semua.
const activeTab = ref<string>('all');

const visibleSections = computed<Section[]>(() => {
    const sections = props.sections ?? [];

    return activeTab.value === 'all' ? sections : sections.filter((section) => section.key === activeTab.value);
});

watch(
    () => props.sections,
    (sections) => {
        if (activeTab.value !== 'all' && !sections?.some((section) => section.key === activeTab.value)) {
            activeTab.value = 'all';
        }
    },
);

// Kad dipilih (klik) -> panel trend terperinci di bawah grid seksyen itu. Satu pilihan pada satu masa.
const selectedId = ref<string | null>(null);

function toggleSelected(item: SummaryItem): void {
    selectedId.value = selectedId.value === itemId(item) ? null : itemId(item);
}

function selectedItem(section: Section): SummaryItem | null {
    return section.items.find((item) => itemId(item) === selectedId.value && hasTrend(item)) ?? null;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        selectedId.value = null;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

// Bilangan lajur grid semasa (selaras dgn kelas sm:grid-cols-2 lg:grid-cols-3) - utk mengisi sel kosong di baris
// terakhir supaya garis halus grid kekal sempurna.
const columns = computed(() => (isLarge.value ? 3 : isMedium.value ? 2 : 1));

function fillerCount(section: Section): number {
    const cols = columns.value;
    const area = section.items.reduce((total, item) => total + (item.hero ? Math.min(2, cols) * (cols >= 3 ? 2 : 1) : 1), 0);

    return (cols - (area % cols)) % cols;
}

function cellSpan(item: SummaryItem): string | undefined {
    return item.hero ? 'sm:col-span-2 lg:row-span-2' : undefined;
}

const hoveredRank = ref<string | null>(null);

// Bar tab hanya berlatar (kabur) bila sudah melekat di atas skrin; di kedudukan asal ia lutsinar supaya latar grid
// di bahagian atas tak terbelah. Dikesan dgn elemen penanda (sentinel) sebelum bar.
const tabSentinel = ref<HTMLElement | null>(null);
const tabsStuck = ref(false);
let stuckObserver: IntersectionObserver | null = null;

watch(tabSentinel, (element) => {
    stuckObserver?.disconnect();

    if (!element || typeof IntersectionObserver === 'undefined') {
        return;
    }

    stuckObserver = new IntersectionObserver(([entry]) => {
        tabsStuck.value = !entry.isIntersecting && entry.boundingClientRect.top < 0;
    });
    stuckObserver.observe(element);
});

onBeforeUnmount(() => stuckObserver?.disconnect());

// Tema gelap/cerah: dipilih pengguna & disimpan di pelayar (localStorage mungkin tak tersedia - dibungkus try/catch).
// Lalai gelap. Warna halaman semuanya token CSS (--h-*) yg ditakrif di blok <style> di bawah.
type Theme = 'dark' | 'light';

const THEME_KEY = 'm9os-home-theme';
const theme = ref<Theme>('dark');
const isLight = computed(() => theme.value === 'light');

function toggleTheme(): void {
    theme.value = isLight.value ? 'dark' : 'light';

    try {
        window.localStorage.setItem(THEME_KEY, theme.value);
    } catch {
        // tiada storan - tema hanya untuk sesi ini
    }
}

onMounted(() => {
    try {
        const saved = window.localStorage.getItem(THEME_KEY);

        if (saved === 'light' || saved === 'dark') {
            theme.value = saved;
        }
    } catch {
        // abaikan
    }
});
</script>

<template>

    <Head title="Ringkasan Semalam" />

    <div :data-theme="theme" class="home-root relative min-h-screen overflow-x-clip bg-[var(--h-bg)] text-[var(--h-fg)] antialiased transition-colors duration-300">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[var(--h-gold)] to-transparent" />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 h-[26rem] [mask-image:radial-gradient(70%_100%_at_50%_0%,black,transparent)]"
        >
            <FlickeringGrid :color="isLight ? '154, 111, 18' : '232, 196, 106'" :max-opacity="isLight ? 0.3 : 0.4" :square-size="3" :grid-gap="7" />
        </div>
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 h-[26rem] bg-[radial-gradient(50%_80%_at_50%_0%,var(--h-glow-soft),transparent)]"
        />

        <div class="relative z-10 mx-auto flex max-w-6xl flex-col gap-6 px-4 pb-16 pt-6 sm:px-6">
            <header class="flex flex-col gap-5">
                <BlurFade class="flex items-center justify-between gap-3">
                    <img :src="isLight ? LOGO_LIGHT_URL : LOGO_DARK_URL" alt="Merchant9" class="h-6 w-auto" />
                    <div class="flex items-center gap-3">
                        <span class="hidden text-xs text-[var(--h-muted)] sm:inline">Ringkasan semalam · {{ dateLabel }}</span>
                        <button
                            type="button"
                            :aria-label="isLight ? 'Tukar ke tema gelap' : 'Tukar ke tema cerah'"
                            :title="isLight ? 'Tema gelap' : 'Tema cerah'"
                            class="flex size-8 items-center justify-center rounded-full bg-[var(--h-fill)] text-[var(--h-soft)] transition-colors hover:bg-[var(--h-gold2)]/20 hover:text-[var(--h-gold)] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                            @click="toggleTheme"
                        >
                            <Sun v-if="!isLight" class="size-4" />
                            <Moon v-else class="size-4" />
                        </button>
                    </div>
                </BlurFade>

                <BlurFade :delay="0.05" class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div class="flex flex-col gap-1">
                        <h1 class="text-2xl font-medium tracking-tight sm:text-3xl">{{ greeting }}</h1>
                        <p class="text-sm text-[var(--h-muted)] sm:hidden">Ringkasan semalam · {{ dateLabel }}</p>
                    </div>

                    <nav v-if="links.length > 0" aria-label="Pautan pantas" class="flex flex-wrap gap-2">
                        <component
                            :is="link.inertia ? Link : 'a'"
                            v-for="link in links"
                            :key="link.href"
                            :href="link.href"
                            class="group flex items-center gap-2 rounded-full bg-[var(--h-fill)] px-4 py-2 text-sm text-[var(--h-soft)] transition-colors hover:bg-[var(--h-gold2)]/20 hover:text-[var(--h-fg)] focus-visible:bg-[var(--h-gold2)]/20 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                        >
                            {{ link.label }}
                            <ArrowRight class="size-3.5 text-[var(--h-muted)] transition-transform group-hover:translate-x-0.5 group-hover:text-[var(--h-gold)]" />
                        </component>
                    </nav>
                </BlurFade>
            </header>

            <Deferred data="sections">
                <template #fallback>
                    <HairlineGrid class="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="n in 6" :key="n" class="flex flex-col gap-3 bg-[var(--h-bg)] p-5">
                            <Skeleton class="h-3 w-28 bg-[var(--h-line)]" />
                            <Skeleton class="h-7 w-40 bg-[var(--h-line)]" />
                            <Skeleton class="h-3 w-52 bg-[var(--h-line)]" />
                        </div>
                    </HairlineGrid>
                </template>

                <p v-if="!sections || sections.length === 0" class="text-sm text-[var(--h-muted)]">
                    Belum ada ringkasan untuk peranan anda. Gunakan pautan di atas untuk mula bekerja.
                </p>

                <div v-if="sections && sections.length > 1" ref="tabSentinel" aria-hidden="true" class="-mb-6 h-px" />

                <div
                    v-if="sections && sections.length > 1"
                    role="tablist"
                    aria-label="Seksyen ringkasan"
                    class="sticky top-0 z-30 -mx-4 flex overflow-x-auto border-b px-4 transition-colors sm:-mx-6 sm:px-6"
                    :class="tabsStuck ? 'border-[var(--h-line)] bg-[var(--h-bg)]/85 backdrop-blur' : 'border-transparent'"
                >
                    <button
                        v-for="tab in [{ key: 'all', title: 'Semua' }, ...sections]"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === tab.key"
                        class="relative whitespace-nowrap px-4 py-3 text-sm transition-colors focus-visible:outline-none"
                        :class="activeTab === tab.key ? 'text-[var(--h-fg)]' : 'text-[var(--h-muted)] hover:text-[var(--h-fg)]'"
                        @click="activeTab = tab.key"
                    >
                        {{ tab.title }}
                        <span
                            class="absolute inset-x-3 bottom-0 h-0.5 origin-left rounded-full bg-[var(--h-gold)] transition-transform duration-300"
                            :class="activeTab === tab.key ? 'scale-x-100' : 'scale-x-0'"
                        />
                    </button>
                </div>

                <template v-for="section in visibleSections" :key="`${activeTab}-${section.key}`">
                    <section :id="`section-${section.key}`" class="flex flex-col gap-3">
                        <BlurFade class="flex items-center gap-3">
                            <h2 class="text-xs font-medium uppercase tracking-[0.18em] text-[var(--h-muted)]">{{ section.title }}</h2>
                            <span class="h-px flex-1 bg-[var(--h-line)]" />
                        </BlurFade>

                        <p v-if="section.note" class="rounded-lg border border-[var(--h-gold2)]/30 bg-[var(--h-gold2)]/10 px-4 py-3 text-sm text-[var(--h-note)]">
                            {{ section.note }}
                        </p>

                        <BlurFade v-if="section.items.length > 0" :delay="0.05">
                            <HairlineGrid class="grid-flow-dense grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                                <component
                                    :is="hasTrend(item) && !item.hero ? 'button' : 'div'"
                                    v-for="item in section.items"
                                    :key="itemId(item)"
                                    :type="hasTrend(item) && !item.hero ? 'button' : undefined"
                                    :aria-pressed="hasTrend(item) && !item.hero ? selectedId === itemId(item) : undefined"
                                    class="group/cell relative flex flex-col gap-3 bg-[var(--h-bg)] p-5 text-left transition-colors duration-200 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-[var(--h-gold)]"
                                    :class="[
                                        cellSpan(item),
                                        hasTrend(item) && !item.hero ? 'cursor-pointer hover:bg-[var(--h-hover)]' : 'hover:bg-[var(--h-hover2)]',
                                        selectedId === itemId(item) && !item.hero ? 'bg-[var(--h-sel)] ring-1 ring-inset ring-[var(--h-gold)]/50' : '',
                                    ]"
                                    @click="hasTrend(item) && !item.hero ? toggleSelected(item) : undefined"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs text-[var(--h-muted)]">{{ item.label }}</span>
                                        <component
                                            :is="iconFor(item.label)"
                                            class="size-4 text-[var(--h-faint)] transition-colors group-hover/cell:text-[var(--h-gold)]"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            <div
                                                class="font-medium tracking-tight sm:whitespace-nowrap"
                                                :class="[TONE_TEXT[item.tone ?? 'default'], item.hero ? 'text-3xl sm:text-4xl' : 'text-2xl']"
                                            >
                                                <NumberTicker :value="item.value" />
                                            </div>
                                            <span
                                                v-if="item.delta"
                                                class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium tabular-nums"
                                                :class="DELTA_CLASS[item.delta.tone]"
                                            >
                                                <component :is="item.delta.text.startsWith('-') ? ArrowDownRight : ArrowUpRight" class="size-3" />
                                                {{ item.delta.text }}
                                            </span>
                                        </div>
                                        <Sparkline
                                            v-if="!item.hero && hasTrend(item)"
                                            :values="item.trend ?? []"
                                            class="shrink-0 text-[var(--h-gold2)] transition-transform duration-300 group-hover/cell:scale-110"
                                        />
                                    </div>

                                    <div v-if="item.hint" class="text-xs leading-relaxed text-[var(--h-muted)]">{{ item.hint }}</div>

                                    <TrendChart
                                        v-if="item.hero && hasTrend(item)"
                                        :values="item.trend ?? []"
                                        :labels="item.trend_labels ?? []"
                                        :unit="trendUnit(item)"
                                        class="mt-2 h-auto min-h-28 flex-1"
                                    />

                                    <span
                                        v-if="hasTrend(item) && !item.hero"
                                        class="text-[11px] text-[var(--h-faint)] opacity-0 transition-opacity group-hover/cell:opacity-100 group-focus-visible/cell:opacity-100"
                                    >
                                        {{ selectedId === itemId(item) ? 'Klik untuk tutup trend' : 'Klik untuk lihat trend' }}
                                    </span>
                                </component>

                                <div v-for="n in fillerCount(section)" :key="`filler-${n}`" aria-hidden="true" class="hidden bg-[var(--h-bg)] sm:block" />
                            </HairlineGrid>
                        </BlurFade>

                        <BlurFade v-if="selectedItem(section)" :key="selectedId ?? undefined" :duration="0.35">
                            <div class="flex flex-col gap-4 rounded-lg border border-[var(--h-gold)]/30 bg-[var(--h-panel)] p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-xs text-[var(--h-muted)]">{{ selectedItem(section)?.label }} · trend {{ selectedItem(section)?.trend?.length }} hari</span>
                                        <span class="text-xl font-medium" :class="TONE_TEXT[selectedItem(section)?.tone ?? 'default']">
                                            {{ selectedItem(section)?.value }}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        aria-label="Tutup trend"
                                        class="rounded-md p-1.5 text-[var(--h-muted)] transition-colors hover:bg-[var(--h-line)] hover:text-[var(--h-fg)] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[var(--h-gold)]"
                                        @click="selectedId = null"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>
                                <TrendChart
                                    :values="selectedItem(section)?.trend ?? []"
                                    :labels="selectedItem(section)?.trend_labels ?? []"
                                    :unit="trendUnit(selectedItem(section) as SummaryItem)"
                                    class="h-36"
                                />
                            </div>
                        </BlurFade>

                        <BlurFade v-if="section.ranking && section.ranking.rows.length > 0" :delay="0.1">
                            <div class="flex flex-col rounded-lg border border-[var(--h-line)]" @pointerleave="hoveredRank = null">
                                <div class="border-b border-[var(--h-line)] px-5 py-3 text-xs text-[var(--h-muted)]">{{ section.ranking.title }}</div>
                                <div
                                    v-for="(row, index) in section.ranking.rows"
                                    :key="row.label"
                                    class="flex flex-col gap-2 border-b border-[var(--h-line)] px-5 py-3 transition-[opacity,background-color] duration-200 last:border-b-0"
                                    :class="[
                                        hoveredRank === row.label ? 'bg-[var(--h-gold2)]/[0.06]' : '',
                                        hoveredRank !== null && hoveredRank !== row.label ? 'opacity-45' : '',
                                    ]"
                                    @pointerenter="hoveredRank = row.label"
                                >
                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                        <span class="flex items-baseline gap-2.5">
                                            <span class="w-4 text-xs tabular-nums text-[var(--h-faint)]">{{ index + 1 }}</span>
                                            <span class="font-medium">{{ row.label }}</span>
                                        </span>
                                        <span class="tabular-nums" :class="TONE_TEXT[row.tone ?? 'default']">{{ row.value }}</span>
                                    </div>
                                    <div class="ml-[1.625rem] h-1 overflow-hidden rounded-full bg-[var(--h-line)]">
                                        <div v-if="reduceMotion" class="h-full rounded-full" :class="BAR_CLASS[row.tone ?? 'default']" :style="{ width: `${row.bar}%` }" />
                                        <Motion
                                            v-else
                                            :initial="{ width: '0%' }"
                                            :animate="{ width: `${row.bar}%` }"
                                            :transition="{ duration: 0.8, delay: 0.1 + index * 0.06, ease: 'easeOut' }"
                                            class="h-full rounded-full"
                                            :class="BAR_CLASS[row.tone ?? 'default']"
                                        />
                                    </div>
                                    <span v-if="row.sub" class="ml-[1.625rem] text-xs text-[var(--h-faint)]">{{ row.sub }}</span>
                                </div>
                            </div>
                        </BlurFade>

                        <BlurFade v-if="section.list.length > 0" :delay="0.15">
                            <div class="flex flex-col rounded-lg border border-[var(--h-line)]">
                                <component
                                    :is="entry.href ? 'a' : 'div'"
                                    v-for="(entry, index) in section.list"
                                    :key="index"
                                    :href="entry.href ?? undefined"
                                    class="group/row flex items-center gap-3 border-b border-[var(--h-line)] px-5 py-3 text-sm transition-colors last:border-b-0 focus-visible:outline-none"
                                    :class="entry.href ? 'hover:bg-[var(--h-gold2)]/[0.07] focus-visible:bg-[var(--h-gold2)]/[0.07]' : undefined"
                                >
                                    <span class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium" :class="TAG_CLASS[entry.tone ?? 'default']">{{ entry.tag }}</span>
                                    <span class="flex-1 text-[var(--h-soft)]">{{ entry.text }}</span>
                                    <ArrowUpRight
                                        v-if="entry.href"
                                        class="size-4 shrink-0 text-[var(--h-faint)] transition-transform group-hover/row:-translate-y-0.5 group-hover/row:translate-x-0.5 group-hover/row:text-[var(--h-gold)]"
                                    />
                                </component>
                            </div>
                        </BlurFade>
                    </section>
                </template>
            </Deferred>
        </div>
    </div>
</template>

<style>
.home-root {
    --h-bg: #0c0a07;
    --h-fg: #f6eedb;
    --h-soft: #e0d7bf;
    --h-muted: #a89f8a;
    --h-faint: #7d7463;
    --h-gold: #e8c46a;
    --h-gold2: #d9b24c;
    --h-note: #ecd48a;
    --h-hover: #15110a;
    --h-hover2: #100d08;
    --h-sel: #17130a;
    --h-panel: #12100a;
    --h-line: rgba(255, 255, 255, 0.1);
    --h-fill: rgba(255, 255, 255, 0.07);
    --h-glow: rgba(232, 196, 106, 0.85);
    --h-glow-soft: rgba(217, 178, 76, 0.1);
    --h-good: #34d399;
    --h-warn: #fb923c;
    --h-bad: #f87171;
}

.home-root[data-theme='light'] {
    --h-bg: #faf6ea;
    --h-fg: #2a2113;
    --h-soft: #4a3f2b;
    --h-muted: #6f6550;
    --h-faint: #8c826b;
    --h-gold: #9a6f12;
    --h-gold2: #b8892a;
    --h-note: #6b4c0a;
    --h-hover: #f1e8cf;
    --h-hover2: #f6efdc;
    --h-sel: #efe3c0;
    --h-panel: #f3ead2;
    --h-line: rgba(42, 33, 19, 0.13);
    --h-fill: rgba(42, 33, 19, 0.06);
    --h-glow: rgba(184, 137, 42, 0.8);
    --h-glow-soft: rgba(184, 137, 42, 0.14);
    --h-good: #047857;
    --h-warn: #c2410c;
    --h-bad: #b91c1c;
}
</style>
