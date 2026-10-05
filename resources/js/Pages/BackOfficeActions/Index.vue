<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { Check, ChevronDown, Download, FileSpreadsheet, Loader2, Printer, Save, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ImagePreview from '@/components/ImagePreview.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { Separator } from '@/components/ui/separator';

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
    plan: {
        target: number;
        branches: { store_code: string; stock: number; requested: number; need: number }[];
        surplus: number;
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
const branchFilter = ref('');
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
const restockEdits = ref<Record<number, { qty?: number; remove?: boolean; ordered?: boolean }>>({});
const restockGroup = ref<'category' | 'branch' | 'supplier'>('category');
const exportOpen = ref(false);

const restockItems = computed<RestockItem[]>(() => props.restock?.items ?? []);

// Kuantiti design BARU (belum disimpan) yg leader BO ubah - kunci = kod design.
const pendingQty = ref<Record<string, number>>({});

function restockQty(item: RestockItem): number {
    return restockEdits.value[item.id]?.qty ?? item.qty_to_order;
}

function setRestockQty(item: RestockItem, value: string) {
    const qty = Math.max(0, Math.min(100000, Math.floor(Number(value) || 0)));
    const ordered = restockEdits.value[item.id]?.ordered;

    if (qty === item.qty_to_order && !ordered) {
        delete restockEdits.value[item.id];
    } else {
        restockEdits.value[item.id] = { qty, ordered };
    }
}

// Tick = tanda Dah Order (dilaksanakan bila Simpan). Tick sekali lagi untuk batal.
function toggleOrdered(item: RestockItem) {
    const edit = restockEdits.value[item.id];

    if (edit?.ordered) {
        if (edit.qty !== undefined && edit.qty !== item.qty_to_order) {
            restockEdits.value[item.id] = { qty: edit.qty };
        } else {
            delete restockEdits.value[item.id];
        }

        return;
    }

    restockEdits.value[item.id] = { qty: edit?.qty, ordered: true };
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
        const plan = design?.restock_plan ?? { target: 3, branches: [], surplus: 0, need_total: 0, suggested_qty: 0 };

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

function rowQty(item: RestockItem): number {
    return item.pending ? item.qty_to_order : restockQty(item);
}

function setRowQty(item: RestockItem, value: string) {
    if (!item.pending) {
        setRestockQty(item, value);

        return;
    }

    const qty = Math.max(0, Math.min(100000, Math.floor(Number(value) || 0)));

    if (qty === item.suggested_qty) {
        delete pendingQty.value[item.internal_code];
    } else {
        pendingQty.value[item.internal_code] = qty;
    }
}

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
}

function planHint(item: RestockItem): string {
    const parts = item.plan.branches.filter((b) => b.need > 0).map((b) => `${b.store_code} ${b.need}`).join(', ');

    return `Paras ${item.plan.target} setiap cawangan: ${parts} = ${item.plan.need_total}; tolak lebihan ${item.plan.surplus} (stok HQ / cawangan tak request) = ${item.plan.suggested_qty}`;
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

const branches = computed(() => Array.from(new Set(lines.value.map((l) => l.store_code))).sort());

const filteredLines = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return lines.value.filter((l) => {
        if (branchFilter.value && l.store_code !== branchFilter.value) {
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
const designGroups = computed<DesignGroup[]>(() => {
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
const isOverride = computed(() => !!activeGroup.value && overrideLineId.value !== null && activeGroup.value.lines.some((l) => l.id === overrideLineId.value));

const scopeLines = computed<Line[]>(() => {
    const group = activeGroup.value;

    if (!group) {
        return [];
    }

    return isOverride.value ? group.lines.filter((l) => l.id === overrideLineId.value) : group.lines;
});

const scopeLabel = computed(() => (isOverride.value ? `hanya ${scopeLines.value[0].store_code}` : 'semua cawangan'));
const scopeRearrange = computed(() => scopeLines.value.reduce((sum, l) => sum + (l.action ? l.rearrange_qty : 0), 0));
const scopeRestock = computed(() => scopeLines.value.reduce((sum, l) => sum + (l.action ? l.restock_qty : 0), 0));
const scopeStaged = computed(() => scopeLines.value.filter((l) => staged.value[l.id]).length);
const scopeStatus = computed(() => {
    const set = new Set(scopeLines.value.map(effectiveStatus));

    return set.size === 1 ? Array.from(set)[0] : '';
});

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

async function chooseCode(group: DesignGroup, candidate: Candidate) {
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

        selectedKey.value = candidate.internal_code;
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
    selectedKey.value = preview.originalKey;
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

    const code = activeGroup.value?.code;

    if (code && scopeLines.value.some((l) => staged.value[l.id]?.status === STATUS_RESTOCK_DEFAULT)) {
        toast.success(`${code} masuk Senarai Restock di bawah. Tekan Simpan untuk menyimpan.`);
    }
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
                to: edit.remove ? 'Buang dari senarai' : edit.ordered ? `Dah Order ${edit.qty ?? item.qty_to_order} unit` : `order ${edit.qty}`,
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
    const restock_items = Object.entries(restockEdits.value).map(([id, e]) => ({
        id: Number(id),
        qty: e.qty ?? null,
        remove: e.remove ?? false,
        ordered: e.ordered ?? false,
    }));

    const restock_new = pendingRestock.value
        .filter((i) => pendingQty.value[i.internal_code] !== undefined)
        .map((i) => ({ internal_code: i.internal_code, qty: pendingQty.value[i.internal_code] }));

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
            previews.value = {};
            candidates.value = {};
            candidatePage.value = {};
            selectedKey.value = null;

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
                    (<span class="font-medium">Rearrange</span> / <span class="font-medium">Restock</span> / status) terpakai untuk
                    semua cawangan yang minta design itu.
                </p>
            </div>
            <Button type="button" variant="outline" @click="handlePrint">
                <Printer class="size-4" />
                Print
            </Button>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- ===== Kiri: senarai design ===== -->
            <Card class="lg:col-span-2">
                <CardHeader class="gap-3">
                    <CardTitle class="text-base">Senarai Permintaan Cawangan</CardTitle>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative min-w-56 flex-1">
                            <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                            <Input v-model="search" class="pl-8" placeholder="Cari kod design, keterangan, no. permintaan..." />
                        </div>
                        <NativeSelect v-model="branchFilter" aria-label="Tapis cawangan">
                            <NativeSelectOption value="">Semua cawangan</NativeSelectOption>
                            <NativeSelectOption v-for="b in branches" :key="b" :value="b">{{ b }}</NativeSelectOption>
                        </NativeSelect>
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

                            <ScrollArea v-else class="h-[min(34rem,calc(100vh-18rem))]">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 z-10 bg-card">
                                        <tr class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                            <th class="py-2 pr-3 font-medium">Design</th>
                                            <th class="py-2 pr-3 font-medium">Permintaan</th>
                                            <th class="py-2 pr-3 font-medium">Cadangan</th>
                                            <th class="py-2 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="group in designGroups" :key="group.key"
                                            class="cursor-pointer border-b last:border-0 hover:bg-muted/40"
                                            :class="{
                                                'bg-muted/60': selectedKey === group.key,
                                                'bg-primary/5': stagedIn(group) > 0 && selectedKey !== group.key,
                                            }"
                                            @click="selectedKey = group.key">
                                            <td class="py-2 pr-3">
                                                <div class="flex items-center gap-3">
                                                    <ImagePreview :src="group.image_url" :alt="group.description ?? ''" class="size-10 shrink-0 rounded-md" />
                                                    <div class="min-w-0">
                                                        <p class="font-medium">{{ group.title }}</p>
                                                        <p class="truncate text-xs text-muted-foreground">
                                                            <template v-if="group.code">{{ group.description ?? '-' }}</template>
                                                            <template v-if="group.nickname"> &middot; &quot;{{ group.nickname }}&quot;</template>
                                                        </p>
                                                        <Badge v-if="group.category_name" variant="outline" class="mt-1">{{ group.category_name }}</Badge>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2 pr-3">
                                                <div class="flex flex-wrap gap-1">
                                                    <Badge v-for="l in group.lines" :key="l.id" variant="secondary"
                                                        :class="{ 'ring-1 ring-destructive': l.is_critical }"
                                                        :title="l.is_critical ? 'Stok kritikal' : undefined">
                                                        {{ l.store_code }} {{ l.qty_outstanding }}
                                                    </Badge>
                                                </div>
                                                <p class="mt-1 text-xs text-muted-foreground">jumlah {{ group.total_outstanding }} unit</p>
                                            </td>
                                            <td class="py-2 pr-3">
                                                <span v-if="group.action"
                                                    :class="`inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_BADGE_CLASS[group.action]}`">
                                                    {{ ACTION_LABEL[group.action] }}
                                                </span>
                                                <span v-else class="text-muted-foreground">-</span>
                                            </td>
                                            <td class="py-2">
                                                <p>{{ groupStatus(group) ? labelOf(groupStatus(group)) : 'Bercampur' }}</p>
                                                <p v-if="stagedIn(group)" class="mt-0.5 text-xs text-primary">Belum disimpan</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </ScrollArea>
                        </template>
                    </Deferred>
                </CardContent>
            </Card>

            <!-- ===== Kanan: cadangan terperinci + tindakan ===== -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Cadangan Terperinci</CardTitle>
                </CardHeader>
                <CardContent>
                    <p v-if="!activeGroup" class="text-sm text-muted-foreground">
                        Pilih satu design di sebelah kiri untuk lihat cadangan Rearrange / Restock.
                    </p>

                    <ScrollArea v-else class="h-[min(34rem,calc(100vh-18rem))]">
                    <div class="flex flex-col gap-4 pr-3 text-sm">
                        <div class="flex items-center gap-3">
                            <ImagePreview :src="activeGroup.image_url" :alt="activeGroup.description ?? ''" class="size-14 shrink-0 rounded-md" />
                            <div class="min-w-0">
                                <p class="font-semibold">{{ activeGroup.title }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="activeGroup.code">{{ activeGroup.description ?? '-' }}</template>
                                    <template v-if="activeGroup.nickname"> &middot; &quot;{{ activeGroup.nickname }}&quot;</template>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ activeGroup.lines.length }} cawangan &middot; baki {{ activeGroup.total_outstanding }} unit
                                </p>
                            </div>
                        </div>

                        <!-- Design tanpa kod (web/upload): BO pilih kod dulu, baru cadangan keluar -->
                        <section v-if="needsDesignCode" class="flex flex-col gap-2 rounded-md border border-dashed px-3 py-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pilih design code</h3>
                            <p class="text-xs text-muted-foreground">
                                Cawangan tidak nyatakan kod. Pilih padanan nickname/nama di bawah &mdash; cadangan Rearrange / Restock
                                keluar selepas kod dipilih, dan kod disimpan pada semua cawangan di bawah design ini.
                            </p>
                            <form class="flex gap-2" @submit.prevent="loadCandidates(activeGroup, candidateQuery)">
                                <Input v-model="candidateQuery" placeholder="Cari nama / nickname / kod design" class="h-8" />
                                <Button type="submit" size="sm" variant="outline" :disabled="candidateLoading">Cari</Button>
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
                                        <ImagePreview :src="c.image_url" :alt="c.nickname ?? c.description ?? ''" class="size-10 shrink-0 rounded-md" />
                                        <div class="min-w-0">
                                            <p class="font-medium">{{ c.internal_code }}
                                                <Badge v-if="c.score > 0" :class="['ml-1 px-1.5 py-0 text-[9px]', scoreBadgeClass(c.score)]"
                                                    :title="`${c.score}% mirip dengan nama permintaan`">{{ c.score }}%</Badge>
                                                <!-- <span class="ml-1 text-xs font-normal text-muted-foreground">{{ c.score }}% mirip</span> -->
                                            </p>
                                            <p class="truncate text-[9px] text-muted-foreground">{{ c.nickname ?? c.description }}</p>
                                        </div>
                                    </div>
                                    <Button type="button" size="sm" variant="outline" :disabled="previewLoading" @click="chooseCode(activeGroup, c)">
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

                        <div v-else-if="activePreview" class="flex items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">Design code dipilih (disimpan bila tekan Simpan)</p>
                                <Badge variant="secondary">{{ activePreview.code }}</Badge>
                                <span class="ml-2 text-xs text-muted-foreground">{{ activePreview.candidate.nickname ?? activePreview.candidate.description }}</span>
                            </div>
                            <Button type="button" size="sm" variant="outline" @click="clearCode(activeGroup)">Tukar</Button>
                        </div>

                        <!-- Ringkasan cadangan: Rearrange / Restock / kedua-duanya -->
                        <div v-if="activeGroup.action" class="rounded-md border px-3 py-2" :class="ACTION_BOX_CLASS[activeGroup.action]">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cadangan semua cawangan</p>
                            <p v-if="activeGroup.action === 'both'" class="mt-0.5">
                                <span class="font-medium">Rearrange {{ activeGroup.rearrange_qty }} unit</span> dari cawangan lain,
                                baki <span class="font-medium">{{ activeGroup.restock_qty }} unit perlu Restock</span>.
                            </p>
                            <p v-else-if="activeGroup.action === 'rearrange'" class="mt-0.5">
                                Rearrange <span class="font-medium">{{ activeGroup.rearrange_qty }} unit</span> boleh penuhi semua baki permintaan.
                            </p>
                            <p v-else-if="activeDesign?.network_low" class="mt-0.5">
                                Tiada cawangan boleh derma (semua stok &le; 2, jumlah rangkaian {{ activeDesign.total_stock }}).
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
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Permintaan cawangan</h3>
                            <ul class="flex flex-col gap-1.5">
                                <li v-for="l in activeGroup.lines" :key="l.id"
                                    class="rounded-md border px-3 py-2"
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
                                        <span v-if="l.qty_outstanding !== l.qty_requested" class="text-xs text-muted-foreground">/ {{ l.qty_requested }}</span>
                                        <span v-if="l.is_critical" class="text-xs font-medium text-destructive">Kritikal</span>
                                        <span v-if="l.action"
                                            :class="`inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_BADGE_CLASS[l.action]}`">
                                            {{ ACTION_LABEL[l.action] }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">{{ labelOf(effectiveStatus(l)) }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ l.request_number }}
                                        <template v-if="l.requested_at"> &middot; {{ formatDate(l.requested_at) }}</template>
                                        <template v-if="l.size"> &middot; Saiz {{ l.size }}</template>
                                        <template v-if="l.weight"> &middot; {{ l.weight }}g</template>
                                    </p>
                                    <p v-if="l.moves.length" class="mt-0.5 text-xs">
                                        Rearrange: {{ describeMoves(l.moves, l.store_code) }}<template v-if="l.restock_qty">, baki {{ l.restock_qty }} Restock</template>
                                    </p>
                                    <p v-else-if="l.restock_qty" class="mt-0.5 text-xs">Restock: {{ l.restock_qty }} unit</p>
                                    <p v-if="l.remark" class="mt-0.5 text-xs italic text-muted-foreground">Nota: {{ l.remark }}</p>
                                </li>
                            </ul>
                        </section>

                        <section v-if="activeDesign?.internal_code" class="flex flex-col gap-1.5">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stok semasa semua cawangan</h3>
                            <div class="flex flex-wrap gap-1.5">
                                <Badge v-for="st in activeDesign.stock" :key="st.store_code"
                                    :variant="activeGroup.lines.some((l) => l.store_code === st.store_code) ? 'default' : 'outline'"
                                    :class="{ 'opacity-60': st.stock === 0 }"
                                    :title="st.reserved ? `${st.reserved} unit sudah ditempah transfer` : undefined">
                                    {{ st.store_code }} {{ st.stock }}<template v-if="st.reserved"> (−{{ st.reserved }})</template>
                                </Badge>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Jumlah {{ activeDesign.total_stock }} &middot; lebihan boleh didermakan {{ activeDesign.spare_stock }}
                                (stok &ge; 3, baki 2 kekal; HQ semua stok boleh dipindah). Security tidak dikira.
                            </p>
                        </section>

                        <section v-if="activeDesign?.internal_code" class="flex flex-col gap-1.5">
                            <div class="flex items-center justify-between">
                                <p class="text-xs text-muted-foreground">
                                    Jualan terkini: <span class="font-medium text-foreground">{{ periodSold(activeDesign) }} unit</span>
                                </p>
                                <div class="flex gap-1">
                                    <Button type="button" size="sm" :variant="period === '7d' ? 'default' : 'outline'" @click="period = '7d'">1 Minggu</Button>
                                    <Button type="button" size="sm" :variant="period === '30d' ? 'default' : 'outline'" @click="period = '30d'">30 Hari</Button>
                                </div>
                            </div>
                            <p v-if="activeDesign.sales.length === 0" class="text-muted-foreground">Tiada jualan dalam 30 hari.</p>
                            <div v-else class="flex flex-wrap gap-1.5">
                                <Badge v-for="sl in activeDesign.sales.filter((x) => periodSold(x) > 0)" :key="sl.store_code" variant="outline">
                                    {{ sl.store_code }} {{ periodSold(sl) }}
                                </Badge>
                            </div>
                        </section>
                    </div>
                    </ScrollArea>
                </CardContent>
                <Separator />
                <!-- Tindakan: kekal di bawah kad (luar ScrollArea). Skop = semua cawangan, atau satu cawangan bila Override. -->
                <CardFooter v-if="activeGroup" class="flex-col items-stretch gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Tindakan ({{ scopeLabel }})
                        </h3>
                        <div class="flex items-center gap-2">
                            <NativeSelect v-if="isOverride" :model-value="String(overrideLineId)" aria-label="Cawangan untuk override"
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
                        <Button v-if="scopeRestock > 0" type="button" size="sm"
                            :variant="scopeRearrange > 0 ? 'outline' : 'default'" @click="applyRestock()">
                            Masuk Restock
                        </Button>
                        <NativeSelect :model-value="scopeStatus"
                            :aria-label="`Status penuhan ${scopeLabel} ${activeGroup.title}`"
                            @update:model-value="(v: unknown) => applyStatus(String(v))">
                            <NativeSelectOption v-if="scopeStatus === ''" value="" disabled>Bercampur</NativeSelectOption>
                            <NativeSelectOption v-for="o in fulfillmentOptions" :key="o.value" :value="o.value"
                                :disabled="o.value === STATUS_REARRANGE && scopeRearrange === 0 && scopeStatus !== STATUS_REARRANGE">
                                {{ o.label }}
                            </NativeSelectOption>
                        </NativeSelect>
                        <Button v-if="scopeStaged" type="button" size="sm" variant="ghost" @click="clearScopeStaged()">Batal</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Override: klik satu cawangan di senarai atau tekan <span class="font-medium">Override</span> untuk ubah satu cawangan sahaja.
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
                            Design yang dimasukkan ke Restock dan disimpan. Kuantiti order lalai = paras 3 setiap cawangan
                            (stok HQ &amp; lebihan cawangan yang tak request ditolak) &mdash; boleh diubah sebelum Simpan.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <NativeSelect v-model="restockGroup" aria-label="Kumpul eksport ikut">
                            <NativeSelectOption value="category">Ikut Kategori</NativeSelectOption>
                            <NativeSelectOption value="branch">Ikut Cawangan</NativeSelectOption>
                            <NativeSelectOption v-if="canSeeSuppliers" value="supplier">Ikut Supplier</NativeSelectOption>
                        </NativeSelect>
                        <Popover v-model:open="exportOpen">
                            <PopoverTrigger as-child>
                                <Button type="button" variant="outline" size="sm" :disabled="restockItems.length === 0">
                                    <Download class="size-4" /> Export <ChevronDown class="size-4" />
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
                    Ada suntingan belum disimpan &mdash; Excel / PDF membaca versi yang telah disimpan, tekan Simpan dahulu.
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
                            Belum ada design dalam senarai. Pilih design di atas dan tekan <span class="font-medium">Masuk Restock</span> &mdash; ia terus muncul di sini.
                        </p>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <th class="py-2 pr-3 font-medium">Design</th>
                                        <th class="py-2 pr-3 font-medium">Cawangan request (saiz / berat)</th>
                                        <th class="py-2 pr-3 font-medium">Stok semua cawangan</th>
                                        <th class="py-2 pr-3 font-medium">Jualan</th>
                                        <th v-if="canSeeSuppliers" class="py-2 pr-3 font-medium">Kod supplier teratas</th>
                                        <th class="py-2 font-medium">Qty order</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in restockRows" :key="rowKey(item)" class="border-b align-top last:border-0"
                                        :class="{ 'bg-destructive/5 line-through opacity-60': isRemovedRow(item), 'bg-success/10': isOrderedRow(item), 'bg-primary/5': item.pending || isEditedRow(item) }">
                                        <td class="py-2 pr-3">
                                            <div class="flex items-center gap-3">
                                                <ImagePreview :src="item.image_url" :alt="item.description ?? ''" class="size-10 shrink-0 rounded-md" />
                                                <div class="min-w-0">
                                                    <p class="font-medium">
                                                        {{ item.internal_code }}
                                                        <span v-if="item.pending" class="ml-1 text-xs font-normal text-primary">Baharu &middot; belum disimpan</span>
                                                    </p>
                                                    <p class="text-xs text-muted-foreground">
                                                        {{ item.description }}<template v-if="item.nickname"> &middot; &quot;{{ item.nickname }}&quot;</template>
                                                    </p>
                                                    <Badge v-if="item.category_name" variant="outline" class="mt-1">{{ item.category_name }}</Badge>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-2 pr-3">
                                            <ul v-if="item.requests.length" class="flex flex-col gap-0.5 text-xs">
                                                <li v-for="(r, i) in item.requests" :key="i">
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
                                            <div class="flex flex-wrap gap-1">
                                                <Badge v-for="s in item.stock" :key="s.store_code" variant="outline"
                                                    :class="s.stock <= 1 ? 'border-destructive/50 text-destructive' : ''">
                                                    {{ s.store_code }} {{ s.stock }}
                                                </Badge>
                                            </div>
                                            <p class="mt-1 text-xs text-muted-foreground">jumlah {{ item.total_stock }}</p>
                                        </td>
                                        <td class="py-2 pr-3 whitespace-nowrap">
                                            {{ periodSold(item) }} unit
                                            <p class="text-xs text-muted-foreground">{{ period === '7d' ? '1 minggu' : '30 hari' }}</p>
                                        </td>
                                        <td v-if="canSeeSuppliers" class="py-2 pr-3 text-xs">
                                            <p v-if="item.suppliers.length === 0" class="text-muted-foreground">{{ item.pending ? 'dikira selepas Simpan' : '-' }}</p>
                                            <p v-for="v in item.suppliers" :key="v.vendor_code">
                                                {{ v.vendor_code }} <span class="text-muted-foreground">({{ v.sold }})</span>
                                            </p>
                                        </td>
                                        <td class="py-2">
                                            <div class="flex items-center gap-2">
                                                <Input type="number" min="0" max="100000" class="h-8 w-20"
                                                    :model-value="rowQty(item)" :disabled="isRemovedRow(item)"
                                                    :aria-label="`Kuantiti order ${item.internal_code}`"
                                                    @update:model-value="(v: string | number) => setRowQty(item, String(v))" />
                                                <Button v-if="!item.pending && !isRemovedRow(item)" type="button" size="icon"
                                                    :variant="isOrderedRow(item) ? 'default' : 'ghost'"
                                                    :class="isOrderedRow(item) ? 'bg-success text-success-foreground hover:bg-success/90' : 'text-success hover:text-success'"
                                                    :title="isOrderedRow(item) ? 'Batal tanda Dah Order' : 'Tanda Dah Order (selepas order dengan supplier)'"
                                                    :aria-label="`Tanda ${item.internal_code} Dah Order`" @click="toggleOrdered(item)">
                                                    <Check class="size-4" />
                                                </Button>
                                                <Button v-if="!isRemovedRow(item) && !isOrderedRow(item)" type="button" size="icon" variant="ghost"
                                                    :aria-label="`Buang ${item.internal_code} dari senarai`" @click="removeRow(item)">
                                                    <X class="size-4" />
                                                </Button>
                                                <Button v-else type="button" size="sm" variant="ghost" @click="undoRestockEdit(item.id)">Batal</Button>
                                            </div>
                                            <p v-if="isOrderedRow(item)" class="mt-1 text-xs font-medium text-success">
                                                Dah Order &mdash; disimpan bila tekan Simpan
                                            </p>
                                            <p v-else class="mt-1 text-xs text-muted-foreground" :title="planHint(item)">
                                                cadangan {{ item.suggested_qty }}
                                                <button v-if="rowQty(item) !== item.suggested_qty && !isRemovedRow(item)" type="button"
                                                    class="ml-1 text-primary underline"
                                                    @click="setRowQty(item, String(item.suggested_qty))">guna</button>
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Best Supplier to Meet - super_admin sahaja buat masa ni -->
                        <section v-if="canSeeSuppliers && (props.restock?.suppliers?.length ?? 0) > 0" class="mt-6 flex flex-col gap-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Best Supplier to Meet <span class="font-normal normal-case">(3 supplier teratas ikut jualan keseluruhan setiap design)</span>
                            </h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                            <th class="py-2 pr-3 font-medium">Kod supplier</th>
                                            <th class="py-2 font-medium">Kod design</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="v in props.restock?.suppliers" :key="v.vendor_code" class="border-b last:border-0">
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
                        <li v-for="e in stagedEntries" :key="e.key" class="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5">
                            <span>
                                <span class="font-medium">{{ e.design }}</span>
                                <Badge variant="secondary" class="mx-2">{{ e.store }}</Badge>
                                {{ e.from }} &rarr; {{ e.to }}
                            </span>
                            <Button type="button" size="icon" variant="ghost" :aria-label="`Buang perubahan ${e.design} ${e.store}`"
                                @click="removeEntry(e.key)">
                                <X class="size-4" />
                            </Button>
                        </li>
                    </ul>
                </section>

                <section v-if="savedLog.length" class="flex flex-col gap-1.5">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Telah disimpan</h3>
                    <ul class="flex max-h-48 flex-col gap-1 overflow-auto">
                        <li v-for="(e, i) in savedLog" :key="`${e.key}-${i}`" class="rounded-md bg-muted/40 px-3 py-1.5 text-muted-foreground">
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
    </div>
</template>
