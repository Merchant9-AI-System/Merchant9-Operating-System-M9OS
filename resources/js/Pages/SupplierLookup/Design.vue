<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { copyText } from '@/lib/clipboard';
import { nextSort, sortByKeys, sortDirectionOf, sortPriorityOf } from '@/lib/multiSort';
import type { Sort } from '@/lib/multiSort';
import SortableHeader from '@/components/SortableHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Carousel, CarouselContent, CarouselItem, CarouselNext, CarouselPrevious } from '@/components/ui/carousel';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Frame, FrameDescription, FrameFooter, FrameHeader, FramePanel, FrameTitle } from '@/components/ui/frame';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';

// Butiran SATU design (butang "Lihat" pd Supplier Lookup): carousel semua gambar, maklumat asas (saiz, berat, supplier), stok ikut
// cawangan & senarai piece. Definisi: App\Support\SupplierLookup::designDetail(). Gambar DITANGGUH (kikisan laman web boleh lambat).
interface DesignBranch {
    store: string;
    pieces: number;
    on_hand: number;
    sold: number;
    requested: number;
}

interface DesignPiece {
    inventory_code: string;
    store: string;
    status: string;
    status_label: string;
    vendor: string;
    // Saiz piece ini (null = tiada).
    size: string | null;
    weight: number;
    purchased_at: string | null;
    sold_at: string | null;
    // Tarikh ISO utk susunan.
    purchased_on: string | null;
    sold_on: string | null;
}

interface DesignDetail {
    code: string;
    nickname: string | null;
    description: string | null;
    category: { code: string; name: string } | null;
    // Semua saiz yg pernah di-stock in & yg ada stok sekarang (subset `sizes`).
    sizes: string[];
    sizes_available: string[];
    weight: { min: number; max: number };
    length: string | null;
    vendors: { code: string; name: string }[];
    // Supplier yg ada design ini (semua masa): Ambil, Sold, Available (= Ambil tolak Sold).
    suppliers: { code: string; name: string; buy: number; sold: number; available: number }[];
    totals: { pieces: number; on_hand: number; sold: number };
    branches: DesignBranch[];
    pieces: DesignPiece[];
    pieces_total: number;
}

const props = defineProps<{
    design: DesignDetail;
    // Supplier yg dicari di jadual Supplier Lookup (kod) - dipaparkan dahulu; selebihnya "+N".
    focusSupplier?: string | null;
    // DITANGGUH - undefined sehingga siap (skeleton dipaparkan).
    images?: { urls: string[]; reference: boolean };
}>();

const SOLD_STATUSES = ['0', '9'];

// Susunan BERBILANG jadual piece (sebelah klien atas semua piece yg dihantar): klik tajuk menambah / menukar / membuang lajur itu; kunci
// lain kekal (nombor = keutamaan). Kosong = urutan asal (terkini dahulu). Tarikh kosong sentiasa di hujung.
type PieceSortKey = 'code' | 'store' | 'status' | 'vendor' | 'size' | 'weight' | 'purchased' | 'sold';

const pieceSorts = ref<Sort<PieceSortKey>[]>([]);

function pieceValue(piece: DesignPiece, key: PieceSortKey): string | number | null {
    switch (key) {
        case 'code':
            return piece.inventory_code;
        case 'store':
            return piece.store;
        case 'status':
            return piece.status_label;
        case 'vendor':
            return piece.vendor;
        case 'size':
            return piece.size;
        case 'weight':
            return piece.weight;
        case 'purchased':
            return piece.purchased_on;
        case 'sold':
            return piece.sold_on;
    }
}

// Jumlah semua cawangan (baris pengaki jadual stok).
const branchTotals = computed(() =>
    props.design.branches.reduce(
        (total, branch) => ({
            pieces: total.pieces + branch.pieces,
            on_hand: total.on_hand + branch.on_hand,
            sold: total.sold + branch.sold,
            requested: total.requested + branch.requested,
        }),
        { pieces: 0, on_hand: 0, sold: 0, requested: 0 },
    ),
);

const sortedPieces = computed(() => sortByKeys(props.design.pieces, pieceSorts.value, pieceValue));

function togglePieceSort(key: PieceSortKey) {
    pieceSorts.value = nextSort(pieceSorts.value, key);
}

// Lencana supplier dlm Maklumat: supplier yg dicari (jika design ini ada) dahulu SAHAJA; tanpa carian, 3 teratas (Ambil terbanyak).
// Selebihnya dikumpul dlm satu lencana "+N" yg membuka senarai penuh.
const DEFAULT_VISIBLE_SUPPLIERS = 3;

