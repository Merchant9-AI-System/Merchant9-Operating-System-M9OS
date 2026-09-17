<script setup lang="ts">
import type { Component } from "vue"
import { DialogClose } from "reka-ui"

// Written locally instead of `defineProps<DialogCloseProps>()` (reka-ui's type, which is just
// `PrimitiveProps`) because the current vite/@vitejs/plugin-vue build can't resolve types
// imported from another package when generating this SFC's runtime props - it throws "No fs
// option provided to compileScript in non-Node environment" (see
// toggle-group/ToggleGroupItem.vue for the same fix, in more detail).
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
}>()
</script>

<template>
  <DialogClose
    data-slot="dialog-close"
    v-bind="props"
  >
    <slot />
  </DialogClose>
</template>
