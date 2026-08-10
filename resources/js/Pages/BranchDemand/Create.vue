<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ClipboardList, Loader2, Paperclip, Pencil, Plus, Search, Store, Trash2, Upload, User, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ImagePreview from '@/components/ImagePreview.vue';
import { Badge } from '@/components/ui/badge';
import { ButtonGroup } from '@/components/ui/button-group';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NumberField, NumberFieldContent, NumberFieldDecrement, NumberFieldIncrement, NumberFieldInput } from '@/components/ui/number-field';
import { SelectNative } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import ProductFilters from './ProductFilters.vue';
import ProductPicker, { type ProductSearchResult, type WebSearchResult } from './ProductPicker.vue';
import RestockSuggestions, { type RestockSuggestion } from './RestockSuggestions.vue';

interface LineItem {
    internal_code: string | null;
    item_desc: string;
    qty_requested: number;
    remark: string;
    current_stock: number | null;
    image_url: string | null;
    size: string | null;
    weight: number | null;
    category_name: string | null;
    // 'web' = cadangan carian laman web merchant9.com; 'upload' = staf langkau carian/dua2
    // carian TIADA hasil, muat naik gambar sendiri (rujuk ProductPicker) - kedua2 TIADA
    // internal_code boleh dipercayai, HQ padankan ke stok sebenar semasa semakan (rujuk
    // ViewBranchDemandRequest).
    source_type: 'catalog' | 'web' | 'upload';
    // Toggle staf cawangan - menentukan fulfillment_status AWAL line di server (rujuk
    // BranchDemandRequestLine::FULFILLMENT_STOK_KRITIKAL), bukan lajur berasingan.
    is_critical: boolean;
}

// Item SEDIA ADA drpd rekod BranchDemandRequest TERKINI cawangan (satu SAHAJA per cawangan,
// kekal selama-lamanya - rujuk App\Models\BranchDemandRequest & currentItems() dokblok) -
// PAPARAN progress SAHAJA (line dah sedia ada di DB), BUKAN staging spt LineItem/form.lines.
interface ExistingLine {
    id: number;
    internal_code: string | null;
    item_desc: string | null;
    source_type: 'catalog' | 'web' | 'upload';
    image_url: string | null;
    qty_requested: number;
    size: string | null;
    weight: number | null;
    category_name: string | null;
    line_status: string;
    fulfillment_status: string;
    fulfillment_label: string;
}

interface StoreOption {
    code: string;
    label: string;
}

interface CategoryOption {
    value: string;
    label: string;
}

// Pengguna log masuk admin panel yg buka borang ni via NavigationItem "Form Items Request
// (Branch)" (rujuk AdminPanelProvider) - session dikongsi walaupun route ni tiada middleware
// 'auth' (staf awam kekal x perlu login, authUser null utk depa). store_code null = leader
// blm ditetapkan HQ ke cawangan mana, pilih sendiri spt staf awam.
interface AuthUser {
    name: string;
    store_code: string | null;
}

const props = defineProps<{
    stores: StoreOption[];
    categories: CategoryOption[];
    initialStoreCode?: string | null;
    authUser?: AuthUser | null;
}>();

const page = usePage<{ flash: { success: string | null } }>();

// Tapisan (jenis emas/julat berat/julat saiz) DIINGAT merentasi lawatan - staf tak perlu
// tanda semula setiap kali nak cari (rujuk keperluan pengguna). Dikongsi antara carian item
// (ProductPicker) & Cadangan Restock (RestockSuggestions) via satu keadaan ni.
const FILTERS_STORAGE_KEY = 'branch-demand-filters';

