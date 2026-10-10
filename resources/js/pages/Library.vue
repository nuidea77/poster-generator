<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api, date } from '../lib/api';
import Icon from '../components/Icon.vue';

const items = ref([]);
const loading = ref(true);
const error = ref('');
const filter = ref('all');
const page = ref(1);
const lastPage = ref(1);
let timer;

const hasActive = computed(() => items.value.some((c) => ['queued', 'running', 'assembling'].includes(c.status)));

async function load(reset = true) {
    try {
        const type = filter.value === 'all' ? '' : `&type=${filter.value}`;
        const res = await api.get(`/creations?page=${reset ? 1 : page.value}${type}`);
        items.value = reset ? res.data : [...items.value, ...res.data];
        page.value = res.meta.current_page;
        lastPage.value = res.meta.last_page;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function more() {
    page.value++;
    load(false);
}

watch(filter, () => {
    loading.value = true;
    load();
});

onMounted(async () => {
    await load();
    timer = setInterval(() => hasActive.value && load(), 5000);
});

onBeforeUnmount(() => clearInterval(timer));

const cover = (c) => (c.type === 'reel' ? c.outputs[0]?.thumb_url : c.outputs[0]?.url);
const aspect = (c) => (c.type === 'reel' ? 'aspect-[9/16]' : { feed_square: 'aspect-square', story: 'aspect-[9/16]', fb_landscape: 'aspect-[1.91/1]' }[c.outputs[0]?.format] || 'aspect-[4/5]');
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <h1 class="display text-3xl">Миний бүтээлүүд</h1>
            <div class="ml-auto flex gap-1.5">
                <button v-for="f in [['all', 'Бүгд'], ['poster', 'Постер'], ['reel', 'Reels']]" :key="f[0]" class="chip chip-sm" :class="{ 'chip-active': filter === f[0] }" @click="filter = f[0]">{{ f[1] }}</button>
            </div>
            <RouterLink to="/create" class="btn btn-lime"><Icon name="plus" size="14" /> Шинээр</RouterLink>
        </div>

        <p v-if="error" class="rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>
        <div v-if="loading" class="grid min-h-[40vh] place-items-center"><span class="spinner text-lime" /></div>
        <div v-else-if="!items.length" class="panel grid min-h-[40vh] place-items-center text-center">
            <div>
                <p class="text-muted">Одоогоор бүтээл алга.</p>
                <RouterLink to="/create" class="btn btn-lime mt-4">Анхны бүтээлээ хийх</RouterLink>
            </div>
        </div>

        <div class="columns-2 gap-4 sm:columns-3 xl:columns-4 2xl:columns-5">
            <RouterLink v-for="c in items" :key="c.id" :to="`/c/${c.id}`" class="group media-card mb-4 block break-inside-avoid">
                <img v-if="cover(c)" :src="cover(c)" class="w-full object-cover" :class="aspect(c)" loading="lazy" alt="" />
                <div v-else class="grid w-full place-items-center bg-[radial-gradient(circle_at_center,#1f1f1f,#121212)]" :class="aspect(c)">
                    <span v-if="c.status === 'failed'" class="text-xs text-red-300">Амжилтгүй</span>
                    <span v-else-if="c.status === 'done'" class="grid size-12 place-items-center rounded-full bg-white/10"><Icon :name="c.type === 'reel' ? 'play' : 'image'" size="18" /></span>
                    <span v-else class="flex flex-col items-center gap-2 text-xs text-muted"><span class="spinner text-lime" /> {{ c.progress }}%</span>
                </div>
                <span class="badge absolute top-2 left-2 backdrop-blur" :class="c.type === 'reel' ? 'badge-top' : 'badge-new'">{{ c.type === 'reel' ? 'Reels' : 'Постер' }}</span>
                <span v-if="c.type === 'poster' && c.outputs.length > 1" class="badge badge-muted absolute top-2 right-2 backdrop-blur">{{ c.outputs.length }}</span>
                <span v-if="c.type === 'reel' && cover(c)" class="pointer-events-none absolute inset-0 grid place-items-center"><span class="grid size-12 place-items-center rounded-full bg-black/50 backdrop-blur"><Icon name="play" size="18" /></span></span>
                <div class="media-overlay">
                    <div class="line-clamp-2 text-sm font-semibold">{{ c.prompt }}</div>
                    <div class="mt-0.5 text-[10px] text-zinc-400">{{ date(c.created_at) }}</div>
                </div>
            </RouterLink>
        </div>

        <div v-if="page < lastPage" class="mt-4 text-center"><button class="btn btn-ghost" @click="more">Цааш үзэх</button></div>
    </div>
</template>
