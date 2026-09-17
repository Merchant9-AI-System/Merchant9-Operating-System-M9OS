<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  AlertDialogContent,
  AlertDialogOverlay,
  AlertDialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

// Written locally instead of `defineProps<AlertDialogContentProps>()` /
// `defineEmits<AlertDialogContentEmits>()` (reka-ui's types, identical shape to
// DialogContentProps/DialogContentEmits) - see ui/toggle-group/ToggleGroupItem.vue for why.
// Event payload types are widened to plain `Event` since they're only forwarded here, never
// inspected.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
  disableOutsidePointerEvents?: boolean
  forceMount?: boolean
  class?: HTMLAttributes["class"]
}>()
const emits = defineEmits<{
  escapeKeyDown: [event: KeyboardEvent]
  pointerDownOutside: [event: Event]
  focusOutside: [event: Event]
  interactOutside: [event: Event]
  openAutoFocus: [event: Event]
  closeAutoFocus: [event: Event]
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <AlertDialogPortal>
    <AlertDialogOverlay
      class="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-fade-in data-[state=closed]:animate-fade-out"
    />
    <AlertDialogContent
      data-slot="alert-dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="cn(
        'fixed top-1/2 left-1/2 z-50 grid w-full max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 rounded-lg border bg-background p-6 shadow-lg duration-200 data-[state=open]:animate-fade-in data-[state=closed]:animate-fade-out',
        props.class,
      )"
    >
      <slot />
    </AlertDialogContent>
  </AlertDialogPortal>
</template>
