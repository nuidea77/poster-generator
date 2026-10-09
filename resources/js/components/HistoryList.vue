<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import Icon from './Icon.vue';

const emit = defineEmits(['open']);
const items = ref([]);
const loading = ref(true);
const error = ref('');
const filter = ref('all');

onMounted(async () => {
    try {
        items.value = await api.get('/api/generations');
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
});

const shown = computed(() => items.value.filter((g) => filter.value === 'all' || g.type === filter.value));
const thumb = (g) => (g.type === 'reel' ? g.content.scenes?.find((s) => s.image_url)?.image_url : g.content.image_url);
const title = (g) => (g.type === 'reel' ? g.content.title || g.content.hook : g.content.headline) || g.prompt;
const aspect = (g) => (g.type === 'reel' ? 'aspect-[9/16]' : { '1:1': 'aspect-square', '9:16': 'aspect-[9/16]', '16:9': 'aspect-video' }[g.content.format] || 'aspect-[4/5]');

async function remove(g) {
    if (!confirm('Устгах уу?')) return;
    await api.delete(`/api/generations/${g.id}`);
    items.value = items.value.filter((i) => i.id !== g.id);
}
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <h1 class="display text-3xl">Галерей</h1>
            <span class="badge badge-dark">{{ items.length }}</span>
            <div class="ml-auto flex gap-1.5">
                <button v-for="f in [['all', 'Бүгд'], ['poster', 'Постер'], ['reel', 'Reels']]" :key="f[0]" class="chip chip-sm" :class="{ 'chip-active': filter === f[0] }" @click="filter = f[0]">{{ f[1] }}</button>
            </div>
        </div>

        <p v-if="error" class="rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>
        <div v-if="loading" class="grid min-h-[40vh] place-items-center"><span class="spinner text-lime" /></div>
        <div v-else-if="!shown.length" class="panel grid min-h-[40vh] place-items-center text-center text-muted">Одоогоор юу ч үүсгээгүй байна.</div>

        <div class="columns-2 gap-4 sm:columns-3 xl:columns-4 2xl:columns-5">
            <div v-for="g in shown" :key="g.id" class="group media-card mb-4 cursor-pointer break-inside-avoid" @click="emit('open', g)">
                <div
                    class="w-full bg-cover bg-center"
                    :class="aspect(g)"
                    :style="thumb(g) ? { backgroundImage: `url(${thumb(g)})` } : { background: `linear-gradient(135deg, ${g.content.palette?.primary}, ${g.content.palette?.background})` }"
                />
                <span class="badge absolute top-2 left-2 backdrop-blur" :class="g.type === 'reel' ? 'badge-new' : 'badge-muted'">{{ g.type === 'reel' ? 'Reels' : 'Poster' }}</span>
                <div class="media-overlay">
                    <div class="line-clamp-2 text-sm font-semibold">{{ title(g) }}</div>
                    <div class="mt-0.5 text-[10px] text-zinc-400">{{ new Date(g.created_at).toLocaleDateString() }} · {{ g.text_provider }}</div>
                    <div class="mt-2 flex gap-1.5">
                        <span class="btn btn-lime btn-sm">Нээх <Icon name="arrow" size="12" /></span>
                        <button class="btn btn-ghost btn-sm" @click.stop="remove(g)"><Icon name="trash" size="12" /></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
