<script setup lang="ts">
import { PopoverRoot } from 'reka-ui';

defineProps<{ open?: boolean; defaultOpen?: boolean; modal?: boolean }>();
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();
</script>

<template>
    <PopoverRoot :open="open" :default-open="defaultOpen" :modal="modal" @update:open="emit('update:open', $event)">
        <slot />
    </PopoverRoot>
</template>
