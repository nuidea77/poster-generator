<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../lib/api';

const skills = ref([]);
const open = ref(false);
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
    <div class="panel space-y-3">
        <button class="flex w-full items-center justify-between text-sm font-semibold" @click="open = !open">
            <span>📚 Skills ({{ skills.length }})</span>
            <span class="text-xs text-zinc-500">{{ open ? 'хаах' : 'нээх' }}</span>
        </button>

        <template v-if="open">
            <p class="text-[11px] text-zinc-500">
                Агент ажиллахдаа хэрэгтэй skill-ээ өөрөө ачаалж уншина. Өөрийн брэнд, дүрэм, стилийн заавраа skill болгон нэмээрэй — тэдгээр нь ерөнхий зааврыг давамгайлна.
            </p>

            <div class="space-y-1">
                <div v-for="s in skills" :key="s.name" class="group rounded-lg px-2 py-1.5 text-xs hover:bg-zinc-800" :class="{ 'opacity-50': !s.enabled }">
                    <div class="flex items-center gap-2">
                        <button class="flex-1 truncate text-left font-medium text-zinc-200 hover:underline" @click="view(s)">{{ s.name }}</button>
                        <span v-if="s.source === 'custom'" class="rounded bg-fuchsia-500/20 px-1 text-[10px] text-fuchsia-200">custom</span>
                        <template v-if="s.source === 'custom'">
                            <button class="hidden text-zinc-400 group-hover:inline" :title="s.enabled ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх'" @click="toggle(s)">{{ s.enabled ? '⏸' : '▶' }}</button>
                            <button class="hidden text-zinc-400 group-hover:inline" @click="edit(s)">✎</button>
                            <button class="hidden text-red-400 group-hover:inline" @click="remove(s)">✕</button>
                        </template>
                    </div>
                    <div class="line-clamp-2 text-zinc-500">{{ s.description }}</div>
                </div>
            </div>

            <button v-if="!editing" class="btn btn-ghost w-full text-xs" @click="startNew">+ Skill нэмэх</button>

            <div v-if="editing" class="space-y-2 rounded-lg border border-zinc-700 p-3">
                <input v-model="editing.name" class="field text-xs" placeholder="нэр (жиш. my-brand)" />
                <input v-model="editing.description" class="field text-xs" placeholder="Хэзээ ашиглах вэ — агент үүнийг уншаад ачаалах эсэхээ шийднэ" />
                <textarea v-model="editing.content" rows="10" class="field resize-y font-mono text-[11px]" />
                <div class="flex gap-2">
                    <button class="btn btn-primary flex-1 text-xs" :disabled="saving" @click="save">Хадгалах</button>
                    <button class="btn btn-ghost text-xs" @click="editing = null">Болих</button>
                </div>
            </div>

            <div v-if="viewing" class="rounded-lg border border-zinc-800 bg-zinc-950/60 p-3">
                <div class="mb-2 flex items-center justify-between text-xs">
                    <b>{{ viewing.name }}</b>
                    <button class="text-zinc-500" @click="viewing = null">✕</button>
                </div>
                <pre class="max-h-80 overflow-auto font-mono text-[11px] whitespace-pre-wrap text-zinc-400">{{ viewing.content }}</pre>
            </div>

            <p v-if="error" class="rounded-lg bg-red-950/70 p-2 text-xs text-red-200">{{ error }}</p>
        </template>
    </div>
</template>
