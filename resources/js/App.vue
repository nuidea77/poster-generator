<script setup>
import { onMounted, ref } from 'vue';
import { api } from './lib/api';
import Icon from './components/Icon.vue';
import AgentStudio from './components/AgentStudio.vue';
import PosterStudio from './components/PosterStudio.vue';
import ReelStudio from './components/ReelStudio.vue';
import HistoryList from './components/HistoryList.vue';
import SkillsPanel from './components/SkillsPanel.vue';

const nav = [
    { id: 'agent', label: 'Агент', icon: 'sparkles', hint: 'Claude Fable' },
    { id: 'poster', label: 'Постер', icon: 'image', hint: 'Зураг' },
    { id: 'reel', label: 'Reels', icon: 'video', hint: 'Видео' },
    { id: 'history', label: 'Галерей', icon: 'grid', hint: 'Бүх ажил' },
    { id: 'skills', label: 'Skills', icon: 'book', hint: 'Агентын заавар' },
];

const tab = ref(location.hash.replace('#', '') || 'agent');
const config = ref(null);
const configError = ref('');
const opened = ref({ poster: null, reel: null });
const openKey = ref(0);

onMounted(async () => {
    window.addEventListener('hashchange', () => (tab.value = location.hash.replace('#', '') || 'agent'));
    try {
        config.value = await api.get('/api/config');
    } catch (e) {
        configError.value = e.message;
    }
});

function go(id) {
    tab.value = id;
    history.replaceState(null, '', '#' + id);
    window.scrollTo({ top: 0 });
}

function open(generation) {
    opened.value[generation.type] = generation;
    openKey.value++;
    go(generation.type);
}
</script>

<template>
    <div class="flex min-h-full">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-30 hidden w-[220px] flex-col border-r border-line bg-bg md:flex">
            <div class="flex items-center gap-2.5 px-5 py-5">
                <div class="grid size-8 place-items-center rounded-lg bg-white text-sm font-black text-black">P</div>
                <div class="leading-tight">
                    <div class="text-sm font-bold">Poster Studio</div>
                    <div class="text-[10px] text-zinc-500">AI creative suite</div>
                </div>
            </div>

            <nav class="mt-2 flex-1 space-y-0.5 px-3">
                <button
                    v-for="n in nav"
                    :key="n.id"
                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition"
                    :class="tab === n.id ? 'bg-white text-black' : 'text-zinc-400 hover:bg-white/5 hover:text-white'"
                    @click="go(n.id)"
                >
                    <Icon :name="n.icon" size="18" />
                    <span class="flex-1 font-medium">{{ n.label }}</span>
                    <span v-if="n.id === 'agent'" class="badge" :class="tab === n.id ? 'bg-black/10 text-black' : 'badge-top'">top</span>
                </button>
            </nav>

            <div v-if="config" class="m-3 rounded-xl border border-line bg-surface p-3 text-[11px] text-zinc-500">
                <div class="mb-1.5 font-semibold text-zinc-300">Моделиуд</div>
                <div v-for="p in config.providers.filter((p) => p.id !== 'demo')" :key="p.id" class="flex items-center gap-1.5 py-0.5">
                    <span class="size-1.5 rounded-full" :class="p.configured ? 'bg-lime-400' : 'bg-zinc-700'" />
                    <span :class="p.configured ? 'text-zinc-300' : ''">{{ p.label }}</span>
                </div>
            </div>
        </aside>

        <!-- Mobile bottom nav -->
        <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-line bg-bg/95 backdrop-blur md:hidden">
            <button v-for="n in nav" :key="n.id" class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[10px]" :class="tab === n.id ? 'text-white' : 'text-zinc-500'" @click="go(n.id)">
                <Icon :name="n.icon" size="18" />
                {{ n.label }}
            </button>
        </nav>

        <main class="min-w-0 flex-1 pb-20 md:ml-[220px] md:pb-0">
            <div class="mx-auto max-w-[1500px] px-4 py-5 md:px-6">
                <p v-if="configError" class="mb-4 rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ configError }}</p>

                <template v-if="config">
                    <AgentStudio v-show="tab === 'agent'" :config="config" @open="open" />
                    <PosterStudio v-show="tab === 'poster'" :key="'p' + openKey" :config="config" :initial="opened.poster" />
                    <ReelStudio v-show="tab === 'reel'" :key="'r' + openKey" :config="config" :initial="opened.reel" :active="tab === 'reel'" />
                    <HistoryList v-if="tab === 'history'" @open="open" />
                    <SkillsPanel v-if="tab === 'skills'" page />
                </template>
                <div v-else-if="!configError" class="grid min-h-[60vh] place-items-center text-zinc-500"><span class="spinner" /></div>
            </div>
        </main>
    </div>
</template>
