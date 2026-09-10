<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ListboxRoot, useFilter, useForwardPropsEmits } from "reka-ui"
import { reactive, ref, watch } from "vue"
import { cn } from "@/lib/utils"
import { provideCommandContext } from "."

// Inline literal (bukan `ListboxRootProps & {...}` drpd reka-ui) - defineProps() dgn type
// diimport drpd package lain gagal build dlm projek ni (rujuk Separator.vue utk corak sama).
const props = withDefaults(defineProps<{
  as?: string
  asChild?: boolean
  modelValue?: string | string[]
  defaultValue?: string | string[]
  multiple?: boolean
  orientation?: "horizontal" | "vertical"
  dir?: "ltr" | "rtl"
  disabled?: boolean
  selectionBehavior?: "toggle" | "replace"
  highlightOnHover?: boolean
  by?: string | ((a: string, b: string) => boolean)
  name?: string
  required?: boolean
  class?: HTMLAttributes["class"]
  // shouldFilter=false - matikan text-filter tempatan (contains() drpd useFilter) - WAJIB bila
  // <CommandItem> yg mount DIKAWAL sepenuhnya oleh parent (cth. hasil carian remote/server yg
  // dah pun ditapis di server - ProductPicker.vue), bukan senarai statik client. Default true
  // (behavior asal, elak pecahkan usage Command lain cth. CommandDialog kategori statik).
  shouldFilter?: boolean
}>(), {
  modelValue: "",
  highlightOnHover: true,
  shouldFilter: true,
})

const emits = defineEmits<{
  "update:modelValue": [value: string]
  highlight: [payload: { ref: HTMLElement; value: string } | undefined]
  entryFocus: [event: CustomEvent]
  leave: [event: Event]
}>()

const delegatedProps = reactiveOmit(props, "class", "shouldFilter")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const allItems = ref<Map<string, string>>(new Map())
const allGroups = ref<Map<string, Set<string>>>(new Map())

const { contains } = useFilter({ sensitivity: "base" })
const filterState = reactive({
  search: "",
  filtered: {
    /** The count of all visible items. */
    count: 0,
    /** Map from visible item id to its search score. */
    items: new Map() as Map<string, number>,
    /** Set of groups with at least one visible item. */
    groups: new Set() as Set<string>,
  },
})

function filterItems() {
  if (!props.shouldFilter) {
    // Mod terkawal - CommandItem yg mount dikawal SEPENUHNYA oleh parent (padanan dah jadi di
    // server), jadi kira SEMUA yg mount sbg "sepadan". CommandEmpty (filtered.count === 0)
    // tetap betul sbb 0 CommandItem mount = 0 hasil sebenar.
    filterState.filtered.count = allItems.value.size
    filterState.filtered.groups = new Set(allGroups.value.keys())
    return
  }

  if (!filterState.search) {
    filterState.filtered.count = allItems.value.size
    // Do nothing, each item will know to show itself because search is empty
    return
  }

  // Reset the groups
  filterState.filtered.groups = new Set()
  let itemCount = 0

  // Check which items should be included
  for (const [id, value] of allItems.value) {
    const score = contains(value, filterState.search)
    filterState.filtered.items.set(id, score ? 1 : 0)
    if (score)
      itemCount++
  }

  // Check which groups have at least 1 item shown
  for (const [groupId, group] of allGroups.value) {
    for (const itemId of group) {
      if (filterState.filtered.items.get(itemId)! > 0) {
        filterState.filtered.groups.add(groupId)
        break
      }
    }
  }

  filterState.filtered.count = itemCount
}

watch(() => filterState.search, () => {
  filterItems()
})

// Recompute jugak bila BILANGAN CommandItem yg mount berubah (cth. hasil carian remote baru
// sampai/hilang) - mod shouldFilter=false punya "count" bergantung BILA item mount/unmount,
// BUKAN bila teks carian berubah (watch di atas hanya on filterState.search). Getter
// `allItems.value.size` (bukan `watch(allItems, ..., {deep:true})`) - deep-watch terus pd ref
// yg pegang Map TIDAK reliable trigger di sini (disahkan sebenar - CommandEmpty kekal "0 hasil"
// walau CommandItem dah mount), getter size lebih tepat drpd proxy reaktif Map.
watch(() => allItems.value.size, () => {
  filterItems()
})

provideCommandContext({
  allItems,
  allGroups,
  filterState,
})
</script>

<template>
  <ListboxRoot
    data-slot="command"
    v-bind="forwarded"
    :class="cn('bg-popover text-popover-foreground flex h-full w-full flex-col overflow-hidden rounded-md', props.class)"
  >
    <slot />
  </ListboxRoot>
</template>
