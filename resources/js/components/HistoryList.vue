<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../lib/api';

const emit = defineEmits(['open']);
const items = ref([]);
const loading = ref(true);
const error = ref('');

onMounted(async () => {
    try {
        items.value = await api.get('/api/generations');
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
});

const thumb = (g) => (g.type === 'reel' ? g.content.scenes?.find((s) => s.image_url)?.image_url : g.content.image_url);
const title = (g) => (g.type === 'reel' ? g.content.title || g.content.hook : g.content.headline) || g.prompt;

async function remove(g) {
    if (!confirm('Устгах уу?')) return;
    await api.delete(`/api/generations/${g.id}`);
    items.value = items.value.filter((i) => i.id !== g.id);
}
</script>

<template>
    <div>
        <p v-if="error" class="rounded-lg bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>
        <div v-if="loading" class="py-20 text-center text-zinc-500">Ачаалж байна…</div>
        <div v-else-if="!items.length" class="py-20 text-center text-zinc-500">Одоогоор юу ч үүсгээгүй байна.</div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <div v-for="g in items" :key="g.id" class="group relative cursor-pointer overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900" @click="emit('open', g)">
                <div
                    class="aspect-[4/5] bg-cover bg-center"
                    :style="thumb(g) ? { backgroundImage: `url(${thumb(g)})` } : { background: `linear-gradient(135deg, ${g.content.palette?.primary}, ${g.content.palette?.background})` }"
                />
                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 to-transparent p-3 pt-10">
                    <span class="mb-1 inline-block rounded bg-white/15 px-1.5 py-0.5 text-[10px] uppercase">{{ g.type === 'reel' ? '🎬 Reels' : '🖼 Постер' }}</span>
                    <div class="line-clamp-2 text-sm font-semibold">{{ title(g) }}</div>
                    <div class="text-[10px] text-zinc-400">{{ new Date(g.created_at).toLocaleString() }} · {{ g.text_provider }}</div>
                </div>
                <button class="absolute top-2 right-2 hidden rounded-full bg-black/70 px-2 py-0.5 text-xs group-hover:block" @click.stop="remove(g)">✕</button>
            </div>
        </div>
    </div>
</template>
