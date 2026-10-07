<script setup lang="ts">
import { CloudUpload, ExternalLink, FileText, Loader2, RefreshCw, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// Medan muat naik resit / invois: zon seret-lepas (atau pilih fail), kad pratonton imej dgn nama,
// saiz & status, serta butang ganti / buang - corak "FileUpload + Image Preview" tanpa pakej luar.
// v-model = URL resit yg disimpan server (rujuk ExpenseClaimController::uploadReceipt()). Fail
// dimuat naik TERUS bila dipilih (borang tuntutan perlukan receipt_url semasa Simpan).
const props = withDefaults(defineProps<{
    modelValue?: string | null;
    uploadUrl?: string;
    accept?: string;
    /** Saiz maksimum fail (bait) - sepadan dgn peraturan server (max:10240 KB). */
    maxSize?: number;
    class?: HTMLAttributes['class'];
}>(), {
    modelValue: '',
    uploadUrl: '/claims/upload-receipt',
    accept: 'image/*,application/pdf',
    maxSize: 10 * 1024 * 1024,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

const input = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const dragging = ref(false);
const error = ref<string | null>(null);

// Maklumat fail yg baru dipilih SESI ni (DB cuma simpan URL, bukan nama asal / saiz).
const fileName = ref<string | null>(null);
const fileSize = ref<number | null>(null);

const url = computed(() => props.modelValue ?? '');

const displayName = computed(() => {
    if (fileName.value) {
        return fileName.value;
    }

    if (!url.value) {
        return null;
    }

    try {
        return decodeURIComponent(new URL(url.value, window.location.origin).pathname.split('/').pop() ?? 'Resit');
    } catch {
        return 'Resit';
    }
});

const isImage = computed(() => {
    const ext = displayName.value?.split('.').pop()?.toLowerCase() ?? '';

    return IMAGE_EXTENSIONS.includes(ext);
});

// Pratonton guna URL server (sama origin) selepas muat naik - URL blob: sengaja TIDAK dipakai kerana
// CSP img-src (App\Http\Middleware\SecurityHeaders) tak membenarkan blob:.
const previewSrc = computed(() => (isImage.value && url.value && !uploading.value ? url.value : null));

const sizeLabel = computed(() => (fileSize.value !== null ? formatFileSize(fileSize.value) : 'Resit sedia ada'));

const maxSizeLabel = computed(() => formatFileSize(props.maxSize).replace('.0', ''));

// Token CSRF: tag <meta name="csrf-token"> kalau ada, jika tidak cookie XSRF-TOKEN Laravel (dihantar
// semula sbg header X-XSRF-TOKEN) - layout app.blade.php tiada tag meta tsb.
function csrfHeaders(): Record<string, string> {
    const meta = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content;

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) } : {};
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

function openPicker() {
    if (!uploading.value) {
        input.value?.click();
    }
}

async function upload(file: File) {
    error.value = null;

    if (!file.type.startsWith('image/') && file.type !== 'application/pdf') {
        error.value = 'Jenis fail tidak disokong. Pilih imej (JPG, PNG, WEBP) atau PDF.';

        return;
    }

    if (file.size > props.maxSize) {
        error.value = `Fail terlalu besar (${formatFileSize(file.size)}). Maksimum ${maxSizeLabel.value}.`;

        return;
    }

    uploading.value = true;
    fileName.value = file.name;
    fileSize.value = file.size;

    const formData = new FormData();
    formData.append('receipt', file);

    try {
        const response = await fetch(props.uploadUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', ...csrfHeaders() },
            body: formData,
        });
        const data = await response.json().catch(() => null);

        if (!response.ok || !data?.receipt_url) {
            throw new Error(data?.errors?.receipt?.[0] ?? data?.message ?? 'Gagal memuat naik resit. Cuba lagi.');
        }

        emit('update:modelValue', data.receipt_url);
    } catch (e) {
        error.value = (e as Error).message;
        fileName.value = null;
        fileSize.value = null;
    } finally {
        uploading.value = false;
    }
}

function onPick(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    // Kosongkan value - tanpa ni, pilih fail SAMA 2x berturut tak trigger "change" kali kedua.
    target.value = '';

    if (file) {
        upload(file);
    }
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0];

    if (file && !uploading.value) {
        upload(file);
    }
}

function clear() {
    fileName.value = null;
    fileSize.value = null;
    error.value = null;
    emit('update:modelValue', '');
}
</script>

<template>
    <div :class="cn('flex flex-col gap-2', props.class)">
        <input ref="input" type="file" :accept="accept" class="hidden" @change="onPick">

        <!-- Belum ada resit: zon seret-lepas / klik -->
        <div v-if="!url && !uploading" role="button" tabindex="0" aria-label="Muat naik resit / invois"
            class="flex cursor-pointer flex-col items-center gap-1.5 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors hover:bg-muted/40 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
            :class="dragging ? 'border-primary bg-primary/5' : 'border-border'" @click="openPicker"
            @keydown.enter.prevent="openPicker" @keydown.space.prevent="openPicker" @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false" @drop.prevent="onDrop">
            <CloudUpload class="size-8 text-muted-foreground" />
            <p class="text-sm">
                Seret &amp; lepas resit di sini, atau <span class="font-medium text-primary underline">pilih fail</span>
            </p>
            <p class="text-xs text-muted-foreground">JPG, PNG, WEBP atau PDF, maks {{ maxSizeLabel }}</p>
        </div>

        <!-- Ada resit (atau sedang dimuat naik): kad pratonton -->
        <div v-else class="flex items-center gap-3 rounded-lg border p-3 transition-colors"
            :class="dragging ? 'border-primary bg-primary/5' : ''" @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false" @drop.prevent="onDrop">
            <div class="relative size-20 shrink-0 overflow-hidden rounded-md border bg-muted">
                <img v-if="previewSrc" :src="previewSrc" :alt="displayName ?? 'Resit'" class="size-full object-cover">
                <div v-else class="grid size-full place-items-center text-muted-foreground">
                    <FileText class="size-8" />
                </div>
                <div v-if="uploading" class="absolute inset-0 grid place-items-center bg-background/70">
                    <Loader2 class="size-5 animate-spin" />
                </div>
            </div>

            <div class="flex min-w-0 flex-1 flex-col items-start gap-1">
                <p class="w-full truncate text-sm font-medium" :title="displayName ?? undefined">{{ displayName }}</p>
                <Badge v-if="uploading" variant="secondary">Memuat naik</Badge>
                <Badge v-else-if="fileName" class="border-transparent bg-success text-success-foreground">Selesai
                </Badge>
                <Badge v-else variant="outline">Sedia ada</Badge>
                <p class="text-xs text-muted-foreground">{{ uploading ? 'Memuat naik...' : sizeLabel }}</p>
            </div>

            <div class="flex shrink-0 flex-col gap-1.5">
                <a v-if="url && !uploading" :href="url" target="_blank" rel="noopener" title="Lihat resit"
                    aria-label="Lihat resit" :class="buttonVariants({ variant: 'outline', size: 'icon' })">
                    <ExternalLink class="size-4" />
                </a>
                <Button type="button" variant="outline" size="icon" :disabled="uploading" title="Ganti resit"
                    aria-label="Ganti resit" @click="openPicker">
                    <RefreshCw class="size-4" />
                </Button>
                <Button type="button" variant="outline" size="icon" :disabled="uploading" title="Buang resit"
                    aria-label="Buang resit" @click="clear">
                    <X class="size-4" />
                </Button>
            </div>
        </div>

        <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>
    </div>
</template>
