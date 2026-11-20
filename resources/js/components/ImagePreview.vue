<script setup lang="ts">
import { ZoomIn } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Carousel, CarouselContent, CarouselItem, CarouselNext, CarouselPrevious } from '@/components/ui/carousel';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * Thumbnail yg boleh diklik utk lihat imej produk lebih besar (+ zum), gaya
 * https://primevue.dev/image/ - dibina atas Dialog shadcn-vue sedia ada drpd tarik pustaka
 * UI kedua (PrimeVue) yg akan bertembung/berlapis dgn shadcn-vue yg dah dipilih utk seluruh
 * permukaan Inertia ni. Klik sekali thumbnail = buka pratonton besar; klik imej dlm dialog
 * = toggle zum masuk/keluar.
 *
 * `reference` = gambar rujukan (dipinjam drpd berat terdekat bagi design yg sama, bukan gambar tepat kod ini - rujuk
 * App\Support\DesignFamily): label "Rujukan" di thumbnail & nota di dlm pratonton.
 *
 * `galleryUrl` (pilihan) = URL JSON `{ urls: string[], reference: boolean }` bagi SEMUA gambar design itu. Dimuat sekali bila pratonton dibuka;
 * jika ada lebih drpd satu gambar, pratonton menjadi carousel (anak panah, kiraan & lakaran) bermula pada gambar thumbnail. Sementara menunggu
 * (atau jika gagal / hanya satu gambar) gambar tunggal `src` dipaparkan seperti biasa.
 */
const props = defineProps<{
    src: string | null;
    alt?: string;
    class?: string;
    reference?: boolean;
    galleryUrl?: string;
}>();

interface Gallery {
    urls: string[];
    reference: boolean;
}

const referenceNote = 'Gambar rujukan: dipinjam daripada berat terdekat bagi reka bentuk yang sama.';

const open = ref(false);
const zoomed = ref(false);
const gallery = ref<Gallery | null>(null);
const galleryLoading = ref(false);
let galleryToken = 0;

// Gambar lain (design lain) = galeri lama tidak sah.
watch(
    () => props.galleryUrl,
    () => {
        gallery.value = null;
        galleryLoading.value = false;
        galleryToken++;
    },
);

// Carousel hanya bila ada lebih drpd satu gambar; bermula pada gambar yg sedang ditunjuk di thumbnail.
const carouselUrls = computed(() => (gallery.value && gallery.value.urls.length > 1 ? gallery.value.urls : null));
const startIndex = computed(() => Math.max(0, carouselUrls.value?.indexOf(props.src ?? '') ?? 0));
const isReference = computed(() => (gallery.value?.reference ?? props.reference) === true);

async function loadGallery() {
    if (!props.galleryUrl || gallery.value || galleryLoading.value) {
        return;
    }

    const token = ++galleryToken;
    galleryLoading.value = true;

    try {
        const response = await fetch(props.galleryUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const data = (await response.json()) as Gallery;

        if (token === galleryToken) {
            gallery.value = data;
        }
    } catch {
        // Kekal dengan gambar tunggal; dicuba semula bila dibuka lagi.
    } finally {
        if (token === galleryToken) {
            galleryLoading.value = false;
        }
    }
}

function openPreview() {
    if (props.src) {
        zoomed.value = false;
        open.value = true;
        loadGallery();
    }
}
</script>

<template>
    <button
        v-if="src"
        type="button"
        class="group relative shrink-0 overflow-hidden rounded"
        @click="openPreview"
    >
        <img :src="src" :alt="alt ?? ''" :class="cn('object-cover', props.class)" loading="lazy">
        <span
            v-if="reference"
            class="absolute inset-x-0 bottom-0 bg-black/60 text-center text-[8px] leading-tight text-white"
        >
            Rujukan
        </span>
        <span
            class="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition group-hover:bg-black/30 group-hover:opacity-100"
        >
            <ZoomIn class="size-4 text-white" />
        </span>
    </button>
    <div v-else :class="cn('shrink-0 rounded bg-muted', props.class)" />

    <Dialog v-model:open="open">
        <DialogContent class="mx-auto flex max-w-xl flex-col items-center justify-center gap-2 p-2">
            <!-- Banyak gambar: carousel + lakaran (sama seperti halaman butiran Supplier Lookup) -->
            <Carousel
                v-if="carouselUrls"
                :key="carouselUrls.join('|')"
                v-slot="{ selectedIndex, scrollTo }"
                :opts="{ loop: true, startIndex }"
                class="flex w-full min-w-0 flex-col gap-2"
            >
                <div class="relative">
                    <CarouselContent>
                        <CarouselItem v-for="(url, index) in carouselUrls" :key="url">
                            <img
                                :src="url"
                                :alt="`${alt ?? ''} (${index + 1})`"
                                class="mx-auto max-h-[70vh] w-full rounded bg-muted object-contain"
                                loading="lazy"
                            >
                        </CarouselItem>
                    </CarouselContent>
                    <CarouselPrevious class="left-2 border-white/40 bg-black/50 text-white shadow-sm backdrop-blur-sm hover:bg-black/70 hover:text-white dark:border-white/40 dark:bg-black/50 dark:text-white dark:hover:bg-black/70 dark:hover:text-white" />
                    <CarouselNext class="right-2 border-white/40 bg-black/50 text-white shadow-sm backdrop-blur-sm hover:bg-black/70 hover:text-white dark:border-white/40 dark:bg-black/50 dark:text-white dark:hover:bg-black/70 dark:hover:text-white" />
                    <span class="absolute right-2 bottom-2 rounded-full bg-background/80 px-2 py-0.5 text-xs tabular-nums">
                        {{ selectedIndex + 1 }} / {{ carouselUrls.length }}
                    </span>
                </div>

                <div class="flex gap-2 overflow-x-auto pb-1 dark:[color-scheme:dark]" role="tablist" aria-label="Lakaran gambar">
                    <button
                        v-for="(url, index) in carouselUrls"
                        :key="url"
                        type="button"
                        role="tab"
                        :aria-selected="selectedIndex === index"
                        :aria-label="`Gambar ${index + 1}`"
                        class="size-14 shrink-0 overflow-hidden rounded-md border-2 bg-muted transition-opacity"
                        :class="selectedIndex === index ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'"
                        @click="scrollTo(index)"
                    >
                        <img :src="url" alt="" class="size-full object-cover" loading="lazy">
                    </button>
                </div>
            </Carousel>

            <!-- Satu gambar (atau galeri belum dimuat): paparan asal dgn zum -->
            <div v-else class="relative flex max-w-full flex-col items-center">
                <img
                    :src="src ?? ''"
                    :alt="alt ?? ''"
                    :class="cn(
                        'max-h-[75vh] rounded transition-transform duration-200',
                        zoomed ? 'max-w-none scale-150 cursor-zoom-out' : 'max-w-full cursor-zoom-in',
                    )"
                    @click="zoomed = !zoomed"
                >
                <Spinner v-if="galleryLoading" class="absolute right-2 bottom-2 size-5 rounded-full bg-background/80 p-0.5" aria-label="Memuatkan gambar lain" />
            </div>

            <p v-if="isReference" class="text-xs text-muted-foreground">
                {{ referenceNote }}
            </p>
        </DialogContent>
    </Dialog>
</template>
