<script setup>
// Админ: хэв маягийн жишээ сан — агент зургийн моделд style reference болгон өгөх сайн жишээ постер/зургууд.
// Зургийн гэрэл, өнгө, найруулгыг л зээлнэ; текст, логог хуулахгүй.
import { computed, onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import AdminNav from '../../components/AdminNav.vue';
import { api } from '../../lib/api';

// Ангиллууд (backend-ийн StyleReference::CATEGORIES-тэй ижил) ба монгол нэр.
const categories = {
    food: 'Хоол, ундаа',
    beauty: 'Гоо сайхан',
    fashion: 'Хувцас, загвар',
    health: 'Эрүүл мэнд',
    education: 'Боловсрол',
    event: 'Арга хэмжээ',
    retail: 'Худалдаа',
    tech: 'Технологи',
    real_estate: 'Үл хөдлөх',
    sport: 'Спорт',
    other: 'Бусад',
};

// Жагсаалт, шүүлтүүр, төлөв.
const refs = ref([]);
const filter = ref('');
const error = ref('');
const notice = ref('');
const uploading = ref(false);
const editingId = ref(null);
const draft = ref({});

// Оруулах маягт: олон файл, нийтлэг ангилал/хэв маяг/тэмдэглэл.
const form = ref({ files: [], category: 'food', style: '', notes: '' });
const fileInput = ref(null);

// Хэдэн жишээ идэвхтэй байгааг толгойд харуулна.
const activeCount = computed(() => refs.value.filter((r) => r.enabled).length);

onMounted(load);

// Жишээнүүдийг (сонгосон ангиллаар) ачаална.
async function load() {
    try {
        refs.value = await api.get('/admin/references' + (filter.value ? `?category=${filter.value}` : ''));
    } catch (e) {
        error.value = e.message;
    }
}

// Ангиллын шүүлтүүрийг солиод дахин ачаална.
function setFilter(c) {
    filter.value = c;
    load();
}

// Сонгосон файлуудыг маягтад хадгална.
function pickFiles(e) {
    form.value.files = Array.from(e.target.files || []);
}

// Сонгосон бүх файлыг нэг хүсэлтээр оруулна.
async function upload() {
    if (!form.value.files.length) return;
    error.value = notice.value = '';
    uploading.value = true;
    const data = new FormData();
    form.value.files.forEach((f) => data.append('images[]', f));
    data.append('category', form.value.category);
    if (form.value.style.trim()) data.append('style', form.value.style.trim());
    if (form.value.notes.trim()) data.append('notes', form.value.notes.trim());
    try {
        const created = await api.post('/admin/references', data);
        notice.value = `${created.length} жишээ нэмэгдлээ.`;
        form.value = { ...form.value, files: [], notes: '' };
        if (fileInput.value) fileInput.value.value = '';
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        uploading.value = false;
    }
}

// Картыг засах горимд оруулна.
function edit(r) {
    editingId.value = r.id;
    draft.value = { title: r.title, category: r.category, style: r.style || '', notes: r.notes || '', tags: (r.tags || []).join(', ') };
}

// Засварыг хадгална.
async function save(r) {
    error.value = '';
    const d = draft.value;
    try {
        await api.put(`/admin/references/${r.id}`, {
            title: d.title,
            category: d.category,
            style: d.style.trim() || null,
            notes: d.notes.trim() || null,
            tags: d.tags.split(',').map((t) => t.trim()).filter(Boolean),
        });
        editingId.value = null;
        await load();
    } catch (e) {
        error.value = e.message;
    }
}

// Идэвхтэй/идэвхгүй сэлгэнэ.
async function toggle(r) {
    error.value = '';
    try {
        Object.assign(r, await api.post(`/admin/references/${r.id}/toggle`));
    } catch (e) {
        error.value = e.message;
    }
}

// Баталгаажуулаад файлтай нь устгана.
async function remove(r) {
    if (!confirm(`"${r.title}" жишээг устгах уу?`)) return;
    try {
        await api.delete(`/admin/references/${r.id}`);
        refs.value = refs.value.filter((x) => x.id !== r.id);
    } catch (e) {
        error.value = e.message;
    }
}
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <!-- Толгой: гарчиг, тоо, тайлбар -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <h1 class="display text-3xl">Жишээ сан</h1>
            <span class="badge badge-dark">{{ activeCount }} / {{ refs.length }}</span>
            <p class="hidden text-sm text-zinc-400 md:block">Агент эндээс жишээ сонгож зургийн моделд хэв маяг (гэрэл, өнгө, найруулга) болгон өгнө. Текст, логог хуулахгүй.</p>
        </div>
        <!-- Алдаа ба мэдэгдэл -->
        <p v-if="error" class="mb-3 rounded-xl bg-red-950/70 p-2 text-xs text-red-200">{{ error }}</p>
        <p v-if="notice" class="mb-3 rounded-xl bg-lime/10 p-2 text-xs text-lime">{{ notice }}</p>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div>
                <!-- Ангиллын шүүлтүүр -->
                <div class="mb-4 flex flex-wrap gap-1.5">
                    <button class="chip chip-sm" :class="{ 'chip-active': !filter }" @click="setFilter('')">Бүгд</button>
                    <button v-for="(label, key) in categories" :key="key" class="chip chip-sm" :class="{ 'chip-active': filter === key }" @click="setFilter(key)">{{ label }}</button>
                </div>

                <!-- Жишээнүүдийн тор -->
                <div v-if="refs.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div v-for="r in refs" :key="r.id" class="feature-card p-0! overflow-hidden" :class="{ 'opacity-50': !r.enabled }">
                        <a :href="r.url" target="_blank" class="block aspect-[4/5] bg-black/40">
                            <img :src="r.url" :alt="r.title" loading="lazy" class="h-full w-full object-cover" />
                        </a>
                        <div class="space-y-2 p-3">
                            <template v-if="editingId === r.id">
                                <input v-model="draft.title" class="field" placeholder="Гарчиг" />
                                <select v-model="draft.category" class="field">
                                    <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <input v-model="draft.style" class="field" placeholder="Хэв маяг (Swiss, Editorial Warmth…)" />
                                <input v-model="draft.tags" class="field" placeholder="Шошго, таслалаар" />
                                <textarea v-model="draft.notes" rows="3" class="field resize-y text-xs" placeholder="Юуг нь зээлэх вэ (ж: хоолны ард диагональ өнгөт зурвас)" />
                                <div class="flex gap-2">
                                    <button class="btn btn-lime btn-sm flex-1" @click="save(r)">Хадгалах</button>
                                    <button class="btn btn-ghost btn-sm" @click="editingId = null">Болих</button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="line-clamp-1 text-[15px] font-semibold" :title="r.title">{{ r.title }}</div>
                                <div class="flex flex-wrap gap-1">
                                    <span class="badge badge-dark">{{ categories[r.category] || r.category }}</span>
                                    <span v-if="r.style" class="badge badge-new">{{ r.style }}</span>
                                    <span v-for="t in r.tags || []" :key="t" class="badge bg-white/5 text-zinc-400">{{ t }}</span>
                                </div>
                                <p class="line-clamp-3 min-h-[1rem] cursor-text text-xs text-zinc-400" title="Засах" @click="edit(r)">{{ r.notes || 'Тэмдэглэл нэмэх…' }}</p>
                                <div class="flex gap-1">
                                    <button class="btn btn-ghost btn-sm gap-1 px-2" title="Засах" @click="edit(r)"><Icon name="edit" size="12" /> Засах</button>
                                    <button class="btn btn-ghost btn-sm px-2" :title="r.enabled ? 'Идэвхгүй болгох' : 'Идэвхжүүлэх'" @click="toggle(r)"><Icon :name="r.enabled ? 'pause' : 'play'" size="12" /></button>
                                    <button class="btn btn-ghost btn-sm px-2 text-red-300" title="Устгах" @click="remove(r)"><Icon name="trash" size="12" /></button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <div v-else class="panel grid min-h-[30vh] place-items-center text-center text-sm text-muted">Энэ ангилалд жишээ алга. Баруун талаас зураг оруулна уу.</div>
            </div>

            <!-- Оруулах маягт: олон файл, нийтлэг ангилал/хэв маяг -->
            <div class="lg:sticky lg:top-20 lg:self-start">
                <div class="panel space-y-3">
                    <div class="text-sm font-semibold">Жишээ нэмэх</div>
                    <label class="btn btn-lime-soft w-full cursor-pointer">
                        <Icon name="upload" size="14" /> {{ form.files.length ? `${form.files.length} файл сонгосон` : 'Зураг сонгох (JPG, PNG, WebP)' }}
                        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="pickFiles" />
                    </label>
                    <div>
                        <label class="label">Ангилал</label>
                        <select v-model="form.category" class="field">
                            <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div><label class="label">Хэв маяг (заавал биш)</label><input v-model="form.style" class="field" placeholder="Swiss, Editorial Warmth…" /></div>
                    <div><label class="label">Юуг нь зээлэх вэ (заавал биш)</label><textarea v-model="form.notes" rows="3" class="field resize-y text-xs" placeholder="ж: хоолны ард диагональ өнгөт зурвас" /></div>
                    <p class="text-[11px] text-muted">Нэг файл 8 MB хүртэл. Урт тал нь 1536 px болж жижгэрнэ. Гарчиг файлын нэрээс авна.</p>
                    <button class="btn btn-lime w-full" :disabled="uploading || !form.files.length" @click="upload"><span v-if="uploading" class="spinner" /> Оруулах</button>
                </div>
            </div>
        </div>
    </div>
</template>
