<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { Check, ChevronDown, Download, FileSpreadsheet, Loader2, Printer, Save, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FacetedFilter from '@/components/FacetedFilter.vue';
import ImagePreview from '@/components/ImagePreview.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NumberField, NumberFieldContent, NumberFieldDecrement, NumberFieldIncrement, NumberFieldInput } from '@/components/ui/number-field';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Separator } from '@/components/ui/separator';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

// Satu design = satu baris di senarai & satu tindakan utk SEMUA cawangan yg minta design tsb
// (rujuk App\Support\BackOfficeActionsAdvisor). Data asas masih per-line (satu line = permintaan
// SATU cawangan) kerana status penuhan & Rearrange disimpan per line di server.
interface Move {
    from_store: string;
    qty: number;
}

interface Line {
    id: number;
    design_key: string;
    request_number: string;
    requested_at: string | null;
    store_code: string;
    internal_code: string | null;
    item_desc: string | null;
    category_name: string | null;
    image_url: string | null;
    nickname: string | null;
    size: string | null;
    weight: string | null;
    remark: string | null;
    qty_requested: number;
    qty_outstanding: number;
    fulfillment_status: string;
    is_critical: boolean;
    own_stock: number;
    action: 'rearrange' | 'restock' | 'both' | null;
    rearrange_qty: number;
    restock_qty: number;
    moves: Move[];
}

// Data peringkat DESIGN (merentas semua cawangan) - jualan 7d/30d dikira serentak di server,
// tukar tempoh di UI tak perlu request semula.
interface Design {
    internal_code: string | null;
    demand: { store_code: string; qty: number }[];
    total_outstanding: number;
    stock: { store_code: string; stock: number; reserved: number }[];
    total_stock: number;
    spare_stock: number;
    network_low: boolean;
    sales: { store_code: string; sold_7d: number; sold_30d: number }[];
    sold_7d: number;
    sold_30d: number;
    rearrange_qty: number | null;
    restock_qty: number | null;
    restock_plan: RestockItem['plan'] | null;
}

interface Board {
    lines: Line[];
    designs: Record<string, Design>;
}

// Calon kod design utk design TANPA kod (web/upload) - padanan nickname/Description inventori.
interface Candidate {
    internal_code: string;
    description: string | null;
    nickname: string | null;
    category_code: string | null;
    size: string | null;
    weight: number | null;
    image_url: string | null;
    total_stock: number;
    match: 'nickname' | 'description';
    score: number;
}

// Pratonton selepas BO pilih kod: cadangan dikira seolah-olah line sudah ada kod tsb.
interface Preview {
    code: string;
    candidate: Candidate;
    design: Design;
    lines: Record<number, Line>;
    lineIds: number[];
    originalKey: string;
}

// Perubahan BELUM disimpan - kunci = id line. Hanya dihantar ke server bila tekan Simpan.
// `internal_code` = kod design yg BO pilih utk line yg belum ada kod.
interface Staged {
    status: string;
    moves?: Move[];
    internal_code?: string;
}

interface DesignGroup {
    key: string;
    code: string | null;
    title: string;
    description: string | null;
    nickname: string | null;
    image_url: string | null;
    category_name: string | null;
    lines: Line[];
    total_outstanding: number;
    rearrange_qty: number;
    restock_qty: number;
    action: 'rearrange' | 'restock' | 'both' | null;
}

// Senarai Restock tersimpan (RestockListItem). `requests` = cawangan yg request (saiz/berat dari
// mereka sahaja); cawangan lain yg stok rendah tapi tak request cuma ada dlm `stock`/`plan`.
interface RestockRequest {
    store_code: string;
    request_number: string;
    size: string | null;
    weight: string | null;
    qty: number;
    remark: string | null;
}

interface RestockItem {
    id: number;
    // true = design baru dimasukkan (status Order BELUM disimpan) - papar sementara drpd data papan.
    pending?: boolean;
    internal_code: string;
    description: string | null;
    nickname: string | null;
    image_url: string | null;
    category_name: string | null;
    qty_to_order: number;
    suggested_qty: number;
    // Paras setiap cawangan utk design ini (lalai) + pengecualian setiap cawangan; allocation = agihan qty order
    // yg disimpan semasa Simpan (null utk design baharu - dikira di browser).
    target_stock: number;
    branch_targets: Record<string, number>;
    allocation: Record<string, number> | null;
    plan: {
        target: number;
        branches: { store_code: string; stock: number; requested: number; target: number; need: number }[];
        surplus: number;
        external_stock: number;
        need_total: number;
        suggested_qty: number;
    };
    requests: RestockRequest[];
    requested_total: number;
    stock: { store_code: string; stock: number }[];
    total_stock: number;
    sold_7d: number;
    sold_30d: number;
    suppliers: { vendor_code: string; sold: number; pieces: number }[];
}

interface RestockSupplier {
    vendor_code: string;
    designs: number;
    sold: number;
    qty_to_order: number;
    codes: string[];
}

interface LogEntry {
    key: string;
    design: string;
    store: string;
    from: string;
    to: string;
    at?: string;
}

const props = defineProps<{
    board?: Board;
    restock?: { items: RestockItem[]; suppliers: RestockSupplier[] };
    canSeeSuppliers?: boolean;
    fulfillmentOptions: { value: string; label: string }[];
}>();

// Warna cadangan: Rearrange = biru, Restock = kuning (warning), gabungan = hitam (primary) pada lencana
// tapi biru pada kotak ringkasan (Rearrange dilakukan dulu).
const ACTION_BADGE_CLASS: Record<string, string> = {
    restock: 'border-transparent bg-warning text-warning-foreground',
    rearrange: 'border-transparent bg-blue-600 text-white',
    both: 'border-transparent bg-primary text-primary-foreground',
};

const ACTION_BOX_CLASS: Record<string, string> = {
    restock: 'border-warning/50 bg-warning/10',
    rearrange: 'border-blue-500/40 bg-blue-500/10',
    both: 'border-blue-500/40 bg-blue-500/10',
};

const ACTION_LABEL: Record<string, string> = {
    restock: 'Restock',
    rearrange: 'Rearrange',
    both: 'Rearrange + Restock',
};

const STATUS_REARRANGE = 'rearrange';
const STATUS_RESTOCK_DEFAULT = 'order';

const search = ref('');
// Penapis senarai (berbilang pilihan). Cawangan = tapis line; Kategori/Cadangan/Status = tapis design.
const branchFilters = ref<string[]>([]);
const categoryFilters = ref<string[]>([]);
const actionFilters = ref<string[]>([]);
const statusFilters = ref<string[]>([]);
const onlyCritical = ref(false);
// Pilihan pukal (kunci design) - tindakan footer terpakai kpd semua design dipilih yg kelihatan.
const checkedKeys = ref<string[]>([]);
const bulkPickerOpen = ref(false);

type SortKey = 'design' | 'requested' | 'action' | 'status';
const sortKey = ref<SortKey | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');
const selectedKey = ref<string | null>(null);
// Override: tindakan di footer cuma utk SATU cawangan (id line) dan bukan semua cawangan design ni.
const overrideLineId = ref<number | null>(null);
const period = ref<'7d' | '30d'>('7d');
const staged = ref<Record<number, Staged>>({});
const savedLog = ref<LogEntry[]>([]);
const saving = ref(false);

const candidates = ref<Record<string, Candidate[]>>({});
// Halaman calon seterusnya (butang "Muat Lagi") - kunci = kunci design; term carian yg sama dgn
// halaman pertama dikekalkan supaya halaman seterusnya konsisten.
const candidatePage = ref<Record<string, { page: number; hasMore: boolean; query: string }>>({});
const candidateQuery = ref('');
const candidateLoading = ref(false);
const candidateLoadingMore = ref(false);
const previewLoading = ref(false);
const previews = ref<Record<string, Preview>>({});

// Suntingan Senarai Restock BELUM disimpan - kunci = id item (kuantiti baharu atau dibuang).
// qty = kuantiti yg BO taip sendiri (manual); target/branchTargets = paras diubah (cadangan & agihan dikira semula).
interface RestockEdit {
    qty?: number;
    remove?: boolean;
    ordered?: boolean;
    target?: number;
    branchTargets?: Record<string, number>;
}

const restockEdits = ref<Record<number, RestockEdit>>({});
const restockGroup = ref<'category' | 'branch' | 'supplier'>('category');
const exportOpen = ref(false);

const restockItems = computed<RestockItem[]>(() => props.restock?.items ?? []);

// Kuantiti design BARU (belum disimpan) yg leader BO ubah - kunci = kod design.
const pendingQty = ref<Record<string, number>>({});
// Paras design BARU (belum disimpan) - kunci = kod design.
const pendingTarget = ref<Record<string, number>>({});
const pendingBranchTargets = ref<Record<string, Record<string, number>>>({});

function commitRestockEdit(id: number, edit: RestockEdit) {
    const hasValue = Object.values(edit).some((v) => v !== undefined && v !== false);

    if (hasValue) {
        restockEdits.value[id] = edit;
    } else {
        delete restockEdits.value[id];
    }
}

// Tick = tanda Dah Order (dilaksanakan bila Simpan). Tick sekali lagi untuk batal.
function toggleOrdered(item: RestockItem) {
    const edit = restockEdits.value[item.id] ?? {};

    commitRestockEdit(item.id, { ...edit, ordered: edit.ordered ? undefined : true });
}

function removeRestockItem(item: RestockItem) {
    restockEdits.value[item.id] = { remove: true };
}

function undoRestockEdit(id: number) {
    delete restockEdits.value[id];
}

// Design yg baru ditekan "Masuk Restock" (status Order belum disimpan) terus muncul dlm Senarai Restock
// dgn data drpd papan (cadangan paras 3, stok, jualan, saiz/berat cawangan request) - satu Simpan sahaja.
const pendingRestock = computed<RestockItem[]>(() => {
    const persisted = new Set(restockItems.value.map((i) => i.internal_code));
    const codes = new Set<string>();

    for (const l of lines.value) {
        if (l.internal_code && !persisted.has(l.internal_code) && staged.value[l.id]?.status === STATUS_RESTOCK_DEFAULT) {
            codes.add(l.internal_code);
        }
    }

    return Array.from(codes).map((code) => {
        const codeLines = lines.value.filter((l) => l.internal_code === code && effectiveStatus(l) === STATUS_RESTOCK_DEFAULT);
        const first = codeLines[0];
        const design = previews.value[code]?.design ?? props.board?.designs?.[code] ?? null;
        const plan = design?.restock_plan ?? { target: 3, branches: [], surplus: 0, external_stock: 0, need_total: 0, suggested_qty: 0 };

        return {
            id: 0,
            pending: true,
            internal_code: code,
            description: first.item_desc,
            nickname: first.nickname,
            image_url: codeLines.find((l) => l.image_url)?.image_url ?? null,
            category_name: first.category_name,
            qty_to_order: pendingQty.value[code] ?? plan.suggested_qty,
            suggested_qty: plan.suggested_qty,
            target_stock: plan.target,
            branch_targets: {},
            allocation: null,
            plan,
            requests: codeLines.map((l) => ({
                store_code: l.store_code,
                request_number: l.request_number,
                size: l.size,
                weight: l.weight,
                qty: l.qty_outstanding,
                remark: l.remark,
            })),
            requested_total: codeLines.reduce((sum, l) => sum + l.qty_outstanding, 0),
            stock: (design?.stock ?? []).map((s) => ({ store_code: s.store_code, stock: s.stock })),
            total_stock: design?.total_stock ?? 0,
            sold_7d: design?.sold_7d ?? 0,
            sold_30d: design?.sold_30d ?? 0,
            suppliers: [],
        };
    });
});

const restockRows = computed<RestockItem[]>(() => [...pendingRestock.value, ...restockItems.value]);

function rowKey(item: RestockItem): string {
    return item.pending ? `pending-${item.internal_code}` : String(item.id);
}

// ===== Paras, cadangan & agihan (dikira di browser supaya serta-merta; server mengira semula semasa Simpan) =====
interface LiveBranch {
    store_code: string;
    stock: number;
    requested: number;
    target: number;
    need: number;
}

function clampInt(value: unknown, max: number): number {
    return Math.max(0, Math.min(max, Math.floor(Number(value) || 0)));
}

function sameTargets(a: Record<string, number>, b: Record<string, number>): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    return Array.from(keys).every((k) => a[k] === b[k]);
}

