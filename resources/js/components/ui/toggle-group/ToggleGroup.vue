<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ToggleGroupRoot, useForwardPropsEmits } from "reka-ui"
import { provide } from "vue"
import { cn } from "@/lib/utils"
import { toggleVariants } from '@/components/ui/toggle'

// Props/emits are spelled out locally instead of `defineProps<ToggleGroupRootProps & {...}>()`
// (see ToggleGroupItem.vue for why) because the current vite/@vitejs/plugin-vue build can't
// resolve types imported from another package (reka-ui) or via the "@" alias when generating
// this SFC's runtime props - it throws "No fs option provided to compileScript in non-Node
// environment". This mirrors reka-ui's actual `ToggleGroupRootProps` shape (PrimitiveProps &
// FormFieldProps & SingleOrMultipleProps, plus rovingFocus/disabled/orientation/dir/loop). If/when
// that build issue is fixed upstream, this can be regenerated with `shadcn-vue add toggle-group`
// to go back to the type-based version.
const props = withDefaults(defineProps<{
  asChild?: boolean
  as?: string | Component
  name?: string
  required?: boolean
  type?: "single" | "multiple"
  modelValue?: string | number | bigint | Record<string, any> | null | (string | number | bigint | Record<string, any> | null)[]
  defaultValue?: string | number | bigint | Record<string, any> | null | (string | number | bigint | Record<string, any> | null)[]
  rovingFocus?: boolean
  disabled?: boolean
  orientation?: "vertical" | "horizontal"
  dir?: "ltr" | "rtl"
  loop?: boolean
  class?: HTMLAttributes["class"]
  variant?: "default" | "outline"
  size?: "default" | "sm" | "lg"
  spacing?: number
}>(), {
  spacing: 0,
})

const emits = defineEmits<{
  "update:modelValue": [payload: string | number | bigint | Record<string, any> | null | (string | number | bigint | Record<string, any> | null)[]]
}>()

provide("toggleGroup", {
  variant: props.variant,
  size: props.size,
  spacing: props.spacing,
})

const delegatedProps = reactiveOmit(props, "class", "size", "variant")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ToggleGroupRoot
    v-slot="slotProps"
    data-slot="toggle-group"
    :data-size="size"
    :data-variant="variant"
    :data-spacing="spacing"
    :style="{
      '--gap': spacing,
    }"
    v-bind="forwarded"
    :class="cn('group/toggle-group flex w-fit items-center gap-[--spacing(var(--gap))] rounded-md data-[spacing=default]:data-[variant=outline]:shadow-xs', props.class)"
  >
    <slot v-bind="slotProps" />
  </ToggleGroupRoot>
</template>
