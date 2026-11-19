<script setup lang="ts">
import { ChevronRight } from "@lucide/vue"
import { PaginationNext } from "reka-ui"
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
  <PaginationNext
    data-slot="pagination-next"
    :class="cn(buttonVariants({ variant: 'ghost', size }), 'gap-1 px-2.5', props.class)"
  >
    <slot>
      <span class="hidden sm:block">Seterusnya</span>
      <ChevronRight class="size-4" />
    </slot>
  </PaginationNext>
</template>
