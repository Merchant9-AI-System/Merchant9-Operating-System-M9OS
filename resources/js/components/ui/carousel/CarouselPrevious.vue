<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { ArrowLeft } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { useCarousel } from "./useCarousel"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { orientation, canScrollPrev, scrollPrev } = useCarousel()
</script>

<template>
  <Button
    data-slot="carousel-previous"
    :disabled="!canScrollPrev"
    :class="cn(
      'absolute size-8 touch-manipulation rounded-full',
      orientation === 'horizontal' ? 'top-1/2 -left-12 -translate-y-1/2' : '-top-12 left-1/2 -translate-x-1/2 rotate-90',
      props.class,
    )"
    variant="outline"
    size="icon"
    type="button"
    aria-label="Gambar sebelum"
    @click="scrollPrev"
  >
    <slot>
      <ArrowLeft class="size-4" />
    </slot>
  </Button>
</template>
