<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="frame-footer"
    :class="cn('text-muted-foreground flex items-center gap-2 px-4 py-2 text-sm', props.class)"
  >
    <slot />
  </div>
</template>
