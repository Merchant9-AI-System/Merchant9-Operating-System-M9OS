<script setup lang="ts">
import { computed, ref } from 'vue';
import { ArrowRightLeft, Hash, MessageSquare, Ruler, Scale, Send } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

// Mencipta StockTransfer (rujuk RestockAnalysisController::storeTransfer) - staf cawangan asal & destinasi serta manager dimaklumkan automatik.
// `give`  = cawangan Overstock (selfStore) memberi kepada cawangan yg kekurangan (pilih cawangan penerima).
// `request` = cawangan yg kekurangan (selfStore) meminta daripada cawangan yg boleh menyumbang (pilih cawangan asal).
interface Option {
    store_code: string;
    // Teks kecil pada butang pilihan (cth. "kurang 3" atau "boleh diambil 5").
    label: string;
    title: string;
    // Kuantiti maksimum (stok boleh diambil di cawangan asal) & kuantiti dicadangkan.
    max: number;
    suggested: number;
}

interface StockedSize {
    size: string;
    weight: string;
    stock: number;
}

const props = defineProps<{
    code: string;
    mode: 'give' | 'request';
    selfStore: string;
    options: Option[];
    // Saiz yg masih ada stok di sebuah cawangan (cawangan asal transfer).
    sizesFor: (store: string) => StockedSize[];
}>();

const open = ref(false);
const choice = ref('');
const qty = ref('');
const size = ref('');
const weight = ref('');
const notes = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

const isRequest = computed(() => props.mode === 'request');
const chosen = computed(() => props.options.find((o) => o.store_code === choice.value) ?? null);
const maxQty = computed(() => Math.max(1, chosen.value?.max ?? Math.max(...props.options.map((o) => o.max), 1)));
const sourceStore = computed(() => (isRequest.value ? choice.value : props.selfStore));
const sizes = computed(() => (sourceStore.value ? props.sizesFor(sourceStore.value) : []));

function csrfHeaders(): Record<string, string> {
    const meta = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content;

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) } : {};
}

// Pilih cawangan: cadangkan kuantiti (tidak melebihi stok boleh diambil), dan kosongkan saiz kerana senarai saiz berubah mengikut cawangan asal.
function pick(option: Option) {
    const changed = choice.value !== option.store_code;

    choice.value = option.store_code;
    qty.value = String(Math.min(option.max, Math.max(1, option.suggested)));

    if (changed && isRequest.value) {
        size.value = '';
        weight.value = '';
    }
}

function pickSize(row: StockedSize) {
    size.value = row.size;

    if (weight.value.trim() === '' && row.weight !== '-') {
        weight.value = row.weight;
    }
}

function reset() {
    choice.value = '';
    qty.value = '';
    size.value = '';
    weight.value = '';
    notes.value = '';
    error.value = null;
}

