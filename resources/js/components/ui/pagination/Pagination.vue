<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import { PaginationRoot, useForwardPropsEmits } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

// Prop PaginationRootProps disalin sebagai jenis literal (bukan diimport): bundler projek ini tak dapat meleraikan jenis import
// drpd reka-ui dlm defineProps() - rujuk nota shadcn-vue lain dlm projek ini.
const props = defineProps<{
  page?: number
  defaultPage?: number
  itemsPerPage: number
  total?: number
  siblingCount?: number
  disabled?: boolean
  showEdges?: boolean
  class?: HTMLAttributes["class"]
}>()
const emits = defineEmits<{
  "update:page": [value: number]
}>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <PaginationRoot
    v-slot="slotProps"
    data-slot="pagination"
    v-bind="forwarded"
    :class="cn('mx-auto flex w-full justify-center', props.class)"
  >
    <slot v-bind="slotProps" />
  </PaginationRoot>
</template>
