<script setup lang="ts">
import { ref, watch } from 'vue';
import { Hash, MessageSquare, Ruler, Scale, Send, TriangleAlert } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { RequestLine } from '@/lib/restock';

// Borang ringkas hantar permintaan restock satu design terus dari panel: SAIZ, BERAT, KRITIKAL & REMARK sahaja - kuantiti diputuskan
// Back Office. Server menambah satu baris pada permintaan cawangan (rujuk RestockAnalysisController::storeRequest).
interface SizeHint {
    size: string;
    weight: string;
    // Habis = stok 0 tetapi ada jualan 3 bulan - saiz yg paling wajar diminta.
    soldOut: boolean;
    stock: number;
}

const props = defineProps<{
    code: string;
    store: string;
    sizeHints: SizeHint[];
    label?: string;
    // `order` = tiada cawangan boleh menyumbang, jadi baris bermula di peringkat Order.
    type?: 'request' | 'order';
    initialRemark?: string;
}>();

const emit = defineEmits<{ submitted: [line: RequestLine] }>();

const open = ref(false);
const qty = ref('');
const size = ref('');
const weight = ref('');
const critical = ref(false);
const remark = ref(props.initialRemark ?? '');
const submitting = ref(false);
// Kuantiti dicadangkan automatik daripada bilangan saiz yg ditaip (cth. "11, 12, 13" = 3) selagi pengguna belum menaip sendiri.
let qtyTouched = false;

// Bilangan saiz: dipisah koma/noktahkoma; julat nombor bulat (cth. "1-5") dikembangkan jadi 1,2,3,4,5; saiz sama dikira sekali.
// Julat berperpuluhan (cth. "9.5-12") tidak dikembangkan (langkahnya tak pasti) jadi dikira satu.
const MAX_RANGE_SPAN = 50;

function countSizes(value: string): number {
    const sizes = new Set<string>();

    for (const part of value.split(/[,;]/).map((item) => item.trim().toLowerCase()).filter(Boolean)) {
        const range = part.match(/^(\d+)\s*-\s*(\d+)$/);
        const from = range ? Number(range[1]) : 0;
        const to = range ? Number(range[2]) : 0;

        if (range && to >= from && to - from <= MAX_RANGE_SPAN) {
            for (let n = from; n <= to; n++) {
                sizes.add(String(n));
            }
        } else {
            sizes.add(part);
        }
    }

    return sizes.size;
}

watch(size, (value) => {
    const count = countSizes(value);

    if (!qtyTouched && count > 0) {
        qty.value = String(count);
    }
});
const error = ref<string | null>(null);

// Token CSRF: cookie XSRF-TOKEN Laravel dihantar semula sbg header X-XSRF-TOKEN (layout app.blade.php tiada tag meta csrf).
function csrfHeaders(): Record<string, string> {
    const meta = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content;

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) } : {};
}

// Pilih saiz cadangan: isi saiz & (jika berat masih kosong) julat berat piece saiz itu.
function pick(hint: SizeHint) {
    size.value = hint.size;

    if (weight.value.trim() === '' && hint.weight !== '-') {
        weight.value = hint.weight;
    }
}

function reset() {
    qty.value = '';
    qtyTouched = false;
    size.value = '';
    weight.value = '';
    critical.value = false;
    remark.value = props.initialRemark ?? '';
    error.value = null;
}

