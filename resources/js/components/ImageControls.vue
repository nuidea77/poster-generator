<script setup>
import { ref } from 'vue';
import { api } from '../lib/api';
import Icon from './Icon.vue';

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
        <div class="flex flex-wrap gap-1.5">
            <button class="btn btn-outline btn-sm flex-1" :disabled="busy || !prompt" @click="emit('regenerate')">
                <span v-if="busy" class="spinner size-3" /><Icon v-else name="refresh" size="12" />
                {{ url ? 'Дахин үүсгэх' : 'Зураг үүсгэх' }}
            </button>
            <label class="btn btn-outline btn-sm cursor-pointer" :class="{ 'opacity-50': uploading }">
                <Icon name="upload" size="12" /> Өөрийн зураг
                <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="upload" />
            </label>
            <button v-if="url" class="btn btn-outline btn-sm px-2" title="Зураг арилгах" @click="url = null"><Icon name="x" size="12" /></button>
        </div>
    </div>
</template>
