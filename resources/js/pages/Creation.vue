<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, date } from '../lib/api';
import Icon from '../components/Icon.vue';
import StageProgress from '../components/StageProgress.vue';

const route = useRoute();
const router = useRouter();
const creation = ref(null);
const error = ref('');
const busy = ref(false);
let timer;

const active = computed(() => creation.value && ['queued', 'running', 'assembling'].includes(creation.value.status));

async function load() {
    try {
        creation.value = (await api.get(`/creations/${route.params.id}`)).data;
    } catch (e) {
        error.value = e.message;
        clearInterval(timer);
    }
    if (creation.value && !active.value) clearInterval(timer);
}

async function start() {
    clearInterval(timer);
    creation.value = null;
    error.value = '';
    await load();
    if (active.value) timer = setInterval(load, 3000);
}

onMounted(start);
watch(() => route.params.id, (id) => id && start());

onBeforeUnmount(() => clearInterval(timer));

async function retry() {
    busy.value = true;
    try {
        const { data } = await api.post(`/creations/${creation.value.id}/retry`);
        router.push({ name: 'creation', params: { id: data.id } });
    } catch (e) {
        if (e.code === 'subscription_required') return router.push({ name: 'pricing', query: { next: route.fullPath } });
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (!confirm('Энэ бүтээлийг устгах уу?')) return;
    await api.delete(`/creations/${creation.value.id}`);
    router.push('/library');
}

const fileName = (o) => `poster-studio-${creation.value.id.slice(-6).toLowerCase()}-${o.format}.${o.kind === 'video' ? 'mp4' : 'jpg'}`;
</script>

<template>
    <div class="mx-auto max-w-[1200px]">
        <p v-if="error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
        <div v-if="!creation && !error" class="grid min-h-[50vh] place-items-center"><span class="spinner text-lime" /></div>

        <template v-if="creation">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <RouterLink to="/library" class="btn btn-ghost btn-sm"><Icon name="arrow" size="12" class="rotate-180" /> Миний бүтээлүүд</RouterLink>
                <span class="badge" :class="creation.type === 'reel' ? 'badge-top' : 'badge-new'">{{ creation.type === 'reel' ? 'Reels 1:30' : 'Постер' }}</span>
                <span class="text-xs text-muted">{{ date(creation.created_at) }}</span>
                <span class="ml-auto flex gap-2">
                    <button v-if="!active" class="btn btn-ghost btn-sm" :disabled="busy" @click="retry"><Icon name="refresh" size="12" /> Дахин үүсгэх</button>
                    <button v-if="!active" class="btn btn-ghost btn-sm text-red-300" @click="remove"><Icon name="trash" size="12" /></button>
                </span>
            </div>

            <h1 class="display mb-6 line-clamp-2 text-2xl leading-tight md:text-3xl">{{ creation.prompt }}</h1>

            <div v-if="active" class="panel mx-auto max-w-2xl p-6">
                <StageProgress :creation="creation" />
                <p class="mt-5 text-sm text-zinc-400">
                    <template v-if="creation.type === 'reel'">1:30 минутын видео бүтээхэд ихэвчлэн 15–25 минут болдог. Энэ хуудсыг хааж болно, бэлэн болмогц "Миний бүтээлүүд"-д харагдана.</template>
                    <template v-else>Постер ихэвчлэн 1–3 минутад бэлэн болно.</template>
                </p>
            </div>

            <div v-else-if="creation.status === 'failed'" class="panel mx-auto max-w-xl p-6 text-center">
                <div class="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-red-500/15 text-red-300"><Icon name="x" size="22" /></div>
                <p class="font-semibold">{{ creation.error }}</p>
                <button class="btn btn-lime mt-4" :disabled="busy" @click="retry"><Icon name="refresh" size="14" /> Дахин оролдох</button>
            </div>

            <template v-else>
                <div v-if="creation.type === 'reel'" class="mx-auto max-w-sm">
                    <div class="media-card bg-black">
                        <video :src="creation.outputs[0]?.url" :poster="creation.outputs[0]?.thumb_url" class="aspect-[9/16] w-full" controls playsinline preload="none" />
                    </div>
                    <a :href="creation.outputs[0]?.url" :download="fileName(creation.outputs[0])" class="btn btn-lime mt-4 w-full py-3"><Icon name="download" size="16" /> Видео татах (MP4)</a>
                </div>

                <div v-else class="grid gap-6" :class="creation.outputs.length > 1 ? 'md:grid-cols-2' : 'mx-auto max-w-xl'">
                    <div v-for="o in creation.outputs" :key="o.format">
                        <div class="media-card">
                            <img :src="o.url" :width="o.width" :height="o.height" class="w-full" :alt="o.label" />
                        </div>
                        <div class="mt-3 flex items-center gap-3">
                            <div>
                                <div class="text-sm font-semibold">{{ o.label }}</div>
                                <div class="text-xs text-muted">{{ o.width }}×{{ o.height }}</div>
                            </div>
                            <a :href="o.url" :download="fileName(o)" class="btn btn-lime ml-auto"><Icon name="download" size="14" /> Татах</a>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>
