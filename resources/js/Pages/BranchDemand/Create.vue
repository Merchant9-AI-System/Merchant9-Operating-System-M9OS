<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { List, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectNative } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import ProductPicker, { type ProductSearchResult } from './ProductPicker.vue';
import RestockSuggestions, { type RestockSuggestion } from './RestockSuggestions.vue';

interface LineItem {
    search_query: string;
    internal_code: string;
    item_desc: string;
    qty_requested: number;
    current_stock: number | null;
    image_url: string | null;
}

interface StoreOption {
    code: string;
    label: string;
}

const props = defineProps<{
    stores: StoreOption[];
    initialStoreCode?: string | null;
}>();

const page = usePage<{ flash: { success: string | null } }>();

function blankLine(): LineItem {
    return {
        search_query: '',
        internal_code: '',
        item_desc: '',
        qty_requested: 1,
        current_stock: null,
        image_url: null,
    };
}

const form = useForm({
    store_code: props.initialStoreCode ?? '',
    submitted_by_name: '',
    notes: '',
    lines: [] as LineItem[],
});

const confirmOpen = ref(false);

const canSubmit = computed(() => Boolean(form.store_code)
    && Boolean(form.submitted_by_name)
    && form.lines.some((line) => line.internal_code && line.qty_requested > 0));

const selectedStoreLabel = computed(() => props.stores.find((s) => s.code === form.store_code)?.label ?? form.store_code);

const selectedLines = computed(() => form.lines.filter((line) => line.internal_code && line.qty_requested > 0));

function onSelect(index: number, result: ProductSearchResult) {
    form.lines[index].internal_code = result.internal_code;
    form.lines[index].item_desc = result.description;
    form.lines[index].current_stock = result.current_stock;
    form.lines[index].image_url = result.image_url;
}

function addLine() {
    form.lines.push(blankLine());
}

function removeLine(index: number) {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);
    }
}

// Cadangan restock (klik "+") - kalau design tu dah ada dlm senarai, tambah kuantiti sahaja
// (elak baris berganda utk design yg sama); kalau ada baris kosong, isi baris tu dulu drpd
// tambah baris baharu; kalau semua baris dah terisi design lain, tambah baris baharu di hujung.
function addFromSuggestion(item: RestockSuggestion) {
    const existing = form.lines.find((line) => line.internal_code === item.internal_code);

    if (existing) {
        existing.qty_requested += 1;

        return;
    }

    const blankIndex = form.lines.findIndex((line) => !line.internal_code);
    const target = blankIndex !== -1 ? form.lines[blankIndex] : blankLine();

    target.search_query = `${item.internal_code} - ${item.description}`;
    target.internal_code = item.internal_code;
    target.item_desc = item.description;
    target.qty_requested = 1;
    target.current_stock = item.current_stock;
    target.image_url = item.image_url;

    if (blankIndex === -1) {
        form.lines.push(target);
    }
}

