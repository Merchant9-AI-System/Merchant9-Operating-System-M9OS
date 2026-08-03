<script setup lang="ts">
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    modelValue?: string;
    placeholder?: string;
    rows?: number;
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string): void;
}>();
</script>

<template>
    <textarea
        :value="modelValue"
        :placeholder="placeholder"
        :rows="rows ?? 3"
        :class="cn(
            'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            props.class,
        )"
        @input="emits('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
</template>
