<script lang="ts" setup>
import type { DateValue } from "@internationalized/date"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarCell, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `RangeCalendarCellProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
// `DateValue` selamat diimport terus drpd @internationalized/date (bukan reka-ui) - pakej lain,
// tak kena isu compiler yg sama.
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
  date: DateValue
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RangeCalendarCell
    data-slot="range-calendar-cell"
    :class="cn(
      'relative p-0 text-center text-sm focus-within:relative focus-within:z-20 [&:has([data-selected])]:bg-accent first:[&:has([data-selected])]:rounded-l-md last:[&:has([data-selected])]:rounded-r-md [&:has([data-selected][data-selection-end])]:rounded-r-md [&:has([data-selected][data-selection-start])]:rounded-l-md',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot />
  </RangeCalendarCell>
</template>
