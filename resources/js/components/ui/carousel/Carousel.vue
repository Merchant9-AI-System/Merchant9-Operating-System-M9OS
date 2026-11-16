<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import type { CarouselApi, CarouselOptions, CarouselPlugin } from "./interface"
import { cn } from "@/lib/utils"
import { useProvideCarousel } from "./useCarousel"

// Jenis props ditulis inline (bukan CarouselProps diimport) - defineProps() dgn jenis diimport gagal dibina dlm projek ini.
// `opts` & `plugins` dihantar apa adanya kpd embla.
const props = withDefaults(
  defineProps<{
    opts?: Record<string, unknown>
    plugins?: unknown[]
    orientation?: "horizontal" | "vertical"
    class?: HTMLAttributes["class"]
  }>(),
  { orientation: "horizontal" },
)

const emits = defineEmits<{
  "init-api": [api: CarouselApi]
}>()

const { canScrollNext, canScrollPrev, carouselApi, carouselRef, orientation, scrollNext, scrollPrev, scrollTo, selectedIndex, slideCount } = useProvideCarousel(
  { opts: props.opts as CarouselOptions | undefined, plugins: props.plugins as CarouselPlugin[] | undefined, orientation: props.orientation },
  (_event, api) => emits("init-api", api),
)

defineExpose({
  canScrollNext,
  canScrollPrev,
  carouselApi,
  carouselRef,
  orientation,
  scrollNext,
  scrollPrev,
  scrollTo,
  selectedIndex,
  slideCount,
})

function onKeyDown(event: KeyboardEvent) {
  const prevKey = props.orientation === "vertical" ? "ArrowUp" : "ArrowLeft"
  const nextKey = props.orientation === "vertical" ? "ArrowDown" : "ArrowRight"

  if (event.key === prevKey) {
    event.preventDefault()
    scrollPrev()
  } else if (event.key === nextKey) {
    event.preventDefault()
    scrollNext()
  }
}
</script>

<template>
  <div
    data-slot="carousel"
    :class="cn('relative', props.class)"
    role="region"
    aria-roledescription="carousel"
    tabindex="0"
    @keydown="onKeyDown"
  >
    <slot
      :can-scroll-next="canScrollNext"
      :can-scroll-prev="canScrollPrev"
      :carousel-api="carouselApi"
      :scroll-next="scrollNext"
      :scroll-prev="scrollPrev"
      :scroll-to="scrollTo"
      :selected-index="selectedIndex"
      :slide-count="slideCount"
    />
  </div>
</template>