const orderedSuppliers = computed(() => {
    const focus = props.focusSupplier?.trim().toUpperCase();
    const list = [...props.design.suppliers];

    if (!focus) {
        return list;
    }

    return [...list.filter((supplier) => supplier.code.toUpperCase() === focus), ...list.filter((supplier) => supplier.code.toUpperCase() !== focus)];
});

const hasFocusedSupplier = computed(() => {
    const focus = props.focusSupplier?.trim().toUpperCase();

    return !!focus && props.design.suppliers.some((supplier) => supplier.code.toUpperCase() === focus);
});

const visibleSuppliers = computed(() => orderedSuppliers.value.slice(0, hasFocusedSupplier.value ? 1 : DEFAULT_VISIBLE_SUPPLIERS));
const hiddenSuppliers = computed(() => orderedSuppliers.value.slice(visibleSuppliers.value.length));
const hiddenOpen = ref(false);

// Saiz dlm Maklumat: yg ADA STOK dipaparkan; saiz lain yg pernah di-stock in dikumpul dlm lencana "+N" (popover "Saiz pernah stock in").
const otherSizes = computed(() => props.design.sizes.filter((size) => !props.design.sizes_available.includes(size)));
const otherSizesOpen = ref(false);

const weightLabel = computed(() => {
    const { min, max } = props.design.weight;

    return min === max ? `${min.toFixed(2)} g` : `${min.toFixed(2)} - ${max.toFixed(2)} g`;
});

const copied = ref(false);

async function copyCode() {
    if (await copyText(props.design.code)) {
        copied.value = true;
        toast.success(`Kod ${props.design.code} disalin`);
        setTimeout(() => (copied.value = false), 1500);
    } else {
        toast.error('Gagal menyalin kod');
    }
}

// Kembali ke jadual dgn penapis/susunan/halaman dipulihkan (Inertia memulihkan keadaan dgn sejarah pelayar); jika dibuka terus
// (tiada sejarah dlm app ini), pergi ke halaman Supplier Lookup.
function goBack() {
    if (window.history.length > 1 && document.referrer.startsWith(window.location.origin)) {
        window.history.back();

        return;
    }

    router.visit('/supplier-lookup');
}

function formatNumber(value: number): string {
    return value.toLocaleString('en-MY');
}
</script>

