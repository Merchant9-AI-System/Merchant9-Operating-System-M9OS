<script setup lang="ts">
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    modelValue?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();
</script>

<template>
    <input
        type="checkbox"
        :checked="modelValue"
        :class="cn(
            'size-4 shrink-0 rounded-sm border border-input accent-primary',
            props.class,
        )"
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
    >
</template>
