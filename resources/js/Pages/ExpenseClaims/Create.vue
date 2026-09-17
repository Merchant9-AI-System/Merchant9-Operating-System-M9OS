<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Loader2, Paperclip, Plus, Receipt, Trash2, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckboxNative } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';

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
}

interface Claim {
    id: number;
    claim_number: string;
    claimant_name: string;
    designation: string | null;
    claim_month: string;
    status: 'Draft' | 'Submitted' | 'Approved' | 'Rejected';
    total_amount: number;
    rejection_reason: string | null;
    notes: string | null;
    lines: ClaimLine[];
}

interface RecentClaim {
    id: number;
    claim_number: string;
    claim_month: string;
    status: string;
    total_amount: number;
}

const props = defineProps<{
    claim: Claim;
    // Cadangan tags-input SAHAJA (rujuk ExpenseClaimLine::DEFAULT_CATEGORIES) - `category` kini
    // free text, staf boleh cipta kategori baharu sendiri, bukan enum tertutup.
    categories: string[];
    recentClaims: RecentClaim[];
}>();

const page = usePage<{ flash: { success: string | null } }>();

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
    detailsForm.put('/claims/details', { preserveScroll: true });
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
    lineForm.charges = value ? [{ description: '', amount: '' }] : [];
}

function addCharge() {
    lineForm.charges.push({ description: '', amount: '' });
}

function removeCharge(index: number) {
    lineForm.charges.splice(index, 1);
}

const uploadingReceipt = ref(false);
const receiptFileName = ref<string | null>(null);

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
    } finally {
        uploadingReceipt.value = false;
    }
}

