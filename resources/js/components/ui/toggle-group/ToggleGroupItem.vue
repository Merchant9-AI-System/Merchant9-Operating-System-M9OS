<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ToggleGroupItem, useForwardProps } from "reka-ui"
import { inject } from "vue"
import { cn } from "@/lib/utils"
import { toggleVariants } from '@/components/ui/toggle'

// Props are spelled out locally instead of `defineProps<ToggleGroupItemProps & {...}>()`
// (reka-ui's type, intersected with a locally-derived variant type) because the current
// vite/@vitejs/plugin-vue build can't resolve types imported from another package or via
// the "@" alias when generating this SFC's runtime props - it throws "No fs option provided
// to compileScript in non-Node environment". This mirrors reka-ui's actual
// `ToggleGroupItemProps = Omit<ToggleProps, 'name' | 'required' | 'modelValue' | 'defaultValue'>
// & { value: AcceptableValue }` shape. If/when that build issue is fixed upstream, this can be
// regenerated with `shadcn-vue add toggle-group` to go back to the type-based version.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
  disabled?: boolean
  value: string | number | bigint | Record<string, any> | null
  class?: HTMLAttributes["class"]
  variant?: "default" | "outline"
  size?: "default" | "sm" | "lg"
}>()

const context = inject<{
  variant?: "default" | "outline"
  size?: "default" | "sm" | "lg"
  spacing?: number
}>("toggleGroup")

const delegatedProps = reactiveOmit(props, "class", "size", "variant")
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <ToggleGroupItem
    v-slot="slotProps"
    data-slot="toggle-group-item"
    :data-variant="context?.variant || variant"
    :data-size="context?.size || size"
    :data-spacing="context?.spacing"
    v-bind="forwardedProps"
    :class="cn(
      toggleVariants({
        variant: context?.variant || variant,
        size: context?.size || size,
      }),
      'w-auto min-w-0 shrink-0 px-3 focus:z-10 focus-visible:z-10',
      'data-[spacing=0]:rounded-none data-[spacing=0]:shadow-none data-[spacing=0]:first:rounded-l-md data-[spacing=0]:last:rounded-r-md data-[spacing=0]:data-[variant=outline]:border-l-0 data-[spacing=0]:data-[variant=outline]:first:border-l',
      props.class)"
  >
    <slot v-bind="slotProps" />
  </ToggleGroupItem>
</template>
