<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import { PaginationListItem } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { buttonVariants } from "@/components/ui/button"

const props = withDefaults(
  defineProps<{
    value: number
    isActive?: boolean
    size?: "default" | "xs" | "sm" | "lg" | "icon" | "icon-xs" | "icon-sm" | "icon-lg"
    class?: HTMLAttributes["class"]
  }>(),
  { size: "icon-sm" },
)

const delegatedProps = reactiveOmit(props, "class", "size", "isActive")
</script>

<template>
  <PaginationListItem
    data-slot="pagination-item"
    :data-active="isActive"
    :aria-current="isActive ? 'page' : undefined"
    v-bind="delegatedProps"
    :class="cn(buttonVariants({ variant: isActive ? 'outline' : 'ghost', size }), 'tabular-nums', props.class)"
  >
    <slot />
  </PaginationListItem>
</template>
