<script setup lang="ts">
import { AlertDialogRoot, useForwardPropsEmits } from "reka-ui"

// Inline literal (bukan `AlertDialogProps` / `AlertDialogEmits` drpd reka-ui) - jenis diimport drpd package lain
// gagal build dlm projek ni ("No fs option provided to compileScript"; rujuk Command.vue / Item.vue / Button.vue).
// `open: undefined` lalai: prop boolean yg tak diberi dijadikan `false` oleh Vue, lalu dialog dikawal-tertutup
// selama-lamanya (tak boleh dibuka melalui AlertDialogTrigger).
const props = withDefaults(defineProps<{
  open?: boolean
  defaultOpen?: boolean
}>(), {
  open: undefined,
  defaultOpen: undefined,
})
const emits = defineEmits<{
  "update:open": [value: boolean]
}>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <AlertDialogRoot v-slot="slotProps" data-slot="alert-dialog" v-bind="forwarded">
    <slot v-bind="slotProps" />
  </AlertDialogRoot>
</template>
