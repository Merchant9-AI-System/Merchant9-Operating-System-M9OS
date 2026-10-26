<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { RangeCalendarGridHead } from "reka-ui"

// Inline literal (bukan `RangeCalendarGridHeadProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <RangeCalendarGridHead
    data-slot="range-calendar-grid-head"
    v-bind="props"
  >
    <slot />
  </RangeCalendarGridHead>
</template>
