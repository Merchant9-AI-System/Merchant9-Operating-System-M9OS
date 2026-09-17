<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { X } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import SheetOverlay from "./SheetOverlay.vue"

// Written locally instead of extending `DialogContentProps` / `defineEmits<DialogContentEmits>()`
// (reka-ui's types) - see toggle-group/ToggleGroupItem.vue for why. Event payload types are
// widened to plain `Event` since they're only forwarded here, never inspected.
interface SheetContentProps {
  asChild?: boolean
  as?: string | Component
  disableOutsidePointerEvents?: boolean
  forceMount?: boolean
  class?: HTMLAttributes["class"]
  side?: "top" | "right" | "bottom" | "left"
}

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<SheetContentProps>(), {
  side: "right",
})
const emits = defineEmits<{
  escapeKeyDown: [event: KeyboardEvent]
  pointerDownOutside: [event: Event]
  focusOutside: [event: Event]
  interactOutside: [event: Event]
  openAutoFocus: [event: Event]
  closeAutoFocus: [event: Event]
}>()

const delegatedProps = reactiveOmit(props, "class", "side")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal>
    <SheetOverlay />
    <DialogContent
      data-slot="sheet-content"
      :class="cn(
        'bg-background fixed z-50 flex flex-col gap-4 shadow-lg',
        side === 'right'
          && 'inset-y-0 right-0 h-full w-3/4 border-l sm:max-w-sm data-[state=open]:animate-slide-in-right data-[state=closed]:animate-slide-out-right',
        side === 'left'
          && 'inset-y-0 left-0 h-full w-3/4 border-r sm:max-w-sm data-[state=open]:animate-slide-in-left data-[state=closed]:animate-slide-out-left',
        side === 'top'
          && 'inset-x-0 top-0 h-auto border-b data-[state=open]:animate-slide-in-top data-[state=closed]:animate-slide-out-top',
        side === 'bottom'
          && 'inset-x-0 bottom-0 h-auto border-t data-[state=open]:animate-slide-in-bottom data-[state=closed]:animate-slide-out-bottom',
        props.class)"
      v-bind="{ ...$attrs, ...forwarded }"
    >
      <slot />

      <DialogClose
        class="ring-offset-background focus:ring-ring data-[state=open]:bg-secondary absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none"
      >
        <X class="size-4" />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
