<script setup lang="ts">
import { DialogRoot, useForwardPropsEmits } from "reka-ui"

// Written locally instead of `defineProps<DialogRootProps>()` / `defineEmits<DialogRootEmits>()`
// (reka-ui's types) - see toggle-group/ToggleGroupItem.vue for why.
const props = defineProps<{
  open?: boolean
  defaultOpen?: boolean
  modal?: boolean
  unmountOnHide?: boolean
}>()
const emits = defineEmits<{
  "update:open": [value: boolean]
}>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <DialogRoot
    v-slot="slotProps"
    data-slot="sheet"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </DialogRoot>
</template>
