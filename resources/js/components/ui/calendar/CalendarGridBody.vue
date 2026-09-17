<script lang="ts" setup>
import { CalendarGridBody } from "reka-ui"

// Inline literal (bukan `CalendarGridBodyProps` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
}>()
</script>

<template>
  <CalendarGridBody
    data-slot="calendar-grid-body"
    v-bind="props"
  >
    <slot />
  </CalendarGridBody>
</template>
