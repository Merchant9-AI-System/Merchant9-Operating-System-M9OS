<script setup lang="ts">
import { DialogOverlay } from 'reka-ui';
import { cn } from '@/lib/utils';
</script>

<template>
    <DialogOverlay :class="cn('fixed inset-0 z-50 bg-black/50')" />
</template>
