<script setup lang="ts">
import type { DateRange } from 'reka-ui';
import { DateFormatter, getLocalTimeZone, parseDate } from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
import { cn } from '@/lib/utils';

const open = ref(false);

const props = withDefaults(
    defineProps<{
        start?: string | null;
        end?: string | null;
        placeholder?: string;
        numberOfMonths?: number;
        class?: string;
    }>(),
    {
        start: null,
        end: null,
        placeholder: 'Pilih julat tarikh',
        numberOfMonths: 2,
    },
);

const emit = defineEmits<{
    'update:start': [value: string | null];
    'update:end': [value: string | null];
}>();

function safeParse(value: string | null) {
    if (!value) {
        return undefined;
    }

    try {
        return parseDate(value);
    } catch {
        return undefined;
    }
}

// ms-MY - papar tarikh ikut format tempatan, sepadan dgn DatePicker.vue (satu tarikh).
const formatter = new DateFormatter('ms-MY', { day: 'numeric', month: 'short', year: 'numeric' });

const rangeValue = computed<DateRange>(() => ({
    start: safeParse(props.start ?? null),
    end: safeParse(props.end ?? null),
}));

const label = computed(() => {
    const { start, end } = rangeValue.value;

    if (start && end) {
        return `${formatter.format(start.toDate(getLocalTimeZone()))} - ${formatter.format(end.toDate(getLocalTimeZone()))}`;
    }

    if (start) {
        return formatter.format(start.toDate(getLocalTimeZone()));
    }

    return props.placeholder;
});

// Tutup popover automatik bila kedua-dua hujung julat dah dipilih (pengguna tak perlu klik lagi).
function onUpdate(value: DateRange) {
    emit('update:start', value.start ? value.start.toString() : null);
    emit('update:end', value.end ? value.end.toString() : null);

    if (value.start && value.end) {
        open.value = false;
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" variant="outline"
                :class="cn('justify-start text-left font-normal', !rangeValue.start && 'text-muted-foreground', props.class)">
                <CalendarIcon class="mr-2 size-4 shrink-0" />
                <span class="truncate">{{ label }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <RangeCalendar :model-value="rangeValue" :number-of-months="numberOfMonths"
                @update:model-value="onUpdate" />
        </PopoverContent>
    </Popover>
</template>