function targetOf(item: RestockItem): number {
    return (item.pending ? pendingTarget.value[item.internal_code] : restockEdits.value[item.id]?.target) ?? item.target_stock;
}

function branchTargetsOf(item: RestockItem): Record<string, number> {
    return (item.pending ? pendingBranchTargets.value[item.internal_code] : restockEdits.value[item.id]?.branchTargets) ?? item.branch_targets;
}

function planEdited(item: RestockItem): boolean {
    return item.pending
        ? pendingTarget.value[item.internal_code] !== undefined || pendingBranchTargets.value[item.internal_code] !== undefined
        : restockEdits.value[item.id]?.target !== undefined || restockEdits.value[item.id]?.branchTargets !== undefined;
}

// Sama persis dgn BackOfficeActionsAdvisor::restockPlan() - paras setiap cawangan = pengecualian ?? paras lalai.
function livePlan(item: RestockItem): { branches: LiveBranch[]; surplus: number; need_total: number; suggested_qty: number } {
    const target = targetOf(item);
    const overrides = branchTargetsOf(item);

    const branches = item.plan.branches.map((b) => {
        const branchTarget = overrides[b.store_code] ?? target;

        return {
            store_code: b.store_code,
            stock: b.stock,
            requested: b.requested,
            target: branchTarget,
            need: Math.max(Math.max(0, branchTarget - b.stock), b.requested),
        };
    });

    const surplus = branches
        .filter((b) => b.requested === 0)
        .reduce((sum, b) => sum + Math.max(0, b.stock - b.target), 0) + item.plan.external_stock;
    const needTotal = branches.reduce((sum, b) => sum + b.need, 0);

    return { branches, surplus, need_total: needTotal, suggested_qty: Math.max(0, needTotal - surplus) };
}

function suggestedLive(item: RestockItem): number {
    return planEdited(item) ? livePlan(item).suggested_qty : item.suggested_qty;
}

// Qty ikut cadangan (auto) selagi BO tak taip sendiri: design baharu, atau item yg qty simpanan = cadangan simpanan.
function isAutoQty(item: RestockItem): boolean {
    return item.pending || item.qty_to_order === item.suggested_qty;
}

function manualQty(item: RestockItem): number | undefined {
    return item.pending ? pendingQty.value[item.internal_code] : restockEdits.value[item.id]?.qty;
}

function rowQty(item: RestockItem): number {
    return manualQty(item) ?? (isAutoQty(item) ? suggestedLive(item) : item.qty_to_order);
}

function setRowQty(item: RestockItem, value: string) {
    const qty = clampInt(value, 100000);

    if (item.pending) {
        if (qty === suggestedLive(item)) {
            delete pendingQty.value[item.internal_code];
        } else {
            pendingQty.value[item.internal_code] = qty;
        }

        return;
    }

    const base = isAutoQty(item) ? suggestedLive(item) : item.qty_to_order;

    commitRestockEdit(item.id, { ...restockEdits.value[item.id], qty: qty === base ? undefined : qty });
}

function setTarget(item: RestockItem, value: unknown) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return;
    }

    const target = clampInt(value, 50);

    if (item.pending) {
        if (target === item.target_stock) {
            delete pendingTarget.value[item.internal_code];
        } else {
            pendingTarget.value[item.internal_code] = target;
        }

        return;
    }

    commitRestockEdit(item.id, { ...restockEdits.value[item.id], target: target === item.target_stock ? undefined : target });
}

function storeBranchTargets(item: RestockItem, overrides: Record<string, number>) {
    if (item.pending) {
        if (sameTargets(overrides, item.branch_targets)) {
            delete pendingBranchTargets.value[item.internal_code];
        } else {
            pendingBranchTargets.value[item.internal_code] = overrides;
        }

        return;
    }

    commitRestockEdit(item.id, {
        ...restockEdits.value[item.id],
        branchTargets: sameTargets(overrides, item.branch_targets) ? undefined : overrides,
    });
}

// Nilai sama dgn paras lalai = bukan pengecualian (dibuang).
function setBranchTarget(item: RestockItem, store: string, value: unknown) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return;
    }

    const overrides = { ...branchTargetsOf(item) };
    const branchTarget = clampInt(value, 50);

    if (branchTarget === targetOf(item)) {
        delete overrides[store];
    } else {
        overrides[store] = branchTarget;
    }

    storeBranchTargets(item, overrides);
}

function resetBranchTargets(item: RestockItem) {
    storeBranchTargets(item, {});
}

// Agihan Qty order ke cawangan (Restock sahaja) - SAMA persis dgn App\Support\RestockAllocator (ubah KEDUA-DUA):
// 1) cawangan yg request dahulu, 2) isi ke paras, 3) lebihan diratakan bergilir ikut kod. Fasa 1 & 2: unit diberi
// satu-satu bergilir, baki keperluan terbesar dahulu (kemudian kod).
function spreadUnits(allocation: Record<string, number>, caps: Record<string, number>, qtyLeft: number): number {
    const remaining: Record<string, number> = {};
    let left = qtyLeft;

    Object.entries(caps).forEach(([store, cap]) => {
        if (cap > 0) {
            remaining[store] = cap;
        }
    });

    while (left > 0 && Object.keys(remaining).length > 0) {
        const stores = Object.keys(remaining).sort((a, b) => remaining[b] - remaining[a] || (a < b ? -1 : a > b ? 1 : 0));

        for (const store of stores) {
            if (left === 0) {
                break;
            }

            allocation[store]++;
            left--;
            remaining[store]--;

            if (remaining[store] === 0) {
                delete remaining[store];
            }
        }
    }

    return left;
}

function allocateQty(rows: { store_code: string; need: number; requested: number }[], qty: number): Record<string, number> {
    const allocation: Record<string, number> = {};
    const requestedCap: Record<string, number> = {};
    const fillCap: Record<string, number> = {};

    rows.forEach((r) => {
        allocation[r.store_code] = 0;
        requestedCap[r.store_code] = Math.min(r.requested, r.need);
        fillCap[r.store_code] = Math.max(0, r.need - r.requested);
    });

    let left = Math.max(0, qty);
    left = spreadUnits(allocation, requestedCap, left);
    left = spreadUnits(allocation, fillCap, left);

    const stores = Object.keys(allocation).sort();

    for (let i = 0; left > 0 && stores.length > 0; i++, left--) {
        allocation[stores[i % stores.length]]++;
    }

    return allocation;
}

// Agihan simpanan dipakai selagi qty & paras tak diubah; selain itu dikira semula serta-merta.
function allocationOf(item: RestockItem): Record<string, number> {
    const qty = rowQty(item);

    if (!item.pending && item.allocation && !planEdited(item) && qty === item.qty_to_order) {
        return item.allocation;
    }

    return allocateQty(livePlan(item).branches, qty);
}

const allocationByRow = computed<Record<string, Record<string, number>>>(() =>
    Object.fromEntries(restockRows.value.map((item) => [rowKey(item), allocationOf(item)])),
);

function allocOf(item: RestockItem, store: string): number {
    return allocationByRow.value[rowKey(item)]?.[store] ?? 0;
}

function requestedOf(item: RestockItem, store: string): number {
    return item.plan.branches.find((b) => b.store_code === store)?.requested ?? 0;
}

// Qty yg BO taip sendiri (beza drpd cadangan) tak cukup utk permintaan cawangan ini -> dot amber (amaran).
function requestShort(item: RestockItem, store: string): boolean {
    return rowQty(item) !== suggestedLive(item) && allocOf(item, store) < requestedOf(item, store);
}

function requestDotTitle(item: RestockItem, store: string): string | undefined {
    const requested = requestedOf(item, store);

    if (!requested) {
        return undefined;
    }

    return requestShort(item, store)
        ? `${store} request ${requested} unit, dapat ${allocOf(item, store)} - belum dipenuhi`
        : `${store} request ${requested} unit`;
}

function stockChipClass(item: RestockItem, s: { store_code: string; stock: number }): string {
    if (allocOf(item, s.store_code) > 0) {
        return 'border-primary bg-primary/10 font-medium';
    }

    return s.stock <= 1 ? 'border-destructive/50 text-destructive opacity-70' : 'opacity-70';
}

const branchPopoverKey = ref<string | null>(null);

// ===== Kumpulan paparan Senarai Restock (sama dgn Export/PDF - rujuk RestockListBuilder::sections()) =====
interface RestockSection {
    key: string;
    title: string;
    items: RestockItem[];
}

const NO_REQUEST_SECTION = 'Tiada permintaan cawangan';

const restockSections = computed<RestockSection[]>(() => {
    const groups = new Map<string, RestockItem[]>();
    const add = (key: string, item: RestockItem) => groups.set(key, [...(groups.get(key) ?? []), item]);

    for (const item of restockRows.value) {
        if (restockGroup.value === 'branch') {
            // Cawangan yg REQUEST atau MENERIMA unit drpd agihan (cawangan lain yg stok rendah pun nampak design ini).
            const received = Object.entries(allocationByRow.value[rowKey(item)] ?? {}).filter(([, qty]) => qty > 0).map(([store]) => store);
            const keys = Array.from(new Set([...item.requests.map((r) => r.store_code), ...received]));

            (keys.length ? keys : [NO_REQUEST_SECTION]).forEach((key) => add(key, item));
        } else if (restockGroup.value === 'supplier' && props.canSeeSuppliers) {
            add(item.suppliers[0]?.vendor_code || 'Tiada data supplier', item);
        } else {
            add(item.category_name || 'Tanpa kategori', item);
        }
    }

    return Array.from(groups, ([key, items]) => ({ key, title: key, items })).sort((a, b) => (a.title < b.title ? -1 : a.title > b.title ? 1 : 0));
});

// Pada kumpulan Cawangan, setiap bahagian cuma tunjuk permintaan cawangan itu & stok cawangan itu.
function sectionRequests(item: RestockItem, sectionKey: string): RestockRequest[] {
    return restockGroup.value === 'branch' ? item.requests.filter((r) => r.store_code === sectionKey) : item.requests;
}

function branchStockOf(item: RestockItem, store: string): number {
    return item.stock.find((s) => s.store_code === store)?.stock ?? 0;
}

const restockColumnCount = computed(() => (props.canSeeSuppliers ? 6 : 5));

function isRemovedRow(item: RestockItem): boolean {
    return !item.pending && !!restockEdits.value[item.id]?.remove;
}

function isOrderedRow(item: RestockItem): boolean {
    return !item.pending && !!restockEdits.value[item.id]?.ordered;
}

function isEditedRow(item: RestockItem): boolean {
    return !item.pending && !!restockEdits.value[item.id] && !restockEdits.value[item.id].remove && !restockEdits.value[item.id].ordered;
}

// Buang: baris baru -> batalkan status Order (kod yg BO pilih dikekalkan); baris tersimpan -> tanda buang.
function removeRow(item: RestockItem) {
    if (!item.pending) {
        removeRestockItem(item);

        return;
    }

    lines.value
        .filter((l) => l.internal_code === item.internal_code && staged.value[l.id]?.status === STATUS_RESTOCK_DEFAULT)
        .forEach((l) => stageStatus(l, l.fulfillment_status));
    delete pendingQty.value[item.internal_code];
    delete pendingTarget.value[item.internal_code];
    delete pendingBranchTargets.value[item.internal_code];
}

function planHint(item: RestockItem): string {
    const plan = livePlan(item);
    const parts = plan.branches.filter((b) => b.need > 0).map((b) => `${b.store_code} ${b.need}`).join(', ');
    const custom = Object.keys(branchTargetsOf(item)).length > 0 ? ' (ada pengecualian cawangan)' : '';

    return `Paras ${targetOf(item)} setiap cawangan${custom}: ${parts} = ${plan.need_total}; tolak lebihan ${plan.surplus} (stok HQ / cawangan tak request) = ${plan.suggested_qty}`;
}

const rawLines = computed<Line[]>(() => props.board?.lines ?? []);

// Line yg BO dah pilihkan kod design guna data pratonton server (kod, cadangan, donor) - status
// penuhan kekal drpd data sebenar.
const lines = computed<Line[]>(() => {
    const byLine = new Map<number, Preview>();

    for (const p of Object.values(previews.value)) {
        p.lineIds.forEach((id) => byLine.set(id, p));
    }

    return rawLines.value.map((l) => {
        const p = byLine.get(l.id);
        const preview = p?.lines[l.id];

        return p && preview
            ? { ...l, ...preview, id: l.id, fulfillment_status: l.fulfillment_status, nickname: p.candidate.nickname ?? l.nickname }
            : l;
    });
});

