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
    { id: 'agent', label: 'Агент', badge: 'Top' },
    { id: 'poster', label: 'Постер' },
    { id: 'reel', label: 'Reels', badge: 'New' },
    { id: 'history', label: 'Галерей' },
    { id: 'skills', label: 'Skills' },
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
    <div class="min-h-full">
        <!-- Top navigation, higgsfield.ai style -->
        <header class="sticky top-0 z-30 border-b border-line bg-bg/90 backdrop-blur">
            <div class="flex h-14 items-center gap-1 px-4">
                <button class="mr-3 grid size-8 place-items-center rounded-lg bg-white text-sm font-black text-ink" @click="go('agent')">P</button>

                <nav class="flex items-center gap-0.5 overflow-x-auto scroll-thin">
                    <button
                        v-for="n in nav"
                        :key="n.id"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium tracking-[0.1px] transition"
                        :class="tab === n.id ? 'text-lime' : 'text-zinc-400 hover:text-fg'"
                        @click="go(n.id)"
                    >
                        {{ n.label }}
                        <span v-if="n.badge" class="badge" :class="n.badge === 'Top' ? 'badge-dark' : 'badge-new'">{{ n.badge }}</span>
                    </button>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <div v-if="config" class="hidden items-center gap-2 rounded-[10px] bg-surface-2 px-3 py-1.5 text-sm md:flex">
                        <Icon name="layers" size="14" class="text-zinc-400" />
                        <span v-for="p in config.providers.filter((p) => p.id !== 'demo')" :key="p.id" class="size-2 rounded-full" :class="p.configured ? 'bg-lime' : 'bg-zinc-700'" :title="p.label + (p.configured ? ' — холбогдсон' : ' — түлхүүргүй')" />
                        <span class="text-zinc-400">Моделиуд</span>
                    </div>
                    <button class="btn btn-lime-soft hidden sm:inline-flex" @click="go('skills')">Skills</button>
                    <button class="btn btn-lime" @click="go('agent')">Үүсгэх</button>
                </div>
            </div>
        </header>

        <main class="px-4 pt-4 pb-56">
            <p v-if="configError" class="mb-4 rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ configError }}</p>

            <template v-if="config">
                <AgentStudio v-show="tab === 'agent'" :config="config" @open="open" />
                <PosterStudio v-show="tab === 'poster'" :key="'p' + openKey" :config="config" :initial="opened.poster" :active="tab === 'poster'" />
                <ReelStudio v-show="tab === 'reel'" :key="'r' + openKey" :config="config" :initial="opened.reel" :active="tab === 'reel'" />
                <HistoryList v-if="tab === 'history'" @open="open" />
                <SkillsPanel v-if="tab === 'skills'" page />
            </template>
            <div v-else-if="!configError" class="grid min-h-[60vh] place-items-center text-zinc-500"><span class="spinner text-lime" /></div>
        </main>
    </div>
</template>
