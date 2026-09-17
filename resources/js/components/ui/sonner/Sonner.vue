<script lang="ts" setup>
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

// Written locally instead of `defineProps<ToasterProps>()` (vue-sonner's type) because the
// current vite/@vitejs/plugin-vue build can't resolve types imported from another package when
// generating this SFC's runtime props - it throws "No fs option provided to compileScript in
// non-Node environment" (see toggle-group/ToggleGroupItem.vue for the same fix, in more detail).
// Nested option shapes (toastOptions/icons) are widened to Record<string, any> since they're
// only forwarded here via v-bind, never inspected.
type ToasterPosition = "top-left" | "top-right" | "bottom-left" | "bottom-right" | "top-center" | "bottom-center"

interface ToasterProps {
  id?: string
  invert?: boolean
  theme?: "light" | "dark" | "system"
  position?: ToasterPosition
  closeButtonPosition?: Exclude<ToasterPosition, "top-center" | "bottom-center">
  hotkey?: string[]
  richColors?: boolean
  expand?: boolean
  duration?: number
  gap?: number
  visibleToasts?: number
  closeButton?: boolean
  toastOptions?: Record<string, any>
  class?: string
  style?: Record<string, any>
  offset?: string | number | Record<"top" | "right" | "bottom" | "left", string | number | undefined>
  mobileOffset?: string | number | Record<"top" | "right" | "bottom" | "left", string | number | undefined>
  dir?: "rtl" | "ltr" | "auto"
  swipeDirections?: ("top" | "right" | "bottom" | "left")[]
  icons?: Record<string, any>
  containerAriaLabel?: string
}

const props = defineProps<ToasterProps>()
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--border-radius': 'var(--radius)',
    }"
    v-bind="props"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
