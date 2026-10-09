<script setup>
import { computed, ref } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    caption: { type: String, default: '' },
    hashtags: { type: Array, default: () => [] },
    extra: { type: String, default: '' },
});

const copied = ref(false);
const text = computed(() => [props.caption, props.hashtags.join(' ')].filter(Boolean).join('\n\n'));

async function copy() {
    await navigator.clipboard.writeText(text.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <div v-if="text" class="panel space-y-2">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold">Пост бичвэр</span>
            <button class="btn btn-ghost btn-sm" @click="copy"><Icon :name="copied ? 'check' : 'copy'" size="12" /> {{ copied ? 'Хуулсан' : 'Хуулах' }}</button>
        </div>
        <p class="whitespace-pre-line text-sm text-zinc-300">{{ caption }}</p>
        <p class="text-xs text-sky-400">{{ hashtags.join(' ') }}</p>
        <p v-if="extra" class="text-xs text-zinc-500">{{ extra }}</p>
    </div>
</template>
