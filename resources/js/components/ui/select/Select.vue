<script setup lang="ts">
import { SelectRoot, useForwardPropsEmits } from "reka-ui"

const props = defineProps<{
  open?: boolean
  defaultOpen?: boolean
  defaultValue?: unknown
  modelValue?: unknown
  nullableValue?: string
  by?: string | ((a: unknown, b: unknown) => boolean)
  dir?: "ltr" | "rtl"
  multiple?: boolean
  autocomplete?: string
  disabled?: boolean
  name?: string
  required?: boolean
}>()
const emits = defineEmits<{
  "update:modelValue": [value: unknown]
  "update:open": [value: boolean]
}>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <SelectRoot
    v-slot="slotProps"
    data-slot="select"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </SelectRoot>
</template>
