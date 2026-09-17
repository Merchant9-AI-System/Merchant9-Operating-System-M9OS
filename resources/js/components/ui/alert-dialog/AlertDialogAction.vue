<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { AlertDialogAction } from "reka-ui"
import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

// Written locally instead of `defineProps<AlertDialogActionProps & {...}>()` (reka-ui's type,
// which is just `PrimitiveProps`, same as DialogCloseProps) - see
// ui/toggle-group/ToggleGroupItem.vue for why.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <AlertDialogAction data-slot="alert-dialog-action" :class="cn(buttonVariants(), props.class)" v-bind="delegatedProps">
    <slot />
  </AlertDialogAction>
</template>
