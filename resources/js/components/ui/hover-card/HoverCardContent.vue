<script setup lang="ts">
import { HoverCardContent, HoverCardPortal } from 'reka-ui';
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        align?: 'start' | 'center' | 'end';
        sideOffset?: number;
    }>(),
    { align: 'center', sideOffset: 4 },
);
</script>

<template>
    <HoverCardPortal>
        <HoverCardContent
            :align="props.align"
            :side-offset="props.sideOffset"
            :class="cn(
                'z-50 w-72 rounded-md border bg-popover p-4 text-popover-foreground shadow-md outline-none',
                props.class,
            )"
        >
            <slot />
        </HoverCardContent>
    </HoverCardPortal>
</template>
