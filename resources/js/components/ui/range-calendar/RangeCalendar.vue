<script lang="ts" setup>
import type { DateValue } from "@internationalized/date"
import type { HTMLAttributes } from "vue"
import type { DateRange } from "reka-ui"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarRoot, useForwardPropsEmits } from "reka-ui"
import { RangeCalendarCell, RangeCalendarCellTrigger, RangeCalendarGrid, RangeCalendarGridBody, RangeCalendarGridHead, RangeCalendarGridRow, RangeCalendarHeadCell, RangeCalendarHeader, RangeCalendarHeading, RangeCalendarNextButton, RangeCalendarPrevButton } from "."
import { cn } from "@/lib/utils"

// Inline literal (bukan `RangeCalendarRootProps & RangeCalendarRootEmits` drpd reka-ui) - rujuk
// Command.vue/Calendar.vue utk sebab (compileScript perlukan akses fs utk flatten type reka-ui,
// tiada dlm persekitaran bina ni).
const props = defineProps<{
  asChild?: boolean
  as?: string
  class?: HTMLAttributes["class"]
  defaultPlaceholder?: DateValue
  defaultValue?: DateRange
  modelValue?: DateRange | null
  placeholder?: DateValue
  allowNonContiguousRanges?: boolean
  pagedNavigation?: boolean
  preventDeselect?: boolean
  maximumDays?: number
  weekStartsOn?: 0 | 1 | 2 | 3 | 4 | 5 | 6
  weekdayFormat?: 'narrow' | 'short' | 'long'
  calendarLabel?: string
  fixedWeeks?: boolean
  maxValue?: DateValue
  minValue?: DateValue
  locale?: string
  numberOfMonths?: number
  disabled?: boolean
  readonly?: boolean
  initialFocus?: boolean
  isDateDisabled?: (date: DateValue) => boolean
  isDateUnavailable?: (date: DateValue) => boolean
  isDateHighlightable?: (date: DateValue) => boolean
  dir?: 'ltr' | 'rtl'
  nextPage?: (placeholder: DateValue) => DateValue
  prevPage?: (placeholder: DateValue) => DateValue
  disableDaysOutsideCurrentView?: boolean
  fixedDate?: 'start' | 'end'
}>()

const emits = defineEmits<{
  'update:modelValue': [date: DateRange]
  'update:validModelValue': [date: DateRange]
  'update:placeholder': [date: DateValue]
  'update:startValue': [date: DateValue | undefined]
}>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <RangeCalendarRoot
    v-slot="{ grid, weekDays }"
    data-slot="range-calendar"
    :class="cn('p-3', props.class)"
    v-bind="forwarded"
  >
    <RangeCalendarHeader>
      <RangeCalendarHeading />

      <div class="flex items-center gap-1">
        <RangeCalendarPrevButton />
        <RangeCalendarNextButton />
      </div>
    </RangeCalendarHeader>

    <div class="flex flex-col gap-y-4 mt-4 sm:flex-row sm:gap-x-4 sm:gap-y-0">
      <RangeCalendarGrid v-for="month in grid" :key="month.value.toString()">
        <RangeCalendarGridHead>
          <RangeCalendarGridRow>
            <RangeCalendarHeadCell
              v-for="day in weekDays" :key="day"
            >
              {{ day }}
            </RangeCalendarHeadCell>
          </RangeCalendarGridRow>
        </RangeCalendarGridHead>
        <RangeCalendarGridBody>
          <RangeCalendarGridRow v-for="(weekDates, index) in month.rows" :key="`weekDate-${index}`" class="mt-2 w-full">
            <RangeCalendarCell
              v-for="weekDate in weekDates"
              :key="weekDate.toString()"
              :date="weekDate"
            >
              <RangeCalendarCellTrigger
                :day="weekDate"
                :month="month.value"
              />
            </RangeCalendarCell>
          </RangeCalendarGridRow>
        </RangeCalendarGridBody>
      </RangeCalendarGrid>
    </div>
  </RangeCalendarRoot>
</template>
