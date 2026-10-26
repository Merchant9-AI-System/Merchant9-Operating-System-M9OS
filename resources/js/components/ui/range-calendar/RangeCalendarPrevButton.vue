<script lang="ts" setup>
import type { DateValue } from "@internationalized/date"
import type { HTMLAttributes } from "vue"
import { ChevronLeft } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarPrev, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"
import { buttonVariants } from '@/components/ui/button'

// Inline literal (bukan `RangeCalendarPrevProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
  prevPage?: (placeholder: DateValue) => DateValue
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RangeCalendarPrev
    data-slot="range-calendar-prev-button"
    :class="cn(
      buttonVariants({ variant: 'outline' }),
      'absolute left-1',
      'size-7 bg-transparent p-0 opacity-50 hover:opacity-100',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot>
      <ChevronLeft class="size-4" />
    </slot>
  </RangeCalendarPrev>
</template>
