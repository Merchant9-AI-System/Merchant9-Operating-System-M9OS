<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckIcon, ChevronDown, FileText, Paperclip, Pencil, Plus, Receipt, Trash2, Upload, X } from '@lucide/vue';
import { ListboxContent, ListboxFilter, ListboxItem, ListboxItemIndicator, ListboxRoot, useFilter } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Attachment,
    AttachmentAction,
    AttachmentActions,
    AttachmentContent,
    AttachmentDescription,
    AttachmentMedia,
    AttachmentTitle,
    AttachmentTrigger,
} from '@/components/ui/attachment';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import DialogClose from '@/components/ui/dialog/DialogClose.vue';

interface ClaimLineCharge {
    id: number;
    description: string;
    amount: number;
}

interface ClaimLine {
    id: number;
    expense_date: string;
    description: string;
    category: string;
    amount: number;
    remarks: string | null;
    receipt_url: string | null;
    is_vendor_invoice: boolean;
    vendor: string | null;
    invoice_number: string | null;
    charges: ClaimLineCharge[];
    wht: string | null;
}

interface Claim {
    id: number;
    claim_number: string;
    claimant_name: string;
    designation: string | null;
    claimant_role_label: string | null;
    created_by_name: string | null;
    claim_month: string;
    status: 'Draft' | 'Submitted' | 'Approved' | 'Rejected';
    total_amount: number;
    rejection_reason: string | null;
    notes: string | null;
    lines: ClaimLine[];
}

const props = defineProps<{
    claim: Claim;
    // Cadangan tags-input SAHAJA (rujuk ExpenseClaimLine::DEFAULT_CATEGORIES) - `category` kini
    // free text, staf boleh cipta kategori baharu sendiri, bukan enum tertutup.
    categories: string[];
}>();

const isEditable = computed(() => props.claim.status === 'Draft' || props.claim.status === 'Rejected');

const STATUS_BADGE_CLASS: Record<string, string> = {
    Draft: 'border-transparent bg-muted text-muted-foreground',
    Submitted: 'border-transparent bg-warning text-warning-foreground',
    Approved: 'border-transparent bg-emerald-600 text-white',
    Rejected: 'border-transparent bg-destructive text-white',
};

function formatMonth(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('ms-MY', { month: 'long', year: 'numeric' });
}

