<script setup lang="ts">
import { HoverCardRoot } from 'reka-ui';

// `open`/`defaultOpen` WAJIB berlalai `undefined`: Vue memaksa prop Boolean yg tak dihantar menjadi `false`, dan
// `open=false` menjadikan HoverCardRoot "terkawal & sentiasa tertutup" (kad tak pernah dibuka).
withDefaults(
    defineProps<{
        open?: boolean;
        defaultOpen?: boolean;
        openDelay?: number;
        closeDelay?: number;
    }>(),
    { open: undefined, defaultOpen: undefined, openDelay: undefined, closeDelay: undefined },
);
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();
</script>

<template>
    <HoverCardRoot :open="open" :default-open="defaultOpen" :open-delay="openDelay" :close-delay="closeDelay"
        @update:open="emit('update:open', $event)">
        <slot />
    </HoverCardRoot>
</template>
