<script setup lang="ts">
import { useForwardPropsEmits } from "reka-ui"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import Command from "./Command.vue"

// Inline literal (bukan `DialogRootProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
// Cuma `open` yg diforward - Dialog.vue (wrapper projek ni, BUKAN reka-ui DialogRoot terus)
// hanya declare prop `open`/emit `update:open`, jadi modal/defaultOpen/unmountOnHide tak
// berguna diforward pun (Dialog.vue tak consume).
const props = withDefaults(defineProps<{
  open?: boolean
  title?: string
  description?: string
  // Forward ke Command dalaman - rujuk Command.vue (default true, senarai statik/kecil spt
  // kategori sesuai filter tempatan; set false utk kandungan yg dah ditapis luar cth. server).
  shouldFilter?: boolean
}>(), {
  title: "Command Palette",
  description: "Search for a command to run...",
  shouldFilter: true,
})
const emits = defineEmits<{
  "update:open": [value: boolean]
}>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <Dialog v-slot="slotProps" v-bind="forwarded">
    <!-- overlay-class TIADA backdrop-blur-sm (dulu ada) - animate opacity atas elemen
         backdrop-filter:blur() paksa browser re-sample+re-composite SEMUA di belakangnya
         SETIAP frame transisi buka/tutup, lagi teruk bila kandungan dialog (cadangan restock +
         imej) turut mounting serentak - nampak/rasa tersekat-sekat (jank) bila dialog dibuka,
         bukan smooth. Overlay dim biasa (bg-black/50, sama pattern Dialog/AlertDialog/Sheet lain
         dlm app ni) cukup, tiada blur perlu dianimasi. -->
    <DialogContent hide-close class="overflow-hidden rounded-xl border p-0 shadow-2xl">
      <DialogHeader class="sr-only">
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>{{ description }}</DialogDescription>
      </DialogHeader>
      <Command :should-filter="shouldFilter">
        <slot v-bind="slotProps" />
      </Command>
    </DialogContent>
  </Dialog>
</template>
