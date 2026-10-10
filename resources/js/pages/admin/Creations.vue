<script setup>
import { onMounted, ref, watch } from 'vue';
import { api, date } from '../../lib/api';
import AdminNav from '../../components/AdminNav.vue';

const items = ref([]);
const status = ref('');
const open = ref(null);
const error = ref('');

async function load() {
    try {
        items.value = (await api.get(`/admin/creations${status.value ? `?status=${status.value}` : ''}`)).data;
    } catch (e) {
        error.value = e.message;
    }
}

onMounted(load);
watch(status, load);

const tokens = (c) => (c.input_tokens + c.output_tokens).toLocaleString();
const usage = (c) =>
    [
        ...Object.entries(c.usage.images || {}).map(([p, n]) => `${p} ×${n}`),
        ...Object.entries(c.usage.videos || {}).map(([p, n]) => `${p} ×${n}`),
    ].join(', ') || '—';
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="display text-3xl">Бүтээлүүд</h1>
            <div class="ml-auto flex gap-1.5">
                <button v-for="s in ['', 'running', 'done', 'failed']" :key="s" class="chip chip-sm" :class="{ 'chip-active': status === s }" @click="status = s">{{ s || 'Бүгд' }}</button>
            </div>
        </div>
        <p v-if="error" class="rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>

        <div class="overflow-x-auto rounded-2xl border border-line">
            <table class="w-full text-left text-sm">
                <thead class="bg-surface text-xs text-muted uppercase">
                    <tr>
                        <th class="px-3 py-2">Огноо</th><th class="px-3 py-2">Хэрэглэгч</th><th class="px-3 py-2">Төрөл</th><th class="px-3 py-2">Төлөв</th>
                        <th class="px-3 py-2">Модель хэрэглээ</th><th class="px-3 py-2">Видео сек</th><th class="px-3 py-2">Claude токен</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="c in items" :key="c.id">
                        <tr class="cursor-pointer border-t border-line hover:bg-white/5" @click="open = open === c.id ? null : c.id">
                            <td class="px-3 py-2 whitespace-nowrap">{{ date(c.created_at) }}</td>
                            <td class="px-3 py-2">{{ c.user.email }}</td>
                            <td class="px-3 py-2">{{ c.type }}</td>
                            <td class="px-3 py-2"><span class="badge" :class="{ done: 'badge-new', failed: 'bg-red-500/20 text-red-300' }[c.status] || 'badge-muted'">{{ c.status }}</span></td>
                            <td class="px-3 py-2 text-xs">{{ usage(c) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ c.usage.video_seconds }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ tokens(c) }}</td>
                        </tr>
                        <tr v-if="open === c.id" class="border-t border-line bg-surface">
                            <td colspan="7" class="space-y-3 px-3 py-4 text-xs">
                                <div><b>Бриф:</b> {{ c.prompt }}</div>
                                <div v-if="c.summary"><b>Fable:</b> {{ c.summary }}</div>
                                <div v-if="c.error_detail" class="text-red-300"><b>Алдаа:</b> {{ c.error_detail }}</div>
                                <div class="flex flex-wrap gap-2">
                                    <a v-for="a in c.assets" :key="a.id" :href="a.url" target="_blank" class="w-24">
                                        <video v-if="a.kind === 'video'" :src="a.url" class="aspect-[9/16] w-full rounded-lg object-cover" muted />
                                        <img v-else :src="a.url" class="w-full rounded-lg" />
                                        <span class="text-[10px] text-muted">{{ a.id }} · {{ a.provider }}</span>
                                    </a>
                                </div>
                                <ol class="space-y-1 border-l border-white/10 pl-3">
                                    <li v-for="(s, i) in c.steps" :key="i">
                                        <span v-if="s.type === 'note'" class="text-zinc-400">💭 {{ s.text }}</span>
                                        <span v-else><b>{{ s.name }}</b> <span v-if="s.input?.provider" class="badge badge-muted">{{ s.input.provider }}</span>
                                            <span v-if="s.result" class="text-lime"> {{ s.result }}</span><span v-if="s.error" class="text-red-300"> {{ s.error }}</span>
                                            <span v-if="s.input?.prompt" class="block text-muted">{{ s.input.prompt }}</span>
                                        </span>
                                    </li>
                                </ol>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
