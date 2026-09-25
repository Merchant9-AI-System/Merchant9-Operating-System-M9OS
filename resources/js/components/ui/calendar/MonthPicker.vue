<script lang="ts" setup>
import type { DateValue } from "@internationalized/date"
import type { Ref } from "vue"
import { getLocalTimeZone, today } from "@internationalized/date"
import { ChevronLeft, ChevronRight } from "@lucide/vue"
import { useVModel } from "@vueuse/core"
import {
  MonthPickerCell,
  MonthPickerCellTrigger,
  MonthPickerGrid,
  MonthPickerGridBody,
  MonthPickerGridRow,
  MonthPickerHeader,
  MonthPickerHeading,
  MonthPickerNext,
  MonthPickerPrev,
  MonthPickerRoot,
} from "reka-ui"
import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

// Picker BULAN SAHAJA (tiada grid hari langsung) - guna primitif reka-ui MonthPicker* terus,
// BUKAN Calendar* + layout="month-and-year" (versi lama tu cuma tukar heading, grid hari tetap
// ada & boleh diklik - rujuk DatePicker.vue sblm ni). `grid` MonthPickerRoot ialah SATU objek
// {value, cells, rows} - rows dah di-chunk 4 bulan/baris utk 1 tahun/12 bulan (rujuk
// createMonthGrid, node_modules/reka-ui/dist/date/calendar.js) - BUKAN array "halaman" spt
// CalendarRoot punya `grid`, jadi TIADA v-for luar utk "tahun", terus iterate grid.rows.
const props = defineProps<{
  modelValue?: DateValue
  placeholder?: DateValue
  minValue?: DateValue
  maxValue?: DateValue
}>()

const emit = defineEmits<{
  "update:modelValue": [date: DateValue | undefined]
  "update:placeholder": [date: DateValue]
}>()

const placeholder = useVModel(props, "placeholder", emit, {
  passive: true,
  defaultValue: props.modelValue ?? today(getLocalTimeZone()),
}) as Ref<DateValue>
</script>

<template>
  <MonthPickerRoot
    v-slot="{ grid }"
    :model-value="modelValue"
    v-model:placeholder="placeholder"
    :min-value="minValue"
    :max-value="maxValue"
    data-slot="month-picker"
    class="p-3"
    @update:model-value="(date) => emit('update:modelValue', date as DateValue | undefined)"
  >
    <MonthPickerHeader class="relative flex w-full items-center justify-between">
      <MonthPickerPrev
        :class="cn(buttonVariants({ variant: 'outline' }), 'size-7 bg-transparent p-0 opacity-50 hover:opacity-100')"
      >
        <ChevronLeft class="size-4" />
      </MonthPickerPrev>
      <MonthPickerHeading class="text-sm font-medium" />
      <MonthPickerNext
        :class="cn(buttonVariants({ variant: 'outline' }), 'size-7 bg-transparent p-0 opacity-50 hover:opacity-100')"
      >
        <ChevronRight class="size-4" />
      </MonthPickerNext>
    </MonthPickerHeader>

    <MonthPickerGrid class="mt-4 w-full border-collapse">
      <MonthPickerGridBody>
        <MonthPickerGridRow v-for="(monthRow, index) in grid.rows" :key="index" class="flex w-full">
          <MonthPickerCell
            v-for="monthDate in monthRow"
            :key="monthDate.toString()"
            :date="monthDate"
            class="flex-1 p-0.5 text-center"
          >
            <MonthPickerCellTrigger
              as="button"
              :month="monthDate"
              :class="cn(
                buttonVariants({ variant: 'ghost' }),
                'size-full min-w-14 p-0 font-normal aria-selected:opacity-100',
                '[&[data-today]:not([data-selected])]:bg-accent [&[data-today]:not([data-selected])]:text-accent-foreground',
                'data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[selected]:opacity-100 [&[data-selected]:hover]:bg-primary data-[selected]:hover:text-primary-foreground data-[selected]:focus:bg-primary data-[selected]:focus:text-primary-foreground',
                'data-[disabled]:text-muted-foreground data-[disabled]:opacity-50',
                'data-[unavailable]:text-destructive-foreground data-[unavailable]:line-through',
              )"
            />
          </MonthPickerCell>
        </MonthPickerGridRow>
      </MonthPickerGridBody>
    </MonthPickerGrid>
  </MonthPickerRoot>
</template>
