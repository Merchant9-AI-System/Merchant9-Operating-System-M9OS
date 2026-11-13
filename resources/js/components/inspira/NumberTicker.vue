<script setup lang="ts">
import { TransitionPresets, useMediaQuery, useTransition } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';

// Gaya Inspira UI "Number Ticker": semua nombor dlm teks (cth. "387 pcs · 1,939.22 g", "RM1,044,658.12")
// dikira naik dari 0 ke nilai akhir. Perpuluhan & pemisah ribuan mengikut teks asal. Tipografi: nombor
// dipaparkan penuh, manakala unit/awalan (pcs, g, RM, "hutang") lebih kecil & malap; tanda +/- kekal besar.
// Teks tanpa nombor (cth. "Data tidak tersedia") dipaparkan apa adanya. Tiada animasi bila prefers-reduced-motion.
interface Props {
    value: string;
    duration?: number;
}

interface Part {
    text: string;
    kind: 'number' | 'unit' | 'plain';
}

const props = withDefaults(defineProps<Props>(), { duration: 900 });

const NUMBER_PATTERN = /(\d[\d,]*(?:\.\d+)?)/;

const reduceMotion = useMediaQuery('(prefers-reduced-motion: reduce)');
const source = ref(0);
const progress = useTransition(source, { duration: props.duration, transition: TransitionPresets.easeOutCubic });

onMounted(() => {
    source.value = 1;
});

const hasDigits = computed(() => NUMBER_PATTERN.test(props.value));

const parts = computed<Part[]>(() => {
    const t = reduceMotion.value ? 1 : progress.value;

    return props.value.split(NUMBER_PATTERN).flatMap((part, index): Part[] => {
        if (part === '') {
            return [];
        }

        // split() dgn kumpulan tangkapan: nombor sentiasa di indeks ganjil.
        if (index % 2 === 0) {
            return [{ text: part, kind: hasDigits.value && !/^[+-]$/.test(part.trim()) ? 'unit' : 'plain' }];
        }

        const decimals = part.includes('.') ? part.split('.')[1].length : 0;
        const target = Number(part.replace(/,/g, ''));
        const current = target * t;

        return [
            {
                kind: 'number',
                text:
                    part.includes(',') || target >= 1000
                        ? current.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                        : current.toFixed(decimals),
            },
        ];
    });
});
</script>

<template>
    <span class="tabular-nums">
        <template v-for="(part, index) in parts" :key="index">
            <span v-if="part.kind === 'unit'" class="text-[0.5em] font-medium tracking-normal opacity-70">{{ part.text }}</span>
            <template v-else>{{ part.text }}</template>
        </template>
    </span>
</template>