function loadPersistedFilters() {
    if (typeof window === 'undefined') {
        return { goldTypes: [], weightRanges: [], sizeRanges: [], categoryCodes: [] };
    }

    try {
        const raw = window.localStorage.getItem(FILTERS_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : {};

        return {
            goldTypes: Array.isArray(parsed.goldTypes) ? parsed.goldTypes : [],
            weightRanges: Array.isArray(parsed.weightRanges) ? parsed.weightRanges : [],
            sizeRanges: Array.isArray(parsed.sizeRanges) ? parsed.sizeRanges : [],
            categoryCodes: Array.isArray(parsed.categoryCodes) ? parsed.categoryCodes : [],
        };
    } catch {
        return { goldTypes: [], weightRanges: [], sizeRanges: [], categoryCodes: [] };
    }
}

const persistedFilters = loadPersistedFilters();
const goldTypes = ref<string[]>(persistedFilters.goldTypes);
const weightRanges = ref<string[]>(persistedFilters.weightRanges);
const sizeRanges = ref<string[]>(persistedFilters.sizeRanges);
const categoryCodes = ref<string[]>(persistedFilters.categoryCodes);

watch([goldTypes, weightRanges, sizeRanges, categoryCodes], () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(FILTERS_STORAGE_KEY, JSON.stringify({
        goldTypes: goldTypes.value,
        weightRanges: weightRanges.value,
        sizeRanges: sizeRanges.value,
        categoryCodes: categoryCodes.value,
    }));
}, { deep: true });

const form = useForm({
    // authUser.store_code (leader dah ditetapkan HQ ke cawangan tsb) diutamakan drpd
    // initialStoreCode (query param) - keduanya sepatutnya sepadan lepas redirect submit,
    // tapi store_code rekod User tetap sumber paling dipercayai bila leader log masuk.
    store_code: props.authUser?.store_code ?? props.initialStoreCode ?? '',
    submitted_by_name: props.authUser?.name ?? '',
    notes: '',
    lines: [] as LineItem[],
});

// Leader log masuk yg DAH ada store_code ditetapkan - kunci lajur Cawangan (elak tersalah
// pilih cawangan lain drpd yg ditetapkan HQ pd rekod User). Leader TANPA store_code (blm
// ditetapkan) & staf awam (authUser null) kekal boleh pilih sendiri.
const isStoreLocked = computed(() => Boolean(props.authUser?.store_code));

const confirmOpen = ref(false);
const searchQuery = ref('');

// Item yg sedang dipilih drpd carian, BLM ditambah ke senarai - staf sahkan kuantiti/remark
// dulu (Seksyen 1), lepas klik "Tambah ke Senarai" baru masuk form.lines (Seksyen 2).
const stagedItem = ref<LineItem | null>(null);

const canSubmit = computed(() => Boolean(form.store_code)
    && Boolean(form.submitted_by_name)
    && form.lines.length > 0);

const selectedStoreLabel = computed(() => props.stores.find((s) => s.code === form.store_code)?.label ?? form.store_code);

// Satu SAHAJA rekod BranchDemandRequest per cawangan, kekal selama-lamanya (rujuk
// BranchDemandEntryController::currentItems()/store()) - item SEDIA ADA (dgn progress semasa)
// dimuatkan bila cawangan dipilih, papar terus dlm "Senarai Item" (GANTI RequestList.vue).
const existingLines = ref<ExistingLine[]>([]);
const existingRequestNumber = ref<string | null>(null);

