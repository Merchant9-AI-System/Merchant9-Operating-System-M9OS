<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import type { ItemVariants } from "."
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"
import { itemVariants } from "."

// Inline literal (bukan `PrimitiveProps & {...}` drpd reka-ui) - defineProps() dgn type diimport drpd package
// lain gagal build dlm projek ni (rujuk Command.vue / Separator.vue utk corak sama).
const props = withDefaults(defineProps<{
  as?: string
  asChild?: boolean
  class?: HTMLAttributes["class"]
  variant?: ItemVariants["variant"]
  size?: ItemVariants["size"]
}>(), {
  as: "div",
})
</script>

<template>
  <Primitive
    data-slot="item"
    :data-variant="variant"
    :data-size="size"
    :as="as"
    :as-child="asChild"
    :class="cn(itemVariants({ variant, size }), props.class)"
  >
    <slot />
  </Primitive>
</template>
