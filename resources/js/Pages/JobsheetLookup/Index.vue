<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Loader2, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';

// Satu baris = satu piece fizikal jemisys_inventory_mirror (rujuk App\Models\Jemisys\
// InventoryPiece dokblok) yg kongsi JobSheetNo yg dicari - BUKAN agregat/kumpulan.
interface JobsheetItem {
    inventory_code: string;
    internal_code: string | null;
    description: string | null;
    category_name: string | null;
    store_code: string | null;
    vendor_name: string | null;
    size: string | null;
    weight: number | null;
    qty_on_hand: number;
    status: string | null;
    purch_date: string | null;
    sales_date: string | null;
    image_url: string | null;
    nickname: string | null;
    // Cadangan tindakan - rujuk App\Support\JobsheetRestockScorer dokblok utk 2 peraturan
    // (RESTOCK bila semua cawangan yg ada design ni balance=1; REARRANGE bila cawangan INI
    // sendiri nipis/habis TAPI ada jualan dlm tempoh trend & ada cawangan lain ada lebihan).
    restock_action: 'restock' | 'rearrange' | null;
    restock_action_label: string | null;
    restock_action_color: 'danger' | 'warning' | 'gray';
    restock_action_detail: string | null;
    // Maksud beza ikut action - cawangan PALING LAKU (RESTOCK, agih bila stok baharu sampai)
    // atau cawangan SUMBER lebihan (REARRANGE, pindah drpd mana).
    restock_target_branches: string[];
}

// Badge shadcn-vue tiada variant "danger"/"warning" terus - peta ke variant/kelas sedia ada
// (sama corak warna dgn FULFILLMENT_COLORS Filament merentasi app ni).
const ACTION_BADGE_CLASS: Record<string, string> = {
    danger: 'border-transparent bg-destructive text-white',
    warning: 'border-transparent bg-warning text-warning-foreground',
    gray: 'border-transparent bg-muted text-muted-foreground',
};

// Label RASMI kod `Status` (TblInventory, varchar(1)) - disahkan terus drpd kod SQL Server
// JEMiSys sendiri (stored procedure PushSalesToDFSServer ada CASE literal; 'I' disahkan drpd
// ReportSplitItemStatus/UpdateRMIssueStatus - "Disassembly from FG"), BUKAN teka drpd corak
// data. Kod tak disenaraikan (cth. '4') tiada makna diketahui - papar kod mentah sahaja.
const STATUS_LABELS: Record<string, string> = {
    0: 'Sold',
    1: 'Available',
    2: 'Purchase/Consign Return',
    3: 'Consign Sales',
    5: 'Transit',
    6: 'Stock Out',
    7: 'Loan Transit',
    8: 'Loan Return Transit',
    9: 'Sold',
    I: 'Disassembly from FG',
};

function statusLabel(status: string | null): string {
    if (!status) {
        return '-';
    }
    const trimmed = status.trim();
    return STATUS_LABELS[trimmed] ?? trimmed;
}

// Satu KUMPULAN = design (internal_code) + cawangan (store_code) YANG SAMA - satu job sheet
// selalunya bawa byk keping fizikal design serupa ke cawangan sama (purata ~57 keping/jobsheet,
// rujuk analisis awal), jadi papar 1 baris ringkasan + cadangan (dikira SEKALI per design+
// cawangan - rujuk JobsheetRestockScorer) drpd ulang badge IDENTIK utk setiap keping. Keping
// individu (tarikh, berat, imej masing2) kekal boleh dilihat via toggle "kembang".
interface GroupedRow {
    key: string;
    internal_code: string | null;
    description: string | null;
    category_name: string | null;
    store_code: string | null;
    vendor_name: string | null;
    nickname: string | null;
    image_url: string | null;
    on_hand_count: number;
    sold_count: number;
    total_weight: number | null;
    restock_action: JobsheetItem['restock_action'];
    restock_action_label: string | null;
    restock_action_color: JobsheetItem['restock_action_color'];
    restock_action_detail: string | null;
    restock_target_branches: string[];
    pieces: JobsheetItem[];
}

