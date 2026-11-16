import { createInjectionState } from '@vueuse/core'
import emblaCarouselVue from 'embla-carousel-vue'
import { onMounted, ref } from 'vue'
import type { CarouselApi, CarouselProps } from './interface'

// Keadaan carousel dikongsi antara <Carousel> dan anak-anaknya (Content/Item/Previous/Next) melalui provide/inject.
// Selain panah, `selectedIndex` + `scrollTo` didedahkan utk penunjuk titik / lakaran kecil.
const [useProvideCarousel, useInjectCarousel] = createInjectionState(({ opts, orientation, plugins }: CarouselProps, emit: (event: 'init-api', api: CarouselApi) => void) => {
  const [emblaNode, emblaApi] = emblaCarouselVue({ ...opts, axis: orientation === 'vertical' ? 'y' : 'x' }, plugins)

  const canScrollPrev = ref(false)
  const canScrollNext = ref(false)
  const selectedIndex = ref(0)
  const slideCount = ref(0)

  function scrollPrev() {
    emblaApi.value?.scrollPrev()
  }

  function scrollNext() {
    emblaApi.value?.scrollNext()
  }

  function scrollTo(index: number) {
    emblaApi.value?.scrollTo(index)
  }

  function onSelect(api?: CarouselApi) {
    canScrollPrev.value = api?.canScrollPrev() ?? false
    canScrollNext.value = api?.canScrollNext() ?? false
    selectedIndex.value = api?.selectedScrollSnap() ?? 0
    slideCount.value = api?.scrollSnapList().length ?? 0
  }

  onMounted(() => {
    const api = emblaApi.value

    if (!api) {
      return
    }

    onSelect(api)
    api.on('init', onSelect)
    api.on('reInit', onSelect)
    api.on('select', onSelect)
    emit('init-api', api)
  })

  return { carouselRef: emblaNode, carouselApi: emblaApi, canScrollPrev, canScrollNext, selectedIndex, slideCount, scrollPrev, scrollNext, scrollTo, orientation }
})

function useCarousel() {
  const carouselState = useInjectCarousel()

  if (!carouselState) {
    throw new Error('useCarousel mesti digunakan di dalam <Carousel />')
  }

  return carouselState
}

export { useCarousel, useProvideCarousel }
