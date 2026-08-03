<script setup lang="ts">
import { DialogRoot } from 'reka-ui';

defineProps<{ open?: boolean }>();
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();
</script>

<template>
    <DialogRoot :open="open" @update:open="emit('update:open', $event)">
        <slot />
    </DialogRoot>
</template>