const groupedItems = computed<GroupedRow[]>(() => {
    const groups = new Map<string, GroupedRow>();

    for (const item of props.items ?? []) {
        // Fallback ke inventory_code (sentiasa unik) bila internal_code tiada - elak kumpul
        // silap byk keping design x diketahui jadi satu kumpulan.
        const key = `${item.internal_code ?? item.inventory_code}|${item.store_code ?? ''}`;

        if (!groups.has(key)) {
            groups.set(key, {
                key,
                internal_code: item.internal_code,
                description: item.description,
                category_name: item.category_name,
                store_code: item.store_code,
                vendor_name: item.vendor_name,
                nickname: item.nickname,
                image_url: item.image_url,
                on_hand_count: 0,
                sold_count: 0,
                total_weight: null,
                restock_action: item.restock_action,
                restock_action_label: item.restock_action_label,
                restock_action_color: item.restock_action_color,
                restock_action_detail: item.restock_action_detail,
                restock_target_branches: item.restock_target_branches,
                pieces: [],
            });
        }

        const group = groups.get(key)!;
        group.pieces.push(item);
        if (item.qty_on_hand > 0) {
            group.on_hand_count += item.qty_on_hand;
        } else {
            group.sold_count += 1;
        }
        if (item.weight) {
            group.total_weight = (group.total_weight ?? 0) + item.weight;
        }
    }

    return Array.from(groups.values());
});

const expandedKeys = ref<Set<string>>(new Set());

function toggleGroup(key: string) {
    if (expandedKeys.value.has(key)) {
        expandedKeys.value.delete(key);
    } else {
        expandedKeys.value.add(key);
    }
    // Set baharu - Vue reaktif atas RUJUKAN objek utk Set/Map (mutate on-site tak cukup trigger).
    expandedKeys.value = new Set(expandedKeys.value);
}

// Props drpd Inertia (JobsheetLookupController::index()) - carian ialah GET Inertia biasa ke
// halaman ni sendiri dgn ?jobsheet=..., BUKAN fetch() ke endpoint JSON berasingan. `items`
// DITANGGUH (Inertia::defer() - rujuk JobsheetLookupController dokblok) - shell halaman (tajuk,
// borang carian) terpapar SERTA-MERTA, `items` sampai kemudian via <Deferred> di bawah, jadi
// `undefined` sehingga siap (rujuk fallback Skeleton).
const props = defineProps<{
    jobsheet: string;
    hasSearched: boolean;
    items?: JobsheetItem[];
}>();

const jobsheetInput = ref(props.jobsheet);
const searching = ref(false);

function search() {
    const query = jobsheetInput.value.trim();

    if (!query) {
        return;
    }

    searching.value = true;

    router.get('/jobsheet-lookup', { jobsheet: query }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            searching.value = false;
        },
    });
}
</script>