function money(amount: number): string {
    return `RM ${amount.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// --- Maklumat (designation & notes) ---
const detailsForm = useForm({
    designation: props.claim.designation ?? '',
    notes: props.claim.notes ?? '',
});

function saveDetails() {
    detailsForm.put(`/claims/${props.claim.id}/details`, { preserveScroll: true });
}

// Auto-isi "Jawatan" drpd role SISTEM claimant (cth. role 'ceo' -> "CEO") bila medan ni MASIH
// KOSONG (rujuk ExpenseClaim::claimantRoleLabel()) - cuma cadangan awal, staf Finance tetap
// boleh timpa terus dgn jawatan sebenar (medan bukan read-only). Terus simpan (bukan tunggu
// blur) supaya claim yg baru dicipta terus ada jawatan tanpa staf perlu klik apa2 dulu; lepas
// designation diisi (kekal di DB), syarat ni takkan trigger lagi pd visit akan datang.
if (!detailsForm.designation && props.claim.claimant_role_label && isEditable.value) {
    detailsForm.designation = props.claim.claimant_role_label;
    saveDetails();
}

// --- Subtotal per kategori - kategori kini free text, jadi kumpulan dikira DINAMIK drpd
// kategori SEDIA ADA dlm lines claim ni sahaja (bukan senarai tetap props.categories, yg
// sekadar cadangan borang, bukan taksonomi lengkap). ---
const categoryTotals = computed(() => {
    const totals: Record<string, number> = {};
    for (const line of props.claim.lines) {
        totals[line.category] = (totals[line.category] ?? 0) + Number(line.amount);
    }
    return totals;
});

// --- Tambah item ---
// Toggle "Ada Invois Vendor?" - item macam Google/Facebook/TikTok Ads ada invois formal dgn
// byk baris amount (cth. invois Google Ads: Amount + Service Tax 8% + Turkey Regulatory
// Operating Cost + India Regulatory Operating Cost + Service Tax atas caj tsb - 5 baris).
// "charges" ialah senarai BEBAS (description bebas taip + amount) staf boleh tambah/padam
// ikut berapa banyak baris yg ADA di invois sebenar - "amount" (jumlah line) dikira SERVER-SIDE
// drpd jumlah semua charges (rujuk ExpenseClaimController::validateLine()), bukan medan tetap.
const lineForm = useForm({
    expense_date: new Date().toISOString().slice(0, 10),
    description: '',
    category: '',
    amount: '',
    remarks: '',
    receipt_url: '',
    is_vendor_invoice: false,
    vendor: '',
    invoice_number: '',
    charges: [] as { description: string; amount: string }[],
    wht: '',
});

const vendorInvoiceTotal = computed(() => lineForm.charges.reduce((sum, c) => sum + (Number(c.amount) || 0), 0));

// TagsInput (reka-ui) sentiasa array (v-model modelValue: string[]) - line ni cuma 1 kategori
// (lajur `category` DB tunggal), jadi wrapper ni PAKSA 1 tag sahaja: bila TagsInput tambah tag
// baharu (array jadi 2 - lama+baharu), setter ambil yg TERAKHIR & buang yg lama (ganti, bukan
// tambah) - padam (X) hantar array kosong terus, category jadi '' semula.
const categoryTags = computed<string[]>({
    get: () => (lineForm.category ? [lineForm.category] : []),
    set: (tags) => {
        lineForm.category = tags.length > 0 ? tags[tags.length - 1] : '';
    },
});

// --- Listbox cadangan kategori (combobox) - gantikan badge cadangan statik dgn dropdown
// filter-boleh-cari, tapi kekalkan "free text" (staf taip kategori baharu terus bila tiada
// padanan dlm cadangan, rujuk komen categoryTags di atas). ---
const categorySearch = ref('');
const categoryListOpen = ref(false);
const { contains } = useFilter({ sensitivity: 'base' });

const filteredCategories = computed(() =>
    categorySearch.value === '' ? props.categories : props.categories.filter((c) => contains(c, categorySearch.value)),
);

watch(categorySearch, (term) => {
    if (term) {
        categoryListOpen.value = true;
    }
});

function selectCategory(value: string) {
    categoryTags.value = [value];
    categorySearch.value = '';
    categoryListOpen.value = false;
}

function commitFreeTextCategory() {
    const typed = categorySearch.value.trim();
    if (!typed || filteredCategories.value.length > 0) {
        return;
    }
    categoryTags.value = [typed];
    categorySearch.value = '';
    categoryListOpen.value = false;
}

// Cadangan kategori drpd keyword dlm description/vendor - versi ringkas mekanisme Wise/Finny
// (MCC/rules-based, bukan ML) - padanan kata kunci umum vendor Malaysia. Cuma isi bila kategori
// MASIH KOSONG (elak timpa pilihan staf sendiri kalau dah pilih/taip manual).
const CATEGORY_KEYWORD_RULES: { keywords: string[]; category: string }[] = [
    { keywords: ['petronas', 'shell', 'bhpetrol', 'petron', 'caltex', 'petrol'], category: 'Petrol' },
    { keywords: ['toll', 'plus', 'touch n go', 'tng', 'parking', 'letak kereta'], category: 'Toll & Parking' },
    { keywords: ['stationery', 'pen', 'paper', 'printer ink'], category: 'Stationery' },
    { keywords: ['bengkel', 'workshop', 'tayar', 'tire', 'car wash', 'servis kereta'], category: 'Car Maintenance' },
    { keywords: ['klinik', 'clinic', 'hospital', 'farmasi', 'pharmacy'], category: 'Medical' },
    { keywords: ['cafe', 'kopitiam', 'restaurant', 'makan', 'coffee'], category: 'Entertainment / Refreshment' },
    { keywords: ['lazada', 'shopee', 'amazon'], category: 'Shop Expenses' },
    { keywords: ['google ads', 'facebook ads', 'tiktok ads', 'fb ads', 'meta ads'], category: 'Digital Marketing / Ads' },
];

function suggestCategory(text: string): string | null {
    const lower = text.toLowerCase();
    return CATEGORY_KEYWORD_RULES.find(({ keywords }) => keywords.some((k) => lower.includes(k)))?.category ?? null;
}

watch([() => lineForm.description, () => lineForm.vendor], ([description, vendor]) => {
    if (lineForm.category) {
        return;
    }
    const suggestion = suggestCategory(description) ?? (vendor ? suggestCategory(vendor) : null);
    if (suggestion) {
        lineForm.category = suggestion;
    }
});

function toggleVendorInvoice(value: boolean) {
    lineForm.is_vendor_invoice = value;
    // Bantu staf mula - 1 baris kosong terus muncul bila toggle ON (senang terus taip, bukan
    // perlu klik "+ Tambah Caj" dulu utk baris pertama). Kosongkan balik bila toggle OFF supaya
    // tak hantar caj basi kalau staf tukar fikiran & guna medan "Jumlah" biasa sebaliknya.
    lineForm.charges = value ? [{ description: 'Amount', amount: '' }] : [];
}

function addCharge() {
    lineForm.charges.push({ description: '', amount: '' });
}

function addChargeTax() {
    lineForm.charges.push({ description: 'Tax', amount: '' });
}

function removeCharge(index: number) {
    lineForm.charges.splice(index, 1);
}

const uploadingReceipt = ref(false);
const receiptFileName = ref<string | null>(null);
const receiptFileSize = ref<number | null>(null);

async function handleReceiptUpload(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) {
        return;
    }

    uploadingReceipt.value = true;
    const formData = new FormData();
    formData.append('receipt', file);

    try {
        const response = await fetch('/claims/upload-receipt', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
            body: formData,
        });
        const data = await response.json();
        lineForm.receipt_url = data.receipt_url;
        receiptFileName.value = file.name;
        receiptFileSize.value = file.size;
    } finally {
        uploadingReceipt.value = false;
        // Kosongkan value input fail - tanpa ni, pilih fail SAMA (nama+path) 2x berturut tak
        // trigger event "change" kali kedua (browser anggap value tak berubah).
        (event.target as HTMLInputElement).value = '';
    }
}

function clearReceipt() {
    lineForm.receipt_url = '';
    receiptFileName.value = null;
    receiptFileSize.value = null;
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// Nama fail utk papar - fail baru dimuat naik SESI NI (receiptFileName) ATAU, bila edit line
// sedia ada, ambil drpd hujung URL storan (tiada nama asal fail disimpan di DB, cuma path).
const receiptDisplayName = computed<string | null>(() => {
    if (receiptFileName.value) {
        return receiptFileName.value;
    }
    if (!lineForm.receipt_url) {
        return null;
    }
    try {
        return decodeURIComponent(new URL(lineForm.receipt_url).pathname.split('/').pop() ?? 'Resit');
    } catch {
        return 'Resit';
    }
});

const receiptIsImage = computed(() => {
    const ext = receiptDisplayName.value?.split('.').pop()?.toLowerCase() ?? '';
    return IMAGE_EXTENSIONS.includes(ext);
});

const receiptDescription = computed(() => {
    if (uploadingReceipt.value) {
        return 'Memuat naik...';
    }
    if (receiptFileSize.value) {
        return formatFileSize(receiptFileSize.value);
    }
    if (lineForm.receipt_url) {
        return 'Resit sedia ada';
    }
    return 'JPG, PNG atau PDF, maks 10MB';
});

// Dialog "Tambah Item" / "Edit Item" (rujuk butang di header Senarai Item & butang pensel setiap
// baris) - satu dialog dikongsi utk cipta & kemaskini, buka/tutup diuruskan di sini (bukan
// DialogTrigger) supaya butang2 tu boleh diletak berasingan drpd DialogContent dlm template.
// `editingLineId` null = mod cipta baharu, ada nilai = mod edit line sedia ada.
const addItemOpen = ref(false);
const editingLineId = ref<number | null>(null);

function resetLineForm() {
    lineForm.reset();
    lineForm.clearErrors();
    lineForm.expense_date = new Date().toISOString().slice(0, 10);
    lineForm.charges = [];
    receiptFileName.value = null;
    receiptFileSize.value = null;
}

function openCreateDialog() {
    editingLineId.value = null;
    resetLineForm();
    addItemOpen.value = true;
}

function openEditDialog(line: ClaimLine) {
    editingLineId.value = line.id;
    lineForm.clearErrors();
    lineForm.expense_date = line.expense_date;
    lineForm.description = line.description;
    lineForm.category = line.category;
    lineForm.amount = String(line.amount);
    lineForm.remarks = line.remarks ?? '';
    lineForm.receipt_url = line.receipt_url ?? '';
    lineForm.is_vendor_invoice = line.is_vendor_invoice;
    lineForm.vendor = line.vendor ?? '';
    lineForm.invoice_number = line.invoice_number ?? '';
    lineForm.charges = line.charges.map((c) => ({ description: c.description, amount: String(c.amount) }));
    lineForm.wht = line.wht ?? '';
    receiptFileName.value = null;
    receiptFileSize.value = null;
    addItemOpen.value = true;
}

function submitLine() {
    if (editingLineId.value) {
        lineForm.put(`/claims/lines/${editingLineId.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                addItemOpen.value = false;
            },
        });
        return;
    }

    lineForm.post(`/claims/${props.claim.id}/lines`, {
        preserveScroll: true,
        onSuccess: () => {
            resetLineForm();
            addItemOpen.value = false;
        },
    });
}

