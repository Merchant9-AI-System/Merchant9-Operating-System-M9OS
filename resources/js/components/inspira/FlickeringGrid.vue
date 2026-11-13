<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { cn } from '@/lib/utils';

// Gaya Inspira UI "Flickering Grid": grid petak kecil di atas canvas, setiap petak berkelip rawak. Dijeda bila
// tab tersembunyi atau canvas di luar skrin; bila prefers-reduced-motion hanya dilukis sekali (statik).
interface Props {
    squareSize?: number;
    gridGap?: number;
    flickerChance?: number;
    // Warna petak, format "r, g, b" (cth. "217, 178, 76").
    color?: string;
    maxOpacity?: number;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    squareSize: 4,
    gridGap: 6,
    flickerChance: 0.25,
    color: '217, 178, 76',
    maxOpacity: 0.35,
    class: undefined,
});

const reduceMotion = useMediaQuery('(prefers-reduced-motion: reduce)');
const canvas = ref<HTMLCanvasElement | null>(null);

let squares = new Float32Array(0);
let columns = 0;
let rows = 0;
let ratio = 1;
let frame = 0;
let lastTime = 0;
let running = false;
let visible = true;
let resizeObserver: ResizeObserver | null = null;
let intersectionObserver: IntersectionObserver | null = null;

function setup(): void {
    const element = canvas.value;

    if (!element) {
        return;
    }

    ratio = window.devicePixelRatio || 1;
    const width = element.clientWidth;
    const height = element.clientHeight;

    element.width = Math.max(1, Math.floor(width * ratio));
    element.height = Math.max(1, Math.floor(height * ratio));

    const cell = props.squareSize + props.gridGap;
    columns = Math.ceil(width / cell);
    rows = Math.ceil(height / cell);
    squares = new Float32Array(columns * rows).map(() => Math.random() * props.maxOpacity);
    draw();
}

function draw(): void {
    const context = canvas.value?.getContext('2d');

    if (!context || !canvas.value) {
        return;
    }

    context.clearRect(0, 0, canvas.value.width, canvas.value.height);

    const size = props.squareSize * ratio;
    const cell = (props.squareSize + props.gridGap) * ratio;

    for (let column = 0; column < columns; column++) {
        for (let row = 0; row < rows; row++) {
            context.fillStyle = `rgba(${props.color}, ${squares[column * rows + row]})`;
            context.fillRect(column * cell, row * cell, size, size);
        }
    }
}

function tick(time: number): void {
    if (!running) {
        return;
    }

    const delta = Math.min((time - lastTime) / 1000, 0.1);
    lastTime = time;

    for (let index = 0; index < squares.length; index++) {
        if (Math.random() < props.flickerChance * delta) {
            squares[index] = Math.random() * props.maxOpacity;
        }
    }

    draw();
    frame = requestAnimationFrame(tick);
}

function start(): void {
    if (running || reduceMotion.value || !visible || document.hidden) {
        return;
    }

    running = true;
    lastTime = performance.now();
    frame = requestAnimationFrame(tick);
}

function stop(): void {
    running = false;
    cancelAnimationFrame(frame);
}

function onVisibilityChange(): void {
    document.hidden ? stop() : start();
}

onMounted(() => {
    setup();
    start();

    resizeObserver = new ResizeObserver(() => setup());

    if (canvas.value) {
        resizeObserver.observe(canvas.value);
        intersectionObserver = new IntersectionObserver(([entry]) => {
            visible = entry.isIntersecting;
            visible ? start() : stop();
        });
        intersectionObserver.observe(canvas.value);
    }

    document.addEventListener('visibilitychange', onVisibilityChange);
});

onBeforeUnmount(() => {
    stop();
    resizeObserver?.disconnect();
    intersectionObserver?.disconnect();
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

watch(reduceMotion, (reduced) => (reduced ? stop() : start()));
watch(() => props.color, draw);
</script>

<template>
    <canvas ref="canvas" aria-hidden="true" :class="cn('pointer-events-none size-full', props.class)" />
</template>
