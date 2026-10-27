<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { cn } from "@/lib/utils"
import { SelectSeparator } from "reka-ui"

const props = defineProps<{
  asChild?: boolean
  as?: string | object
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <SelectSeparator
    data-slot="select-separator"
    v-bind="delegatedProps"
    :class="cn('bg-border pointer-events-none -mx-1 my-1 h-px', props.class)"
  />
</template>