function addLine() {
    lineForm.post('/claims/lines', {
        preserveScroll: true,
        onSuccess: () => {
            lineForm.reset('description', 'category', 'amount', 'remarks', 'receipt_url', 'is_vendor_invoice', 'vendor', 'invoice_number');
            lineForm.charges = [];
            receiptFileName.value = null;
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
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Expense Claim - {{ formatMonth(claim.claim_month) }}</h1>
                <p class="text-sm text-muted-foreground">{{ claim.claim_number }} &middot; {{ claim.claimant_name }}</p>
            </div>
            <Badge :class="STATUS_BADGE_CLASS[claim.status]">{{ claim.status }}</Badge>
        </div>

        <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ page.props.flash.success }}
        </div>

        <div v-if="claim.status === 'Rejected' && claim.rejection_reason" class="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            <p class="font-medium">Claim ditolak - sila kemaskini & hantar semula:</p>
            <p>{{ claim.rejection_reason }}</p>
        </div>

        <!-- Maklumat -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Maklumat</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <div>
                    <Label for="designation">Jawatan</Label>
                    <Input id="designation" v-model="detailsForm.designation" :disabled="!isEditable" placeholder="cth. Sales Executive" @blur="saveDetails" />
                </div>
                <div>
                    <Label for="notes">Nota (optional)</Label>
                    <Textarea id="notes" v-model="detailsForm.notes" :disabled="!isEditable" rows="2" @blur="saveDetails" />
                </div>
            </CardContent>
        </Card>

        <!-- Tambah item -->
        <Card v-if="isEditable">
            <CardHeader>
                <CardTitle class="text-base">Tambah Item</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Label for="expense_date">Tarikh</Label>
                        <Input id="expense_date" v-model="lineForm.expense_date" type="date" />
                        <p v-if="lineForm.errors.expense_date" class="mt-1 text-xs text-destructive">{{ lineForm.errors.expense_date }}</p>
                    </div>
                    <div>
                        <Label for="category">Kategori</Label>
                        <TagsInput id="category" v-model="categoryTags">
                            <TagsInputItem v-for="tag in categoryTags" :key="tag" :value="tag">
                                <TagsInputItemText />
                                <TagsInputItemDelete />
                            </TagsInputItem>
                            <TagsInputInput placeholder="Taip utk cipta kategori baharu..." />
                        </TagsInput>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <button
                                v-for="suggestion in categories"
                                :key="suggestion"
                                type="button"
                                class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                @click="categoryTags = [suggestion]"
                            >
                                {{ suggestion }}
                            </button>
                        </div>
                        <p v-if="lineForm.errors.category" class="mt-1 text-xs text-destructive">{{ lineForm.errors.category }}</p>
                    </div>
                    <div class="col-span-2">
                        <Label for="description">Keterangan</Label>
                        <Input id="description" v-model="lineForm.description" placeholder="cth. Google Ads, Petrol Petronas, dll." />
                        <p v-if="lineForm.errors.description" class="mt-1 text-xs text-destructive">{{ lineForm.errors.description }}</p>
                    </div>

                    <div class="col-span-2 flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-2">
                        <CheckboxNative id="is_vendor_invoice" :model-value="lineForm.is_vendor_invoice" @update:model-value="toggleVendorInvoice" />
                        <Label for="is_vendor_invoice" class="flex cursor-pointer items-center gap-1.5 font-normal">
                            <Receipt class="size-3.5 text-muted-foreground" />
                            Ada Invois Vendor? (cth. Google/Facebook/TikTok Ads - ada byk baris caj)
                        </Label>
                    </div>

                    <template v-if="!lineForm.is_vendor_invoice">
                        <div>
                            <Label for="amount">Jumlah (RM)</Label>
                            <Input id="amount" v-model="lineForm.amount" type="number" step="0.01" min="0" placeholder="0.00" />
                            <p v-if="lineForm.errors.amount" class="mt-1 text-xs text-destructive">{{ lineForm.errors.amount }}</p>
                        </div>
                    </template>

                    <template v-else>
                        <div>
                            <Label for="vendor">Vendor</Label>
                            <Input id="vendor" v-model="lineForm.vendor" placeholder="cth. Google Ads" />
                            <p v-if="lineForm.errors.vendor" class="mt-1 text-xs text-destructive">{{ lineForm.errors.vendor }}</p>
                        </div>
                        <div>
                            <Label for="invoice_number">No. Invois (optional)</Label>
                            <Input id="invoice_number" v-model="lineForm.invoice_number" placeholder="cth. INV-2026-0042" />
                        </div>

                        <!-- Senarai caj bebas (description + amount setiap baris) - staf tambah ikut
                             berapa banyak baris di invois sebenar (Amount, Service Tax, Regulatory
                             Cost, dll.), bukan medan tetap. -->
                        <div class="col-span-2 flex flex-col gap-2 rounded-md border p-3">
                            <div class="flex items-center justify-between">
                                <Label class="text-xs text-muted-foreground">Caj (ikut baris invois sebenar)</Label>
                                <Button type="button" variant="outline" size="sm" class="gap-1" @click="addCharge">
                                    <Plus class="size-3.5" />Tambah Caj
                                </Button>
                            </div>
                            <p v-if="lineForm.errors.charges" class="text-xs text-destructive">{{ lineForm.errors.charges }}</p>
                            <div v-for="(charge, index) in lineForm.charges" :key="index" class="flex items-center gap-2">
                                <Input v-model="charge.description" placeholder="cth. Amount, Service Tax (8%), Regulatory Cost" class="flex-1" />
                                <Input v-model="charge.amount" type="number" step="0.01" min="0" placeholder="0.00" class="w-32" />
                                <Button type="button" variant="ghost" size="icon" class="shrink-0 text-destructive" @click="removeCharge(index)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <p v-if="lineForm.charges.length === 0" class="text-xs text-muted-foreground">Belum ada caj - klik "Tambah Caj".</p>
                        </div>

                        <div class="col-span-2 flex justify-between rounded-md border bg-muted/40 px-3 py-2 text-sm">
                            <span class="text-muted-foreground">Jumlah Keseluruhan (auto)</span>
                            <span class="font-semibold tabular-nums">{{ money(vendorInvoiceTotal) }}</span>
                        </div>
                    </template>

                    <div>
                        <Label for="remarks">Catatan (optional)</Label>
                        <Input id="remarks" v-model="lineForm.remarks" placeholder="cth. tablet redmi" />
                    </div>
                    <div class="col-span-2">
                        <Label>Resit / Invois (optional)</Label>
                        <div class="flex items-center gap-2">
                            <Button type="button" variant="outline" size="sm" :disabled="uploadingReceipt" as="label" class="cursor-pointer">
                                <Loader2 v-if="uploadingReceipt" class="size-3.5 animate-spin" />
                                <Upload v-else class="size-3.5" />
                                Muat Naik
                                <input type="file" accept="image/*,application/pdf" class="hidden" @change="handleReceiptUpload" />
                            </Button>
                            <span v-if="receiptFileName" class="flex items-center gap-1 text-xs text-muted-foreground">
                                <Paperclip class="size-3.5" />{{ receiptFileName }}
                            </span>
                        </div>
                    </div>
                </div>
                <Button type="button" class="mt-4 gap-1.5" :disabled="lineForm.processing" @click="addLine">
                    <Plus class="size-4" />Tambah Item
                </Button>
            </CardContent>
        </Card>

        <!-- Senarai item -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Senarai Item ({{ claim.lines.length }})</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <p v-if="claim.lines.length === 0" class="text-sm text-muted-foreground">Belum ada item ditambah.</p>
                <div v-for="line in claim.lines" :key="line.id" class="flex items-start justify-between gap-3 rounded-md border p-3">
                    <div class="flex flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ line.description }}</span>
                            <Badge variant="outline">{{ line.category }}</Badge>
                            <Badge v-if="line.is_vendor_invoice" class="gap-1 border-transparent bg-blue-500 text-white">
                                <Receipt class="size-3" />{{ line.vendor }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ new Date(line.expense_date).toLocaleDateString('en-GB') }}
                            <span v-if="line.invoice_number"> &middot; No. Invois: {{ line.invoice_number }}</span>
                            <span v-if="line.remarks"> &middot; {{ line.remarks }}</span>
                        </p>
                        <ul v-if="line.is_vendor_invoice && line.charges.length > 0" class="ml-4 list-disc text-xs text-muted-foreground">
                            <li v-for="charge in line.charges" :key="charge.id">{{ charge.description }}: {{ money(charge.amount) }}</li>
                        </ul>
                        <a v-if="line.receipt_url" :href="line.receipt_url" target="_blank" class="flex items-center gap-1 text-xs text-primary hover:underline">
                            <Paperclip class="size-3" />Lihat resit/invois
                        </a>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-medium tabular-nums">{{ money(line.amount) }}</span>
                        <Button v-if="isEditable" type="button" variant="ghost" size="icon" class="text-destructive" @click="deleteLine(line.id)">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>

                <div v-if="claim.lines.length > 0" class="mt-2 flex flex-col gap-1 border-t pt-3 text-sm">
                    <div v-for="(total, key) in categoryTotals" :key="key" v-show="total > 0" class="flex justify-between text-muted-foreground">
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

        <Button v-if="isEditable" type="button" size="lg" :disabled="submitting || claim.lines.length === 0" @click="submitClaim">
            <Loader2 v-if="submitting" class="size-4 animate-spin" />
            Hantar Claim
        </Button>
        <p v-else class="text-sm text-muted-foreground">
            Claim ini sudah {{ claim.status === 'Submitted' ? 'dihantar, menunggu kelulusan' : 'diluluskan' }} - tidak boleh diedit.
        </p>

        <!-- Sejarah claim -->
        <Card v-if="recentClaims.length > 0">
            <CardHeader>
                <CardTitle class="text-base">Claim Terdahulu</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <div v-for="rc in recentClaims" :key="rc.id" class="flex items-center justify-between rounded-md border p-2.5 text-sm">
                    <span>{{ formatMonth(rc.claim_month) }} &middot; {{ rc.claim_number }}</span>
                    <div class="flex items-center gap-3">
                        <span class="tabular-nums text-muted-foreground">{{ money(rc.total_amount) }}</span>
                        <Badge :class="STATUS_BADGE_CLASS[rc.status]">{{ rc.status }}</Badge>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
