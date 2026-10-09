<script setup>
import { onMounted, ref } from 'vue';
import Icon from './Icon.vue';
import { api } from '../lib/api';

defineProps({ page: { type: Boolean, default: false } });

const skills = ref([]);
const viewing = ref(null); // { name, content }
const editing = ref(null); // { id?, name, description, content, enabled }
const error = ref('');
const saving = ref(false);

const template = `# Миний skill

## Хэзээ ашиглах
...

## Дүрэм
- ...
`;

onMounted(load);

async function load() {
    try {
        skills.value = await api.get('/api/skills');
    } catch (e) {
        error.value = e.message;
    }
}

async function view(s) {
    error.value = '';
    try {
        viewing.value = await api.get(`/api/skills/${s.name}`);
    } catch (e) {
        error.value = e.message;
    }
}

function startNew() {
    editing.value = { name: '', description: '', content: template, enabled: true };
    viewing.value = null;
}

async function edit(s) {
    const { content } = await api.get(`/api/skills/${s.name}`);
    editing.value = { id: s.id, name: s.name, description: s.description, content, enabled: s.enabled };
    viewing.value = null;
}

async function save() {
    error.value = '';
    saving.value = true;
    try {
        const e = editing.value;
        if (e.id) await api.put(`/api/skills/${e.id}`, e);
        else await api.post('/api/skills', e);
        editing.value = null;
        await load();
    } catch (err) {
        error.value = err.message;
    } finally {
        saving.value = false;
    }
}

async function toggle(s) {
    await api.put(`/api/skills/${s.id}`, { name: s.name, description: s.description, content: (await api.get(`/api/skills/${s.name}`)).content, enabled: !s.enabled });
    await load();
}

async function remove(s) {
    if (!confirm(`"${s.name}" skill-ийг устгах уу?`)) return;
    await api.delete(`/api/skills/${s.id}`);
    await load();
}
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[1fr_minmax(0,1.3fr)]">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-bold">Skills</h1>
                <span class="badge badge-muted">{{ skills.length }}</span>
                <button class="btn btn-primary btn-sm ml-auto" @click="startNew"><Icon name="plus" size="14" /> Skill нэмэх</button>
            </div>
            <p class="text-sm text-zinc-500">
                Агент ажиллахдаа хэрэгтэй skill-ээ өөрөө ачаалж уншина. Өөрийн брэнд, дүрэм, стилийн заавраа skill болгон нэмээрэй — тэдгээр нь ерөнхий зааврыг давамгайлна.
            </p>

            <div class="space-y-2">
                <div v-for="s in skills" :key="s.name" class="group panel flex cursor-pointer items-start gap-3 py-3 transition hover:border-line-2" :class="{ 'opacity-50': !s.enabled, 'border-white': viewing?.name === s.name }" @click="view(s)">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg" :class="s.source === 'custom' ? 'bg-violet-500/20 text-violet-300' : 'bg-white/5 text-zinc-300'"><Icon name="book" size="16" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate text-sm font-semibold">{{ s.name }}</span>
                            <span class="badge" :class="s.source === 'custom' ? 'badge-new' : 'badge-muted'">{{ s.source === 'custom' ? 'custom' : 'bundled' }}</span>
                        </span>
                        <span class="line-clamp-2 text-xs text-zinc-500">{{ s.description }}</span>
                    </span>
                    <span v-if="s.source === 'custom'" class="flex gap-1 opacity-0 transition group-hover:opacity-100">
                        <button class="btn btn-soft btn-sm px-2" :title="s.enabled ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх'" @click.stop="toggle(s)"><Icon :name="s.enabled ? 'pause' : 'play'" size="12" /></button>
                        <button class="btn btn-soft btn-sm px-2" @click.stop="edit(s)"><Icon name="edit" size="12" /></button>
                        <button class="btn btn-soft btn-sm px-2 text-red-300" @click.stop="remove(s)"><Icon name="trash" size="12" /></button>
                    </span>
                </div>
            </div>
            <p v-if="error" class="rounded-xl bg-red-950/70 p-2 text-xs text-red-200">{{ error }}</p>
        </div>

        <div class="lg:sticky lg:top-5 lg:self-start">
            <div v-if="editing" class="panel space-y-3">
                <div class="text-sm font-semibold">{{ editing.id ? 'Skill засах' : 'Шинэ skill' }}</div>
                <div><label class="label">Нэр</label><input v-model="editing.name" class="field" placeholder="my-brand (жижиг үсэг, зураас)" /></div>
                <div><label class="label">Хэзээ ашиглах</label><input v-model="editing.description" class="field" placeholder="Агент үүнийг уншаад ачаалах эсэхээ шийднэ" /></div>
                <div><label class="label">Агуулга (Markdown)</label><textarea v-model="editing.content" rows="16" class="field resize-y font-mono text-xs" /></div>
                <div class="flex gap-2">
                    <button class="btn btn-primary flex-1" :disabled="saving" @click="save"><span v-if="saving" class="spinner" /> Хадгалах</button>
                    <button class="btn btn-ghost" @click="editing = null">Болих</button>
                </div>
            </div>

            <div v-else-if="viewing" class="panel">
                <div class="mb-3 flex items-center justify-between">
                    <b class="text-sm">{{ viewing.name }}</b>
                    <button class="text-zinc-500 hover:text-white" @click="viewing = null"><Icon name="x" size="16" /></button>
                </div>
                <pre class="max-h-[75vh] overflow-auto font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-300 scroll-thin">{{ viewing.content }}</pre>
            </div>

            <div v-else class="panel grid min-h-[40vh] place-items-center text-center text-sm text-zinc-500">Skill сонгож агуулгыг нь харна, эсвэл шинээр нэмнэ.</div>
        </div>
    </div>
</template>
