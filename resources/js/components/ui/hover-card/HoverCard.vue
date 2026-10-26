<script setup lang="ts">
import { HoverCardRoot } from 'reka-ui';

defineProps<{
    open?: boolean;
    defaultOpen?: boolean;
    openDelay?: number;
    closeDelay?: number;
}>();
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();
</script>

<template>
    <HoverCardRoot :open="open" :default-open="defaultOpen" :open-delay="openDelay" :close-delay="closeDelay"
        @update:open="emit('update:open', $event)">
        <slot />
    </HoverCardRoot>
</template>
