<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { ArrowRight } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { useCarousel } from "./useCarousel"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { orientation, canScrollNext, scrollNext } = useCarousel()
</script>

<template>
  <Button
    data-slot="carousel-next"
    :disabled="!canScrollNext"
    :class="cn(
      'absolute size-8 touch-manipulation rounded-full',
      orientation === 'horizontal' ? 'top-1/2 -right-12 -translate-y-1/2' : '-bottom-12 left-1/2 -translate-x-1/2 rotate-90',
      props.class,
    )"
    variant="outline"
    size="icon"
    type="button"
    aria-label="Gambar seterusnya"
    @click="scrollNext"
  >
    <slot>
      <ArrowRight class="size-4" />
    </slot>
  </Button>
</template>
