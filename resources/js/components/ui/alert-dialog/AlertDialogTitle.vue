<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { AlertDialogTitle } from "reka-ui"
import { cn } from "@/lib/utils"

// Written locally instead of `defineProps<AlertDialogTitleProps & {...}>()` (reka-ui's type,
// which is just `PrimitiveProps`) - see ui/toggle-group/ToggleGroupItem.vue for why.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <AlertDialogTitle
    data-slot="alert-dialog-title"
    :class="cn('text-lg font-semibold', props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </AlertDialogTitle>
</template>