async function fetchCurrentItems(storeCode: string) {
    try {
        const response = await fetch(`/branch-demand/current-items?store_code=${encodeURIComponent(storeCode)}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            existingLines.value = [];
            existingRequestNumber.value = null;

            return;
        }

        const data = await response.json();
        existingLines.value = data.lines ?? [];
        existingRequestNumber.value = data.request_number ?? null;

        // Leader log masuk - nama SENDIRI (rekod User) diutamakan, BUKAN nama hantaran
        // terdahulu (mungkin staf lain drpd cawangan yg sama). Staf awam (authUser null)
        // kekal ikut nama hantaran sebelum ini - cuma bila ADA nilai (elak kosongkan taipan
        // semasa kalau cawangan baharu ditukar tiada sejarah nama lagi).
        if (props.authUser?.name) {
            form.submitted_by_name = props.authUser.name;
        } else if (data.submitted_by_name) {
            form.submitted_by_name = data.submitted_by_name ?? '';
        }
    } catch {
        existingLines.value = [];
        existingRequestNumber.value = null;
    }
}

watch(() => form.store_code, (storeCode) => {
    if (!storeCode) {
        existingLines.value = [];
        existingRequestNumber.value = null;

        return;
    }

    fetchCurrentItems(storeCode);
}, { immediate: true });

// Cerminkan cawangan terpilih pd URL (?store_code=...) - staf boleh refresh/bookmark/kongsi
// pautan tanpa hilang pilihan cawangan, TANPA reload/visit Inertia (cuma tukar address bar).
watch(() => form.store_code, (storeCode) => {
    const url = new URL(window.location.href);

    if (storeCode) {
        url.searchParams.set('store_code', storeCode);
    } else {
        url.searchParams.delete('store_code');
    }

    window.history.replaceState(window.history.state, '', url.toString());
});

// Label butang bezakan hantaran PERTAMA ("Hantar Permintaan") drpd hantaran SETERUSNYA ke
// rekod sedia ada ("Simpan & Hantar") - ditentukan terus drpd kewujudan item sedia ada,
// TIADA panggilan berasingan diperlukan lagi (dulu guna endpoint has-request tersendiri).
const hasExistingRequest = computed(() => existingLines.value.length > 0);
const submitTriggerLabel = computed(() => (hasExistingRequest.value ? 'Simpan & Hantar' : 'Hantar Permintaan'));
const confirmSubmitLabel = computed(() => (hasExistingRequest.value ? 'Simpan & Hantar' : 'Sahkan & Hantar'));

// Peringkat "tamat" - tiada apa2 lagi BO perlu buat (rujuk BranchDemandRequestLine::
// FULFILLMENT_TERMINAL, PHP tetap sumber rujukan - senarai ni cuma salinan nilai string utk
// UI, sama corak dgn source_type 'web'/'upload' yg juga diduplikasi sbg literal di fail ni).
const FULFILLMENT_TERMINAL = ['dah_delivery', 'rearrange', 'item_not_available'];
const isTerminal = (status: string) => FULFILLMENT_TERMINAL.includes(status);

// Warna badge progress - sama corak/warna dgn RequestList.vue (dibuang) & BranchDemandRequestLine::FULFILLMENT_COLORS.
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

// Klik "+" pd item sedia ada yg dah TAMAT (dimmed) - minta semula, terus tambah 1 unit sbg
// LINE BAHARU (line lama KEKAL terpisah sbg sejarah) - sama corak dgn addFromSuggestion() bawah.
function requestAgain(line: ExistingLine) {
    const existing = line.internal_code
        ? form.lines.find((l) => l.internal_code === line.internal_code)
        : undefined;

    if (existing) {
        existing.qty_requested += 1;

        return;
    }

    form.lines.push({
        internal_code: line.internal_code,
        item_desc: line.item_desc ?? '',
        qty_requested: 1,
        remark: '',
        current_stock: null,
        image_url: line.image_url,
        size: line.size,
        weight: line.weight,
        category_name: line.category_name,
        source_type: line.source_type,
        is_critical: false,
    });
}

function onSelect(result: ProductSearchResult) {
    stagedItem.value = {
        internal_code: result.internal_code,
        item_desc: result.description,
        qty_requested: 1,
        remark: '',
        current_stock: result.current_stock,
        image_url: result.image_url,
        size: result.size,
        weight: result.weight,
        category_name: result.category_name || null,
        source_type: 'catalog',
        is_critical: false,
    };
}

function onSelectWeb(result: WebSearchResult) {
    stagedItem.value = {
        internal_code: null,
        item_desc: result.name,
        qty_requested: 1,
        remark: '',
        current_stock: null,
        image_url: result.image_url,
        size: null,
        weight: null,
        category_name: result.category_label,
        source_type: 'web',
        is_critical: false,
    };
}

// Staf langkau carian terus, ATAU carian dalaman+laman web dua2 TIADA hasil (rujuk
// ProductPicker "selectManual") - muat naik gambar sendiri & taip keterangan manual, sama spt
// proses Excel+gambar asal, cuma didigitalkan (rujuk BranchDemandRequestLine::SOURCE_UPLOAD).
function onSelectManual(descriptionHint: string) {
    stagedItem.value = {
        internal_code: null,
        item_desc: descriptionHint,
        qty_requested: 1,
        remark: '',
        current_stock: null,
        image_url: null,
        size: null,
        weight: null,
        category_name: null,
        source_type: 'upload',
        is_critical: false,
    };
}

// Line 'upload' WAJIB ada keterangan & gambar sendiri (rujuk store() validation) - tiada apa2
// lain (kod/carian) utk HQ rujuk kalau dua2 kosong.
const canAddStaged = computed(() => {
    if (!stagedItem.value) {
        return false;
    }

    if (stagedItem.value.source_type === 'upload') {
        return stagedItem.value.item_desc.trim().length > 0 && Boolean(stagedItem.value.image_url);
    }

    return true;
});

function addStagedToList() {
    if (!stagedItem.value || !canAddStaged.value) {
        return;
    }

    // Line 'web'/'upload' TIADA internal_code (sentiasa null) - tak boleh gunakan utk kenal
    // pasti pendua, setiap satu ditambah sbg baris berasingan (HQ padankan masing2 semasa semakan).
    const existing = stagedItem.value.internal_code
        ? form.lines.find((l) => l.internal_code === stagedItem.value?.internal_code)
        : undefined;

    if (existing) {
        existing.qty_requested += stagedItem.value.qty_requested;
        existing.remark = stagedItem.value.remark || existing.remark;
    } else {
        form.lines.push({ ...stagedItem.value });
    }

    stagedItem.value = null;
    searchQuery.value = '';
}

function removeLine(index: number) {
    form.lines.splice(index, 1);

    if (editingIndex.value === index) {
        editingIndex.value = null;
    }
}

// Bnrkan satu line pd satu masa (Keterangan/Saiz/Berat/Remark) - berguna khusus utk line
// 'web' (rujuk onSelectWeb) yg mula tanpa saiz/berat, staf isi sendiri, atau betulkan
// keterangan drpd carian yg kurang tepat.
const editingIndex = ref<number | null>(null);

function toggleEdit(index: number) {
    editingIndex.value = editingIndex.value === index ? null : index;
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

// Staf cawangan muat naik gambar rujukan SENDIRI (tak jumpa item dlm katalog/laman web) -
// disimpan server (rujuk BranchDemandEntryController::uploadImage()), URL yg dikembalikan
// terus jadi image_url line (medan SAMA dgn imej katalog/laman web).
async function uploadImage(file: File): Promise<string | null> {
    const formData = new FormData();
    formData.append('image', file);

    try {
        const response = await fetch('/branch-demand/upload-image', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: formData,
        });

        if (!response.ok) {
            return null;
        }

        const data = await response.json();

        return data.image_url ?? null;
    } catch {
        return null;
    }
}

const uploadingStagedImage = ref(false);
const stagedImageError = ref('');

async function onStagedImagePick(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file || !stagedItem.value) {
        return;
    }

    uploadingStagedImage.value = true;
    stagedImageError.value = '';

    const url = await uploadImage(file);

    if (url) {
        stagedItem.value.image_url = url;
    } else {
        stagedImageError.value = 'Muat naik gagal - cuba imej lain (jpg/png/webp, maks 5MB).';
    }

    uploadingStagedImage.value = false;
    input.value = '';
}

const stagedFileInput = ref<HTMLInputElement | null>(null);
const lineFileInput = ref<HTMLInputElement | null>(null);

const uploadingLineImage = ref(false);
const lineImageError = ref('');

async function onLineImagePick(event: Event, index: number) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    uploadingLineImage.value = true;
    lineImageError.value = '';

    const url = await uploadImage(file);

    if (url) {
        form.lines[index].image_url = url;
    } else {
        lineImageError.value = 'Muat naik gagal - cuba imej lain (jpg/png/webp, maks 5MB).';
    }

    uploadingLineImage.value = false;
    input.value = '';
}

// Cadangan restock (klik "+") - tambah TERUS ke senarai (bukan ke staging Seksyen 1) - kalau
// design tu dah ada dlm senarai, tambah kuantiti sahaja drpd baris berganda.
function addFromSuggestion(item: RestockSuggestion) {
    const existing = form.lines.find((l) => l.internal_code === item.internal_code);

    if (existing) {
        existing.qty_requested += 1;

        return;
    }

    form.lines.push({
        internal_code: item.internal_code,
        item_desc: item.description,
        qty_requested: 1,
        remark: '',
        current_stock: item.current_stock,
        image_url: item.image_url,
        size: item.size,
        weight: item.weight,
        category_name: item.category_name || null,
        source_type: 'catalog',
        is_critical: false,
    });
}

// Bila cawangan ditukar SELEPAS item dah ditambah, "Stok Semasa" yg dipaparkan terpegun pd
// nilai cawangan LAMA - kemaskan semula stok bagi setiap line yg dah ada.
watch(() => form.store_code, async (newCode, oldCode) => {
    if (!newCode || newCode === oldCode) {
        return;
    }

    await Promise.all(form.lines.filter((line) => line.internal_code).map(async (line) => {
        try {
            const params = new URLSearchParams({ q: line.internal_code as string, store_code: newCode });
            const response = await fetch(`/branch-demand/search?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const results: ProductSearchResult[] = await response.json();
            const match = results.find((r) => r.internal_code === line.internal_code);

            if (match) {
                line.current_stock = match.current_stock;
            }
        } catch {
            // Diamkan - paparan stok akan kekal nilai lama jika carian semula gagal, bukan isu kritikal.
        }
    }));
});