const labelByValue = computed<Record<string, string>>(() =>
    Object.fromEntries(props.fulfillmentOptions.map((o) => [o.value, o.label])),
);

const branchOptions = computed(() => {
    const counts = new Map<string, number>();

    lines.value.forEach((l) => counts.set(l.store_code, (counts.get(l.store_code) ?? 0) + 1));

    return Array.from(counts, ([value, count]) => ({ value, label: value, count })).sort((a, b) => a.value.localeCompare(b.value));
});

const filteredLines = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return lines.value.filter((l) => {
        if (branchFilters.value.length && !branchFilters.value.includes(l.store_code)) {
            return false;
        }
        if (onlyCritical.value && !l.is_critical) {
            return false;
        }
        if (!needle) {
            return true;
        }

        return [l.internal_code, l.item_desc, l.nickname, l.category_name, l.request_number]
            .some((v) => (v ?? '').toLowerCase().includes(needle));
    });
});

// Kumpul line ikut kod design - server sudah susun ikut kod, jadi tertib kemunculan pertama
// dikekalkan. Line tanpa kod dikumpul ikut nama.
const groupedDesigns = computed<DesignGroup[]>(() => {
    const byKey = new Map<string, DesignGroup>();

    for (const line of filteredLines.value) {
        let group = byKey.get(line.design_key);

        if (!group) {
            group = {
                key: line.design_key,
                code: line.internal_code,
                title: line.internal_code ?? line.item_desc ?? '-',
                description: line.item_desc,
                nickname: line.nickname,
                image_url: line.image_url,
                category_name: line.category_name,
                lines: [],
                total_outstanding: 0,
                rearrange_qty: 0,
                restock_qty: 0,
                action: null,
            };
            byKey.set(line.design_key, group);
        }

        group.lines.push(line);
        group.total_outstanding += line.qty_outstanding;
        group.rearrange_qty += line.action ? line.rearrange_qty : 0;
        group.restock_qty += line.action ? line.restock_qty : 0;
        group.nickname ??= line.nickname;
        group.image_url ??= line.image_url;
        group.category_name ??= line.category_name;
    }

    // Cadangan peringkat design: gabungan semua cawangan (cawangan yg dah diputuskan BO tak dikira).
    for (const group of byKey.values()) {
        group.action = group.rearrange_qty > 0 && group.restock_qty > 0
            ? 'both'
            : group.rearrange_qty > 0 ? 'rearrange' : group.restock_qty > 0 ? 'restock' : null;
    }

    return Array.from(byKey.values());
});

const NO_CATEGORY = '__none';
const NO_ACTION = '__none';

