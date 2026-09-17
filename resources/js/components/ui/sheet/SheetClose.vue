<script setup lang="ts">
import type { Component } from "vue"
import { DialogClose } from "reka-ui"

// Written locally instead of `defineProps<DialogCloseProps>()` (reka-ui's type, which is just
// `PrimitiveProps`) - see toggle-group/ToggleGroupItem.vue for why.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
}>()
</script>

<template>
  <DialogClose
    data-slot="sheet-close"
    v-bind="props"
  >
    <slot />
  </DialogClose>
</template>
