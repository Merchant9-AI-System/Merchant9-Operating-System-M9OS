<script setup lang="ts">
import { ChevronLeft } from "@lucide/vue"
import { PaginationPrev } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { buttonVariants } from "@/components/ui/button"

const props = withDefaults(
  defineProps<{
    size?: "default" | "xs" | "sm" | "lg" | "icon" | "icon-xs" | "icon-sm" | "icon-lg"
    class?: HTMLAttributes["class"]
  }>(),
  { size: "sm" },
)
</script>

<template>
  <PaginationPrev
    data-slot="pagination-previous"
    :class="cn(buttonVariants({ variant: 'ghost', size }), 'gap-1 px-2.5', props.class)"
  >
    <slot>
      <ChevronLeft class="size-4" />
      <span class="hidden sm:block">Sebelum</span>
    </slot>
  </PaginationPrev>
</template>
