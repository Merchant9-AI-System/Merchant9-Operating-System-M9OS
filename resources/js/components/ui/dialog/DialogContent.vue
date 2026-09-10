<script setup lang="ts">
import { X } from '@lucide/vue';
import { DialogClose, DialogContent, DialogPortal } from 'reka-ui';
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import DialogOverlay from './DialogOverlay.vue';

// hideClose - sorok butang X sudut (cth. CommandDialog, yg dah ada butang X sendiri utk
// clear teks carian + biasanya ditutup via Escape/klik luar, bukan butang X sudut).
// overlayClass - tambahan kelas utk DialogOverlay (cth. `backdrop-blur-sm` CommandDialog sahaja
// - bukan default utk SEMUA dialog, rujuk DialogOverlay.vue).
const props = defineProps<{ class?: HTMLAttributes['class']; hideClose?: boolean; overlayClass?: HTMLAttributes['class'] }>();
</script>

<template>
    <DialogPortal>
        <DialogOverlay :class="overlayClass" />
        <DialogContent
            :class="cn(
                'fixed left-1/2 top-1/2 z-50 grid w-full max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 rounded-lg border bg-background p-6 shadow-lg',
                'data-[state=open]:animate-fade-in data-[state=closed]:animate-fade-out',
                props.class,
            )"
        >
            <slot />
            <DialogClose v-if="!hideClose" class="absolute right-4 top-4 rounded-sm opacity-70 transition-opacity hover:opacity-100 focus:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                <X class="size-4" />
                <span class="sr-only">Tutup</span>
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
