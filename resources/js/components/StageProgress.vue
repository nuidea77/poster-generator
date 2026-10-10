<script setup>
import { computed } from 'vue';

const props = defineProps({
    creation: { type: Object, required: true },
});

const labels = {
    queued: 'Дараалалд орлоо',
    planning: 'Брифийг боловсруулж байна',
    generating: 'Зураг бүтээж байна',
    storyboard: 'Сценари, гол кадрууд бэлдэж байна',
    filming: 'Видео клипүүдийг бүтээж байна',
    assembling: 'Видеог угсарч байна',
    done: 'Бэлэн боллоо',
    failed: 'Амжилтгүй',
};

const steps = computed(() =>
    props.creation.type === 'reel'
        ? ['planning', 'storyboard', 'filming', 'assembling']
        : ['planning', 'generating'],
);

const current = computed(() => steps.value.indexOf(props.creation.stage));
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between text-sm">
            <span class="flex items-center gap-2 font-semibold"><span class="spinner text-lime" /> {{ labels[creation.stage] || labels.queued }}</span>
            <span class="tabular-nums text-muted">{{ creation.progress }}%</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-surface-3">
            <div class="h-full rounded-full bg-lime transition-all duration-700" :style="{ width: `${Math.max(3, creation.progress)}%` }" />
        </div>
        <ol class="flex flex-wrap gap-2 text-xs">
            <li v-for="(s, i) in steps" :key="s" class="flex items-center gap-1.5 rounded-lg px-2 py-1" :class="i < current ? 'text-lime' : i === current ? 'bg-white/5 text-fg' : 'text-muted'">
                <span class="size-1.5 rounded-full" :class="i <= current ? 'bg-lime' : 'bg-zinc-600'" />
                {{ labels[s] }}
            </li>
        </ol>
    </div>
</template>