<template>

    <Head :title="`${design.code} - Supplier Lookup`" />

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-3 py-6 sm:px-6 sm:py-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <Button type="button" variant="outline" size="icon-sm" class="mt-0.5 bg-card" aria-label="Kembali" @click="goBack">
                    <ArrowLeft class="size-4" />
                </Button>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-semibold tracking-tight">{{ design.code }}</h1>
                        <Badge v-if="design.category" variant="secondary">{{ design.category.name }}</Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ design.nickname ?? design.description ?? 'Tiada nama' }}</p>
                </div>
            </div>

            <Button type="button" variant="outline" size="sm" class="h-8 bg-card" @click="copyCode">
                <Check v-if="copied" class="size-4" />
                <Copy v-else class="size-4" />
                Salin Code
            </Button>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:items-start">
            <!-- Lajur kiri (melekat): gambar + senarai supplier -->
            <div class="flex min-w-0 flex-col gap-4 lg:sticky lg:top-4">
            <!-- Gambar -->
            <Frame spacing="sm">
                <FrameHeader class="py-2">
                    <FrameTitle>Gambar</FrameTitle>
                    <FrameDescription v-if="images && images.urls.length > 0">
                        {{ images.urls.length }} gambar
                        <template v-if="images.reference"> - gambar rujukan (berat terdekat), bukan gambar tepat kod ini</template>
                    </FrameDescription>
                </FrameHeader>
                <FramePanel class="p-3">
                    <Skeleton v-if="images === undefined" class="aspect-square w-full rounded-lg" />

                    <Empty v-else-if="images.urls.length === 0" class="border-0 p-6">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <ImageOff />
                            </EmptyMedia>
                            <EmptyTitle>Tiada gambar</EmptyTitle>
                            <EmptyDescription>Tiada gambar ditemui untuk kod ini di laman web atau cermin.</EmptyDescription>
                        </EmptyHeader>
                    </Empty>

                    <Carousel v-else v-slot="{ selectedIndex, scrollTo }" :opts="{ loop: images.urls.length > 1 }" class="flex flex-col gap-3">
                        <div class="relative">
                            <CarouselContent>
                                <CarouselItem v-for="(url, index) in images.urls" :key="url">
                                    <img :src="url" :alt="`${design.code} (${index + 1})`"
                                        class="aspect-square w-full rounded-lg bg-muted object-contain" loading="lazy" />
                                </CarouselItem>
                            </CarouselContent>
                            <template v-if="images.urls.length > 1">
                                <CarouselPrevious class="left-2 border-white/40 bg-black/50 text-white shadow-sm backdrop-blur-sm hover:bg-black/70 hover:text-white dark:border-white/40 dark:bg-black/50 dark:text-white dark:hover:bg-black/70 dark:hover:text-white" />
                                <CarouselNext class="right-2 border-white/40 bg-black/50 text-white shadow-sm backdrop-blur-sm hover:bg-black/70 hover:text-white dark:border-white/40 dark:bg-black/50 dark:text-white dark:hover:bg-black/70 dark:hover:text-white" />
                                <span class="absolute right-2 bottom-2 rounded-full bg-background/80 px-2 py-0.5 text-xs tabular-nums">
                                    {{ selectedIndex + 1 }} / {{ images.urls.length }}
                                </span>
                            </template>
                        </div>

                        <div v-if="images.urls.length > 1" class="flex gap-2 overflow-x-auto pb-1 dark:[color-scheme:dark]" role="tablist" aria-label="Lakaran gambar">
                            <button v-for="(url, index) in images.urls" :key="url" type="button" role="tab"
                                :aria-selected="selectedIndex === index" :aria-label="`Gambar ${index + 1}`"
                                class="size-14 shrink-0 overflow-hidden rounded-md border-2 bg-muted transition-opacity"
                                :class="selectedIndex === index ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'"
                                @click="scrollTo(index)">
                                <img :src="url" alt="" class="size-full object-cover" loading="lazy" />
                            </button>
                        </div>
                    </Carousel>
                </FramePanel>
            </Frame>

            <!-- Senarai supplier yg ada design ini -->
            <Frame spacing="sm">
                <FrameHeader class="py-2">
                    <FrameTitle>Senarai supplier</FrameTitle>
                    <FrameDescription>Supplier yang pernah mengambil design ini (semua masa).</FrameDescription>
                </FrameHeader>
                <FramePanel class="overflow-hidden p-0">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Supplier</TableHead>
                                <TableHead class="text-right">Ambil</TableHead>
                                <TableHead class="text-right">Sold</TableHead>
                                <TableHead class="text-right">Available</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="design.suppliers.length === 0" class="hover:bg-transparent">
                                <TableCell colspan="4" class="text-center text-sm text-muted-foreground">Tiada supplier.</TableCell>
                            </TableRow>
                            <TableRow v-for="supplier in orderedSuppliers" :key="supplier.code">
                                <TableCell class="font-medium">{{ supplier.name }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ formatNumber(supplier.buy) }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ formatNumber(supplier.sold) }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ formatNumber(supplier.available) }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </FramePanel>
            </Frame>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <!-- Maklumat asas -->
                <Frame spacing="sm">
                    <FrameHeader class="py-2">
                        <FrameTitle>Maklumat</FrameTitle>
                    </FrameHeader>
                    <FramePanel class="p-4">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-muted-foreground">Kod design</dt>
                                <dd class="font-medium">{{ design.code }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Nickname</dt>
                                <dd>{{ design.nickname ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Kategori</dt>
                                <dd>{{ design.category ? `${design.category.name} (${design.category.code})` : '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Saiz</dt>
                                <dd>
                                    <span v-if="design.sizes.length === 0">-</span>
                                    <span v-else class="flex flex-wrap items-center gap-1">
                                        <span v-if="design.sizes_available.length === 0" class="text-xs text-muted-foreground">Tiada stok</span>
                                        <Badge v-for="size in design.sizes_available" :key="size" variant="outline" class="tabular-nums">{{ size }}</Badge>
                                        <Popover v-if="otherSizes.length > 0" v-model:open="otherSizesOpen">
                                            <PopoverTrigger as-child>
                                                <button type="button" :aria-label="`Lihat ${otherSizes.length} saiz dah terjual`"
                                                    class="rounded-full focus-visible:outline-2 focus-visible:outline-ring">
                                                    <Badge variant="secondary" class="cursor-pointer tabular-nums hover:bg-accent">+{{ otherSizes.length }}</Badge>
                                                </button>
                                            </PopoverTrigger>
                                            <PopoverContent class="w-64 p-2" align="start">
                                                <p class="px-1 pb-1.5 text-xs font-medium text-muted-foreground">Saiz dah terjual ({{ otherSizes.length }})</p>
                                                <div class="flex max-h-64 flex-wrap gap-1 overflow-y-auto">
                                                    <Badge v-for="size in otherSizes" :key="size" variant="outline" class="tabular-nums">{{ size }}</Badge>
                                                </div>
                                            </PopoverContent>
                                        </Popover>
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Berat emas</dt>
                                <dd class="tabular-nums">{{ weightLabel }}</dd>
                            </div>
                            <div v-if="design.length">
                                <dt class="text-xs text-muted-foreground">Panjang</dt>
                                <dd class="tabular-nums">{{ design.length }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Supplier</dt>
                                <dd>
                                    <span v-if="design.suppliers.length === 0">-</span>
                                    <span v-else class="flex flex-wrap items-center gap-1">
                                        <Badge v-for="supplier in visibleSuppliers" :key="supplier.code" variant="secondary">{{ supplier.name }}</Badge>
                                        <Popover v-if="hiddenSuppliers.length > 0" v-model:open="hiddenOpen">
                                            <PopoverTrigger as-child>
                                                <button type="button" :aria-label="`Lihat ${hiddenSuppliers.length} supplier lain`"
                                                    class="rounded-full focus-visible:outline-2 focus-visible:outline-ring">
                                                    <Badge variant="outline" class="cursor-pointer tabular-nums hover:bg-accent">+{{ hiddenSuppliers.length }}</Badge>
                                                </button>
                                            </PopoverTrigger>
                                            <PopoverContent class="w-64 p-2" align="start">
                                                <p class="px-1 pb-1.5 text-xs font-medium text-muted-foreground">Supplier lain ({{ hiddenSuppliers.length }})</p>
                                                <ul class="flex max-h-64 flex-col overflow-y-auto">
                                                    <li v-for="supplier in hiddenSuppliers" :key="supplier.code"
                                                        class="flex items-center justify-between gap-3 rounded-sm px-1 py-1 text-sm">
                                                        <span class="truncate">{{ supplier.name }}</span>
                                                        <span class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums">{{ supplier.buy }}</span>
                                                    </li>
                                                </ul>
                                            </PopoverContent>
                                        </Popover>
                                    </span>
                                </dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-muted-foreground">Penerangan</dt>
                                <dd>{{ design.description ?? '-' }}</dd>
                            </div>
                        </dl>
                    </FramePanel>
                    <FrameFooter class="gap-4 py-2">
                        <span>Jumlah piece <strong class="text-foreground tabular-nums">{{ formatNumber(design.totals.pieces) }}</strong></span>
                        <span>Ada stok <strong class="text-foreground tabular-nums">{{ formatNumber(design.totals.on_hand) }}</strong></span>
                        <span>Terjual <strong class="text-foreground tabular-nums">{{ formatNumber(design.totals.sold) }}</strong></span>
                    </FrameFooter>
                </Frame>

                <!-- Stok ikut cawangan -->
                <Frame spacing="sm">
                    <FrameHeader class="py-2">
                        <FrameTitle>Stok mengikut cawangan</FrameTitle>
                    </FrameHeader>
                    <FramePanel class="overflow-hidden p-0">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead>Cawangan</TableHead>
                                    <TableHead class="text-right">Jumlah piece</TableHead>
                                    <TableHead class="text-right">Ada stok</TableHead>
                                    <TableHead class="text-right">Terjual</TableHead>
                                    <TableHead class="text-right">Branch Requested</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="branch in design.branches" :key="branch.store">
                                    <TableCell class="font-medium">{{ branch.store }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branch.pieces) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branch.on_hand) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branch.sold) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branch.requested) }}</TableCell>
                                </TableRow>
                            </TableBody>
                            <TableFooter v-if="design.branches.length > 1">
                                <TableRow class="hover:bg-transparent">
                                    <TableCell>Jumlah</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branchTotals.pieces) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branchTotals.on_hand) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branchTotals.sold) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ formatNumber(branchTotals.requested) }}</TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </FramePanel>
                </Frame>

                <!-- Senarai piece -->
                <Frame spacing="sm">
                    <FrameHeader class="py-2">
                        <FrameTitle>Senarai piece</FrameTitle>
                        <FrameDescription>
                            <template v-if="design.pieces_total > design.pieces.length">
                                Menunjukkan {{ formatNumber(design.pieces.length) }} terkini daripada {{ formatNumber(design.pieces_total) }} piece.
                            </template>
                            <template v-else>{{ formatNumber(design.pieces_total) }} piece, terkini dahulu.</template>
                            <template v-if="pieceSorts.length > 0">
                                Disusun ikut {{ pieceSorts.length }} lajur -
                                <button type="button" class="underline underline-offset-2 hover:text-foreground" @click="pieceSorts = []">kosongkan susunan</button>.
                            </template>
                            <template v-else> Klik tajuk lajur untuk menyusun (boleh beberapa lajur).</template>
                        </FrameDescription>
                    </FrameHeader>
                    <FramePanel class="overflow-hidden p-0">
                        <!-- ScrollArea (bar skrol tema, sama spt jadual Supplier Lookup). Kelas penimpa pd ScrollArea di bawah: pembungkus dlm viewport reka-ui asalnya
                             display:table & melebar ikut kandungan. -->
                        <ScrollArea horizontal class="max-h-96 rounded-[inherit] [&_[data-slot=scroll-area-viewport]>div]:!block">
                            <Table>
                                <TableHeader class="sticky top-0 z-10 bg-card shadow-[0_1px_0_var(--border)]">
                                    <TableRow class="hover:bg-transparent">
                                        <TableHead>
                                            <SortableHeader title="Kod inventori" :direction="sortDirectionOf(pieceSorts, 'code')"
                                                :priority="sortPriorityOf(pieceSorts, 'code')" @sort="togglePieceSort('code')" />
                                        </TableHead>
                                        <TableHead>
                                            <SortableHeader title="Cawangan" :direction="sortDirectionOf(pieceSorts, 'store')"
                                                :priority="sortPriorityOf(pieceSorts, 'store')" @sort="togglePieceSort('store')" />
                                        </TableHead>
                                        <TableHead>
                                            <SortableHeader title="Status" :direction="sortDirectionOf(pieceSorts, 'status')"
                                                :priority="sortPriorityOf(pieceSorts, 'status')" @sort="togglePieceSort('status')" />
                                        </TableHead>
                                        <TableHead>
                                            <SortableHeader title="Supplier" :direction="sortDirectionOf(pieceSorts, 'vendor')"
                                                :priority="sortPriorityOf(pieceSorts, 'vendor')" @sort="togglePieceSort('vendor')" />
                                        </TableHead>
                                        <TableHead>
                                            <SortableHeader title="Saiz" :direction="sortDirectionOf(pieceSorts, 'size')"
                                                :priority="sortPriorityOf(pieceSorts, 'size')" @sort="togglePieceSort('size')" />
                                        </TableHead>
                                        <TableHead class="text-right">
                                            <SortableHeader title="Berat" :direction="sortDirectionOf(pieceSorts, 'weight')"
                                                :priority="sortPriorityOf(pieceSorts, 'weight')" @sort="togglePieceSort('weight')" />
                                        </TableHead>
                                        <TableHead>
                                            <SortableHeader title="Tarikh masuk" :direction="sortDirectionOf(pieceSorts, 'purchased')"
                                                :priority="sortPriorityOf(pieceSorts, 'purchased')" @sort="togglePieceSort('purchased')" />
                                        </TableHead>
                                        <TableHead>
                                            <SortableHeader title="Tarikh jual" :direction="sortDirectionOf(pieceSorts, 'sold')"
                                                :priority="sortPriorityOf(pieceSorts, 'sold')" @sort="togglePieceSort('sold')" />
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="piece in sortedPieces" :key="piece.inventory_code">
                                        <TableCell class="font-mono text-xs">{{ piece.inventory_code }}</TableCell>
                                        <TableCell>{{ piece.store }}</TableCell>
                                        <TableCell>
                                            <Badge :variant="SOLD_STATUSES.includes(piece.status) ? 'secondary' : 'default'">{{ piece.status_label }}</Badge>
                                        </TableCell>
                                        <TableCell>{{ piece.vendor }}</TableCell>
                                        <TableCell class="tabular-nums">{{ piece.size ?? '-' }}</TableCell>
                                        <TableCell class="text-right whitespace-nowrap tabular-nums">{{ piece.weight.toFixed(2) }} g</TableCell>
                                        <TableCell class="whitespace-nowrap tabular-nums">{{ piece.purchased_at ?? '-' }}</TableCell>
                                        <TableCell class="whitespace-nowrap tabular-nums">{{ piece.sold_at ?? '-' }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </ScrollArea>
                    </FramePanel>
                </Frame>
            </div>
        </div>
    </div>
</template>
