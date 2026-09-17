<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { CalendarGridHead } from "reka-ui"

// Inline literal (bukan `CalendarGridHeadProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <CalendarGridHead
    data-slot="calendar-grid-head"
    v-bind="props"
  >
    <slot />
  </CalendarGridHead>
</template>