function openConfirm() {
    if (canSubmit.value) {
        confirmOpen.value = true;
    }
}

function submit() {
    form.post('/branch-demand', {
        onSuccess: () => {
            const storeCode = form.store_code;
            const picName = form.submitted_by_name;
            form.reset();
            form.store_code = storeCode;
            form.submitted_by_name = picName;
            form.lines = [];
            confirmOpen.value = false;

            // Segerak semula "Item Sedia Ada" terus (item yg baru dihantar tu terus masuk
            // senarai dgn progress "Requested") - staf x perlu refresh browser secara manual.
            if (storeCode) {
                fetchCurrentItems(storeCode);
            }
        },
    });
}
</script>

<template>

    <Head title="Form Permintaan Stok" />

    <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Permintaan Stok Cawangan</h1>
                <p class="text-sm text-muted-foreground">
                    Pilih cawangan anda, kemudian cari item yang diperlukan.
                </p>
            </div>
        </div>

        <div v-if="page.props.flash.success"
            class="rounded-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success-700">
            {{ page.props.flash.success }}
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <form class="flex flex-col gap-4 col-span-2" @submit.prevent="openConfirm">
                <Card>
                    <CardContent class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label class="mb-1.5 block flex items-center gap-2">
                                <Store class="size-4" />
                                Cawangan
                            </Label>
                            <SelectNative v-model="form.store_code" :disabled="isStoreLocked">
                                <option value="" disabled>Pilih cawangan...</option>
                                <option v-for="store in stores" :key="store.code" :value="store.code">
                                    {{ store.label }}
                                </option>
                            </SelectNative>
                            <p v-if="isStoreLocked" class="mt-1 text-xs text-muted-foreground">
                                Cawangan anda ditetapkan HQ - tak boleh ditukar di sini.
                            </p>
                            <p v-if="form.errors.store_code" class="mt-1 text-xs text-destructive">
                                {{ form.errors.store_code }}
                            </p>
                        </div>
                        <div>
                            <Label class="mb-1.5 block flex items-center gap-2">
                                <User class="size-4" />
                                Nama PIC
                            </Label>
                            <Input v-model="form.submitted_by_name" placeholder="Nama anda" autocomplete="off" />
                            <p v-if="form.errors.submitted_by_name" class="mt-1 text-xs text-destructive">
                                {{ form.errors.submitted_by_name }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Seksyen 1: Cari & Tambah Item -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Search class="size-4" />Cari &amp; Tambah Item
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <div class="space-y-4">
                            <ProductFilters :categories="categories" v-model:gold-types="goldTypes"
                                v-model:weight-ranges="weightRanges" v-model:size-ranges="sizeRanges"
                                v-model:category-codes="categoryCodes" />

                            <ProductPicker v-model="searchQuery" :store-code="form.store_code || null"
                                :gold-types="goldTypes" :weight-ranges="weightRanges" :size-ranges="sizeRanges"
                                :category-codes="categoryCodes" @select="onSelect" @select-web="onSelectWeb"
                                @select-manual="onSelectManual" />
                        </div>

                        <div v-if="stagedItem"
                            class="flex flex-col gap-3 rounded-md border border-muted-background border-dashed p-3">
                            <div class="flex items-center justify-between gap-3 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <ImagePreview :src="stagedItem.image_url" :alt="stagedItem.item_desc"
                                        class="size-14" />
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-1.5 font-medium">
                                            <span v-if="stagedItem.internal_code">{{ stagedItem.internal_code }}
                                                -</span>
                                            <span v-if="stagedItem.source_type !== 'upload'">{{ stagedItem.item_desc
                                                }}</span>
                                            <Badge v-if="stagedItem.source_type === 'web'" variant="outline"
                                                class="text-xs">
                                                Laman Web
                                            </Badge>
                                            <Badge v-if="stagedItem.source_type === 'upload'" variant="outline"
                                                class="text-xs">
                                                Gambar Sendiri
                                            </Badge>
                                            <Badge v-if="stagedItem.is_critical" variant="destructive"
                                                class="gap-1 text-xs">
                                                <AlertTriangle class="size-3" /> Kritikal
                                            </Badge>
                                        </p>
                                        <p v-if="stagedItem.current_stock !== null"
                                            class="text-xs text-muted-foreground">
                                            Stok semasa cawangan anda: <span class="font-medium">{{
                                                stagedItem.current_stock
                                                }} unit</span>
                                        </p>
                                        <p v-else class="text-xs text-muted-foreground">
                                            Kod design blm disahkan - HQ akan padankan ke stok sebenar semasa semakan.
                                        </p>
                                    </div>
                                </div>
                                <Button type="button" variant="ghost" size="icon" @click="stagedItem = null">
                                    <X class="size-4" />
                                    <span class="sr-only">Buang item</span>
                                </Button>
                            </div>
                            <div v-if="stagedItem.source_type === 'upload'">
                                <Label class="mb-1.5 block">Keterangan</Label>
                                <Input v-model="stagedItem.item_desc" placeholder="Terangkan item yg diperlukan..." />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label class="mb-1.5 block">Kuantiti</Label>
                                    <Input v-model="stagedItem.qty_requested" type="number" min="1" />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block">Remark (pilihan)</Label>
                                    <Input v-model="stagedItem.remark" placeholder="cth. warna, saiz khas..." />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block">Saiz</Label>
                                    <Input v-model="stagedItem.size" placeholder="cth. 17.5" />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block">Berat (g)</Label>
                                    <Input v-model.number="stagedItem.weight" type="number" step="0.01" min="0" />
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button type="button" :variant="stagedItem.is_critical ? 'destructive' : 'outline'"
                                    size="sm" @click="stagedItem.is_critical = !stagedItem.is_critical">
                                    <AlertTriangle class="size-3.5" />
                                    {{ stagedItem.is_critical ? 'Kritikal' : 'Tanda Kritikal' }}
                                </Button>
                                <Button type="button" variant="outline" size="sm"
                                    v-if="stagedItem.source_type === 'upload'" :disabled="uploadingStagedImage"
                                    @click="stagedFileInput?.click()">
                                    <Loader2 v-if="uploadingStagedImage" class="size-3.5 animate-spin" />
                                    <Upload v-else class="size-3.5" />
                                    Muat Naik Gambar
                                </Button>
                                <input ref="stagedFileInput" type="file" accept="image/png,image/jpeg,image/webp"
                                    class="hidden" @change="onStagedImagePick">
                            </div>
                            <p v-if="stagedImageError" class="text-xs text-destructive">{{ stagedImageError }}</p>
                            <p v-if="stagedItem.source_type === 'upload' && !canAddStaged"
                                class="text-xs text-muted-foreground">
                                Isi keterangan &amp; muat naik gambar dahulu sebelum tambah ke senarai.
                            </p>
                            <Button type="button" :disabled="!canAddStaged" @click="addStagedToList">
                                <Plus class="size-4" /> Tambah ke Senarai
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <!-- Seksyen 2: Senarai Item -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <ClipboardList class="size-4" />Senarai Item ({{ form.lines.length }})
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2">
                        <p v-if="form.lines.length === 0 && existingLines.length === 0" class="text-sm text-muted-foreground">
                            Belum ada item ditambah - cari &amp; tambah item di Seksyen 1, atau guna Cadangan Restock.
                        </p>
                        <p v-if="form.errors.lines" class="text-xs text-destructive">
                            {{ form.errors.lines }}
                        </p>

                        <p v-if="existingLines.length > 0 && form.lines.length > 0"
                            class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Item Sedia Ada{{ existingRequestNumber ? ` (${existingRequestNumber})` : '' }}
                        </p>
                        <div v-for="line in existingLines" :key="`existing-${line.id}`"
                            class="flex items-center gap-3 rounded-xl border p-3"
                            :class="isTerminal(line.fulfillment_status) ? 'opacity-50' : 'bg-muted'">
                            <ImagePreview :src="line.image_url" :alt="line.item_desc" class="size-11 rounded-lg" />
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-1.5 truncate font-medium">
                                    <span v-if="line.internal_code">{{ line.internal_code }} -</span>
                                    {{ line.item_desc }}
                                    <Badge v-if="line.source_type === 'web'" variant="outline" class="text-xs bg-white">
                                        Laman Web
                                    </Badge>
                                    <Badge v-if="line.source_type === 'upload'" variant="outline" class="text-xs bg-white">
                                        Gambar Sendiri
                                    </Badge>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ isTerminal(line.fulfillment_status) ? 'Selesai' : `Diminta: ${line.qty_requested}` }}
                                    &middot;
                                    <span :class="`rounded-full px-1.5 py-0.5 font-medium ${fulfillmentBadgeClass(line.fulfillment_status)}`">
                                        {{ line.fulfillment_label }}
                                    </span>
                                </p>
                            </div>
                            <span class="shrink-0 font-medium">
                                {{ isTerminal(line.fulfillment_status) ? 0 : line.qty_requested }} unit
                            </span>
                            <Button v-if="isTerminal(line.fulfillment_status)" type="button" variant="outline"
                                size="icon" class="shrink-0" @click="requestAgain(line)">
                                <Plus class="size-4" />
                                <span class="sr-only">Minta semula</span>
                            </Button>
                        </div>

                        <p v-if="existingLines.length > 0 && form.lines.length > 0"
                            class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Item Baharu
                        </p>
                        <div v-for="(line, index) in form.lines" :key="index"
                            class="flex flex-col gap-3 bg-muted rounded-xl border p-3">
                            <div class="flex items-center gap-3">
                                <ImagePreview :src="line.image_url" :alt="line.item_desc" class="size-11 rounded-lg" />
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-1.5 truncate font-medium">
                                        <span v-if="line.internal_code">{{ line.internal_code }} -</span>
                                        {{ line.item_desc }}
                                        <Badge v-if="line.source_type === 'web'" variant="outline"
                                            class="text-xs bg-white">
                                            Laman Web
                                        </Badge>
                                        <Badge v-if="line.source_type === 'upload'" variant="outline"
                                            class="text-xs bg-white">
                                            Gambar Sendiri
                                        </Badge>
                                        <Badge v-if="line.is_critical" variant="destructive" class="gap-1 text-xs">
                                            <AlertTriangle class="size-3" /> Kritikal
                                        </Badge>
                                    </p>
                                    <p v-if="line.size || line.weight" class="truncate text-xs text-muted-foreground">
                                        <span v-if="line.size">Saiz {{ line.size }}</span>
                                        <span v-if="line.size && line.weight"> &middot; </span>
                                        <span v-if="line.weight">{{ line.weight }}g</span>
                                    </p>
                                    <p v-if="line.remark" class="truncate text-sm text-muted-foreground">{{ line.remark
                                        }}
                                    </p>
                                </div>
                                <NumberField v-model="line.qty_requested" :min="1" class="w-28 shrink-0 bg-white">
                                    <NumberFieldContent>
                                        <NumberFieldDecrement />
                                        <NumberFieldInput class="h-8" />
                                        <NumberFieldIncrement />
                                    </NumberFieldContent>
                                </NumberField>
                                <div class="flex flex-col items-start gap-8">
                                    <ButtonGroup>
                                        <Button type="button" variant="outline" size="sm" @click="toggleEdit(index)">
                                            <Pencil class="size-3.5" />
                                            <span class="sr-only">Sunting item</span>
                                        </Button>
                                        <Button type="button" variant="destructive" size="sm"
                                            @click="removeLine(index)">
                                            <Trash2 class="size-3" />
                                            <span class="sr-only">Buang</span>
                                        </Button>
                                    </ButtonGroup>
                                </div>
                            </div>

                            <div v-if="editingIndex === index"
                                class="grid gap-3 border-t px-3 py-3 rounded-md sm:grid-cols-2 bg-white">
                                <div class="sm:col-span-2">
                                    <Label class="mb-1.5 block">Keterangan</Label>
                                    <Input v-model="line.item_desc" />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block">Saiz</Label>
                                    <Input v-model="line.size" placeholder="cth. 17.5" />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block">Berat (g)</Label>
                                    <Input v-model.number="line.weight" type="number" step="0.01" min="0" />
                                </div>
                                <div class="sm:col-span-2">
                                    <Label class="mb-1.5 block">Remark (pilihan)</Label>
                                    <Input v-model="line.remark" placeholder="cth. warna, saiz khas..." />
                                </div>
                                <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                                    <Button type="button" :variant="line.is_critical ? 'destructive' : 'outline'"
                                        size="sm" @click="line.is_critical = !line.is_critical">
                                        <AlertTriangle class="size-3.5" />
                                        {{ line.is_critical ? 'Kritikal' : 'Tanda Kritikal' }}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm"
                                        v-if="line.source_type === 'upload'" :disabled="uploadingLineImage"
                                        @click="lineFileInput?.click()">
                                        <Loader2 v-if="uploadingLineImage" class="size-3.5 animate-spin" />
                                        <Upload v-else class="size-3.5" />
                                        Ganti Gambar
                                    </Button>
                                    <input ref="lineFileInput" type="file" accept="image/png,image/jpeg,image/webp"
                                        class="hidden" @change="onLineImagePick($event, index)">
                                </div>
                                <p v-if="lineImageError" class="text-xs text-destructive sm:col-span-2">{{
                                    lineImageError }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Paperclip class="size-4" />Nota
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Textarea v-model="form.notes" placeholder="Nota tambahan (pilihan)" :rows="2" />
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button type="submit" :disabled="!canSubmit || form.processing">
                            {{ submitTriggerLabel }}
                        </Button>
                    </CardFooter>
                </Card>
            </form>

            <div class="lg:sticky lg:top-8 lg:self-start lg:max-w-[600px] overflow-hidden">
                <RestockSuggestions :store-code="form.store_code || null" :gold-types="goldTypes"
                    :weight-ranges="weightRanges" :size-ranges="sizeRanges" :category-codes="categoryCodes"
                    @add="addFromSuggestion" />
            </div>
        </div>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sahkan Permintaan Stok</DialogTitle>
                    <DialogDescription>
                        Sila semak semula sebelum menghantar - permintaan ni akan terus dihantar ke HQ utk semakan.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-3 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <p class="text-muted-foreground">Cawangan</p>
                            <p class="font-medium">{{ selectedStoreLabel }}</p>
                        </div>
                        <div>
                            <p class="text-muted-foreground">Nama PIC</p>
                            <p class="font-medium">{{ form.submitted_by_name }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="mb-1 text-muted-foreground">Item ({{ form.lines.length }})</p>
                        <ul class="flex flex-col gap-1 rounded-md border p-2">
                            <li v-for="(line, i) in form.lines" :key="i" class="flex justify-between gap-2">
                                <span class="min-w-0 truncate">
                                    <template v-if="line.internal_code">{{ line.internal_code }} - </template>{{
                                        line.item_desc }}
                                    <span v-if="line.source_type === 'web'" class="text-xs text-muted-foreground">(Laman
                                        Web)</span>
                                    <span v-if="line.source_type === 'upload'"
                                        class="text-xs text-muted-foreground">(Gambar
                                        Sendiri)</span>
                                </span>
                                <span class="shrink-0 font-medium">{{ line.qty_requested }} unit</span>
                            </li>
                        </ul>
                    </div>

                    <p v-if="form.notes" class="text-muted-foreground">
                        Nota: <span class="text-foreground">{{ form.notes }}</span>
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="outline" :disabled="form.processing" @click="confirmOpen = false">
                        Batal
                    </Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ form.processing ? 'Menghantar...' : confirmSubmitLabel }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
