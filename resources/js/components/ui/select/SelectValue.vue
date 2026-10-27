<script setup lang="ts">
import { SelectValue } from "reka-ui"

const props = defineProps<{
  asChild?: boolean
  as?: string | object
  placeholder?: string
}>()
</script>

<template>
  <SelectValue
    data-slot="select-value"
    v-bind="props"
  >
    <slot />
  </SelectValue>
</template>
