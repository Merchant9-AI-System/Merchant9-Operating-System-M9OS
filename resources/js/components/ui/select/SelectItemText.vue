<script setup lang="ts">
import { SelectItemText } from "reka-ui"

const props = defineProps<{
  asChild?: boolean
  as?: string | object
}>()
</script>

<template>
  <SelectItemText
    data-slot="select-item-text"
    v-bind="props"
  >
    <slot />
  </SelectItemText>
</template>
