<script setup lang="ts">
import { Ellipsis } from "@lucide/vue"
import { PaginationEllipsis } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <PaginationEllipsis
    data-slot="pagination-ellipsis"
    :class="cn('flex size-8 items-center justify-center', props.class)"
  >
    <slot>
      <Ellipsis class="size-4" />
      <span class="sr-only">Halaman lain</span>
    </slot>
  </PaginationEllipsis>
</template>
