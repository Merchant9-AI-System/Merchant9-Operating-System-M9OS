<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import type { ToggleVariants } from "."
import { reactiveOmit } from "@vueuse/core"
import { Toggle, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"
import { toggleVariants } from "."

// The `asChild`/`as`/`name`/`required`/`defaultValue`/`modelValue`/`disabled` fields below mirror
// reka-ui's `ToggleProps` (PrimitiveProps & FormFieldProps, plus defaultValue/modelValue/disabled).
// They're spelled out locally instead of `defineProps<ToggleProps & {...}>()` because the current
// vite/@vitejs/plugin-vue build can't resolve types imported from another package when generating
// this SFC's runtime props - it throws "No fs option provided to compileScript in non-Node
// environment" (see ToggleGroupItem.vue/ToggleGroup.vue for the same fix). If/when that build issue
// is fixed upstream, this can be regenerated with `shadcn-vue add toggle` to go back to the
// type-based version.
const props = withDefaults(defineProps<{
  asChild?: boolean
  as?: string | Component
  name?: string
  required?: boolean
  defaultValue?: boolean
  modelValue?: boolean | null
  disabled?: boolean
  class?: HTMLAttributes["class"]
  variant?: ToggleVariants["variant"]
  size?: ToggleVariants["size"]
}>(), {
  variant: "default",
  size: "default",
  disabled: false,
})

const emits = defineEmits<{
  "update:modelValue": [value: boolean]
}>()

const delegatedProps = reactiveOmit(props, "class", "size", "variant")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <Toggle
    v-slot="slotProps"
    data-slot="toggle"
    v-bind="forwarded"
    :class="cn(toggleVariants({ variant, size }), props.class)"
  >
    <slot v-bind="slotProps" />
  </Toggle>
</template>
