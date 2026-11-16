import type { EmblaCarouselType, EmblaOptionsType, EmblaPluginType } from 'embla-carousel'
import type { HTMLAttributes } from 'vue'

export type CarouselApi = EmblaCarouselType
export type CarouselOptions = EmblaOptionsType
export type CarouselPlugin = EmblaPluginType

export interface CarouselProps {
  opts?: CarouselOptions
  plugins?: CarouselPlugin[]
  orientation?: 'horizontal' | 'vertical'
}

export interface CarouselEmits {
  (e: 'init-api', payload: CarouselApi): void
}

export interface WithClassAsProps {
  class?: HTMLAttributes['class']
}
