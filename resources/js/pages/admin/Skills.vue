<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import AdminNav from '../../components/AdminNav.vue';
import { api } from '../../lib/api';

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
        skills.value = await api.get('/admin/skills');
    } catch (e) {
        error.value = e.message;
    }
}

async function view(s) {
    error.value = '';
    try {
        viewing.value = await api.get(`/admin/skills/${s.name}`);
    } catch (e) {
        error.value = e.message;
    }
}

function startNew() {
    editing.value = { name: '', description: '', content: template, enabled: true };
    viewing.value = null;
}

async function edit(s) {
    const { content } = await api.get(`/admin/skills/${s.name}`);
    editing.value = { id: s.id, name: s.name, description: s.description, content, enabled: s.enabled };
    viewing.value = null;
}

async function save() {
    error.value = '';
    saving.value = true;
    try {
        const e = editing.value;
        if (e.id) await api.put(`/admin/skills/${e.id}`, e);
        else await api.post('/admin/skills', e);
        editing.value = null;
        await load();
    } catch (err) {
        error.value = err.message;
    } finally {
        saving.value = false;
    }
}

async function toggle(s) {
    await api.put(`/admin/skills/${s.id}`, { name: s.name, description: s.description, content: (await api.get(`/admin/skills/${s.name}`)).content, enabled: !s.enabled });
    await load();
}

async function remove(s) {
    if (!confirm(`"${s.name}" skill-ийг устгах уу?`)) return;
    await api.delete(`/admin/skills/${s.id}`);
    await load();
}
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <h1 class="display text-3xl">Skills</h1>
            <span class="badge badge-dark">{{ skills.length }}</span>
            <p class="hidden text-sm text-zinc-400 md:block">Агент ажиллахдаа хэрэгтэй skill-ээ өөрөө ачаалж уншина. Custom skill ерөнхий зааврыг давамгайлна.</p>
            <button class="btn btn-lime ml-auto" @click="startNew"><Icon name="plus" size="14" /> Skill нэмэх</button>
        </div>
        <p v-if="error" class="mb-3 rounded-xl bg-red-950/70 p-2 text-xs text-red-200">{{ error }}</p>

        <div class="grid gap-5 lg:grid-cols-[1fr_minmax(0,1.2fr)]">
            <div class="grid gap-3 sm:grid-cols-2">
                <button v-for="s in skills" :key="s.name" class="group feature-card text-left" :class="{ 'opacity-50': !s.enabled, 'border-lime/60': viewing?.name === s.name }" @click="view(s)">
                    <div class="mb-5 flex items-start justify-between">
                        <Icon name="book" size="20" :class="s.source === 'custom' ? 'text-lime' : 'text-zinc-300'" />
                        <span class="badge" :class="s.source === 'custom' ? 'badge-new' : 'badge-dark'">{{ s.source === 'custom' ? 'Custom' : 'Bundled' }}</span>
                    </div>
                    <div class="text-[15px] font-semibold">{{ s.name }}</div>
                    <div class="mt-1 line-clamp-2 text-sm text-zinc-400">{{ s.description }}</div>
                    <div v-if="s.source === 'custom'" class="mt-3 flex gap-1 opacity-0 transition group-hover:opacity-100">
                        <span class="btn btn-ghost btn-sm px-2" :title="s.enabled ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх'" @click.stop="toggle(s)"><Icon :name="s.enabled ? 'pause' : 'play'" size="12" /></span>
                        <span class="btn btn-ghost btn-sm px-2" @click.stop="edit(s)"><Icon name="edit" size="12" /></span>
                        <span class="btn btn-ghost btn-sm px-2 text-red-300" @click.stop="remove(s)"><Icon name="trash" size="12" /></span>
                    </div>
                </button>
            </div>

            <div class="lg:sticky lg:top-20 lg:self-start">
                <div v-if="editing" class="panel space-y-3">
                    <div class="text-sm font-semibold">{{ editing.id ? 'Skill засах' : 'Шинэ skill' }}</div>
                    <div><label class="label">Нэр</label><input v-model="editing.name" class="field" placeholder="my-brand (жижиг үсэг, зураас)" /></div>
                    <div><label class="label">Хэзээ ашиглах</label><input v-model="editing.description" class="field" placeholder="Агент үүнийг уншаад ачаалах эсэхээ шийднэ" /></div>
                    <div><label class="label">Агуулга (Markdown)</label><textarea v-model="editing.content" rows="16" class="field resize-y font-mono text-xs" /></div>
                    <div class="flex gap-2">
                        <button class="btn btn-lime flex-1" :disabled="saving" @click="save"><span v-if="saving" class="spinner" /> Хадгалах</button>
                        <button class="btn btn-ghost" @click="editing = null">Болих</button>
                    </div>
                </div>
                <div v-else-if="viewing" class="panel">
                    <div class="mb-3 flex items-center justify-between">
                        <b class="text-sm">{{ viewing.name }}</b>
                        <button class="text-zinc-500 hover:text-fg" @click="viewing = null"><Icon name="x" size="16" /></button>
                    </div>
                    <pre class="max-h-[70vh] overflow-auto font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-300 scroll-thin">{{ viewing.content }}</pre>
                </div>
                <div v-else class="panel grid min-h-[40vh] place-items-center text-center text-sm text-muted">Skill сонгож агуулгыг нь харна, эсвэл шинээр нэмнэ.</div>
            </div>
        </div>
    </div>
</template>
