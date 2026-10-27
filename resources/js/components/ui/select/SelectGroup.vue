<script setup lang="ts">
import { SelectGroup } from "reka-ui"

const props = defineProps<{
  asChild?: boolean
  as?: string | object
}>()
</script>

<template>
  <SelectGroup
    data-slot="select-group"
    v-bind="props"
  >
    <slot />
  </SelectGroup>
</template>
