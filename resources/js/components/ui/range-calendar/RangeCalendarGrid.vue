<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarGrid, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `RangeCalendarGridProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RangeCalendarGrid
    data-slot="range-calendar-grid"
    :class="cn('w-full border-collapse space-x-1', props.class)"
    v-bind="forwardedProps"
  >
    <slot />
  </RangeCalendarGrid>
</template>