<template>

    <Head title="Carian Jobsheet" />

    <div class="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Carian Item ikut Jobsheet</h1>
            <p class="text-sm text-muted-foreground">
                Cari semua item (jemisys_inventory_mirror) yang dikaitkan dengan satu No. Jobsheet.
            </p>
        </div>

        <Card>
            <CardContent>
                <form class="flex items-end gap-3" @submit.prevent="search">
                    <div class="flex-1">
                        <Label for="jobsheet" class="mb-1.5 block">No. Jobsheet</Label>
                        <Input id="jobsheet" v-model="jobsheetInput" placeholder="cth. JS000001" autocomplete="off"
                            maxlength="10" />
                    </div>
                    <Button type="submit" :disabled="searching || !jobsheetInput.trim()">
                        <Loader2 v-if="searching" class="size-4 animate-spin" />
                        <Search v-else class="size-4" />
                        Cari
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card v-if="props.hasSearched">
            <Deferred data="items">
                <template #fallback>
                    <CardHeader>
                        <CardTitle class="text-base">Hasil Carian</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <div v-for="row in 6" :key="row" class="flex items-center gap-3">
                            <Skeleton class="size-10 shrink-0 rounded-md" />
                            <div class="flex flex-1 flex-col gap-1.5">
                                <Skeleton class="h-4 w-40" />
                                <Skeleton class="h-3 w-64" />
                            </div>
                            <Skeleton class="h-6 w-24 shrink-0 rounded-full" />
                        </div>
                    </CardContent>
                </template>

                <template #default>
                    <CardHeader>
                        <CardTitle class="text-base">
                            Hasil Carian ({{ (props.items ?? []).length }} keping, {{ groupedItems.length }} design/cawangan)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="(props.items ?? []).length === 0" class="text-sm text-muted-foreground">
                            Tiada item dijumpai untuk Jobsheet "{{ props.jobsheet }}".
                        </p>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <th class="py-2 pr-3 font-medium"></th>
                                        <th class="py-2 pr-3 font-medium">Imej</th>
                                        <th class="py-2 pr-3 font-medium">Kod Design</th>
                                        <th class="py-2 pr-3 font-medium">Keterangan</th>
                                        <th class="py-2 pr-3 font-medium">Kategori</th>
                                        <th class="py-2 pr-3 font-medium">Cawangan</th>
                                        <th class="py-2 pr-3 font-medium">Supplier</th>
                                        <th class="py-2 pr-3 font-medium">Berat</th>
                                        <th class="py-2 pr-3 font-medium">Stok</th>
                                        <th class="py-2 font-medium">Cadangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="group in groupedItems" :key="group.key">
                                        <tr
                                            class="cursor-pointer border-b last:border-0 hover:bg-muted/40"
                                            @click="toggleGroup(group.key)"
                                        >
                                            <td class="py-2 pl-1 text-muted-foreground">
                                                <ChevronDown v-if="expandedKeys.has(group.key)" class="size-4" />
                                                <ChevronRight v-else class="size-4" />
                                            </td>
                                            <td class="py-2 pr-3">
                                                <ImagePreview :src="group.image_url" :alt="group.description"
                                                    class="size-10 rounded-md" />
                                            </td>
                                            <td class="py-2 pr-3">
                                                <p class="font-medium">
                                                    {{ group.internal_code ?? '-' }}
                                                    <span v-if="group.pieces.length > 1" class="font-normal text-muted-foreground">&times;{{ group.pieces.length }}</span>
                                                </p>
                                                <p v-if="group.nickname" class="text-xs italic text-muted-foreground">
                                                    a.k.a. "{{ group.nickname }}"
                                                </p>
                                            </td>
                                            <td class="py-2 pr-3 text-muted-foreground">{{ group.description ?? '-' }}</td>
                                            <td class="py-2 pr-3">
                                                <Badge v-if="group.category_name" variant="outline">{{ group.category_name }}</Badge>
                                                <span v-else class="text-muted-foreground">-</span>
                                            </td>
                                            <td class="py-2 pr-3">
                                                <Badge v-if="group.store_code" variant="secondary">{{ group.store_code }}</Badge>
                                                <span v-else class="text-muted-foreground">-</span>
                                            </td>
                                            <td class="py-2 pr-3 text-muted-foreground">{{ group.vendor_name ?? '-' }}</td>
                                            <td class="py-2 pr-3 text-muted-foreground">
                                                {{ group.total_weight ? `${group.total_weight.toFixed(2)}g` : '-' }}
                                            </td>
                                            <td class="py-2 pr-3 font-medium">
                                                {{ group.on_hand_count }}
                                                <span v-if="group.sold_count" class="font-normal text-muted-foreground">({{ group.sold_count }} dijual)</span>
                                            </td>
                                            <td class="py-2">
                                                <div v-if="group.restock_action" class="flex flex-col gap-1">
                                                    <span
                                                        :class="`inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_BADGE_CLASS[group.restock_action_color]}`"
                                                        :title="group.restock_action_detail ?? ''"
                                                    >
                                                        {{ group.restock_action_label }}
                                                    </span>
                                                    <p v-if="group.restock_action === 'rearrange' && group.restock_target_branches.length" class="text-xs text-muted-foreground">
                                                        Pindah dari {{ group.restock_target_branches.join(', ') }} &rarr; {{ group.store_code }}
                                                    </p>
                                                    <p v-else-if="group.restock_target_branches.length" class="text-xs text-muted-foreground">
                                                        Hantar ke: {{ group.restock_target_branches.join(', ') }}
                                                    </p>
                                                </div>
                                                <span v-else class="text-muted-foreground">-</span>
                                            </td>
                                        </tr>

                                        <!-- Keping individu - tarikh/berat/imej masing2, cuma bila kumpulan dikembang. -->
                                        <template v-if="expandedKeys.has(group.key)">
                                            <tr v-for="piece in group.pieces" :key="piece.inventory_code"
                                                class="border-b bg-muted/20 text-xs last:border-0">
                                                <td class="py-1.5"></td>
                                                <td class="py-1.5 pr-3"></td>
                                                <td class="py-1.5 pr-3 pl-4 text-muted-foreground">{{ piece.inventory_code }}</td>
                                                <td class="py-1.5 pr-3 text-muted-foreground" colspan="2">
                                                    {{ piece.size ? `Saiz ${piece.size}` : '-' }}{{ piece.weight ? ` · ${piece.weight}g` : '' }}
                                                </td>
                                                <td class="py-1.5 pr-3 text-muted-foreground">{{ statusLabel(piece.status) }}</td>
                                                <td class="py-1.5 pr-3 text-muted-foreground">Beli: {{ piece.purch_date ?? '-' }}</td>
                                                <td class="py-1.5 pr-3 text-muted-foreground" colspan="2">Jual: {{ piece.sales_date ?? '-' }}</td>
                                                <td class="py-1.5"></td>
                                            </tr>
                                        </template>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </template>
            </Deferred>
        </Card>
    </div>
</template>
