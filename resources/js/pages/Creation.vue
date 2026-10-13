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

const stamp = (d) => {
    if (!d) return '—';
    const t = new Date(d);
    return `${date(d)} ${String(t.getHours()).padStart(2, '0')}:${String(t.getMinutes()).padStart(2, '0')}`;
};
const took = computed(() => {
    const c = creation.value;
    if (!c?.finished_at || !c?.created_at) return null;
    const s = Math.max(0, Math.round((new Date(c.finished_at) - new Date(c.created_at)) / 1000));
    return s < 60 ? `${s} сек` : `${Math.floor(s / 60)} мин ${s % 60} сек`;
});
const statusLabel = { done: 'Амжилттай', failed: 'Амжилтгүй', queued: 'Дараалалд', running: 'Хийгдэж байна', assembling: 'Хийгдэж байна' };
const roleLabel = { logo: 'Лого', product: 'Бүтээгдэхүүн', reference: 'Жишээ зураг' };
const clock = (s) => `${Math.floor(Math.round(s) / 60)}:${String(Math.round(s) % 60).padStart(2, '0')}`;
const fileName = (o) => `poster-studio-${creation.value.id.slice(-6).toLowerCase()}-${o.label.replace(':', 'x')}.${o.kind === 'video' ? 'mp4' : 'jpg'}`;
</script>

<template>
    <div class="mx-auto max-w-[1200px]">
        <p v-if="error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
        <div v-if="!creation && !error" class="grid min-h-[50vh] place-items-center"><span class="spinner text-lime" /></div>

        <template v-if="creation">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <RouterLink to="/library" class="btn btn-ghost btn-sm"><Icon name="arrow" size="12" class="rotate-180" /> Миний бүтээлүүд</RouterLink>
                <span class="badge" :class="creation.type === 'reel' ? 'badge-top' : 'badge-new'">{{ creation.type === 'reel' ? 'Reels' + (creation.outputs[0]?.duration ? ` · ${clock(creation.outputs[0].duration)}` : '') : 'Постер' }}</span>
                <span class="text-xs text-muted">{{ date(creation.created_at) }}</span>
                <span class="ml-auto flex gap-2">
                    <button v-if="!active" class="btn btn-ghost btn-sm" :disabled="busy" @click="retry"><Icon name="refresh" size="12" /> Дахин үүсгэх</button>
                    <button v-if="!active" class="btn btn-ghost btn-sm text-red-300" @click="remove"><Icon name="trash" size="12" /></button>
                </span>
            </div>

            <h1 class="display mb-4 line-clamp-2 text-2xl leading-tight md:text-3xl">{{ creation.prompt }}</h1>

            <!-- Everything about the job: the full brief, settings, times and uploaded files. -->
            <details class="panel mb-6 p-4" open>
                <summary class="cursor-pointer text-sm font-semibold">Дэлгэрэнгүй мэдээлэл</summary>
                <div class="mt-3 grid gap-4 md:grid-cols-[2fr_1fr]">
                    <div>
                        <div class="mb-1 text-xs text-muted">Бриф</div>
                        <p class="text-sm leading-relaxed whitespace-pre-line">{{ creation.prompt }}</p>
                        <template v-if="creation.product">
                            <div class="mt-3 mb-1 text-xs text-muted">Бүтээгдэхүүн</div>
                            <p class="text-sm whitespace-pre-line">{{ creation.product }}</p>
                        </template>
                        <template v-if="creation.inputs.length">
                            <div class="mt-3 mb-1 text-xs text-muted">Оруулсан зураг</div>
                            <div class="flex flex-wrap gap-2">
                                <a v-for="(i, n) in creation.inputs" :key="n" :href="i.url" target="_blank" class="block w-20 text-center">
                                    <img :src="i.url" class="h-20 w-20 rounded-lg border border-line bg-white/5 object-contain" alt="" />
                                    <span class="text-[10px] text-muted">{{ roleLabel[i.role] ?? i.role }}</span>
                                </a>
                            </div>
                        </template>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 self-start text-sm">
                        <dt class="text-muted">Төрөл</dt><dd>{{ creation.type === 'reel' ? 'Reels' : 'Постер' }}</dd>
                        <dt class="text-muted">Төлөв</dt><dd :class="creation.status === 'failed' ? 'text-red-300' : creation.status === 'done' ? 'text-lime' : ''">{{ statusLabel[creation.status] ?? creation.status }}</dd>
                        <template v-if="creation.formats.length"><dt class="text-muted">Хэмжээ</dt><dd>{{ creation.formats.join(', ') }}</dd></template>
                        <template v-if="creation.type === 'reel' && creation.duration"><dt class="text-muted">Урт</dt><dd>{{ creation.duration }} сек</dd></template>
                        <template v-if="creation.credits"><dt class="text-muted">Кредит</dt><dd>{{ creation.credits }}</dd></template>
                        <dt class="text-muted">Эхэлсэн</dt><dd>{{ stamp(creation.created_at) }}</dd>
                        <template v-if="creation.finished_at"><dt class="text-muted">Дууссан</dt><dd>{{ stamp(creation.finished_at) }}</dd></template>
                        <template v-if="took"><dt class="text-muted">Хугацаа</dt><dd>{{ took }}</dd></template>
                        <template v-if="creation.outputs.length"><dt class="text-muted">Файл</dt><dd>{{ creation.outputs.length }}</dd></template>
                    </dl>
                </div>
            </details>

            <div v-if="active" class="panel mx-auto max-w-2xl p-6">
                <StageProgress :creation="creation" />
                <p class="mt-5 text-sm text-zinc-400">
                    <template v-if="creation.type === 'reel'">Reels видео бүтээхэд ихэвчлэн 10–20 минут болдог. Энэ хуудсыг хааж болно, бэлэн болмогц "Миний бүтээлүүд"-д харагдана.</template>
                    <template v-else>Постер ихэвчлэн 1–3 минутад бэлэн болно.</template>
                </p>
            </div>

            <div v-else-if="creation.status === 'failed'" class="panel mx-auto max-w-xl p-6 text-center">
                <div class="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-red-500/15 text-red-300"><Icon name="x" size="22" /></div>
                <p class="font-semibold">{{ creation.error }}</p>
                <button class="btn btn-lime mt-4" :disabled="busy" @click="retry"><Icon name="refresh" size="14" /> Дахин оролдох</button>
            </div>

            <template v-else>
                <div v-if="creation.type === 'reel'" class="mx-auto" :class="creation.outputs[0]?.width > creation.outputs[0]?.height ? 'max-w-3xl' : 'max-w-sm'">
                    <div class="media-card bg-black">
                        <video :src="creation.outputs[0]?.url" :poster="creation.outputs[0]?.thumb_url" :style="{ aspectRatio: `${creation.outputs[0]?.width ?? 9} / ${creation.outputs[0]?.height ?? 16}` }" class="w-full" controls playsinline preload="none" />
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
