<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

// Higgsfield-style model selector: a list of model cards with badges instead of a <select>.
const model = defineModel({ type: String, required: true });

const props = defineProps({
    config: { type: Object, required: true },
    kind: { type: String, required: true }, // text | image | video
    label: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

const meta = {
    anthropic: { short: 'C', color: 'from-orange-400 to-amber-600', badge: 'top', tag: 'Reasoning' },
    openai: { short: 'G', color: 'from-emerald-400 to-teal-600', badge: '', tag: 'Photoreal' },
    gemini: { short: 'Ge', color: 'from-sky-400 to-indigo-600', badge: 'new', tag: 'Edit · Reference' },
    seedance: { short: 'S', color: 'from-pink-400 to-rose-600', badge: 'new', tag: 'Video' },
    demo: { short: 'D', color: 'from-zinc-500 to-zinc-700', badge: '', tag: 'Түлхүүргүй' },
};

const items = computed(() =>
    props.config.providers
        .filter((p) => p[props.kind])
        .map((p) => ({
            ...p,
            ...(meta[p.id] ?? { short: p.label[0], color: 'from-zinc-500 to-zinc-700', badge: '', tag: '' }),
            model: props.kind === 'image' ? p.image_model : props.kind === 'video' ? p.video_model : p.text_model,
        })),
);
</script>

<template>
    <div>
        <label v-if="label" class="label">{{ label }}</label>
        <div class="grid gap-1.5" :class="compact ? 'grid-cols-2' : 'grid-cols-1'">
            <button
                v-for="p in items"
                :key="p.id"
                type="button"
                class="group flex items-center gap-2.5 rounded-xl border p-2 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                :class="model === p.id ? 'border-white bg-white/10' : 'border-line bg-surface-2 hover:border-line-2'"
                :disabled="!p.configured"
                @click="model = p.id"
            >
                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br text-xs font-black text-white" :class="p.color">{{ p.short }}</span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-1.5">
                        <span class="truncate text-sm font-semibold">{{ p.label }}</span>
                        <span v-if="p.badge" class="badge" :class="p.badge === 'top' ? 'badge-top' : 'badge-new'">{{ p.badge }}</span>
                    </span>
                    <span class="block truncate text-[11px] text-zinc-500">{{ p.configured ? p.model || p.tag : 'API түлхүүр алга' }}</span>
                </span>
                <Icon v-if="model === p.id" name="check" size="16" class="shrink-0 text-white" />
            </button>
        </div>
    </div>
</template>