async function submit() {
    if (submitting.value) {
        return;
    }

    if (!/^[1-9]\d*$/.test(qty.value.trim())) {
        error.value = 'Isi kuantiti: berapa piece yang anda mahu (nombor bulat, sekurang-kurangnya 1).';

        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const response = await fetch('/restock-analysis/request', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
            body: JSON.stringify({ code: props.code, store_code: props.store, type: props.type ?? 'request', qty: Number(qty.value), size: size.value, weight: weight.value, is_critical: critical.value, remark: remark.value }),
        });

        const data = response.headers.get('content-type')?.includes('json') ? await response.json() : null;

        if (!response.ok) {
            error.value = data?.message ?? (response.status === 403 ? 'Anda tidak boleh menghantar permintaan untuk cawangan ini.' : 'Permintaan tidak dapat dihantar. Cuba lagi.');

            return;
        }

        toast.success(data.message);
        emit('submitted', data.line);
        open.value = false;
        reset();
    } catch {
        error.value = 'Permintaan tidak dapat dihantar. Semak sambungan dan cuba lagi.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" size="sm">
                <Send />
                {{ label ?? 'Request' }}
            </Button>
        </PopoverTrigger>
        <!-- Tinggi dihadkan ikut ruang yg ada (var reka-ui) supaya popover tak terpotong di atas/bawah skrin; boleh scroll jika sempit. -->
        <PopoverContent align="start" :collision-padding="16" class="max-h-(--reka-popover-content-available-height) w-80 overflow-y-auto">
            <form class="flex flex-col gap-2.5" @submit.prevent="submit">
                <div>
                    <p class="text-sm font-medium">{{ label ?? 'Request' }} untuk {{ store }}</p>
                    <p class="text-xs text-muted-foreground">{{ code }}</p>
                </div>

                <InputGroup>
                    <InputGroupAddon><Hash /></InputGroupAddon>
                    <InputGroupInput v-model="qty" type="number" min="1" inputmode="numeric" placeholder="Auto dari size, cth: 1,2 / 1-5" aria-label="Kuantiti dalam piece" @input="qtyTouched = true" />
                    <InputGroupAddon align="inline-end">pcs</InputGroupAddon>
                </InputGroup>

                <!-- Pilihan pantas saiz laku 3 bulan (yang habis dahulu) -->
                <div v-if="sizeHints.length > 0" class="flex flex-wrap gap-1">
                    <button
                        v-for="hint in sizeHints"
                        :key="hint.size"
                        type="button"
                        :class="cn('inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-medium transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', size === hint.size && 'border-primary bg-accent')"
                        :title="hint.soldOut ? 'Habis di cawangan ini tetapi ada jualan 3 bulan' : `Stok ${hint.stock}`"
                        @click="pick(hint)"
                    >
                        {{ hint.size }}
                        <span v-if="hint.soldOut" class="text-red-600 dark:text-red-400">habis</span>
                    </button>
                </div>

                <div class="grid grid-cols-[3fr_2fr] gap-2">
                    <InputGroup>
                        <InputGroupAddon><Ruler /></InputGroupAddon>
                        <InputGroupInput v-model="size" maxlength="100" placeholder="Saiz: 11, 12, 13" aria-label="Saiz" autocomplete="off" />
                    </InputGroup>
                    <InputGroup>
                        <InputGroupAddon><Scale /></InputGroupAddon>
                        <InputGroupInput v-model="weight" maxlength="20" placeholder="Berat: 2.5" aria-label="Berat dalam gram" autocomplete="off" />
                        <InputGroupAddon align="inline-end">g</InputGroupAddon>
                    </InputGroup>
                </div>

                <InputGroup>
                    <InputGroupAddon><MessageSquare /></InputGroupAddon>
                    <InputGroupInput v-model="remark" maxlength="255" placeholder="Remark (pilihan)" aria-label="Remark" autocomplete="off" />
                </InputGroup>

                <label class="flex cursor-pointer items-center gap-2 text-sm" for="request-critical" title="Tanda Stok Kritikal supaya Back Office nampak dahulu">
                    <Switch id="request-critical" v-model="critical" />
                    <TriangleAlert class="size-4 text-muted-foreground" aria-hidden="true" />
                    Kritikal
                </label>

                <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" size="sm" :disabled="submitting" @click="open = false">Batal</Button>
                    <Button type="submit" size="sm" :disabled="submitting">
                        <Spinner v-if="submitting" />
                        Hantar
                    </Button>
                </div>
            </form>
        </PopoverContent>
    </Popover>
</template>
