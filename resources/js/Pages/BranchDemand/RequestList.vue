<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Eye, Globe, ImageUp, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SelectNative } from '@/components/ui/select';

interface StoreOption {
    code: string;
    label: string;
}

interface RequestLine {
    internal_code: string | null;
    item_desc: string | null;
    source_type: 'catalog' | 'web' | 'upload';
    image_url: string | null;
    qty_requested: number;
    remark: string | null;
    size: string | null;
    weight: number | null;
    qty_approved: number | null;
    line_status: string;
    fulfillment_status: string;
    fulfillment_label: string;
}

interface BranchDemandRequestRow {
    id: number;
    request_number: string;
    status: string;
    submitted_by: string;
    submitted_at: string | null;
    notes: string | null;
    lines: RequestLine[];
}

const props = defineProps<{
    stores: StoreOption[];
    storeCode: string | null;
    requests: BranchDemandRequestRow[];
}>();

const page = usePage<{ flash: { success: string | null } }>();

const selectedStore = ref(props.storeCode ?? '');
const detailRequest = ref<BranchDemandRequestRow | null>(null);

function onStoreChange() {
    router.get('/branch-demand/requests', { store_code: selectedStore.value }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function statusBadgeClass(status: string) {
    return {
        Submitted: 'bg-warning/15 text-warning-foreground border-warning/30',
        Reviewed: 'bg-success/15 text-success-700 border-success/30',
        Processing: 'bg-blue-500/15 text-blue-700 border-blue-500/30',
        Completed: 'bg-success/15 text-success-700 border-success/30',
        Cancelled: 'bg-destructive/15 text-destructive border-destructive/30',
    }[status] ?? 'bg-muted text-muted-foreground border-border';
}

function statusLabel(status: string) {
    return {
        Submitted: 'Menunggu Semakan',
        Reviewed: 'Disemak',
        Processing: 'Diproses',
        Completed: 'Selesai',
        Cancelled: 'Dibatalkan',
    }[status] ?? status;
}

function lineStatusBadgeClass(status: string) {
    return {
        Approved: 'bg-success/15 text-success-700',
        Rejected: 'bg-destructive/15 text-destructive',
        Pending: 'bg-muted text-muted-foreground',
    }[status] ?? 'bg-muted text-muted-foreground';
}

// Landasan progress BO SENDIRI (rujuk BranchDemandRequestLine::FULFILLMENT_COLORS - warna
// dikekalkan SAMA dgn versi Filament) - berasingan drpd lineStatusBadgeClass (keputusan
// Lulus/Tolak HQ).
function fulfillmentBadgeClass(status: string) {
    return {
        requested: 'bg-muted text-muted-foreground',
        stok_kritikal: 'bg-destructive/15 text-destructive',
        special_request: 'bg-blue-500/15 text-blue-700',
        listed_noted: 'bg-muted text-muted-foreground',
        dah_order: 'bg-warning/15 text-warning-foreground',
        dah_restock: 'bg-warning/15 text-warning-foreground',
        dah_delivery: 'bg-success/15 text-success-700',
        rearrange: 'bg-warning/15 text-warning-foreground',
        order: 'bg-warning/15 text-warning-foreground',
        item_not_available: 'bg-destructive/15 text-destructive',
    }[status] ?? 'bg-muted text-muted-foreground';
}
</script>

<template>
    <Head title="Senarai Permintaan Stok" />
    <div class="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-8">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Senarai Permintaan Stok</h1>
                <p class="text-sm text-muted-foreground">
                    Pilih cawangan utk lihat status permintaan yang telah dihantar.
                </p>
            </div>
            <Link :href="selectedStore ? `/branch-demand?store_code=${selectedStore}` : '/branch-demand'">
                <Button variant="outline">
                    <Plus class="size-4" /> Permintaan Baharu
                </Button>
            </Link>
        </div>

        <div
            v-if="page.props.flash.success"
            class="rounded-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success-700"
        >
            {{ page.props.flash.success }}
        </div>

        <Card>
            <CardContent>
                <SelectNative v-model="selectedStore" @update:model-value="onStoreChange">
                    <option value="" disabled>Pilih cawangan...</option>
                    <option v-for="store in stores" :key="store.code" :value="store.code">
                        {{ store.label }}
                    </option>
                </SelectNative>
            </CardContent>
        </Card>

        <p v-if="!storeCode" class="text-sm text-muted-foreground">Pilih cawangan dahulu utk lihat senarai permintaan.</p>
        <p v-else-if="requests.length === 0" class="text-sm text-muted-foreground">Tiada permintaan lagi utk cawangan ini.</p>

        <Card v-for="request in requests" :key="request.id">
            <CardContent class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="font-medium">{{ request.request_number }}</p>
                        <span :class="`rounded-full border px-2 py-0.5 text-xs font-medium ${statusBadgeClass(request.status)}`">
                            {{ statusLabel(request.status) }}
                        </span>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ request.submitted_by }} &middot; {{ request.submitted_at }} &middot; {{ request.lines.length }} item
                    </p>
                </div>
                <Button variant="outline" size="icon" @click="detailRequest = request">
                    <Eye class="size-4" />
                    <span class="sr-only">Lihat butiran</span>
                </Button>
            </CardContent>
        </Card>

        <Dialog :open="detailRequest !== null" @update:open="(v) => { if (!v) detailRequest = null; }">
            <DialogContent v-if="detailRequest">
                <DialogHeader>
                    <DialogTitle>{{ detailRequest.request_number }}</DialogTitle>
                </DialogHeader>

                <div class="flex flex-col gap-2 text-sm">
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="(line, i) in detailRequest.lines"
                            :key="i"
                            class="flex items-center justify-between gap-2 rounded-md border p-2"
                        >
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-1.5 truncate font-medium">
                                    <template v-if="line.internal_code">{{ line.internal_code }} - </template>{{ line.item_desc }}
                                    <Badge v-if="line.source_type === 'web'" variant="outline" class="gap-1 text-xs">
                                        <Globe class="size-3" /> Laman Web
                                    </Badge>
                                    <Badge v-if="line.source_type === 'upload'" variant="outline" class="gap-1 text-xs">
                                        <ImageUp class="size-3" /> Gambar Sendiri
                                    </Badge>
                                    <Badge v-if="line.fulfillment_status === 'stok_kritikal'" variant="destructive" class="gap-1 text-xs">
                                        <AlertTriangle class="size-3" /> Kritikal
                                    </Badge>
                                </p>
                                <p class="text-muted-foreground">
                                    Diminta: {{ line.qty_requested }}
                                    <span v-if="line.qty_approved !== null"> &middot; Diluluskan: {{ line.qty_approved }}</span>
                                </p>
                                <p v-if="line.size || line.weight" class="text-muted-foreground">
                                    <span v-if="line.size">Saiz {{ line.size }}</span>
                                    <span v-if="line.size && line.weight"> &middot; </span>
                                    <span v-if="line.weight">{{ line.weight }}g</span>
                                </p>
                                <p v-if="line.remark" class="text-muted-foreground">Remark: {{ line.remark }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span :class="`rounded-full px-2 py-0.5 text-xs font-medium ${lineStatusBadgeClass(line.line_status)}`">
                                    {{ line.line_status }}
                                </span>
                                <span v-if="line.line_status === 'Approved'"
                                    :class="`rounded-full px-2 py-0.5 text-xs font-medium ${fulfillmentBadgeClass(line.fulfillment_status)}`">
                                    {{ line.fulfillment_label }}
                                </span>
                            </div>
                        </li>
                    </ul>

                    <p v-if="detailRequest.notes" class="text-muted-foreground">
                        Nota: <span class="text-foreground">{{ detailRequest.notes }}</span>
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
