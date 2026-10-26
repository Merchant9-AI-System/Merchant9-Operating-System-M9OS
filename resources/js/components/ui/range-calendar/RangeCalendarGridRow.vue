<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarGridRow, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `RangeCalendarGridRowProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RangeCalendarGridRow
    data-slot="range-calendar-grid-row"
    :class="cn('flex', props.class)" v-bind="forwardedProps"
  >
    <slot />
  </RangeCalendarGridRow>
</template>
