<script lang="ts" setup>
import type { DateValue } from "@internationalized/date"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { CalendarCell, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `CalendarCellProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
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
  <CalendarCell
    data-slot="calendar-cell"
    :class="cn('relative p-0 text-center text-sm focus-within:relative focus-within:z-20 flex-1 [&:has([data-selected])]:rounded-md [&:has([data-selected])]:bg-accent', props.class)"
    v-bind="forwardedProps"
  >
    <slot />
  </CalendarCell>
</template>
