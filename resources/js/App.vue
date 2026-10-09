<script setup>
import { onMounted, ref } from 'vue';
import { api } from './lib/api';
import PosterStudio from './components/PosterStudio.vue';
import ReelStudio from './components/ReelStudio.vue';
import HistoryList from './components/HistoryList.vue';

const tabs = [
    { id: 'poster', label: 'Постер' },
    { id: 'reel', label: 'Reels видео' },
    { id: 'history', label: 'Түүх' },
];

const tab = ref('poster');
const config = ref(null);
const configError = ref('');
const opened = ref({ poster: null, reel: null });
const openKey = ref(0);

onMounted(async () => {
    try {
        config.value = await api.get('/api/config');
    } catch (e) {
        configError.value = e.message;
    }
});

function open(generation) {
    opened.value[generation.type] = generation;
    openKey.value++;
    tab.value = generation.type;
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-950/90 backdrop-blur">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-3">
                <div class="flex items-center gap-2">
                    <div class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-fuchsia-600 to-orange-500 text-lg font-black">P</div>
                    <div>
                        <div class="text-sm font-bold leading-tight">AI Poster & Reels</div>
                        <div class="text-xs text-zinc-500">Claude Fable · GPT · Gemini</div>
                    </div>
                </div>
                <nav class="ml-auto flex gap-1 rounded-xl bg-zinc-900 p-1">
                    <button
                        v-for="t in tabs"
                        :key="t.id"
                        class="rounded-lg px-4 py-1.5 text-sm font-medium transition"
                        :class="tab === t.id ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-400 hover:text-zinc-100'"
                        @click="tab = t.id"
                    >
                        {{ t.label }}
                    </button>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
            <p v-if="configError" class="mb-4 rounded-lg bg-red-950 p-3 text-sm text-red-200">{{ configError }}</p>

            <template v-if="config">
                <PosterStudio v-show="tab === 'poster'" :key="'p' + openKey" :config="config" :initial="opened.poster" />
                <ReelStudio v-show="tab === 'reel'" :key="'r' + openKey" :config="config" :initial="opened.reel" :active="tab === 'reel'" />
                <HistoryList v-if="tab === 'history'" @open="open" />
            </template>
            <div v-else-if="!configError" class="py-20 text-center text-zinc-500">Ачаалж байна…</div>
        </main>
    </div>
</template>
