<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"

// Inline literal (bukan `extends PrimitiveProps` drpd reka-ui) - rujuk Command.vue utk sebab.
// `Primitive` turut ditambah import (tertinggal dlm sumber asal shadcn-vue - guna dlm template
// tanpa diimport, akan gagal compile).
interface Props {
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
}
const props = withDefaults(defineProps<Props>(), {
  as: "button",
})
</script>

<template>
  <Primitive
    data-slot="attachment-trigger"
    :as="as"
    :as-child="asChild"
    :class="cn('absolute inset-0 z-10 outline-none', props.class)"
  >
    <slot />
  </Primitive>
</template>
