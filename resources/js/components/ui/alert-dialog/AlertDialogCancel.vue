<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { AlertDialogCancel } from "reka-ui"
import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

// Written locally instead of `defineProps<AlertDialogCancelProps & {...}>()` (reka-ui's type,
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
  <AlertDialogCancel
    data-slot="alert-dialog-cancel"
    :class="cn(buttonVariants({ variant: 'outline' }), props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </AlertDialogCancel>
</template>