const categoryOptions = computed(() => {
    const counts = new Map<string, number>();

    groupedDesigns.value.forEach((g) => {
        const key = g.category_name ?? NO_CATEGORY;
        counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    return Array.from(counts, ([value, count]) => ({ value, label: value === NO_CATEGORY ? 'Tiada kategori' : value, count }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

const actionOptions = computed(() => {
    const counts = new Map<string, number>();

    groupedDesigns.value.forEach((g) => {
        const key = g.action ?? NO_ACTION;
        counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    return ['rearrange', 'restock', 'both', NO_ACTION].map((value) => ({
        value,
        label: value === NO_ACTION ? 'Tiada cadangan' : ACTION_LABEL[value],
        count: counts.get(value) ?? 0,
    }));
});

const statusOptions = computed(() => props.fulfillmentOptions.map((o) => ({
    value: o.value,
    label: o.label,
    count: groupedDesigns.value.filter((g) => g.lines.some((l) => effectiveStatus(l) === o.value)).length,
})));

const ACTION_RANK: Record<string, number> = { rearrange: 1, restock: 2, both: 3 };

function sortValue(group: DesignGroup, key: SortKey): string | number {
    switch (key) {
        case 'design':
            return group.title.toLowerCase();
        case 'requested':
            return group.total_outstanding;
        case 'action':
            return group.action ? ACTION_RANK[group.action] : 0;
        case 'status':
            return (groupStatus(group) ? labelOf(groupStatus(group)) : 'Bercampur').toLowerCase();
    }
}

// Tapis peringkat design, kemudian susun ikut lajur (tiada susunan dipilih = tertib server).
const designGroups = computed<DesignGroup[]>(() => {
    const filtered = groupedDesigns.value.filter((g) => {
        if (categoryFilters.value.length && !categoryFilters.value.includes(g.category_name ?? NO_CATEGORY)) {
            return false;
        }
        if (actionFilters.value.length && !actionFilters.value.includes(g.action ?? NO_ACTION)) {
            return false;
        }

        return !statusFilters.value.length || g.lines.some((l) => statusFilters.value.includes(effectiveStatus(l)));
    });

    const key = sortKey.value;

    if (!key) {
        return filtered;
    }

    const direction = sortDir.value === 'asc' ? 1 : -1;

    return [...filtered].sort((a, b) => {
        const left = sortValue(a, key);
        const right = sortValue(b, key);
        const result = typeof left === 'number' && typeof right === 'number'
            ? left - right
            : String(left).localeCompare(String(right), 'ms');

        return result * direction;
    });
});

// Klik pengepala: menaik -> menurun -> tiada susunan.
function toggleSort(key: SortKey) {
    if (sortKey.value !== key) {
        sortKey.value = key;
        sortDir.value = 'asc';
    } else if (sortDir.value === 'asc') {
        sortDir.value = 'desc';
    } else {
        sortKey.value = null;
    }
}

function sortDirection(key: SortKey): 'asc' | 'desc' | null {
    return sortKey.value === key ? sortDir.value : null;
}

const isFiltered = computed(() => !!search.value.trim() || onlyCritical.value
    || branchFilters.value.length + categoryFilters.value.length + actionFilters.value.length + statusFilters.value.length > 0);

function resetFilters() {
    search.value = '';
    onlyCritical.value = false;
    branchFilters.value = [];
    categoryFilters.value = [];
    actionFilters.value = [];
    statusFilters.value = [];
}

const activeGroup = computed(() => designGroups.value.find((g) => g.key === selectedKey.value) ?? null);

const activeDesign = computed<Design | null>(() => {
    const group = activeGroup.value;

    if (!group) {
        return null;
    }

    const preview = group.code ? previews.value[group.code] : undefined;

    return preview?.design ?? props.board?.designs?.[group.key] ?? null;
});

// Skop tindakan footer: SEMUA cawangan design ni, atau SATU cawangan bila override dihidupkan.
// Design dipilih (kotak semak) yg masih kelihatan selepas tapisan, ikut tertib jadual.
const checkedGroups = computed(() => designGroups.value.filter((g) => checkedKeys.value.includes(g.key)));
const isBulk = computed(() => checkedGroups.value.length > 0);

const isOverride = computed(() => !isBulk.value && !!activeGroup.value && overrideLineId.value !== null && activeGroup.value.lines.some((l) => l.id === overrideLineId.value));

const scopeLines = computed<Line[]>(() => {
    if (isBulk.value) {
        return checkedGroups.value.flatMap((g) => g.lines);
    }

    const group = activeGroup.value;

    if (!group) {
        return [];
    }

    return isOverride.value ? group.lines.filter((l) => l.id === overrideLineId.value) : group.lines;
});

const scopeLabel = computed(() => {
    if (isBulk.value) {
        return `${checkedGroups.value.length} design dipilih`;
    }

    return isOverride.value ? `hanya ${scopeLines.value[0].store_code}` : 'semua cawangan';
});
const scopeRearrange = computed(() => scopeLines.value.reduce((sum, l) => sum + (l.action ? l.rearrange_qty : 0), 0));
const scopeRestock = computed(() => scopeLines.value.reduce((sum, l) => sum + (l.action ? l.restock_qty : 0), 0));
const scopeStaged = computed(() => scopeLines.value.filter((l) => staged.value[l.id]).length);
const scopeStatus = computed(() => {
    const set = new Set(scopeLines.value.map(effectiveStatus));

    return set.size === 1 ? Array.from(set)[0] : '';
});

const allVisibleChecked = computed(() => designGroups.value.length > 0 && designGroups.value.every((g) => checkedKeys.value.includes(g.key)));
// Header "pilih semua": penuh = true, sebahagian = 'indeterminate'.
const headerCheckState = computed<boolean | 'indeterminate'>(() => {
    if (allVisibleChecked.value) {
        return true;
    }

    return checkedGroups.value.length > 0 ? 'indeterminate' : false;
});

function toggleChecked(group: DesignGroup, checked: boolean) {
    checkedKeys.value = checked
        ? [...checkedKeys.value.filter((k) => k !== group.key), group.key]
        : checkedKeys.value.filter((k) => k !== group.key);

    // Pilihan pertama terus dibuka di tab supaya panel kanan tak kosong.
    if (checked && !selectedKey.value) {
        selectedKey.value = group.key;
    }
}

function toggleAllVisible(checked: boolean) {
    const visible = new Set(designGroups.value.map((g) => g.key));
    const rest = checkedKeys.value.filter((k) => !visible.has(k));

    checkedKeys.value = checked ? [...rest, ...visible] : rest;

    if (checked && !selectedKey.value && designGroups.value.length) {
        selectedKey.value = designGroups.value[0].key;
    }
}

// Dot status pada tab: merah = semua cawangan stok 0/1 (tiada donor), biru = boleh Rearrange.
function designOf(group: DesignGroup): Design | null {
    const preview = group.code ? previews.value[group.code] : undefined;

    return preview?.design ?? props.board?.designs?.[group.key] ?? null;
}

function groupDot(group: DesignGroup): { class: string; label: string } | null {
    const stock = designOf(group)?.stock ?? [];

    if (stock.length > 0 && stock.every((s) => s.stock <= 1)) {
        return { class: 'bg-destructive', label: 'Semua cawangan stok 0/1 - tiada donor' };
    }

    if (group.rearrange_qty > 0) {
        return { class: 'bg-blue-600', label: 'Boleh Rearrange' };
    }

    return null;
}

function toggleOverride() {
    const group = activeGroup.value;

    overrideLineId.value = isOverride.value || !group ? null : group.lines[0].id;
}

const activePreview = computed(() => (activeGroup.value?.code ? previews.value[activeGroup.value.code] ?? null : null));
const needsDesignCode = computed(() => !!activeGroup.value && !activeGroup.value.code);

const stagedCount = computed(() => Object.keys(staged.value).length + Object.keys(restockEdits.value).length);

async function getJson<T>(url: string): Promise<T> {
    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!res.ok) {
        const body = await res.json().catch(() => null);
        throw new Error(body?.errors?.changes?.[0] ?? body?.message ?? `Ralat ${res.status}`);
    }

    return res.json();
}

async function fetchCandidatePage(group: DesignGroup, query: string, page: number) {
    const qs = new URLSearchParams({ page: String(page) });

    if (query.trim()) {
        qs.set('q', query.trim());
    }

    return getJson<{ candidates: Candidate[]; has_more: boolean }>(
        `/back-office-actions/lines/${group.lines[0].id}/candidates?${qs.toString()}`,
    );
}

async function loadCandidates(group: DesignGroup, query = '') {
    candidateLoading.value = true;

    try {
        const res = await fetchCandidatePage(group, query, 1);
        candidates.value[group.key] = res.candidates;
        candidatePage.value[group.key] = { page: 1, hasMore: res.has_more, query };
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        candidateLoading.value = false;
    }
}

// Butang "Muat Lagi": tambah halaman calon seterusnya ke senarai sedia ada (tak ganti).
async function loadMoreCandidates(group: DesignGroup) {
    const state = candidatePage.value[group.key];

    if (!state || candidateLoadingMore.value) {
        return;
    }

    candidateLoadingMore.value = true;

    try {
        const res = await fetchCandidatePage(group, state.query, state.page + 1);
        const known = new Set((candidates.value[group.key] ?? []).map((c) => c.internal_code));
        candidates.value[group.key] = [...(candidates.value[group.key] ?? []), ...res.candidates.filter((c) => !known.has(c.internal_code))];
        candidatePage.value[group.key] = { ...state, page: state.page + 1, hasMore: res.has_more };
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        candidateLoadingMore.value = false;
    }
}

// Design tanpa kod dipilih -> cari calon kod design (nickname/Description) berdasarkan nama permintaan.
watch(selectedKey, () => {
    overrideLineId.value = null;
    candidateQuery.value = '';
    const group = activeGroup.value;

    if (group && !group.code && !candidates.value[group.key]) {
        loadCandidates(group);
    }
});

// focus=false (dialog Masuk Restock pukal): panel kanan tak dilompatkan kecuali design itulah yg sedang dibuka.
async function chooseCode(group: DesignGroup, candidate: Candidate, focus = true) {
    previewLoading.value = true;

    try {
        const qs = new URLSearchParams({ code: candidate.internal_code });
        group.lines.forEach((l) => qs.append('lines[]', String(l.id)));

        const res = await getJson<{ lines: Line[]; design: Design }>(`/back-office-actions/preview?${qs.toString()}`);

        previews.value[candidate.internal_code] = {
            code: candidate.internal_code,
            candidate,
            design: res.design,
            lines: Object.fromEntries(res.lines.map((l) => [l.id, l])),
            lineIds: group.lines.map((l) => l.id),
            originalKey: group.key,
        };

        group.lines.forEach((l) => {
            staged.value[l.id] = { ...(staged.value[l.id] ?? { status: l.fulfillment_status }), internal_code: candidate.internal_code };
        });

        checkedKeys.value = Array.from(new Set(checkedKeys.value.map((k) => (k === group.key ? candidate.internal_code : k))));

        if (focus || selectedKey.value === group.key) {
            selectedKey.value = candidate.internal_code;
        }
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        previewLoading.value = false;
    }
}

function clearCode(group: DesignGroup) {
    const preview = group.code ? previews.value[group.code] : undefined;

    if (!preview) {
        return;
    }

    preview.lineIds.forEach((id) => delete staged.value[id]);
    delete previews.value[preview.code];
    checkedKeys.value = checkedKeys.value.map((k) => (k === preview.code ? preview.originalKey : k));
    selectedKey.value = preview.originalKey;
}

// ===== Dialog "Masuk Restock" pukal (design yg ditandakan) =====
// Design ada code -> disenaraikan per cawangan (baki, Restock, Rearrange) & boleh dibuang. Design tiada code ->
// disenaraikan berasingan dgn popover pilih design code (guna calon yg sama dgn panel kanan). Submit =
// tetapkan status Order utk baris yg tinggal (masuk Senarai Restock di bawah; tekan Simpan utk menyimpan).
const restockDialogOpen = ref(false);
const excludedLineIds = ref<number[]>([]);
const dialogQuery = ref<Record<string, string>>({});
const pickerOpenKey = ref<string | null>(null);

const dialogCoded = computed(() => checkedGroups.value.filter((g) => g.code));
const dialogUncoded = computed(() => checkedGroups.value.filter((g) => !g.code));

function restockLinesOf(group: DesignGroup): Line[] {
    return group.lines.filter((l) => l.restock_qty > 0 && !excludedLineIds.value.includes(l.id));
}

const dialogRestockLines = computed(() => dialogCoded.value.flatMap(restockLinesOf));
const dialogRestockUnits = computed(() => dialogRestockLines.value.reduce((sum, l) => sum + l.restock_qty, 0));
const dialogDesignCount = computed(() => dialogCoded.value.filter((g) => restockLinesOf(g).length > 0).length);

function openRestockDialog() {
    excludedLineIds.value = [];
    dialogQuery.value = {};
    pickerOpenKey.value = null;
    restockDialogOpen.value = true;
}

function excludeLine(line: Line) {
    excludedLineIds.value = [...excludedLineIds.value, line.id];
}

function openPicker(group: DesignGroup) {
    pickerOpenKey.value = group.key;

    if (!candidates.value[group.key]) {
        loadCandidates(group, dialogQuery.value[group.key] ?? '');
    }
}

async function pickCode(group: DesignGroup, candidate: Candidate) {
    await chooseCode(group, candidate, false);
    pickerOpenKey.value = null;
}

// Teks dlm fungsi (bukan literal dlm template) - pemformat editor memecahkan literal panjang ke dua baris & merosakkan build.
function noRestockNote(line: Line): string {
    return line.rearrange_qty > 0 ? 'Cukup dengan Rearrange - tak masuk Restock' : 'Tiada baki Restock';
}

function submitRestock() {
    const targets = dialogRestockLines.value;

    if (targets.length === 0) {
        toast.info('Tiada baris untuk dimasukkan ke Restock.');

        return;
    }

    targets.forEach((l) => stageStatus(l, STATUS_RESTOCK_DEFAULT));

    const skipped = dialogUncoded.value.length;

    restockDialogOpen.value = false;
    toast.success(
        `${dialogDesignCount.value} design (${targets.length} cawangan) masuk Senarai Restock di bawah. Tekan Simpan untuk menyimpan.`
        + (skipped ? ` ${skipped} design tanpa code dilangkau.` : ''),
    );
}

function labelOf(status: string): string {
    return labelByValue.value[status] ?? status;
}

function effectiveStatus(line: Line): string {
    return staged.value[line.id]?.status ?? line.fulfillment_status;
}

// Status gabungan satu design - satu nilai kalau semua cawangan sama, '' kalau bercampur.
function groupStatus(group: DesignGroup): string {
    const set = new Set(group.lines.map(effectiveStatus));

    return set.size === 1 ? Array.from(set)[0] : '';
}

function stagedIn(group: DesignGroup): number {
    return group.lines.filter((l) => staged.value[l.id]).length;
}

function describeMoves(moves: Move[], to: string): string {
    return `${moves.map((m) => `${m.qty} unit dari ${m.from_store}`).join(', ')} → ${to}`;
}

function describeStaged(line: Line, s: Staged): string {
    const code = s.internal_code ? `Padan ${s.internal_code} · ` : '';

    if (s.status === STATUS_REARRANGE && s.moves?.length) {
        return `${code}${labelOf(s.status)} ${describeMoves(s.moves, line.store_code)}`;
    }

    return `${code}${labelOf(s.status)}`;
}

// Tetapkan status SATU line (kod yg BO pilih dikekalkan). Rearrange guna cadangan agihan server
// (moves) - line tanpa donor dilangkau (pulang false).
function stageStatus(line: Line, status: string): boolean {
    const code = staged.value[line.id]?.internal_code;

    if (status === STATUS_REARRANGE && line.moves.length === 0) {
        return false;
    }

    if (status === line.fulfillment_status && !code) {
        delete staged.value[line.id];

        return true;
    }

    const next: Staged = { status, internal_code: code };

    if (status === STATUS_REARRANGE) {
        next.moves = line.moves.map((m) => ({ ...m }));
    }

    staged.value[line.id] = next;

    return true;
}

// TINDAKAN footer: terpakai kpd SEMUA cawangan design ni, atau SATU cawangan bila override dihidupkan.
function applyToScope(status: string, only?: (l: Line) => boolean) {
    const targets = scopeLines.value.filter((l) => !only || only(l));
    const skipped = targets.filter((l) => !stageStatus(l, status));

    if (targets.length === 0) {
        toast.info('Tiada cawangan yang sesuai untuk tindakan ini.');
    } else if (skipped.length > 0) {
        toast.info(`${skipped.map((l) => l.store_code).join(', ')}: tiada donor, dilangkau.`);
    }
}

function applyRearrange() {
    applyToScope(STATUS_REARRANGE, (l) => l.moves.length > 0);
}

function applyRestock() {
    applyToScope(STATUS_RESTOCK_DEFAULT, (l) => l.restock_qty > 0);

    const entered = new Set(
        scopeLines.value
            .filter((l) => l.internal_code && staged.value[l.id]?.status === STATUS_RESTOCK_DEFAULT)
            .map((l) => l.internal_code as string),
    );

    if (entered.size === 0) {
        return;
    }

    // Pukal: laporkan bilangan sebenar & design yg dilangkau (tiada baki Restock - cth. cukup dgn Rearrange - atau tiada code).
    const skipped = isBulk.value
        ? checkedGroups.value.filter((g) => !g.code || !entered.has(g.code)).map((g) => g.title)
        : [];

    toast.success(
        `${isBulk.value ? `${entered.size} design` : Array.from(entered)[0]} masuk Senarai Restock di bawah. Tekan Simpan untuk menyimpan.`
        + (skipped.length ? ` Dilangkau (tiada baki Restock / tiada code): ${skipped.join(', ')}.` : ''),
    );
}

function applyStatus(status: string) {
    if (status === STATUS_REARRANGE) {
        applyRearrange();

        return;
    }

    applyToScope(status);
}

function clearScopeStaged() {
    scopeLines.value.forEach((l) => delete staged.value[l.id]);
}

function clearStaged(id: number) {
    delete staged.value[id];
}

const stagedEntries = computed<LogEntry[]>(() => [
    ...Object.entries(staged.value).flatMap(([id, s]) => {
        const line = lines.value.find((l) => l.id === Number(id));

        return line
            ? [{
                key: String(line.id),
                design: line.internal_code ?? line.item_desc ?? '-',
                store: line.store_code,
                from: labelOf(line.fulfillment_status),
                to: describeStaged(line, s),
            }]
            : [];
    }),
    ...Object.entries(restockEdits.value).flatMap(([id, edit]) => {
        const item = restockItems.value.find((i) => i.id === Number(id));

        return item
            ? [{
                key: `restock-${item.id}`,
                design: item.internal_code,
                store: 'Restock',
                from: `order ${item.qty_to_order}`,
                to: edit.remove ? 'Buang dari senarai' : edit.ordered ? `Dah Order ${rowQty(item)} unit` : `order ${rowQty(item)}${edit.target !== undefined || edit.branchTargets !== undefined ? ' (paras diubah)' : ''}`,
            }]
            : [];
    }),
]);

function removeEntry(key: string) {
    if (key.startsWith('restock-')) {
        undoRestockEdit(Number(key.slice('restock-'.length)));

        return;
    }

    clearStaged(Number(key));
}

// Warna lencana kemiripan calon design code: hijau = sangat mirip, kuning = sederhana, merah = lemah.
function scoreBadgeClass(score: number): string {
    if (score >= 70) {
        return 'border-transparent bg-success text-success-foreground';
    }

    if (score >= 50) {
        return 'border-transparent bg-warning text-warning-foreground';
    }

    return 'border-transparent bg-destructive text-white';
}

// Tarikh permintaan cawangan dihantar, cth. "5 Okt 2026".
function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('ms-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
}

function periodSold(d: { sold_7d: number; sold_30d: number }): number {
    return period.value === '7d' ? d.sold_7d : d.sold_30d;
}

// Cetak / PDF Senarai Restock TERSIMPAN (paparan cetak, buka tab baharu).
function restockUrl(kind: 'print' | 'export'): string {
    return `/back-office-actions/restock/${kind}?group=${restockGroup.value}`;
}

function handlePrint() {
    window.open(restockUrl('print'), '_blank');
}

function handleExport(kind: 'excel' | 'pdf') {
    exportOpen.value = false;

    if (kind === 'excel') {
        window.location.href = restockUrl('export');
    } else {
        handlePrint();
    }
}

function save(andPrint = false) {
    if (stagedCount.value === 0) {
        toast.info('Tiada perubahan untuk disimpan.');
        return;
    }

    const entries = stagedEntries.value;
    const changes = Object.entries(staged.value).map(([id, s]) => ({
        line_id: Number(id),
        fulfillment_status: s.status,
        moves: s.moves ?? null,
        internal_code: s.internal_code ?? null,
    }));
    // qty dihantar bila BO taip sendiri ATAU paras diubah (server tak tahu qty serta-merta drpd paras baharu - ia mengira
    // semula cadangan & agihan drpd data semasa). Paras dihantar hanya bila diubah.
    const restock_items = Object.entries(restockEdits.value).map(([id, e]) => {
        const item = restockItems.value.find((i) => i.id === Number(id));
        const planChanged = e.target !== undefined || e.branchTargets !== undefined;

        return {
            id: Number(id),
            qty: e.qty ?? (item && (planChanged || e.ordered) ? rowQty(item) : null),
            remove: e.remove ?? false,
            ordered: e.ordered ?? false,
            ...(e.target !== undefined ? { target_stock: e.target } : {}),
            ...(e.branchTargets !== undefined ? { branch_targets: e.branchTargets } : {}),
        };
    });

    const restock_new = pendingRestock.value
        .filter((i) => pendingQty.value[i.internal_code] !== undefined || planEdited(i))
        .map((i) => ({
            internal_code: i.internal_code,
            qty: rowQty(i),
            target_stock: targetOf(i),
            branch_targets: branchTargetsOf(i),
        }));

    router.post('/back-office-actions', {
        changes: changes.length ? changes : null,
        restock_items: restock_items.length ? restock_items : null,
        restock_new: restock_new.length ? restock_new : null,
    }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            const at = new Date().toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit' });
            savedLog.value = [...entries.map((e) => ({ ...e, at })), ...savedLog.value].slice(0, 50);
            staged.value = {};
            restockEdits.value = {};
            pendingQty.value = {};
            pendingTarget.value = {};
            pendingBranchTargets.value = {};
            previews.value = {};
            candidates.value = {};
            candidatePage.value = {};
            selectedKey.value = null;
            checkedKeys.value = [];

            if (andPrint) {
                handlePrint();
            }
        },
        onError: (errors) => {
            toast.error(String(errors.changes ?? Object.values(errors)[0] ?? 'Gagal menyimpan perubahan.'));
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}
</script>

<template>

    <Head title="Back Office Actions" />

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-8">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Back Office Actions</h1>
                <p class="text-sm text-muted-foreground">
                    Senarai design yang diminta cawangan, disusun ikut kod design. Pilih satu design &mdash; tindakan
                    (<span class="font-medium">Rearrange</span> / <span class="font-medium">Restock</span> / status)
                    terpakai untuk
                    semua cawangan yang minta design itu.
                </p>
            </div>
            <Button type="button" variant="outline" @click="handlePrint">
                <Printer class="size-4" />
                Print
            </Button>
        </div>

        <!-- items-start: setiap kad kekal pada tinggi sendiri (senarai kiri tetap tingginya) dan tak diregang
             mengikut kad Cadangan Terperinci yg tingginya berubah ikut design dipilih. -->
        <div class="grid gap-4 lg:grid-cols-3 lg:items-start">
            <!-- ===== Kiri: senarai design ===== -->
            <Card class="min-w-0 lg:col-span-2">
                <CardHeader class="gap-3">
                    <CardTitle class="text-base">Senarai Permintaan Cawangan</CardTitle>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative min-w-56 flex-1">
                            <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                            <Input v-model="search" class="h-8 pl-8"
                                placeholder="Cari kod design, keterangan, no. permintaan..." />
                        </div>
                        <FacetedFilter v-model="branchFilters" title="Cawangan" :options="branchOptions" />
                        <FacetedFilter v-model="categoryFilters" title="Kategori" :options="categoryOptions" />
                        <FacetedFilter v-model="actionFilters" title="Cadangan" :options="actionOptions" />
                        <FacetedFilter v-model="statusFilters" title="Status" :options="statusOptions" />
                        <Button type="button" size="sm" :variant="onlyCritical ? 'default' : 'outline'" class="h-8"
                            @click="onlyCritical = !onlyCritical">
                            Kritikal sahaja
                        </Button>
                        <Button v-if="isFiltered" type="button" variant="ghost" size="sm" class="h-8 px-2 lg:px-3"
                            @click="resetFilters">
                            Reset
                            <X class="size-4" />
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <Deferred data="board">
                        <template #fallback>
                            <div class="flex flex-col gap-3">
                                <div v-for="row in 6" :key="row" class="flex items-center gap-3">
                                    <Skeleton class="size-10 shrink-0 rounded-md" />
                                    <div class="flex flex-1 flex-col gap-1.5">
                                        <Skeleton class="h-4 w-40" />
                                        <Skeleton class="h-3 w-64" />
                                    </div>
                                    <Skeleton class="h-8 w-32 shrink-0" />
                                </div>
                            </div>
                        </template>

                        <template #default>
                            <p v-if="lines.length === 0" class="text-sm text-muted-foreground">
                                Tiada permintaan cawangan yang perlu diuruskan.
                            </p>
                            <p v-else-if="designGroups.length === 0" class="text-sm text-muted-foreground">
                                Tiada baris sepadan dengan carian/tapisan.
                            </p>

                            <!-- Jadual (corak contoh "tasks" shadcn-vue): pengepala boleh disusun. table-fixed + truncate supaya
                                 lajur Design dipendekkan & Status tak terpotong. [&>div]:!block - elemen dalam ScrollArea (reka-ui)
                                 asalnya display:table & melebar ikut kandungan, menyebabkan lebihan dipotong. -->
                            <ScrollArea v-else
                                class="h-[min(34rem,calc(100vh-18rem))] rounded-md border shadow-sm [&_[data-slot=scroll-area-viewport]>div]:!block">
                                <Table class="table-fixed">
                                    <TableHeader class="sticky top-0 z-10 bg-card">
                                        <TableRow class="hover:bg-transparent">
                                            <TableHead class="w-10 pr-0">
                                                <Checkbox :model-value="headerCheckState"
                                                    aria-label="Pilih semua design yang kelihatan"
                                                    @update:model-value="(v) => toggleAllVisible(v === true)" />
                                            </TableHead>
                                            <TableHead>
                                                <SortableHeader title="Design" :direction="sortDirection('design')"
                                                    @sort="toggleSort('design')" />
                                            </TableHead>
                                            <TableHead class="w-32">
                                                <SortableHeader title="Permintaan"
                                                    :direction="sortDirection('requested')"
                                                    @sort="toggleSort('requested')" />
                                            </TableHead>
                                            <TableHead class="w-36">
                                                <SortableHeader title="Cadangan" :direction="sortDirection('action')"
                                                    @sort="toggleSort('action')" />
                                            </TableHead>
                                            <TableHead class="w-28">
                                                <SortableHeader title="Status" :direction="sortDirection('status')"
                                                    @sort="toggleSort('status')" />
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="group in designGroups" :key="group.key" tabindex="0"
                                            class="cursor-pointer focus-visible:bg-muted/40 focus-visible:outline-none"
                                            :class="{
                                                'bg-muted/60': selectedKey === group.key,
                                                'bg-primary/5': stagedIn(group) > 0 && selectedKey !== group.key,
                                            }" @click="selectedKey = group.key"
                                            @keydown.enter.prevent="selectedKey = group.key"
                                            @keydown.space.prevent="selectedKey = group.key">
                                            <TableCell class="w-10 pr-0">
                                                <Checkbox :model-value="checkedKeys.includes(group.key)"
                                                    :aria-label="`Pilih ${group.title}`" @click.stop @keydown.stop
                                                    @update:model-value="(v) => toggleChecked(group, v === true)" />
                                            </TableCell>
                                            <TableCell>
                                                <div class="flex min-w-0 items-center gap-3">
                                                    <ImagePreview :src="group.image_url" :alt="group.description ?? ''"
                                                        class="size-10 shrink-0 rounded-md" />
                                                    <div class="min-w-0">
                                                        <p class="truncate font-medium">{{ group.title }}</p>
                                                        <p class="truncate text-xs text-muted-foreground">
                                                            <template v-if="group.nickname">&quot;{{ group.nickname
                                                            }}&quot;</template>
                                                            <template v-else>{{ group.description ?? '-' }}</template>
                                                        </p>
                                                        <Badge v-if="group.category_name" variant="outline"
                                                            class="mt-1 max-w-full">
                                                            <span class="truncate">{{ group.category_name }}</span>
                                                        </Badge>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div class="flex flex-wrap gap-1">
                                                    <Badge v-for="l in group.lines" :key="l.id" variant="secondary"
                                                        :class="{ 'ring-1 ring-destructive': l.is_critical }"
                                                        :title="l.is_critical ? 'Stok kritikal' : undefined">
                                                        {{ l.store_code }} {{ l.qty_outstanding }}
                                                    </Badge>
                                                </div>
                                                <p class="mt-1 text-xs text-muted-foreground">jumlah {{
                                                    group.total_outstanding }} unit</p>
                                            </TableCell>
                                            <TableCell>
                                                <span v-if="group.action"
                                                    :class="`inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_BADGE_CLASS[group.action]}`">
                                                    {{ ACTION_LABEL[group.action] }}
                                                </span>
                                                <span v-else class="text-muted-foreground">-</span>
                                            </TableCell>
                                            <TableCell class="pr-3">
                                                <p>{{ groupStatus(group) ? labelOf(groupStatus(group)) : 'Bercampur' }}
                                                </p>
                                                <p v-if="stagedIn(group)" class="mt-0.5 text-xs text-primary">Belum
                                                    disimpan</p>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </ScrollArea>
                        </template>
                    </Deferred>
                </CardContent>
            </Card>

            <!-- ===== Kanan: cadangan terperinci + tindakan ===== -->
            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle class="text-base">Cadangan Terperinci</CardTitle>
                </CardHeader>
                <CardContent>
                    <!-- Pilihan pukal: dropdown (Popover + Command, bukan native) senarai design dipilih - nama + dot status,
                         utk semak satu-satu sebelum tindakan. -->
                    <div v-if="isBulk" class="mb-3 flex flex-col gap-1.5">
                        <Popover v-model:open="bulkPickerOpen">
                            <PopoverTrigger as-child>
                                <Button type="button" variant="outline" role="combobox" :aria-expanded="bulkPickerOpen"
                                    class="h-9 w-full justify-between px-3 font-normal shadow-sm">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span v-if="activeGroup && groupDot(activeGroup)"
                                            class="size-2 shrink-0 rounded-full"
                                            :class="groupDot(activeGroup)!.class" />
                                        <span v-else
                                            class="size-2 shrink-0 rounded-full border border-muted-foreground/40" />
                                        <span class="truncate font-medium">{{ activeGroup?.title ?? 'Pilih design...'
                                        }}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-2 text-muted-foreground">
                                        <Badge variant="secondary" class="rounded-sm px-1.5 font-normal">{{
                                            checkedGroups.length }}
                                            dipilih</Badge>
                                        <ChevronDown class="size-4" />
                                    </span>
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent class="w-[var(--reka-popover-trigger-width)] min-w-64 p-0" align="start">
                                <Command>
                                    <CommandInput placeholder="Cari design dipilih..." />
                                    <CommandList>
                                        <CommandEmpty>Tiada padanan.</CommandEmpty>
                                        <CommandGroup>
                                            <CommandItem v-for="g in checkedGroups" :key="g.key" :value="g.title"
                                                @select.prevent="selectedKey = g.key; bulkPickerOpen = false">
                                                <span v-if="groupDot(g)" class="size-2 shrink-0 rounded-full"
                                                    :class="groupDot(g)!.class" :title="groupDot(g)!.label" />
                                                <span v-else
                                                    class="size-2 shrink-0 rounded-full border border-muted-foreground/40" />
                                                <span class="truncate"
                                                    :class="{ 'font-medium': selectedKey === g.key }">{{ g.title
                                                    }}</span>
                                                <Check v-if="selectedKey === g.key" class="ml-auto size-4 shrink-0" />
                                                <button type="button"
                                                    class="rounded-sm p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                                    :class="selectedKey === g.key ? '' : 'ml-auto'"
                                                    :aria-label="`Buang ${g.title} daripada pilihan`"
                                                    @click.stop.prevent="toggleChecked(g, false)" @pointerdown.stop>
                                                    <X class="size-3.5" />
                                                </button>
                                            </CommandItem>
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </PopoverContent>
                        </Popover>
                        <p class="flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-destructive" />
                                Semua cawangan
                                stok 0/1</span>
                            <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-blue-600" /> Boleh
                                Rearrange</span>
                        </p>
                    </div>

                    <p v-if="!activeGroup" class="text-sm text-muted-foreground">
                        Pilih satu design di sebelah kiri untuk lihat cadangan Rearrange / Restock.
                    </p>

                    <ScrollArea v-else class="h-[min(34rem,calc(100vh-18rem))]">
                        <div class="flex flex-col gap-4 pr-3 text-sm">
                            <div class="flex items-center gap-3">
                                <ImagePreview :src="activeGroup.image_url" :alt="activeGroup.description ?? ''"
                                    class="size-14 shrink-0 rounded-md" />
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ activeGroup.title }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        <template v-if="activeGroup.code">{{ activeGroup.description ?? '-'
                                        }}</template>
                                        <template v-if="activeGroup.nickname"> &middot; &quot;{{ activeGroup.nickname
                                        }}&quot;</template>
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ activeGroup.lines.length }} cawangan &middot; baki {{
                                            activeGroup.total_outstanding }}
                                        unit
                                    </p>
                                </div>
                            </div>

                            <!-- Design tanpa kod (web/upload): BO pilih kod dulu, baru cadangan keluar -->
                            <section v-if="needsDesignCode"
                                class="flex flex-col gap-2 rounded-md border border-dashed px-3 py-3">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pilih
                                    design code
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    Cawangan tidak nyatakan kod. Pilih padanan nickname/nama di bawah &mdash; cadangan
                                    Rearrange /
                                    Restock
                                    keluar selepas kod dipilih, dan kod disimpan pada semua cawangan di bawah design
                                    ini.
                                </p>
                                <form class="flex gap-2" @submit.prevent="loadCandidates(activeGroup, candidateQuery)">
                                    <Input v-model="candidateQuery" placeholder="Cari nama / nickname / kod design"
                                        class="h-8" />
                                    <Button type="submit" size="sm" variant="outline"
                                        :disabled="candidateLoading">Cari</Button>
                                </form>

                                <div v-if="candidateLoading" class="flex items-center gap-2 text-muted-foreground">
                                    <Loader2 class="size-4 animate-spin" /> Mencari padanan...
                                </div>
                                <p v-else-if="!(candidates[activeGroup.key]?.length)" class="text-muted-foreground">
                                    Tiada padanan. Cuba carian lain (nama atau kod design).
                                </p>
                                <ScrollArea v-else class="h-[min(22rem,50vh)]">
                                    <ul class="flex flex-col gap-1.5 pr-3">
                                        <li v-for="c in candidates[activeGroup.key]" :key="c.internal_code"
                                            class="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <ImagePreview :src="c.image_url"
                                                    :alt="c.nickname ?? c.description ?? ''"
                                                    class="size-10 shrink-0 rounded-md" />
                                                <div class="min-w-0">
                                                    <p class="font-medium">{{ c.internal_code }}
                                                        <Badge v-if="c.score > 0"
                                                            :class="['ml-1 px-1.5 py-0 text-[9px]', scoreBadgeClass(c.score)]"
                                                            :title="`${c.score}% mirip dengan nama permintaan`">{{
                                                                c.score }}%
                                                        </Badge>
                                                        <!-- <span class="ml-1 text-xs font-normal text-muted-foreground">{{ c.score }}% mirip</span> -->
                                                    </p>
                                                    <p class="truncate text-[9px] text-muted-foreground">{{ c.nickname
                                                        ??
                                                        c.description }}</p>
                                                </div>
                                            </div>
                                            <Button type="button" size="sm" variant="outline" :disabled="previewLoading"
                                                @click="chooseCode(activeGroup, c)">
                                                Pilih
                                            </Button>
                                        </li>
                                    </ul>
                                    <div v-if="candidatePage[activeGroup.key]?.hasMore" class="mt-2 pr-3">
                                        <Button type="button" size="sm" class="w-full" :disabled="candidateLoadingMore"
                                            @click="loadMoreCandidates(activeGroup)">
                                            <Loader2 v-if="candidateLoadingMore" class="size-3.5 animate-spin" />
                                            <Search v-else class="size-3.5" />
                                            {{ candidateLoadingMore ? 'Memuatkan...' : 'Muat Lagi' }}
                                        </Button>
                                    </div>
                                </ScrollArea>
                            </section>

                            <div v-else-if="activePreview"
                                class="flex items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2">
                                <div class="min-w-0">
                                    <p class="text-xs text-muted-foreground">Design code dipilih (disimpan bila tekan
                                        Simpan)</p>
                                    <Badge variant="secondary">{{ activePreview.code }}</Badge>
                                    <span class="ml-2 text-xs text-muted-foreground">{{ activePreview.candidate.nickname
                                        ??
                                        activePreview.candidate.description }}</span>
                                </div>
                                <Button type="button" size="sm" variant="outline"
                                    @click="clearCode(activeGroup)">Tukar</Button>
                            </div>

                            <!-- Ringkasan cadangan: Rearrange / Restock / kedua-duanya -->
                            <div v-if="activeGroup.action" class="rounded-md border px-3 py-2"
                                :class="ACTION_BOX_CLASS[activeGroup.action]">
                                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cadangan
                                    semua
                                    cawangan</p>
                                <p v-if="activeGroup.action === 'both'" class="mt-0.5">
                                    <span class="font-medium">Rearrange {{ activeGroup.rearrange_qty }} unit</span> dari
                                    cawangan
                                    lain,
                                    baki <span class="font-medium">{{ activeGroup.restock_qty }} unit perlu
                                        Restock</span>.
                                </p>
                                <p v-else-if="activeGroup.action === 'rearrange'" class="mt-0.5">
                                    Rearrange <span class="font-medium">{{ activeGroup.rearrange_qty }} unit</span>
                                    boleh penuhi
                                    semua baki permintaan.
                                </p>
                                <p v-else-if="activeDesign?.network_low" class="mt-0.5">
                                    Tiada cawangan boleh derma (semua stok &le; 2, jumlah rangkaian {{
                                        activeDesign.total_stock }}).
                                    Boleh <span class="font-medium">Restock {{ activeGroup.restock_qty }} unit</span>.
                                </p>
                                <p v-else class="mt-0.5">
                                    Stok lebihan cawangan lain tidak mencukupi.
                                    Boleh <span class="font-medium">Restock {{ activeGroup.restock_qty }} unit</span>.
                                </p>
                            </div>
                            <p v-else-if="activeGroup.code" class="text-muted-foreground">
                                Tiada tindakan dicadangkan &mdash; semua cawangan sudah diputuskan atau tiada baki.
                            </p>

                            <!-- Permintaan setiap cawangan (digabung - tak perlu klik asing) -->
                            <section class="flex flex-col gap-1.5">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Permintaan cawangan
                                </h3>
                                <ul class="flex flex-col gap-1.5">
                                    <li v-for="l in activeGroup.lines" :key="l.id" class="rounded-md border px-3 py-2"
                                        :class="{
                                            'border-primary': staged[l.id],
                                            'cursor-pointer hover:bg-muted/40': activeGroup.lines.length > 1,
                                            'ring-2 ring-primary': isOverride && overrideLineId === l.id,
                                        }"
                                        :title="activeGroup.lines.length > 1 ? 'Klik untuk override: tindakan di bawah hanya untuk cawangan ini' : undefined"
                                        @click="activeGroup.lines.length > 1 && (overrideLineId = l.id)">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary">{{ l.store_code }}</Badge>
                                            <span class="font-medium">{{ l.qty_outstanding }} unit</span>
                                            <span v-if="l.qty_outstanding !== l.qty_requested"
                                                class="text-xs text-muted-foreground">/ {{ l.qty_requested }}</span>
                                            <span v-if="l.is_critical"
                                                class="text-xs font-medium text-destructive">Kritikal</span>
                                            <span v-if="l.action"
                                                :class="`inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_BADGE_CLASS[l.action]}`">
                                                {{ ACTION_LABEL[l.action] }}
                                            </span>
                                            <span class="text-xs text-muted-foreground">{{ labelOf(effectiveStatus(l))
                                            }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-muted-foreground">
                                            {{ l.request_number }}
                                            <template v-if="l.requested_at"> &middot; {{ formatDate(l.requested_at)
                                            }}</template>
                                            <template v-if="l.size"> &middot; Saiz {{ l.size }}</template>
                                            <template v-if="l.weight"> &middot; {{ l.weight }}g</template>
                                        </p>
                                        <p v-if="l.moves.length" class="mt-0.5 text-xs">
                                            Rearrange: {{ describeMoves(l.moves, l.store_code) }}<template
                                                v-if="l.restock_qty">, baki {{ l.restock_qty }} Restock</template>
                                        </p>
                                        <p v-else-if="l.restock_qty" class="mt-0.5 text-xs">Restock: {{ l.restock_qty }}
                                            unit</p>
                                        <p v-if="l.remark" class="mt-0.5 text-xs italic text-muted-foreground">Nota: {{
                                            l.remark }}
                                        </p>
                                    </li>
                                </ul>
                            </section>

                            <section v-if="activeDesign?.internal_code" class="flex flex-col gap-1.5">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stok
                                    semasa semua
                                    cawangan</h3>
                                <div class="flex flex-wrap gap-1.5">
                                    <Badge v-for="st in activeDesign.stock" :key="st.store_code"
                                        :variant="activeGroup.lines.some((l) => l.store_code === st.store_code) ? 'default' : 'outline'"
                                        :class="{ 'opacity-60': st.stock === 0 }"
                                        :title="st.reserved ? `${st.reserved} unit sudah ditempah transfer` : undefined">
                                        {{ st.store_code }} {{ st.stock }}<template v-if="st.reserved"> (−{{ st.reserved
                                        }})</template>
                                    </Badge>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Jumlah {{ activeDesign.total_stock }} &middot; lebihan boleh didermakan {{
                                        activeDesign.spare_stock }}
                                    (stok &ge; 3, baki 2 kekal; HQ semua stok boleh dipindah). Security tidak dikira.
                                </p>
                            </section>

                            <section v-if="activeDesign?.internal_code" class="flex flex-col gap-1.5">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs text-muted-foreground">
                                        Jualan terkini: <span class="font-medium text-foreground">{{
                                            periodSold(activeDesign) }}
                                            unit</span>
                                    </p>
                                    <div class="flex gap-1">
                                        <Button type="button" size="sm"
                                            :variant="period === '7d' ? 'default' : 'outline'" @click="period = '7d'">1
                                            Minggu</Button>
                                        <Button type="button" size="sm"
                                            :variant="period === '30d' ? 'default' : 'outline'"
                                            @click="period = '30d'">30 Hari</Button>
                                    </div>
                                </div>
                                <p v-if="activeDesign.sales.length === 0" class="text-muted-foreground">Tiada jualan
                                    dalam 30 hari.
                                </p>
                                <div v-else class="flex flex-wrap gap-1.5">
                                    <Badge v-for="sl in activeDesign.sales.filter((x) => periodSold(x) > 0)"
                                        :key="sl.store_code" variant="outline">
                                        {{ sl.store_code }} {{ periodSold(sl) }}
                                    </Badge>
                                </div>
                            </section>
                        </div>
                    </ScrollArea>
                </CardContent>
                <Separator />
                <!-- Tindakan: kekal di bawah kad (luar ScrollArea). Skop = semua cawangan, atau satu cawangan bila Override. -->
                <CardFooter v-if="activeGroup || isBulk" class="flex-col items-stretch gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Tindakan ({{ scopeLabel }})
                        </h3>
                        <div v-if="activeGroup && !isBulk" class="flex items-center gap-2">
                            <NativeSelect v-if="isOverride" :model-value="String(overrideLineId)"
                                aria-label="Cawangan untuk override"
                                @update:model-value="(v: unknown) => (overrideLineId = Number(v))">
                                <NativeSelectOption v-for="l in activeGroup.lines" :key="l.id" :value="String(l.id)">
                                    {{ l.store_code }} (baki {{ l.qty_outstanding }})
                                </NativeSelectOption>
                            </NativeSelect>
                            <Button type="button" size="sm" :variant="isOverride ? 'default' : 'outline'"
                                :disabled="!isOverride && activeGroup.lines.length < 2" @click="toggleOverride">
                                {{ isOverride ? 'Semua cawangan' : 'Override' }}
                            </Button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="scopeRearrange > 0" type="button" size="sm" @click="applyRearrange()">
                            Rearrange {{ scopeRearrange }} unit
                        </Button>
                        <Button v-if="scopeRestock > 0 || isBulk" type="button" size="sm"
                            :variant="scopeRearrange > 0 ? 'outline' : 'default'"
                            @click="isBulk ? openRestockDialog() : applyRestock()">
                            Masuk Restock
                        </Button>
                        <NativeSelect :model-value="scopeStatus" :aria-label="`Status penuhan ${scopeLabel}`"
                            @update:model-value="(v: unknown) => applyStatus(String(v))">
                            <NativeSelectOption v-if="scopeStatus === ''" value="" disabled>Bercampur
                            </NativeSelectOption>
                            <NativeSelectOption v-for="o in fulfillmentOptions" :key="o.value" :value="o.value"
                                :disabled="o.value === STATUS_REARRANGE && scopeRearrange === 0 && scopeStatus !== STATUS_REARRANGE">
                                {{ o.label }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <Button v-if="scopeStaged" type="button" size="sm" variant="ghost"
                            @click="clearScopeStaged()">Batal</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="isBulk">
                            Tindakan terpakai kepada semua design yang ditandakan. Design tanpa kod atau tanpa donor
                            dilangkau.
                        </template>
                        <template v-else>
                            Override: klik satu cawangan di senarai atau tekan <span class="font-medium">Override</span>
                            untuk ubah satu cawangan sahaja.
                        </template>
                        Perubahan disenaraikan di Log dan hanya dikemaskini bila tekan Simpan.
                    </p>
                </CardFooter>
            </Card>
        </div>

        <!-- ===== Senarai Restock (disimpan - boleh dicetak / dieksport utk CEO) ===== -->
        <Card>
            <CardHeader class="gap-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle class="text-base">Senarai Restock</CardTitle>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Design yang dimasukkan ke Restock dan disimpan. Kuantiti order lalai mengikut paras setiap design (lalai 3 unit setiap cawangan, boleh diubah setiap design atau setiap cawangan); stok HQ &amp; lebihan cawangan yang tak request ditolak. Lajur Stok menunjukkan agihan qty order ke setiap cawangan (cawangan yang request dahulu).
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-muted-foreground">Kumpul ikut</span>
                        <NativeSelect v-model="restockGroup" aria-label="Kumpul ikut">
                            <NativeSelectOption value="category">Ikut Kategori</NativeSelectOption>
                            <NativeSelectOption value="branch">Ikut Cawangan</NativeSelectOption>
                            <NativeSelectOption v-if="canSeeSuppliers" value="supplier">Ikut Supplier
                            </NativeSelectOption>
                        </NativeSelect>
                        <Popover v-model:open="exportOpen">
                            <PopoverTrigger as-child>
                                <Button type="button" variant="outline" size="sm" :disabled="restockItems.length === 0">
                                    <Download class="size-4" /> Export
                                    <ChevronDown class="size-4" />
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent align="end" class="w-44 p-1">
                                <button type="button"
                                    class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-muted"
                                    @click="handleExport('excel')">
                                    <FileSpreadsheet class="size-4" /> Excel (.xlsx)
                                </button>
                                <button type="button"
                                    class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-muted"
                                    @click="handleExport('pdf')">
                                    <Printer class="size-4" /> PDF / Cetak
                                </button>
                            </PopoverContent>
                        </Popover>
                    </div>
                </div>
                <p v-if="Object.keys(restockEdits).length || pendingRestock.length" class="text-xs text-primary">
                    Ada suntingan belum disimpan &mdash; Excel / PDF membaca versi yang telah disimpan, tekan Simpan
                    dahulu.
                </p>
            </CardHeader>
            <CardContent>
                <Deferred data="restock">
                    <template #fallback>
                        <div class="flex flex-col gap-3">
                            <div v-for="row in 3" :key="row" class="flex items-center gap-3">
                                <Skeleton class="size-10 shrink-0 rounded-md" />
                                <div class="flex flex-1 flex-col gap-1.5">
                                    <Skeleton class="h-4 w-40" />
                                    <Skeleton class="h-3 w-64" />
                                </div>
                                <Skeleton class="h-8 w-20 shrink-0" />
                            </div>
                        </div>
                    </template>

                    <template #default>
                        <p v-if="restockRows.length === 0" class="text-sm text-muted-foreground">
                            Belum ada design dalam senarai. Pilih design di atas dan tekan <span
                                class="font-medium">Masuk Restock</span> &mdash; ia terus muncul di sini.
                        </p>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr
                                        class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <th class="py-2 pr-3 font-medium">Design</th>
                                        <th class="py-2 pr-3 font-medium">Cawangan request (saiz / berat)</th>
                                        <th class="py-2 pr-3 font-medium">Stok semua cawangan</th>
                                        <th class="py-2 pr-3 font-medium">Jualan</th>
                                        <th v-if="canSeeSuppliers" class="py-2 pr-3 font-medium">Kod supplier teratas
                                        </th>
                                        <th class="py-2 font-medium">Qty order</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="section in restockSections" :key="section.key">
                                    <tr class="border-b bg-muted/50">
                                        <td :colspan="restockColumnCount" class="px-2 py-1.5 text-xs font-semibold uppercase tracking-wide">
                                            {{ section.title }}
                                            <span class="ml-1 font-normal normal-case text-muted-foreground">{{ section.items.length }} design</span>
                                        </td>
                                    </tr>
                                    <tr v-for="item in section.items" :key="`${section.key}:${rowKey(item)}`"
                                        class="border-b align-top last:border-0"
                                        :class="{ 'bg-destructive/5 line-through opacity-60': isRemovedRow(item), 'bg-success/10': isOrderedRow(item), 'bg-primary/5': item.pending || isEditedRow(item) }">
                                        <td class="py-2 pr-3">
                                            <div class="flex items-center gap-3">
                                                <ImagePreview :src="item.image_url" :alt="item.description ?? ''"
                                                    class="size-10 shrink-0 rounded-md" />
                                                <div class="min-w-0">
                                                    <p class="font-medium">
                                                        {{ item.internal_code }}
                                                        <span v-if="item.pending"
                                                            class="ml-1 text-xs font-normal text-primary">Baharu
                                                            &middot; belum disimpan</span>
                                                    </p>
                                                    <p class="text-xs text-muted-foreground">
                                                        <template v-if="item.nickname">
                                                            &quot;{{ item.nickname }}&quot;</template>
                                                        <template v-else>{{ item.description }}</template>
                                                    </p>
                                                    <Badge v-if="item.category_name" variant="outline" class="mt-1 bg-white">{{
                                                        item.category_name }}</Badge>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-2 pr-3">
                                            <ul v-if="sectionRequests(item, section.key).length" class="flex flex-col gap-0.5 text-xs">
                                                <li v-for="(r, i) in sectionRequests(item, section.key)" :key="i">
                                                    <Badge variant="secondary">{{ r.store_code }}</Badge>
                                                    <span class="ml-1">
                                                        <template v-if="r.size">saiz {{ r.size }}</template>
                                                        <template v-if="r.size && r.weight"> &middot; </template>
                                                        <template v-if="r.weight">{{ r.weight }}g</template>
                                                        &times;{{ r.qty }}
                                                    </span>
                                                </li>
                                            </ul>
                                            <span v-else class="text-muted-foreground">-</span>
                                        </td>
                                        <td class="py-2 pr-3">
                                            <template v-if="restockGroup !== 'branch'">
                                            <div class="flex flex-wrap gap-1">
                                                <Badge v-for="s in item.stock" :key="s.store_code" variant="outline" size="sm"
                                                    class="gap-1 text-[9px]" :class="stockChipClass(item, s)"
                                                    :title="requestDotTitle(item, s.store_code)">
                                                    <span v-if="requestedOf(item, s.store_code)" class="size-1.5 rounded-full"
                                                        :class="requestShort(item, s.store_code) ? 'bg-warning' : 'bg-blue-600'" />
                                                    {{ s.store_code }} {{ s.stock }}
                                                    <template v-if="allocOf(item, s.store_code) > 0">
                                                        &rarr; {{ s.stock + allocOf(item, s.store_code) }}
                                                        <span class="text-primary">(+{{ allocOf(item, s.store_code) }})</span>
                                                    </template>
                                                </Badge>
                                            </div>
                                            <p class="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                                                <span class="inline-flex items-center gap-1"><span class="size-1.5 rounded-full bg-blue-600" /> cawangan request</span>
                                                <span class="inline-flex items-center gap-1"><span class="size-1.5 rounded-full bg-warning" /> request belum dipenuhi</span>
                                            </p>
                                            </template>
                                            <div v-else class="text-sm">
                                                <p>
                                                    <span class="text-xs text-muted-foreground mr-2">Stok cawangan ini</span>
                                                    <span :class="allocOf(item, section.key) > 0 ? 'font-semibold' : ''">
                                                        {{ branchStockOf(item, section.key) }}<template v-if="allocOf(item, section.key) > 0">
                                                            &rarr; {{ branchStockOf(item, section.key) + allocOf(item, section.key) }}
                                                            <span class="text-primary">(+{{ allocOf(item, section.key) }})</span></template>
                                                    </span>
                                                </p>
                                                <p class="mt-0.5 text-xs text-muted-foreground">
                                                    cawangan ini dapat {{ allocOf(item, section.key) }} unit daripada order {{ rowQty(item) }}
                                                </p>
                                            </div>
                                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                                <span class="text-xs text-muted-foreground">Paras</span>
                                                <NumberField class="w-24" :model-value="targetOf(item)" :min="1" :max="50"
                                                    :disabled="isRemovedRow(item)" @update:model-value="(v: number) => setTarget(item, v)">
                                                    <NumberFieldContent>
                                                        <NumberFieldDecrement />
                                                        <NumberFieldInput class="h-7 bg-white" :aria-label="`Paras ${item.internal_code}`" />
                                                        <NumberFieldIncrement />
                                                    </NumberFieldContent>
                                                </NumberField>
                                                <Popover :open="branchPopoverKey === rowKey(item)"
                                                    @update:open="(o: boolean) => (branchPopoverKey = o ? rowKey(item) : null)">
                                                    <PopoverTrigger as-child>
                                                        <Button type="button" size="sm" variant="outline" class="h-7 px-2 text-xs"
                                                            :disabled="isRemovedRow(item)">
                                                            Ikut cawangan
                                                            <span v-if="Object.keys(branchTargetsOf(item)).length"
                                                                class="rounded-sm bg-primary px-1 text-[10px] text-primary-foreground">{{
                                                                    Object.keys(branchTargetsOf(item)).length }}</span>
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent class="w-72" align="start">
                                                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                            Paras setiap cawangan
                                                        </p>
                                                        <p class="mb-2 mt-0.5 text-xs text-muted-foreground">
                                                            Lalai {{ targetOf(item) }}. Ubah cawangan tertentu sahaja; yang sama dengan lalai tak disimpan.
                                                        </p>
                                                        <ul class="flex max-h-72 flex-col gap-1.5 overflow-y-auto pr-1">
                                                            <li v-for="b in livePlan(item).branches" :key="b.store_code"
                                                                class="flex items-center justify-between gap-2 rounded-md px-2 py-1"
                                                                :class="branchTargetsOf(item)[b.store_code] !== undefined ? 'bg-primary/10' : ''">
                                                                <div class="min-w-0 text-sm">
                                                                    <span class="font-medium">{{ b.store_code }}</span>
                                                                    <span class="ml-1 text-xs text-muted-foreground">stok {{ b.stock }}<template v-if="b.requested"> &middot; request {{ b.requested }}</template></span>
                                                                </div>
                                                                <NumberField class="w-24 shrink-0" :model-value="b.target" :min="1" :max="50"
                                                                    @update:model-value="(v: number) => setBranchTarget(item, b.store_code, v)">
                                                                    <NumberFieldContent>
                                                                        <NumberFieldDecrement />
                                                                        <NumberFieldInput class="h-7 bg-white" :aria-label="`Paras ${b.store_code}`" />
                                                                        <NumberFieldIncrement />
                                                                    </NumberFieldContent>
                                                                </NumberField>
                                                            </li>
                                                        </ul>
                                                        <Button v-if="Object.keys(branchTargetsOf(item)).length" type="button" size="sm" variant="ghost"
                                                            class="mt-2 h-7 w-full" @click="resetBranchTargets(item)">Set semula ikut paras lalai</Button>
                                                    </PopoverContent>
                                                </Popover>
                                            </div>
                                        </td>
                                        <td class="py-2 pr-3 whitespace-nowrap">
                                            {{ periodSold(item) }} unit
                                            <p class="text-xs text-muted-foreground">{{ period === '7d' ? '1 minggu' :
                                                '30 hari' }}</p>
                                        </td>
                                        <td v-if="canSeeSuppliers" class="py-2 pr-3 text-xs">
                                            <p v-if="item.suppliers.length === 0" class="text-muted-foreground">{{
                                                item.pending ? 'dikira selepas Simpan' : '-' }}</p>
                                            <p v-for="v in item.suppliers" :key="v.vendor_code">
                                                {{ v.vendor_code }} <span class="text-muted-foreground">({{ v.sold
                                                }})</span>
                                            </p>
                                        </td>
                                        <td class="py-2">
                                            <div class="flex items-center gap-2">
                                                <Input type="number" min="0" max="100000" class="h-8 w-20 bg-white"
                                                    :model-value="rowQty(item)" :disabled="isRemovedRow(item)"
                                                    :aria-label="`Kuantiti order ${item.internal_code}`"
                                                    @update:model-value="(v: string | number) => setRowQty(item, String(v))" />
                                                <Button v-if="!item.pending && !isRemovedRow(item)" type="button"
                                                    size="icon" :variant="isOrderedRow(item) ? 'default' : 'ghost'"
                                                    :class="isOrderedRow(item) ? 'bg-success text-success-foreground hover:bg-success/90' : 'text-success hover:text-success'"
                                                    :title="isOrderedRow(item) ? 'Batal tanda Dah Order' : 'Tanda Dah Order (selepas order dengan supplier)'"
                                                    :aria-label="`Tanda ${item.internal_code} Dah Order`"
                                                    @click="toggleOrdered(item)">
                                                    <Check class="size-4" />
                                                </Button>
                                                <Button v-if="!isRemovedRow(item) && !isOrderedRow(item)" type="button"
                                                    size="icon" variant="ghost"
                                                    :aria-label="`Buang ${item.internal_code} dari senarai`"
                                                    @click="removeRow(item)">
                                                    <X class="size-4" />
                                                </Button>
                                                <Button v-else type="button" size="sm" variant="ghost"
                                                    @click="undoRestockEdit(item.id)">Batal</Button>
                                            </div>
                                            <p v-if="isOrderedRow(item)" class="mt-1 text-xs font-medium text-success">
                                                Dah Order &mdash; disimpan bila tekan Simpan
                                            </p>
                                            <p v-else class="mt-1 text-xs text-muted-foreground"
                                                :title="planHint(item)">
                                                cadangan {{ suggestedLive(item) }}
                                                <button
                                                    v-if="rowQty(item) !== suggestedLive(item) && !isRemovedRow(item)"
                                                    type="button" class="ml-1 text-primary underline"
                                                    @click="setRowQty(item, String(suggestedLive(item)))">guna</button>
                                            </p>
                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Best Supplier to Meet - super_admin sahaja buat masa ni -->
                        <section v-if="canSeeSuppliers && (props.restock?.suppliers?.length ?? 0) > 0"
                            class="mt-6 flex flex-col gap-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Best Supplier to Meet <span class="font-normal normal-case">(3 supplier teratas ikut
                                    jualan
                                    keseluruhan setiap design)</span>
                            </h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr
                                            class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                            <th class="py-2 pr-3 font-medium">Kod supplier</th>
                                            <th class="py-2 font-medium">Kod design</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="v in props.restock?.suppliers" :key="v.vendor_code"
                                            class="border-b last:border-0">
                                            <td class="py-2 pr-3 font-medium">{{ v.vendor_code }}</td>
                                            <td class="py-2 text-xs text-muted-foreground">{{ v.codes.join(', ') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </template>
                </Deferred>
            </CardContent>
        </Card>

        <!-- ===== Log ===== -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Log</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4 text-sm">
                <p v-if="stagedEntries.length === 0 && savedLog.length === 0" class="text-muted-foreground">
                    Tiada aktiviti lagi. Perubahan status akan tersenarai di sini sebelum disimpan.
                </p>

                <section v-if="stagedEntries.length" class="flex flex-col gap-1.5">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Menunggu simpan ({{ stagedEntries.length }})
                    </h3>
                    <ul class="flex flex-col gap-1">
                        <li v-for="e in stagedEntries" :key="e.key"
                            class="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5">
                            <span>
                                <span class="font-medium">{{ e.design }}</span>
                                <Badge variant="secondary" class="mx-2">{{ e.store }}</Badge>
                                {{ e.from }} &rarr; {{ e.to }}
                            </span>
                            <Button type="button" size="icon" variant="ghost"
                                :aria-label="`Buang perubahan ${e.design} ${e.store}`" @click="removeEntry(e.key)">
                                <X class="size-4" />
                            </Button>
                        </li>
                    </ul>
                </section>

                <section v-if="savedLog.length" class="flex flex-col gap-1.5">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Telah disimpan</h3>
                    <ul class="flex max-h-48 flex-col gap-1 overflow-auto">
                        <li v-for="(e, i) in savedLog" :key="`${e.key}-${i}`"
                            class="rounded-md bg-muted/40 px-3 py-1.5 text-muted-foreground">
                            <span class="mr-2 text-xs">{{ e.at }}</span>
                            <span class="font-medium text-foreground">{{ e.design }}</span>
                            <Badge variant="secondary" class="mx-2">{{ e.store }}</Badge>
                            {{ e.from }} &rarr; {{ e.to }}
                        </li>
                    </ul>
                </section>
            </CardContent>
        </Card>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" :disabled="saving || stagedCount === 0" @click="save(false)">
                <Loader2 v-if="saving" class="size-4 animate-spin" />
                <Save v-else class="size-4" />
                Save
            </Button>
            <Button type="button" :disabled="saving || stagedCount === 0" @click="save(true)">
                <Loader2 v-if="saving" class="size-4 animate-spin" />
                <Printer v-else class="size-4" />
                Save &amp; Print
            </Button>
        </div>
        <!-- ===== Dialog Masuk Restock pukal ===== -->
        <Dialog v-model:open="restockDialogOpen">
            <DialogContent class="flex max-h-[90vh] flex-col gap-4 sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Masuk Senarai Restock</DialogTitle>
                    <DialogDescription>
                        {{ checkedGroups.length }} design dipilih: <span class="font-medium text-foreground">{{
                            dialogCoded.length }} sudah ada design code</span><template v-if="dialogUncoded.length">,
                            <span class="font-medium text-destructive">{{ dialogUncoded.length }} belum ada
                                code</span></template>.
                    </DialogDescription>
                </DialogHeader>

                <ScrollArea class="h-[min(28rem,55vh)] [&_[data-slot=scroll-area-viewport]>div]:!block">
                    <div class="flex flex-col gap-4 pr-3 text-sm">
                        <!-- Tiada design code: pilih code dulu, kalau tidak design ini dilangkau -->
                        <section v-if="dialogUncoded.length"
                            class="flex flex-col gap-2 rounded-md border border-warning/50 bg-warning/10 p-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide">Belum ada design code ({{
                                dialogUncoded.length }})</h3>
                            <p class="text-xs text-muted-foreground">
                                Pilih design code supaya cadangan Restock keluar. Design tanpa code <span
                                    class="font-medium">tidak</span> dimasukkan ke Restock.
                            </p>
                            <ul class="flex flex-col gap-1.5">
                                <li v-for="g in dialogUncoded" :key="g.key"
                                    class="flex items-center justify-between gap-2 rounded-md border bg-background px-2.5 py-1.5">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ g.title }}</p>
                                        <p class="truncate text-xs text-muted-foreground">
                                            {{g.lines.map((l) => `${l.store_code} ${l.qty_outstanding}`).join(', ')}}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <Popover :open="pickerOpenKey === g.key"
                                            @update:open="(o: boolean) => (o ? openPicker(g) : (pickerOpenKey = null))">
                                            <PopoverTrigger as-child>
                                                <Button type="button" size="sm" variant="outline">Pilih design code
                                                    <ChevronDown class="size-3.5" />
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent class="w-80 p-2" align="end">
                                                <form class="flex gap-2"
                                                    @submit.prevent="loadCandidates(g, dialogQuery[g.key] ?? '')">
                                                    <Input v-model="dialogQuery[g.key]"
                                                        placeholder="Cari nama / nickname / kod" class="h-8" />
                                                    <Button type="submit" size="sm" variant="outline"
                                                        :disabled="candidateLoading">Cari</Button>
                                                </form>
                                                <div v-if="candidateLoading"
                                                    class="mt-2 flex items-center gap-2 text-muted-foreground">
                                                    <Loader2 class="size-4 animate-spin" /> Mencari padanan...
                                                </div>
                                                <p v-else-if="!(candidates[g.key]?.length)"
                                                    class="mt-2 text-xs text-muted-foreground">
                                                    Tiada padanan. Cuba carian lain.
                                                </p>
                                                <ScrollArea v-else class="mt-2 h-64">
                                                    <ul class="flex flex-col gap-1 pr-3">
                                                        <li v-for="c in candidates[g.key]" :key="c.internal_code">
                                                            <button type="button" :disabled="previewLoading"
                                                                class="flex w-full items-center gap-2 rounded-md border px-2 py-1.5 text-left hover:bg-muted disabled:opacity-50"
                                                                @click="pickCode(g, c)">
                                                                <img v-if="c.image_url" :src="c.image_url"
                                                                    :alt="c.nickname ?? c.description ?? ''"
                                                                    class="size-9 shrink-0 rounded-md object-cover"
                                                                    loading="lazy">
                                                                <div v-else
                                                                    class="size-9 shrink-0 rounded-md bg-muted" />
                                                                <div class="min-w-0 flex-1">
                                                                    <p class="font-medium">{{ c.internal_code }}
                                                                        <Badge v-if="c.score > 0"
                                                                            :class="['ml-1 px-1.5 py-0 text-[9px]', scoreBadgeClass(c.score)]">
                                                                            {{ c.score }}%</Badge>
                                                                    </p>
                                                                    <p
                                                                        class="truncate text-[10px] text-muted-foreground">
                                                                        {{
                                                                        c.nickname ?? c.description }}</p>
                                                                </div>
                                                            </button>
                                                        </li>
                                                    </ul>
                                                    <div v-if="candidatePage[g.key]?.hasMore" class="mt-2 pr-3">
                                                        <Button type="button" size="sm" class="w-full"
                                                            :disabled="candidateLoadingMore"
                                                            @click="loadMoreCandidates(g)">
                                                            <Loader2 v-if="candidateLoadingMore"
                                                                class="size-3.5 animate-spin" />
                                                            {{ candidateLoadingMore ? 'Memuatkan...' : 'Muat Lagi' }}
                                                        </Button>
                                                    </div>
                                                </ScrollArea>
                                            </PopoverContent>
                                        </Popover>
                                        <Button type="button" size="icon" variant="ghost" class="size-8"
                                            :aria-label="`Buang ${g.title} daripada pilihan`"
                                            @click="toggleChecked(g, false)">
                                            <X class="size-4" />
                                        </Button>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Ada design code: setiap cawangan - baki, Restock, Rearrange; boleh dibuang -->
                        <section v-for="g in dialogCoded" :key="g.key"
                            class="flex flex-col gap-1.5 rounded-md border px-3 py-2.5">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ g.title }}</p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        {{ g.description ?? '-' }}<template v-if="g.nickname"> &middot; &quot;{{
                                            g.nickname }}&quot;</template>
                                    </p>
                                </div>
                                <Button type="button" size="icon" variant="ghost" class="size-7 shrink-0"
                                    :aria-label="`Buang ${g.title} daripada pilihan`" @click="toggleChecked(g, false)">
                                    <X class="size-4" />
                                </Button>
                            </div>
                            <ul class="flex flex-col gap-1">
                                <li v-for="l in g.lines.filter((x) => !excludedLineIds.includes(x.id))" :key="l.id"
                                    class="flex items-center justify-between gap-2 rounded-md bg-muted/40 px-2 py-1.5">
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <Badge variant="secondary">{{ l.store_code }}</Badge>
                                        <span>baki {{ l.qty_outstanding }}</span>
                                        <span v-if="l.restock_qty > 0" class="font-medium">Restock {{ l.restock_qty
                                            }}</span>
                                        <span v-if="l.rearrange_qty > 0" class="text-xs text-blue-600">
                                            Rearrange {{ l.rearrange_qty }} ({{ describeMoves(l.moves, l.store_code) }})
                                        </span>
                                        <span v-if="l.restock_qty === 0" class="text-xs text-muted-foreground">
                                            {{ noRestockNote(l) }}
                                        </span>
                                    </div>
                                    <Button v-if="l.restock_qty > 0" type="button" size="icon" variant="ghost"
                                        class="size-6 shrink-0"
                                        :aria-label="`Buang ${l.store_code} daripada senarai Restock`"
                                        @click="excludeLine(l)">
                                        <X class="size-3.5" />
                                    </Button>
                                </li>
                            </ul>
                        </section>

                        <p v-if="dialogCoded.length === 0 && dialogUncoded.length === 0" class="text-muted-foreground">
                            Tiada
                            design dipilih.</p>
                    </div>
                </ScrollArea>

                <p v-if="excludedLineIds.length" class="text-xs text-muted-foreground">
                    {{ excludedLineIds.length }} cawangan dibuang daripada senarai.
                    <button type="button" class="underline hover:text-foreground" @click="excludedLineIds = []">Pulihkan
                        semua</button>
                </p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="restockDialogOpen = false">Batal</Button>
                    <Button type="button" :disabled="dialogRestockLines.length === 0" @click="submitRestock">
                        Masuk Restock ({{ dialogDesignCount }} design &middot; {{ dialogRestockUnits }} unit)
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

    </div>
</template>
