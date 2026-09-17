<script setup lang="ts">
import { AlertDialogRoot, useForwardPropsEmits } from "reka-ui"

// Written locally instead of `defineProps<AlertDialogProps>()` / `defineEmits<AlertDialogEmits>()`
// (reka-ui's types) - see ui/toggle-group/ToggleGroupItem.vue for why (Vite/@vitejs/plugin-vue
// can't resolve types imported from another package when generating this SFC's runtime props).
const props = defineProps<{
  open?: boolean
  defaultOpen?: boolean
  unmountOnHide?: boolean
}>()
const emits = defineEmits<{
  "update:open": [value: boolean]
}>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <AlertDialogRoot v-bind="forwarded">
    <slot />
  </AlertDialogRoot>
</template>
