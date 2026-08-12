<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { Loader2, Search } from '@lucide/vue';
import { ref } from 'vue';
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
    // Skor cadangan restock (0-100) - rujuk App\Support\JobsheetRestockScorer dokblok utk 4
    // isyarat (Stok Habis/Understock/Design Paling Laku/Cawangan Jualan Tertinggi).
    restock_score: number;
    restock_verdict: string | null;
    restock_verdict_color: 'danger' | 'warning' | 'info' | 'gray';
    restock_reasons: string[];
    // Cawangan (StoreCode) plg laku design ni, tersusun menurun - "patut hantar ke mana".
    restock_target_branches: string[];
}

// Badge shadcn-vue tiada variant "danger"/"warning"/"info" terus - peta ke variant/kelas
// sedia ada (sama corak warna dgn FULFILLMENT_COLORS Filament merentasi app ni).
const VERDICT_BADGE_CLASS: Record<string, string> = {
    danger: 'border-transparent bg-destructive text-white',
    warning: 'border-transparent bg-warning text-warning-foreground',
    info: 'border-transparent bg-blue-500 text-white',
    gray: 'border-transparent bg-muted text-muted-foreground',
};

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
                            Hasil Carian ({{ (props.items ?? []).length }})
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
                                        <th class="py-2 pr-3 font-medium">Imej</th>
                                        <th class="py-2 pr-3 font-medium">Kod Design</th>
                                        <th class="py-2 pr-3 font-medium">Keterangan</th>
                                        <th class="py-2 pr-3 font-medium">Kategori</th>
                                        <th class="py-2 pr-3 font-medium">Cawangan</th>
                                        <th class="py-2 pr-3 font-medium">Supplier</th>
                                        <th class="py-2 pr-3 font-medium">Saiz / Berat</th>
                                        <th class="py-2 pr-3 font-medium">Stok</th>
                                        <th class="py-2 pr-3 font-medium">Status</th>
                                        <th class="py-2 pr-3 font-medium">Tarikh Beli</th>
                                        <th class="py-2 pr-3 font-medium">Tarikh Jual</th>
                                        <th class="py-2 font-medium">Cadangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in props.items" :key="item.inventory_code" class="border-b last:border-0">
                                        <td class="py-2 pr-3">
                                            <ImagePreview :src="item.image_url" :alt="item.description"
                                                class="size-10 rounded-md" />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <p class="font-medium">{{ item.internal_code ?? '-' }}</p>
                                            <p v-if="item.nickname" class="text-xs italic text-muted-foreground">
                                                a.k.a. "{{ item.nickname }}"
                                            </p>
                                        </td>
                                        <td class="py-2 pr-3 text-muted-foreground">{{ item.description ?? '-' }}</td>
                                        <td class="py-2 pr-3">
                                            <Badge v-if="item.category_name" variant="outline">{{ item.category_name }}</Badge>
                                            <span v-else class="text-muted-foreground">-</span>
                                        </td>
                                        <td class="py-2 pr-3">
                                            <Badge v-if="item.store_code" variant="secondary">{{ item.store_code }}</Badge>
                                            <span v-else class="text-muted-foreground">-</span>
                                        </td>
                                        <td class="py-2 pr-3 text-muted-foreground">{{ item.vendor_name ?? '-' }}</td>
                                        <td class="py-2 pr-3 text-muted-foreground">
                                            <span v-if="item.size">{{ item.size }}</span>
                                            <span v-if="item.size && item.weight"> &middot; </span>
                                            <span v-if="item.weight">{{ item.weight }}g</span>
                                            <span v-if="!item.size && !item.weight">-</span>
                                        </td>
                                        <td class="py-2 pr-3 font-medium">{{ item.qty_on_hand }}</td>
                                        <td class="py-2 pr-3 text-muted-foreground">{{ item.status ?? '-' }}</td>
                                        <td class="py-2 pr-3 text-muted-foreground">{{ item.purch_date ?? '-' }}</td>
                                        <td class="py-2 pr-3 text-muted-foreground">{{ item.sales_date ?? '-' }}</td>
                                        <td class="py-2">
                                            <div v-if="item.restock_verdict" class="flex flex-col gap-1">
                                                <span
                                                    :class="`inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${VERDICT_BADGE_CLASS[item.restock_verdict_color]}`"
                                                    :title="item.restock_reasons.join(' · ')"
                                                >
                                                    {{ item.restock_verdict }} ({{ item.restock_score }})
                                                </span>
                                                <p v-if="item.restock_target_branches.length" class="text-xs text-muted-foreground">
                                                    Hantar ke: {{ item.restock_target_branches.join(', ') }}
                                                </p>
                                            </div>
                                            <span v-else class="text-muted-foreground">-</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </template>
            </Deferred>
        </Card>
    </div>
</template>
