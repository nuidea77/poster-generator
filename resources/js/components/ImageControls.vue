<script setup>
import { ref } from 'vue';
import { api } from '../lib/api';

const prompt = defineModel('prompt', { type: String, default: '' });
const url = defineModel('url', { type: String, default: null });

defineProps({
    busy: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['regenerate', 'error']);
const uploading = ref(false);

async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const data = new FormData();
    data.append('image', file);
    uploading.value = true;
    try {
        url.value = (await api.post('/api/uploads', data)).url;
    } catch (e) {
        emit('error', e.message);
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div :class="compact ? 'space-y-2' : 'panel space-y-3'">
        <div v-if="!compact" class="text-sm font-semibold">Арын зураг</div>
        <textarea v-model="prompt" :rows="compact ? 2 : 4" class="field resize-none text-xs" placeholder="Зургийн prompt (англиар)" />
        <div class="flex flex-wrap gap-2">
            <button class="btn btn-ghost flex-1 text-xs" :disabled="busy || !prompt" @click="emit('regenerate')">
                <span v-if="busy" class="size-3 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                🔄 {{ url ? 'Дахин үүсгэх' : 'Зураг үүсгэх' }}
            </button>
            <label class="btn btn-ghost cursor-pointer text-xs" :class="{ 'opacity-50': uploading }">
                📁 Өөрийн зураг
                <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="upload" />
            </label>
            <button v-if="url" class="btn btn-ghost text-xs" title="Зураг арилгах" @click="url = null">✕</button>
        </div>
    </div>
</template>
