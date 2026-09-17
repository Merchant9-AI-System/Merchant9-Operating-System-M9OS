<script setup lang="ts">
import type { Component } from "vue"
import { AlertDialogTrigger } from "reka-ui"

// Written locally instead of `defineProps<AlertDialogTriggerProps>()` (reka-ui's type, which is
// just `PrimitiveProps`) - see ui/toggle-group/ToggleGroupItem.vue for why.
const props = defineProps<{
  asChild?: boolean
  as?: string | Component
}>()
</script>

<template>
  <AlertDialogTrigger data-slot="alert-dialog-trigger" v-bind="props">
    <slot />
  </AlertDialogTrigger>
</template>
