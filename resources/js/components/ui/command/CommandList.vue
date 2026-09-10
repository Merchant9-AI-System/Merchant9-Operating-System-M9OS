<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ListboxContent, useForwardProps } from "reka-ui"
import { ScrollArea } from "@/components/ui/scroll-area"
import { cn } from "@/lib/utils"

// Inline literal (bukan `ListboxContentProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  as?: string
  asChild?: boolean
  class?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <ListboxContent
    data-slot="command-list"
    v-bind="forwarded"
    :class="cn('max-h-[300px] overflow-hidden', props.class)"
  >
    <!-- ScrollArea (bukan overflow-y-auto native) - scrollbar custom konsisten dgn ScrollArea
         ui lain projek ni. scrollIntoView() (dipanggil reka-ui bila navigasi anak panah/select)
         API browser asli - cari ancestor scrollable TERDEKAT scr automatik, ScrollAreaViewport
         (overflow sebenar dlm ScrollArea) tetap dikesan walau bukan overflow-y-auto terus. -->
    <ScrollArea class="h-full max-h-[300px]">
      <div role="presentation" class="scroll-py-1">
        <slot />
      </div>
    </ScrollArea>
  </ListboxContent>
</template>
