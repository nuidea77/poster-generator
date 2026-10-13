<script setup>
// Админ: агентын skill-үүд — жагсаах, үзэх, засах, идэвхжүүлэх/унтраах, SKILL.md импортлох, custom skill нэмэх/устгах.
import { onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import AdminNav from '../../components/AdminNav.vue';
import { api } from '../../lib/api';

// Skill-үүдийн жагсаалт ба баруун талын самбарын төлөв (үзэж буй / засаж буй).
const skills = ref([]);
const viewing = ref(null); // { name, content }
const editing = ref(null); // { id?, name, description, content, enabled, bundled }
const error = ref('');
const saving = ref(false);
const importing = ref(false);
const notice = ref('');

// Claude skill-ээс гаралтай эсэх (origin мөрөөр нь).
const isClaude = (s) => /claude skill/i.test(s.origin || '');

// Claude-ийн SKILL.md файлыг импортлоод жагсаалтыг шинэчилж, шинэ skill-ийг нээнэ.
async function importFile(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    error.value = notice.value = '';
    importing.value = true;
    const data = new FormData();
    data.append('file', file);
    try {
        const skill = await api.post('/admin/skills/import', data);
        notice.value = `"${skill.name}" skill нэмэгдлээ.`;
        await load();
        view(skill);
    } catch (err) {
        error.value = err.message;
    } finally {
        importing.value = false;
    }
}

// Шинэ skill-ийн анхдагч Markdown загвар.
const template = `# Миний skill

## Хэзээ ашиглах
...

## Дүрэм
- ...
`;

onMounted(load);

// Бүх skill-ийг (bundled + custom) ачаална.
async function load() {
    try {
        skills.value = await api.get('/admin/skills');
    } catch (e) {
        error.value = e.message;
    }
}

// Skill-ийн агуулгыг ачаалж баруун самбарт харуулна.
async function view(s) {
    error.value = '';
    try {
        viewing.value = { ...(await api.get(`/admin/skills/${s.name}`)), skill: s, origin: s.origin, description: s.description };
    } catch (e) {
        error.value = e.message;
    }
}

// Системтэй хамт ирсэн (bundled) skill эсэх.
const isBundled = (s) => s.source === 'bundled';

// Хоосон загвартай шинэ skill засварлагчийг нээнэ.
function startNew() {
    editing.value = { name: '', description: '', content: template, enabled: true };
    viewing.value = null;
}

// Skill-ийн агуулгыг татаж засварлагчид ачаална.
async function edit(s) {
    error.value = '';
    try {
        const { content } = await api.get(`/admin/skills/${s.name}`);
        editing.value = { id: s.id, name: s.name, description: s.description, content, enabled: s.enabled, bundled: isBundled(s) };
        viewing.value = null;
    } catch (e) {
        error.value = e.message;
    }
}

// Bundled skill нь ижил нэртэй засвар (override) болж хадгалагдана; custom skill өөрийн мөрөөр.
const put = (s, body) => (isBundled(s) ? api.put(`/admin/skills/bundled/${s.name}`, body) : api.put(`/admin/skills/${s.id}`, { name: s.name, ...body }));

// Засварлагчийн утгыг хадгална: байгаа skill бол put(), шинэ бол POST.
async function save() {
    error.value = '';
    saving.value = true;
    try {
        const e = editing.value;
        const body = { name: e.name, description: e.description, content: e.content, enabled: e.enabled };
        if (e.bundled || e.id) await put({ ...e, source: e.bundled ? 'bundled' : 'custom' }, body);
        else await api.post('/admin/skills', body);
        notice.value = `"${e.name}" хадгалагдлаа.`;
        editing.value = null;
        await load();
    } catch (err) {
        error.value = err.message;
    } finally {
        saving.value = false;
    }
}

// Skill-ийг идэвхжүүлэх/унтраах; нээлттэй байвал харагдацыг шинэчилнэ.
async function toggle(s) {
    error.value = '';
    try {
        const { content } = await api.get(`/admin/skills/${s.name}`);
        await put(s, { description: s.description, content, enabled: !s.enabled });
        await load();
        if (viewing.value?.name === s.name) await view(skills.value.find((x) => x.name === s.name));
    } catch (e) {
        error.value = e.message;
    }
}

// Bundled skill-ийн засварыг устгаж анхны хувилбар руу буцаана.
async function reset(s) {
    if (!confirm(`"${s.name}"-ийн засварыг устгаад анхны хувилбар руу буцаах уу?`)) return;
    await api.delete(`/admin/skills/bundled/${s.name}`);
    await load();
    if (viewing.value?.name === s.name) await view(skills.value.find((x) => x.name === s.name));
}

// Custom skill-ийг баталгаажуулаад устгана.
async function remove(s) {
    if (!confirm(`"${s.name}" skill-ийг устгах уу?`)) return;
    await api.delete(`/admin/skills/${s.id}`);
    if (viewing.value?.name === s.name) viewing.value = null;
    await load();
}
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <!-- Толгой: гарчиг, тоо, импортлох ба нэмэх товч -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <h1 class="display text-3xl">Skills</h1>
            <span class="badge badge-dark">{{ skills.length }}</span>
            <p class="hidden text-sm text-zinc-400 md:block">Агент ажиллахдаа хэрэгтэй skill-ээ өөрөө ачаалж уншина. Custom skill ерөнхий зааврыг давамгайлна.</p>
            <div class="ml-auto flex gap-2">
                <label class="btn btn-lime-soft cursor-pointer" :class="{ 'opacity-50': importing }" title="Claude skill-ийн SKILL.md файл">
                    <span v-if="importing" class="spinner" /><Icon v-else name="upload" size="14" /> SKILL.md импортлох
                    <input type="file" accept=".md,text/markdown,text/plain" class="hidden" :disabled="importing" @change="importFile" />
                </label>
                <button class="btn btn-lime" @click="startNew"><Icon name="plus" size="14" /> Skill нэмэх</button>
            </div>
        </div>
        <!-- Алдаа ба мэдэгдэл -->
        <p v-if="error" class="mb-3 rounded-xl bg-red-950/70 p-2 text-xs text-red-200">{{ error }}</p>
        <p v-if="notice" class="mb-3 rounded-xl bg-lime/10 p-2 text-xs text-lime">{{ notice }}</p>

        <div class="grid gap-5 lg:grid-cols-[1fr_minmax(0,1.2fr)]">
            <!-- Skill-үүдийн картууд: тэмдэглэгээ, тайлбар, үйлдлийн товчнууд -->
            <div class="grid gap-3 sm:grid-cols-2">
                <button v-for="s in skills" :key="s.name" class="group feature-card text-left" :class="{ 'opacity-50': !s.enabled, 'border-lime/60': viewing?.name === s.name }" @click="view(s)">
                    <div class="mb-5 flex items-start justify-between">
                        <Icon name="book" size="20" :class="s.source === 'custom' ? 'text-lime' : 'text-zinc-300'" />
                        <span class="flex gap-1">
                            <span v-if="isClaude(s)" class="badge bg-orange-500/20 text-orange-300">Claude skill</span>
                            <span v-if="s.overridden" class="badge bg-sky-500/20 text-sky-300">Засварласан</span>
                            <span class="badge" :class="s.source === 'custom' ? 'badge-new' : 'badge-dark'">{{ s.source === 'custom' ? 'Custom' : 'Bundled' }}</span>
                        </span>
                    </div>
                    <div class="text-[15px] font-semibold">{{ s.name }}</div>
                    <div class="mt-1 line-clamp-2 text-sm text-zinc-400">{{ s.description }}</div>
                    <div v-if="s.origin" class="mt-2 line-clamp-1 text-[11px] text-muted">{{ s.origin }}</div>
                    <div class="mt-3 flex gap-1">
                        <span class="btn btn-ghost btn-sm gap-1 px-2" title="Засах" @click.stop="edit(s)"><Icon name="edit" size="12" /> Засах</span>
                        <span class="btn btn-ghost btn-sm px-2" :title="s.enabled ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх'" @click.stop="toggle(s)"><Icon :name="s.enabled ? 'pause' : 'play'" size="12" /></span>
                        <span v-if="s.overridden" class="btn btn-ghost btn-sm px-2" title="Анхны хувилбар руу буцаах" @click.stop="reset(s)"><Icon name="refresh" size="12" /></span>
                        <span v-if="!isBundled(s)" class="btn btn-ghost btn-sm px-2 text-red-300" title="Устгах" @click.stop="remove(s)"><Icon name="trash" size="12" /></span>
                    </div>
                </button>
            </div>

            <!-- Баруун самбар: засварлагч / skill-ийн агуулга / хоосон төлөв -->
            <div class="lg:sticky lg:top-20 lg:self-start">
                <div v-if="editing" class="panel space-y-3">
                    <div class="text-sm font-semibold">{{ editing.id || editing.bundled ? 'Skill засах' : 'Шинэ skill' }}</div>
                    <p v-if="editing.bundled" class="rounded-xl bg-white/5 p-2 text-[11px] text-zinc-400">Системийн skill. Засвар тусад нь хадгалагдана, хүссэн үедээ анхны хувилбар руу буцааж болно.</p>
                    <div><label class="label">Нэр</label><input v-model="editing.name" class="field" :readonly="editing.bundled" :class="{ 'opacity-60': editing.bundled }" placeholder="my-brand (жижиг үсэг, зураас)" /></div>
                    <div><label class="label">Хэзээ ашиглах</label><input v-model="editing.description" class="field" placeholder="Агент үүнийг уншаад ачаалах эсэхээ шийднэ" /></div>
                    <div><label class="label">Агуулга (Markdown)</label><textarea v-model="editing.content" rows="16" class="field resize-y font-mono text-xs" /></div>
                    <div class="flex gap-2">
                        <button class="btn btn-lime flex-1" :disabled="saving" @click="save"><span v-if="saving" class="spinner" /> Хадгалах</button>
                        <button class="btn btn-ghost" @click="editing = null">Болих</button>
                    </div>
                </div>
                <div v-else-if="viewing" class="panel">
                    <div class="mb-1 flex items-center justify-between">
                        <b class="text-sm">{{ viewing.name }}</b>
                        <button class="text-zinc-500 hover:text-fg" @click="viewing = null"><Icon name="x" size="16" /></button>
                    </div>
                    <p v-if="viewing.description" class="mb-1 text-xs text-zinc-400">{{ viewing.description }}</p>
                    <p v-if="viewing.origin" class="mb-3 text-[11px] text-muted">Эх сурвалж: {{ viewing.origin }}</p>
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        <button class="btn btn-lime btn-sm gap-1" @click="edit(viewing.skill)"><Icon name="edit" size="12" /> Засах</button>
                        <button class="btn btn-ghost btn-sm gap-1" @click="toggle(viewing.skill)"><Icon :name="viewing.skill.enabled ? 'pause' : 'play'" size="12" /> {{ viewing.skill.enabled ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх' }}</button>
                        <button v-if="viewing.skill.overridden" class="btn btn-ghost btn-sm gap-1" @click="reset(viewing.skill)"><Icon name="refresh" size="12" /> Анхных руу буцаах</button>
                        <button v-if="!isBundled(viewing.skill)" class="btn btn-ghost btn-sm gap-1 text-red-300" @click="remove(viewing.skill)"><Icon name="trash" size="12" /> Устгах</button>
                    </div>
                    <pre class="max-h-[70vh] overflow-auto font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-300 scroll-thin">{{ viewing.content }}</pre>
                </div>
                <div v-else class="panel grid min-h-[40vh] place-items-center text-center text-sm text-muted">Skill сонгож агуулгыг нь харна, эсвэл шинээр нэмнэ.</div>
            </div>
        </div>
    </div>
</template>
