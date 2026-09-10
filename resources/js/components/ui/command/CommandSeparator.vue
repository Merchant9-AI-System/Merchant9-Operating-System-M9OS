<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { Separator } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `SeparatorProps & {...}` drpd reka-ui) - rujuk Command.vue/Separator.vue
// (ui/separator, sama isu) utk sebab.
const props = defineProps<{
  as?: string
  asChild?: boolean
  orientation?: "horizontal" | "vertical"
  decorative?: boolean
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <Separator
    data-slot="command-separator"
    v-bind="delegatedProps"
    :class="cn('bg-border -mx-1 h-px', props.class)"
  >
    <slot />
  </Separator>
</template>
