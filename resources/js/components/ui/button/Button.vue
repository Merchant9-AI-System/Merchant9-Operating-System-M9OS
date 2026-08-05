<script setup lang="ts">
import { type HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { type ButtonVariants, buttonVariants } from '.';

interface Props {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    class?: HTMLAttributes['class'];
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    type: 'button',
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        :class="cn(buttonVariants({ variant, size }), props.class)"
        class="cursor-pointer"
    >
        <slot />
    </button>
</template>
