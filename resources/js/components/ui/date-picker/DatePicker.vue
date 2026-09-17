<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import { CalendarDate, DateFormatter, getLocalTimeZone, parseDate } from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const open = ref(false);

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        placeholder?: string;
        monthOnly?: boolean;
        class?: string;
        id?: string;
    }>(),
    {
        modelValue: null,
        placeholder: 'Pilih tarikh',
        monthOnly: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const dateValue = computed<DateValue | undefined>(() => {
    if (!props.modelValue) {
        return undefined;
    }

    try {
        return parseDate(props.modelValue);
    } catch {
        return undefined;
    }
});

// ms-MY - papar tarikh ikut format tempatan, bukan format storan 'YYYY-MM-DD'.
const formatter = new DateFormatter('ms-MY', props.monthOnly ? { month: 'long', year: 'numeric' } : { day: 'numeric', month: 'long', year: 'numeric' });

const label = computed(() => (dateValue.value ? formatter.format(dateValue.value.toDate(getLocalTimeZone())) : props.placeholder));

function onSelect(value: DateValue | undefined) {
    if (!value) {
        return;
    }

    // Mod bulan (cth. bulan claim) - sentiasa simpan sbg 1hb bulan tu, walau apa2 hari diklik.
    const target = props.monthOnly ? new CalendarDate(value.year, value.month, 1) : value;
    emit('update:modelValue', target.toString());
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                :class="cn('w-full justify-start text-left font-normal', !dateValue && 'text-muted-foreground', props.class)"
            >
                <CalendarIcon class="mr-2 size-4 shrink-0" />
                <span class="truncate">{{ label }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0">
            <Calendar :model-value="dateValue" :layout="monthOnly ? 'month-and-year' : undefined" @update:model-value="onSelect" />
        </PopoverContent>
    </Popover>
</template>
