<script setup lang="ts">
import { AlertDialogTrigger } from "reka-ui"

// Inline literal (bukan `AlertDialogTriggerProps` drpd reka-ui) - rujuk AlertDialog.vue untuk sebab.
const props = defineProps<{
  as?: string
  asChild?: boolean
}>()
</script>

<template>
  <AlertDialogTrigger data-slot="alert-dialog-trigger" v-bind="props">
    <slot />
  </AlertDialogTrigger>
</template>
