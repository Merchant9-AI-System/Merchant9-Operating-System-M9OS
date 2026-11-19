<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

// Pembungkus carta (corak ChartContainer shadcn-vue): menetapkan pemboleh ubah CSS Unovis (paksi, grid, tooltip, crosshair) kepada token tema
// projek supaya carta mengikut mod cerah/gelap tanpa gaya berasingan.
const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="chart"
    :class="
      cn(
        'w-full text-xs',
        '[--vis-axis-tick-label-color:var(--muted-foreground)] [--vis-axis-tick-label-font-size:11px]',
        '[--vis-axis-grid-color:var(--border)] [--vis-axis-domain-color:var(--border)] [--vis-axis-tick-color:var(--border)]',
        '[--vis-tooltip-background-color:var(--popover)] [--vis-tooltip-border-color:var(--border)] [--vis-tooltip-text-color:var(--popover-foreground)]',
        '[--vis-crosshair-line-stroke-color:var(--muted-foreground)]',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
