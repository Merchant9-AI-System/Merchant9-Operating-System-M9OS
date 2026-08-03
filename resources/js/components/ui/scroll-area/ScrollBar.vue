<script setup lang="ts">
import { ScrollAreaScrollbar, ScrollAreaThumb } from 'reka-ui';
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{
    class?: HTMLAttributes['class'];
    orientation?: 'vertical' | 'horizontal';
}>(), {
    orientation: 'vertical',
});
</script>

<template>
    <ScrollAreaScrollbar
        data-slot="scroll-area-scrollbar"
        :orientation="orientation"
        :class="cn(
            'flex touch-none select-none p-px transition-colors',
            orientation === 'vertical' && 'h-full w-2.5 border-l border-l-transparent',
            orientation === 'horizontal' && 'h-2.5 flex-col border-t border-t-transparent',
            props.class,
        )"
    >
        <ScrollAreaThumb data-slot="scroll-area-thumb" class="relative flex-1 rounded-full bg-border" />
    </ScrollAreaScrollbar>
</template>
