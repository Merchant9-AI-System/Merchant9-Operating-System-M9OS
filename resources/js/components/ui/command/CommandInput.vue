<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Search } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { ListboxFilter, useForwardProps } from "reka-ui"
import { watch } from "vue"
import { cn } from "@/lib/utils"
import { useCommand } from "."

defineOptions({
  inheritAttrs: false,
})

// Inline literal (bukan `ListboxFilterProps & {...}` drpd reka-ui) - rujuk Command.vue utk sebab.
const props = defineProps<{
  as?: string
  asChild?: boolean
  autoFocus?: boolean
  disabled?: boolean
  class?: HTMLAttributes["class"]
}>()

// v-model SEBENAR (asal generator declare `modelValue` sbg prop mati - tak pernah dibaca dlm
// template, terus v-model terus ke filterState.search context Command induk). Disambung DUA
// HALA dgn filterState.search supaya parent LUAR <Command> (cth. ProductPicker.vue, yg RENDER
// <Command> jadi BUKAN descendant context dia) boleh guna v-model biasa
// (`<CommandInput v-model="query">`) tanpa perlu useCommand() sendiri (provide/inject cuma
// available utk descendant, bukan komponen yg render <Command> tu).
const modelValue = defineModel<string>({ default: "" })

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)

const { filterState } = useCommand()

watch(modelValue, (v) => { filterState.search = v }, { immediate: true })
watch(() => filterState.search, (v) => { modelValue.value = v })
</script>

<template>
  <div
    data-slot="command-input-wrapper"
    class="flex h-9 items-center gap-2 border-b px-3"
  >
    <Search class="size-4 shrink-0 opacity-50" />
    <ListboxFilter
      v-bind="{ ...forwardedProps, ...$attrs }"
      v-model="modelValue"
      data-slot="command-input"
      auto-focus
      :class="cn('placeholder:text-muted-foreground flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-hidden disabled:cursor-not-allowed disabled:opacity-50', props.class)"
    />
  </div>
</template>