function deleteLine(lineId: number) {
    router.delete(`/claims/lines/${lineId}`, { preserveScroll: true });
}

// --- Hantar claim ---
const submitting = ref(false);

function submitClaim() {
    submitting.value = true;
    router.post(`/claims/${props.claim.id}/submit`, {}, {
        onFinish: () => {
            submitting.value = false;
        },
    });
}
</script>

<template>

    <Head title="Expense Claim" />

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-8">
        <Link href="/claims" class="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft class="size-3.5" />Senarai claim
        </Link>

        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Claim untuk {{ claim.claimant_name }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ claim.claim_number }} &middot; {{ formatMonth(claim.claim_month) }}
                    <span v-if="claim.created_by_name"> &middot; dicipta oleh {{ claim.created_by_name }}</span>
                </p>
            </div>
            <Badge :class="STATUS_BADGE_CLASS[claim.status]">{{ claim.status }}</Badge>
        </div>

        <div v-if="claim.status === 'Rejected' && claim.rejection_reason"
            class="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            <p class="font-medium">Claim ditolak - sila kemaskini & hantar semula:</p>
            <p>{{ claim.rejection_reason }}</p>
        </div>

        <!-- Maklumat -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Maklumat</CardTitle>
                <CardDescription>Senarai maklumat claim </CardDescription>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-3">
                <div>
                    <Label for="designation">Jawatan {{ claim.claimant_name }}</Label>
                    <Input id="designation" v-model="detailsForm.designation" :disabled="!isEditable"
                        placeholder="cth. Managing Director" @blur="saveDetails" />
                </div>
                <div>
                    <Label for="notes">Nota (optional)</Label>
                    <Input id="notes" v-model="detailsForm.notes" :disabled="!isEditable" @blur="saveDetails" />
                </div>
            </CardContent>
        </Card>

        <!-- Dialog "Tambah Item" - dipicu drpd butang di header Senarai Item (rujuk bawah). -->
        <Dialog v-model:open="addItemOpen">
            <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editingLineId ? 'Edit Item' : 'Tambah Item' }}</DialogTitle>
                </DialogHeader>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Label for="expense_date">Tarikh</Label>
                        <DatePicker id="expense_date" v-model="lineForm.expense_date" />
                        <p v-if="lineForm.errors.expense_date" class="mt-1 text-xs text-destructive">{{
                            lineForm.errors.expense_date }}</p>
                    </div>
                    <div>
                        <Label for="description">Keterangan</Label>
                        <Input id="description" v-model="lineForm.description"
                            placeholder="cth. Google Ads, Petrol Petronas, dll." />
                        <p v-if="lineForm.errors.description" class="mt-1 text-xs text-destructive">{{
                            lineForm.errors.description }}</p>
                    </div>
                    <div class="col-span-2">
                        <Label for="category">Kategori</Label>
                        <Popover v-model:open="categoryListOpen">
                            <ListboxRoot :model-value="categoryTags" multiple highlight-on-hover>
                                <PopoverAnchor class="block w-full">
                                    <TagsInput v-model="categoryTags">
                                        <TagsInputItem v-for="tag in categoryTags" :key="tag" :value="tag">
                                            <TagsInputItemText />
                                            <TagsInputItemDelete />
                                        </TagsInputItem>

                                        <ListboxFilter v-model="categorySearch" as-child>
                                            <TagsInputInput id="category" placeholder="Cari / taip kategori baharu..."
                                                @focus="categoryListOpen = true" @keydown.down="categoryListOpen = true"
                                                @keydown.enter.prevent="commitFreeTextCategory" />
                                        </ListboxFilter>

                                        <PopoverTrigger as-child>
                                            <Button type="button" variant="ghost" size="icon"
                                                class="order-last ml-auto size-6 shrink-0">
                                                <ChevronDown class="size-3.5 text-muted-foreground" />
                                            </Button>
                                        </PopoverTrigger>
                                    </TagsInput>
                                </PopoverAnchor>

                                <PopoverContent class="w-(--reka-popover-trigger-width) p-1" align="start"
                                    @open-auto-focus.prevent>
                                    <ListboxContent class="max-h-56 overflow-y-auto empty:hidden" tabindex="0">
                                        <ListboxItem v-for="item in filteredCategories" :key="item" :value="item"
                                            class="relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none select-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                                            @select="selectCategory(item)">
                                            <span>{{ item }}</span>
                                            <ListboxItemIndicator
                                                class="ml-auto inline-flex items-center justify-center">
                                                <CheckIcon class="size-3.5" />
                                            </ListboxItemIndicator>
                                        </ListboxItem>
                                    </ListboxContent>
                                    <p v-if="filteredCategories.length === 0"
                                        class="px-2 py-1.5 text-xs text-muted-foreground">
                                        Tiada cadangan sepadan - tekan Enter utk cipta "{{ categorySearch }}"
                                    </p>
                                </PopoverContent>
                            </ListboxRoot>
                        </Popover>
                        <p v-if="lineForm.errors.category" class="mt-1 text-xs text-destructive">{{
                            lineForm.errors.category }}</p>
                    </div>

                    <div class="col-span-2 flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-2">
                        <Switch id="is_vendor_invoice" :model-value="lineForm.is_vendor_invoice"
                            @update:model-value="toggleVendorInvoice" />
                        <Label for="is_vendor_invoice" class="flex cursor-pointer items-center gap-1.5 font-normal">
                            <Receipt class="size-3.5 text-muted-foreground" />
                            Ada Invois Vendor? (cth. Google/Facebook/TikTok Ads - ada byk baris caj)
                        </Label>
                    </div>

                    <template v-if="!lineForm.is_vendor_invoice">
                        <div class="col-span-2">
                            <Label for="amount">Jumlah (RM)</Label>
                            <Input id="amount" v-model="lineForm.amount" type="number" step="0.01" min="0"
                                placeholder="0.00" />
                            <p v-if="lineForm.errors.amount" class="mt-1 text-xs text-destructive">{{
                                lineForm.errors.amount }}</p>
                        </div>
                    </template>

                    <template v-else>
                        <div>
                            <Label for="vendor">Vendor</Label>
                            <Input id="vendor" v-model="lineForm.vendor" placeholder="cth. Google Ads" />
                            <p v-if="lineForm.errors.vendor" class="mt-1 text-xs text-destructive">{{
                                lineForm.errors.vendor }}</p>
                        </div>
                        <div>
                            <Label for="invoice_number">No. Invois (optional)</Label>
                            <Input id="invoice_number" v-model="lineForm.invoice_number"
                                placeholder="cth. INV-2026-0042" />
                        </div>

                        <!-- Senarai caj bebas (description + amount setiap baris) - staf tambah ikut
                                 berapa banyak baris di invois sebenar (Amount, Service Tax, Regulatory
                                 Cost, dll.), bukan medan tetap. -->
                        <div class="col-span-2 flex flex-col gap-2 rounded-md border p-3">
                            <div class="flex items-center justify-between">
                                <Label class="text-xs text-muted-foreground">Caj (ikut baris invois sebenar)</Label>
                                <div class="flex items-center gap-2">
                                    <Button type="button" variant="secondary" size="sm" class="gap-1"
                                        @click="addChargeTax">
                                        <Plus class="size-3.5" />Tambah Tax
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" class="gap-1" @click="addCharge">
                                        <Plus class="size-3.5" />Tambah Caj
                                    </Button>
                                </div>
                            </div>
                            <p v-if="lineForm.errors.charges" class="text-xs text-destructive">{{
                                lineForm.errors.charges }}</p>
                            <div v-for="(charge, index) in lineForm.charges" :key="index"
                                class="flex items-center gap-2">
                                <Input v-model="charge.description"
                                    placeholder="cth. Amount, Service Tax (8%), Regulatory Cost" class="flex-1" />
                                <Input v-model="charge.amount" type="number" step="0.01" min="0" placeholder="0.00"
                                    class="w-32" />
                                <Button type="button" variant="ghost" size="icon" class="shrink-0 text-destructive"
                                    @click="removeCharge(index)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <p v-if="lineForm.charges.length === 0" class="text-xs text-muted-foreground">Belum ada caj
                                - klik "Tambah Caj".</p>
                        </div>

                        <div class="col-span-2 flex justify-between rounded-md border bg-muted/40 px-3 py-2 text-sm">
                            <span class="text-muted-foreground">Jumlah Keseluruhan (auto)</span>
                            <span class="font-semibold tabular-nums">{{ money(vendorInvoiceTotal) }}</span>
                        </div>

                        <div class="col-span-2">
                            <Label for="wht">Subjet to WHT (optional)</Label>
                            <ToggleGroup type="single" v-model="lineForm.wht" variant="outline" size="sm"
                                class="w-full">
                                <ToggleGroupItem value="8" v-model="lineForm.wht"
                                    class="flex-1 shrink justify-center gap-2">
                                    8%
                                </ToggleGroupItem>
                                <ToggleGroupItem value="10" v-model="lineForm.wht"
                                    class="flex-1 shrink justify-center gap-2">
                                    10%
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    </template>

                    <div class="col-span-2">
                        <Label for="remarks">Catatan (optional)</Label>
                        <Textarea id="remarks" v-model="lineForm.remarks" placeholder="Masukkan catatan jika ada..."
                            :rows="2" />
                    </div>
                    <div class="col-span-2">
                        <Label>Resit / Invois (optional)</Label>
                        <Attachment :state="uploadingReceipt ? 'uploading' : lineForm.receipt_url ? 'done' : 'idle'"
                            class="w-full">
                            <AttachmentMedia :variant="receiptIsImage && lineForm.receipt_url ? 'image' : 'icon'">
                                <Spinner v-if="uploadingReceipt" />
                                <img v-else-if="receiptIsImage && lineForm.receipt_url" :src="lineForm.receipt_url"
                                    :alt="receiptDisplayName ?? 'Resit'">
                                <Upload v-else-if="!lineForm.receipt_url" />
                                <FileText v-else />
                            </AttachmentMedia>
                            <AttachmentContent>
                                <AttachmentTitle>{{ receiptDisplayName ?? 'Klik utk muat naik resit / invois' }}
                                </AttachmentTitle>
                                <AttachmentDescription>{{ receiptDescription }}</AttachmentDescription>
                            </AttachmentContent>
                            <AttachmentActions>
                                <AttachmentAction v-if="lineForm.receipt_url" size="icon"
                                    aria-label="Lihat resit" >
                                    <a :href="lineForm.receipt_url" target="_blank">
                                        <Paperclip />
                                    </a>
                                </AttachmentAction>
                                <AttachmentAction v-if="lineForm.receipt_url && !uploadingReceipt" size="icon"
                                    aria-label="Buang resit" @click="clearReceipt">
                                    <X />
                                </AttachmentAction>
                            </AttachmentActions>
                            <AttachmentTrigger v-if="!uploadingReceipt" as="label"
                                :aria-label="lineForm.receipt_url ? 'Ganti resit / invois' : 'Muat naik resit / invois'">
                                <input type="file" accept="image/*,application/pdf" class="hidden"
                                    @change="handleReceiptUpload">
                            </AttachmentTrigger>
                        </Attachment>
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose>
                        <Button variant="outline" :disabled="lineForm.processing">
                            Tutup
                        </Button>
                    </DialogClose>
                    <Button type="button" class="gap-1.5" :disabled="lineForm.processing" @click="submitLine">
                        <Spinner v-if="lineForm.processing" class="size-4" />
                        <Plus v-else class="size-4" />{{ editingLineId ? 'Simpan Perubahan' : 'Tambah Item' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Senarai item -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Senarai Item</CardTitle>
                <CardDescription>Senarai item yang telah ditambah {{ claim.lines.length }}.</CardDescription>
                <CardAction>
                    <Button v-if="isEditable" type="button" size="sm" class="gap-1.5" @click="openCreateDialog()">
                        <Plus class="size-3.5" />Tambah
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <p v-if="claim.lines.length === 0" class="text-sm text-muted-foreground">Belum ada item ditambah.</p>
                <div v-for="line in claim.lines" :key="line.id"
                    class="flex items-start justify-between gap-3 rounded-md border p-3">
                    <div class="flex flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <span class="font-bold">{{ line.description }}</span>
                            <Badge variant="secondary" class=" text-xs">{{ line.category }}</Badge>
                            <Badge v-if="line.is_vendor_invoice"
                                class="gap-1 border-transparent bg-blue-500 text-white text-xs">
                                <Receipt class="size-3" />{{ line.vendor }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ new Date(line.expense_date).toLocaleDateString('ms-MY') }}
                            <!-- <span v-if="line.invoice_number"> &middot; No. Invois: {{ line.invoice_number }}</span>
                            <span v-if="line.remarks"> &middot; {{ line.remarks }}</span> -->
                        </p>
                        <ul class="ml-4 list-disc text-xs text-muted-foreground">
                            <li v-if="line.invoice_number">No. Invois: {{ line.invoice_number }}</li>
                            <li v-if="line.remarks">Catatan: {{ line.remarks }}</li>
                        </ul>
                        <ul v-if="line.is_vendor_invoice && line.charges.length > 0"
                            class="ml-4 list-disc text-xs text-muted-foreground">
                            <li v-for="charge in line.charges" :key="charge.id">{{ charge.description }}: {{
                                money(charge.amount) }}</li>
                        </ul>
                        <a v-if="line.receipt_url" :href="line.receipt_url" target="_blank"
                            class="flex items-center gap-1 text-xs text-primary hover:underline">
                            <Paperclip class="size-3" />Lihat resit/invois
                        </a>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-medium tabular-nums">{{ money(line.amount) }}</span>
                        <ButtonGroup v-if="isEditable">
                            <Button type="button" variant="outline" size="icon" @click="openEditDialog(line)">
                                <Pencil class="size-4" />
                            </Button>
                            <AlertDialog>
                                <AlertDialogTrigger as-child>
                                    <Button type="button" variant="outline" size="icon" class="text-destructive">
                                        <Trash2 class="size-4" />
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>Padam item ini?</AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Tindakan ini tidak boleh diundur. "{{ line.description }}" ({{
                                            money(line.amount)
                                            }})
                                            akan dipadam secara kekal drpd claim ni.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>Batal</AlertDialogCancel>
                                        <AlertDialogAction
                                            class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                            @click="deleteLine(line.id)">
                                            Padam
                                        </AlertDialogAction>
                                    </AlertDialogFooter>
                                </AlertDialogContent>
                            </AlertDialog>
                        </ButtonGroup>
                    </div>
                </div>

                <div v-if="claim.lines.length > 0" class="mt-2 flex flex-col gap-1 border-t pt-3 text-sm">
                    <div v-for="(total, key) in categoryTotals" :key="key" v-show="total > 0"
                        class="flex justify-between text-muted-foreground">
                        <span>{{ key }}</span>
                        <span class="tabular-nums">{{ money(total) }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-1 text-base font-semibold">
                        <span>Jumlah Keseluruhan</span>
                        <span class="tabular-nums">{{ money(claim.total_amount) }}</span>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Button v-if="isEditable" type="button" size="lg" :disabled="submitting || claim.lines.length === 0"
            @click="submitClaim">
            <Spinner v-if="submitting" class="size-4" />
            Hantar Claim
        </Button>
        <p v-else class="text-sm text-muted-foreground">
            Claim ini sudah {{ claim.status === 'Submitted' ? 'dihantar, menunggu kelulusan Head of Finance' :
                'diluluskan' }} -
            tidak boleh diedit.
        </p>
    </div>
</template>
