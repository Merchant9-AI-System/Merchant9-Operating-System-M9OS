<script lang="ts" setup>
import { RangeCalendarGridBody } from "reka-ui"

// Inline literal (bukan `RangeCalendarGridBodyProps` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
}>()
</script>

<template>
  <RangeCalendarGridBody
    data-slot="range-calendar-grid-body"
    v-bind="props"
  >
    <slot />
  </RangeCalendarGridBody>
</template>
