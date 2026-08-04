<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { NumberFieldRoot, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<{
  defaultValue?: number
  modelValue?: number | null
  min?: number
  max?: number
  step?: number
  stepSnapping?: boolean
  focusOnChange?: boolean
  formatOptions?: Intl.NumberFormatOptions
  locale?: string
  disabled?: boolean
  readonly?: boolean
  disableWheelChange?: boolean
  invertWheelChange?: boolean
  id?: string
  name?: string
  required?: boolean
  as?: string
  asChild?: boolean
  class?: HTMLAttributes["class"]
}>()
const emits = defineEmits<{
  (e: "update:modelValue", val: number): void
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <NumberFieldRoot v-slot="slotProps" v-bind="forwarded" :class="cn('grid gap-1.5', props.class)">
    <slot v-bind="slotProps" />
  </NumberFieldRoot>
</template>
