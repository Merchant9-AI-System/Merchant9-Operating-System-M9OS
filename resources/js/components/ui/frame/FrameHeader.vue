<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="frame-header"
    :class="cn('flex flex-col gap-0.5 px-4 py-2.5', props.class)"
  >
    <slot />
  </div>
</template>
