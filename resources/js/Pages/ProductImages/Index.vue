<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ImageOff } from '@lucide/vue';
import ImagePreview from '@/components/ImagePreview.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

// Props drpd ProductImagesController::show() - SEMUA imej dikikis drpd storefront bagi satu
// InternalCode (rujuk App\Support\ProductImageFetcher::imageUrlsFor()), BUKAN cuma 1 thumbnail
// spt ImageColumn asal di Filament (StockRearrangementRecommendation) - halaman ni gantikan
// kelakuan ->url() lama (buka terus 1 imej di tab baharu).
const props = defineProps<{
    internalCode: string;
    images: string[];
}>();
</script>

<template>

    <Head :title="`Imej Produk ( ${props.internalCode})`" />

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Imej Produk</h1>
            <p class="text-sm text-muted-foreground">
                Semua imej dijumpai di storefront merchant9.com bagi kod
                <span class="font-medium text-foreground">{{ props.internalCode }}</span>.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">{{ props.images.length }} imej dijumpai</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="props.images.length" class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                    <div v-for="(url, index) in props.images" :key="url"
                        class="flex flex-col justify-center rounded-xl border border-muted-background shadow-xs p-2 gap-2">
                        <ImagePreview :src="url" :alt="`${props.internalCode} - imej ${index + 1}`"
                            class="aspect-square w-full rounded-md border" />
                        <div class="flex flex-col gap-1 px-1 py-0.5">
                            <span class="text-sm text-primary font-medium">{{ props.internalCode }}</span>
                            <a :href="url" target="_blank" rel="noopener"
                                class="truncate text-xs text-muted-foreground hover:text-primary hover:underline">
                                {{ url }}
                            </a>
                        </div>
                    </div>
                </div>
                <div v-else class="flex flex-col items-center gap-2 py-12 text-muted-foreground">
                    <ImageOff class="size-8" />
                    <p class="text-sm">Tiada imej dijumpai untuk kod ini.</p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
