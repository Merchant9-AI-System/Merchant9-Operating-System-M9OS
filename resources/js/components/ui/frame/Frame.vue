<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { frameVariants } from "."

const props = withDefaults(
  defineProps<{
    variant?: "default" | "ghost"
    spacing?: "sm" | "default" | "lg"
    // Panel bersebelahan (anak terus) bercantum dlm satu lajur dgn sempadan dikongsi.
    stacked?: boolean
    // Tiada padding & jurang - panel melekat pd sempadan luar Frame.
    dense?: boolean
    class?: HTMLAttributes["class"]
  }>(),
  { variant: "default", spacing: "default", stacked: false, dense: false },
)
</script>

<template>
  <div
    data-slot="frame"
    :data-variant="props.variant"
    :data-stacked="props.stacked"
    :data-dense="props.dense"
    :class="cn(frameVariants({ variant: props.variant, spacing: props.spacing, stacked: props.stacked, dense: props.dense }), props.class)"
  >
    <slot />
  </div>
</template>
