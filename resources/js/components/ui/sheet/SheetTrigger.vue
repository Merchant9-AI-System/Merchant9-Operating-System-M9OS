<script setup lang="ts">
import type { Component } from "vue"
import { DialogTrigger } from "reka-ui"

// Written locally instead of `defineProps<DialogTriggerProps>()` (reka-ui's type, which is just
// `PrimitiveProps`) - see toggle-group/ToggleGroupItem.vue for why.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
}>()
</script>

<template>
  <DialogTrigger
    data-slot="sheet-trigger"
    v-bind="props"
  >
    <slot />
  </DialogTrigger>
</template>
