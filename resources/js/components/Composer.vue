<script setup>
import Icon from './Icon.vue';

// Higgsfield-style prompt composer: textarea on top, control chips below,
// glowing GENERATE at the right. Fixed to the bottom of the viewport.
const prompt = defineModel({ type: String, default: '' });

defineProps({
    placeholder: { type: String, default: 'Describe the scene you imagine' },
    busy: { type: Boolean, default: false },
    busyLabel: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    label: { type: String, default: 'Generate' },
});

const emit = defineEmits(['generate']);

function onKey(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') emit('generate');
}
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-40 px-4 pb-4 md:pb-6">
        <div class="composer pointer-events-auto mx-auto max-w-4xl">
            <slot name="top" />
            <textarea
                v-model="prompt"
                rows="2"
                class="w-full resize-none bg-transparent px-2 py-1.5 text-[15px] leading-relaxed placeholder-zinc-500 outline-none"
                :placeholder="placeholder"
                @keydown="onKey"
            />
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                    <slot />
                </div>
                <button class="btn-generate flex items-center gap-2" :disabled="busy || disabled || !prompt.trim()" @click="emit('generate')">
                    <span v-if="busy" class="spinner size-3.5" /><Icon v-else name="sparkles" size="14" />
                    {{ busy && busyLabel ? busyLabel : label }}
                </button>
            </div>
        </div>
    </div>
</template>
