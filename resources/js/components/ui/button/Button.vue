<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import type { ButtonVariants } from "."
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"
import { buttonVariants } from "."

// Inline literal (bukan `interface Props extends PrimitiveProps` drpd reka-ui) - jenis diimport drpd package lain
// gagal build dlm projek ni ("Failed to resolve extends base type"; rujuk Command.vue / Item.vue utk corak sama).
// `type` lalai "button" (Button lama projek begitu) - tanpa ini butang dlm <form> menghantar borang secara senyap.
const props = withDefaults(defineProps<{
  as?: string
  asChild?: boolean
  variant?: ButtonVariants["variant"]
  size?: ButtonVariants["size"]
  class?: HTMLAttributes["class"]
  type?: "button" | "submit" | "reset"
}>(), {
  as: "button",
  type: "button",
})
</script>

<template>
  <Primitive
    data-slot="button"
    :data-variant="variant"
    :data-size="size"
    :as="as"
    :as-child="asChild"
    :type="as === 'button' ? type : undefined"
    :class="cn(buttonVariants({ variant, size }), props.class)"
  >
    <slot />
  </Primitive>
</template>