async function submit() {
    if (submitting.value) {
        return;
    }

    if (choice.value === '') {
        error.value = isRequest.value ? 'Pilih cawangan asal.' : 'Pilih cawangan penerima.';

        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const response = await fetch('/restock-analysis/transfer', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
            body: JSON.stringify({
                code: props.code,
                direction: props.mode,
                from_store: isRequest.value ? choice.value : props.selfStore,
                to_store: isRequest.value ? props.selfStore : choice.value,
                qty: qty.value === '' ? null : Number(qty.value),
                size: size.value,
                weight: weight.value,
                notes: notes.value,
            }),
        });

        const data = response.headers.get('content-type')?.includes('json') ? await response.json() : null;

        if (!response.ok) {
            error.value = data?.message ?? (response.status === 403 ? 'Anda tidak boleh membuat transfer ini.' : 'Transfer tidak dapat dicipta. Cuba lagi.');

            return;
        }

        toast.success(data.message);
        open.value = false;
        reset();
    } catch {
        error.value = 'Transfer tidak dapat dicipta. Semak sambungan dan cuba lagi.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" size="sm">
                <Send v-if="isRequest" />
                <ArrowRightLeft v-else />
                {{ isRequest ? 'Request' : 'Transfer' }}
            </Button>
        </PopoverTrigger>
        <!-- Tinggi dihadkan ikut ruang yg ada (var reka-ui) supaya popover tak terpotong; boleh scroll jika sempit. -->
        <PopoverContent align="start" :collision-padding="16" class="max-h-(--reka-popover-content-available-height) w-80 overflow-y-auto">
            <form class="flex flex-col gap-2.5" @submit.prevent="submit">
                <div>
                    <p class="text-sm font-medium">{{ isRequest ? `Request transfer ke ${selfStore}` : `Transfer dari ${selfStore}` }}</p>
                    <p class="text-xs text-muted-foreground">{{ code }} &middot; staf cawangan dan manager dimaklumkan.</p>
                </div>

                <!-- Cawangan asal (request) / penerima (give) -->
                <div class="flex flex-col gap-1">
                    <span class="text-xs text-muted-foreground">{{ isRequest ? 'Daripada cawangan' : 'Ke cawangan' }}</span>
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="o in options"
                            :key="o.store_code"
                            type="button"
                            :class="cn('inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-medium transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', choice === o.store_code && 'border-primary bg-accent')"
                            :title="o.title"
                            @click="pick(o)"
                        >
                            {{ o.store_code }}
                            <span class="text-muted-foreground tabular-nums">{{ o.label }}</span>
                        </button>
                    </div>
                </div>

                <InputGroup>
                    <InputGroupAddon><Hash /></InputGroupAddon>
                    <InputGroupInput v-model="qty" type="number" min="1" :max="maxQty" inputmode="numeric" placeholder="Kuantiti" aria-label="Kuantiti dalam piece" />
                    <InputGroupAddon align="inline-end" :title="`Stok tolak piece yang sedang dihantar keluar`">maks {{ maxQty }} pcs</InputGroupAddon>
                </InputGroup>

                <!-- Pilihan pantas saiz yg masih ada stok di cawangan asal -->
                <div v-if="sizes.length > 0" class="flex flex-wrap gap-1">
                    <button
                        v-for="row in sizes"
                        :key="row.size"
                        type="button"
                        :class="cn('inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-medium transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', size === row.size && 'border-primary bg-accent')"
                        :title="`Stok ${row.stock} di ${sourceStore}`"
                        @click="pickSize(row)"
                    >
                        {{ row.size }}
                        <span class="text-muted-foreground tabular-nums">{{ row.stock }}</span>
                    </button>
                </div>
                <p v-else-if="isRequest && choice === ''" class="text-xs text-muted-foreground">Pilih cawangan asal untuk lihat saiz yang ada.</p>

                <div class="grid grid-cols-2 gap-2">
                    <InputGroup>
                        <InputGroupAddon><Ruler /></InputGroupAddon>
                        <InputGroupInput v-model="size" maxlength="100" placeholder="Saiz" aria-label="Saiz (pilihan)" autocomplete="off" />
                    </InputGroup>
                    <InputGroup>
                        <InputGroupAddon><Scale /></InputGroupAddon>
                        <InputGroupInput v-model="weight" maxlength="20" placeholder="Berat" aria-label="Berat dalam gram (pilihan)" autocomplete="off" />
                        <InputGroupAddon align="inline-end">g</InputGroupAddon>
                    </InputGroup>
                </div>

                <InputGroup>
                    <InputGroupAddon><MessageSquare /></InputGroupAddon>
                    <InputGroupInput v-model="notes" maxlength="255" placeholder="Nota (pilihan)" aria-label="Nota" autocomplete="off" />
                </InputGroup>

                <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" size="sm" :disabled="submitting" @click="open = false">Batal</Button>
                    <Button type="submit" size="sm" :disabled="submitting">
                        <Spinner v-if="submitting" />
                        {{ isRequest ? 'Hantar request' : 'Cipta transfer' }}
                    </Button>
                </div>
            </form>
        </PopoverContent>
    </Popover>
</template>
