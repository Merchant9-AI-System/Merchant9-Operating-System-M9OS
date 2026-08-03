<script setup lang="ts">
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    modelValue?: string | number;
    type?: string;
    placeholder?: string;
    disabled?: boolean;
    min?: string | number;
    step?: string | number;
    autocomplete?: string;
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void;
}>();
</script>

<template>
    <input
        :value="modelValue"
        :type="type ?? 'text'"
        :placeholder="placeholder"
        :disabled="disabled"
        :min="min"
        :step="step"
        :autocomplete="autocomplete"
        :class="cn(
            'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            props.class,
        )"
        @input="emits('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
</template>
