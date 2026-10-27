<script setup lang="ts">
import { ScrollAreaCorner, ScrollAreaRoot, ScrollAreaViewport } from 'reka-ui';
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import ScrollBar from './ScrollBar.vue';

// horizontal: hidupkan bar scroll mendatar (lalai mati - kandungan melebihi lebar terpotong).
const props = defineProps<{ class?: HTMLAttributes['class']; horizontal?: boolean }>();
</script>

<template>
    <ScrollAreaRoot data-slot="scroll-area" :class="cn('relative flex flex-col', props.class)">
        <!-- flex-1 min-h-0 (bukan size-full/height:100%) - height:100% viewport TAK resolve bila
             root cuma ada max-height (tiada height eksplisit, classic CSS percentage-height
             gotcha), jadi overflow tak scroll langsung, content leak keluar kotak. flex-1 dlm
             flex-col parent betul2 shrink/grow ikut ruang available, kekal serasi dgn root yg
             ada height tetap (h-72 dll) spt sedia ada. -->
        <ScrollAreaViewport
            data-slot="scroll-area-viewport"
            class="min-h-0 w-full flex-1 rounded-[inherit] outline-none transition-[color,box-shadow] focus-visible:outline-1 focus-visible:ring-3 focus-visible:ring-ring/50"
        >
            <slot />
        </ScrollAreaViewport>
        <ScrollBar />
        <ScrollBar v-if="horizontal" orientation="horizontal" />
        <ScrollAreaCorner />
    </ScrollAreaRoot>
</template>
