<script setup lang="ts">
import { DialogOverlay } from 'reka-ui';
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <DialogOverlay :class="cn(
        'fixed inset-0 z-50 bg-black/50',
        'data-[state=open]:animate-fade-in data-[state=closed]:animate-fade-out',
        props.class,
    )" />
</template>
