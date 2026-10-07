<script setup lang="ts">
import { ScrollAreaCorner, ScrollAreaRoot, ScrollAreaViewport } from 'reka-ui';
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import ScrollBar from './ScrollBar.vue';

// horizontal: hidupkan bar scroll mendatar (lalai mati - kandungan melebihi lebar terpotong).
const props = defineProps<{ class?: HTMLAttributes['class']; horizontal?: boolean }>();
</script>

<template>
    <ScrollAreaRoot data-slot="scroll-area" :class="cn('relative', props.class)">
        <ScrollAreaViewport
            data-slot="scroll-area-viewport"
            class="size-full rounded-[inherit] outline-none transition-[color,box-shadow] focus-visible:outline-1 focus-visible:ring-3 focus-visible:ring-ring/50"
        >
            <slot />
        </ScrollAreaViewport>
        <ScrollBar />
        <ScrollBar v-if="horizontal" orientation="horizontal" />
        <ScrollAreaCorner />
    </ScrollAreaRoot>
</template>
