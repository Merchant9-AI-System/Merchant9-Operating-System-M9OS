<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Check, Minus } from "@lucide/vue"
import { CheckboxIndicator, CheckboxRoot } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `CheckboxRootProps & {...}` drpd reka-ui) - defineProps() dgn type diimport
// drpd package lain gagal build dlm projek ni (rujuk Command.vue / Separator.vue utk corak sama).
const props = defineProps<{
  modelValue?: boolean | "indeterminate"
  disabled?: boolean
  class?: HTMLAttributes["class"]
}>()

const emit = defineEmits<{
  "update:modelValue": [value: boolean | "indeterminate"]
}>()
</script>

<template>
  <CheckboxRoot
    data-slot="checkbox"
    :model-value="props.modelValue"
    :disabled="props.disabled"
    :class="cn(
      'peer border-input size-4 shrink-0 rounded-[4px] border shadow-xs outline-none transition-shadow focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground',
      props.class,
    )"
    @update:model-value="(value: boolean | 'indeterminate') => emit('update:modelValue', value)"
  >
    <CheckboxIndicator data-slot="checkbox-indicator" class="flex items-center justify-center text-current">
      <Minus v-if="props.modelValue === 'indeterminate'" class="size-3.5" />
      <Check v-else class="size-3.5" />
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