// Bila cawangan ditukar SELEPAS satu item dipilih, "Stok Semasa" yg dipaparkan terpegun pd
// nilai cawangan LAMA (diambil semasa carian dijalankan) - kemaskan semula stok bagi setiap
// line yg dah ada internal_code supaya sentiasa padan dgn cawangan yg AKTIF sekarang.
watch(() => form.store_code, async (newCode, oldCode) => {
    if (!newCode || newCode === oldCode) {
        return;
    }

    await Promise.all(form.lines.map(async (line) => {
        if (!line.internal_code) {
            return;
        }

        try {
            const params = new URLSearchParams({ q: line.internal_code, store_code: newCode });
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
            form.lines = [blankLine()];
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Permintaan Stok Cawangan</h1>
                <p class="text-sm text-muted-foreground">
                    Pilih cawangan anda, kemudian cari item yang diperlukan.
                </p>
            </div>
            <Link
                :href="form.store_code ? `/branch-demand/requests?store_code=${form.store_code}` : '/branch-demand/requests'">
                <Button variant="outline">
                    <List class="size-4" /> Senarai Permintaan
                </Button>
            </Link>
        </div>

        <div v-if="page.props.flash.success"
            class="rounded-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success-700">
            {{ page.props.flash.success }}
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
            <form class="flex flex-col gap-4" @submit.prevent="openConfirm">
                <Card>
                    <CardContent class="grid gap-3 pt-6 sm:grid-cols-2">
                        <div>
                            <Label class="mb-1.5 block">Cawangan</Label>
                            <SelectNative v-model="form.store_code">
                                <option value="" disabled>Pilih cawangan...</option>
                                <option v-for="store in stores" :key="store.code" :value="store.code">
                                    {{ store.label }}
                                </option>
                            </SelectNative>
                            <p v-if="form.errors.store_code" class="mt-1 text-xs text-destructive">
                                {{ form.errors.store_code }}
                            </p>
                        </div>
                        <div>
                            <Label class="mb-1.5 block">Nama PIC</Label>
                            <Input v-model="form.submitted_by_name" placeholder="Nama anda" autocomplete="off" />
                            <p v-if="form.errors.submitted_by_name" class="mt-1 text-xs text-destructive">
                                {{ form.errors.submitted_by_name }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card v-for="(line, index) in form.lines" :key="index">
                    <CardContent class="flex flex-col gap-3 pt-6">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <Label class="mb-1.5 block">Kod Design / Keterangan / Kategori</Label>
                                <ProductPicker v-model="line.search_query" :store-code="form.store_code || null"
                                    @select="(result) => onSelect(index, result)" />
                                <p v-if="form.errors[`lines.${index}.internal_code`]"
                                    class="mt-1 text-xs text-destructive">
                                    Sila pilih satu design drpd senarai carian.
                                </p>
                            </div>
                            <div class="w-24 shrink-0">
                                <Label class="mb-1.5 block">Kuantiti</Label>
                                <Input v-model="line.qty_requested" type="number" min="1" />
                            </div>
                            <Button type="button" variant="ghost" size="icon"
                                class="mt-6 text-muted-foreground hover:text-destructive"
                                :disabled="form.lines.length <= 1" @click="removeLine(index)">
                                <Trash2 class="size-4" />
                            </Button>
                        </div>

                        <div v-if="line.internal_code"
                            class="flex items-center gap-3 rounded-md bg-muted/50 px-3 py-2 text-sm">
                            <img v-if="line.image_url" :src="line.image_url" class="size-10 rounded object-cover"
                                alt="">
                            <div class="flex-1">
                                <p class="font-medium">{{ line.internal_code }} - {{ line.item_desc }}</p>
                                <p class="text-muted-foreground">
                                    Stok semasa cawangan anda: <span class="font-medium">{{ line.current_stock }}
                                        unit</span>
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Button type="button" :disabled="!form.store_code" @click="addLine">
                    <Plus class="size-4" /> Tambah Item
                </Button>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Nota</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Textarea v-model="form.notes" placeholder="Nota tambahan (pilihan)" :rows="4" />
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button type="submit" :disabled="!canSubmit || form.processing">
                            Hantar Permintaan
                        </Button>
                    </CardFooter>
                </Card>
            </form>

            <div class="lg:sticky lg:top-8 lg:self-start overflow-hidden">
                <RestockSuggestions :store-code="form.store_code || null" @add="addFromSuggestion" />
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
                        <p class="mb-1 text-muted-foreground">Item ({{ selectedLines.length }})</p>
                        <ul class="flex flex-col gap-1 rounded-md border p-2">
                            <li v-for="(line, i) in selectedLines" :key="i" class="flex justify-between gap-2">
                                <span class="min-w-0 truncate">{{ line.internal_code }} - {{ line.item_desc }}</span>
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
                        {{ form.processing ? 'Menghantar...' : 'Sahkan & Hantar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
