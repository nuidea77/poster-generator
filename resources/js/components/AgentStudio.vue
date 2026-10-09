<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import Icon from './Icon.vue';
import Composer from './Composer.vue';

const props = defineProps({
    config: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const prompt = ref('');
const language = ref('mn');
const files = ref([]); // { file, url, note }
const run = ref(null);
const runs = ref([]);
const error = ref('');
const submitting = ref(false);
let timer = null;

const claudeReady = computed(() => props.config.providers.find((p) => p.id === 'anthropic')?.configured);
const models = computed(() => props.config.providers.filter((p) => p.configured && (p.image || p.video) && p.id !== 'demo'));
const busy = computed(() => run.value && ['queued', 'running'].includes(run.value.status));

const examples = [
    'Энэ бүтээгдэхүүний зургийг ашиглаад Instagram-д зориулсан 4:5 постер, мөн 15 секундын reels хий. Хямдрал 20%, 10-р сарын 15 хүртэл.',
    'Манай кафены нээлтийг зарлах постер. Хавсаргасан лого, интерьерийн зургийг ашигла. Дулаан, тухтай уур амьсгал.',
    'Шинэ фитнес клубын сурталчилгааны эрч хүчтэй 9:16 видео клип + постер. Залуучуудад зориулсан.',
];

onMounted(loadRuns);
onBeforeUnmount(() => clearInterval(timer));

async function loadRuns() {
    try {
        runs.value = await api.get('/api/agent-runs');
    } catch (e) {
        error.value = e.message;
    }
}

function addFiles(event) {
    for (const file of event.target.files ?? []) {
        if (files.value.length >= 6) break;
        files.value.push({ file, url: URL.createObjectURL(file), note: '' });
    }
    event.target.value = '';
}

function removeFile(i) {
    URL.revokeObjectURL(files.value[i].url);
    files.value.splice(i, 1);
}

async function submit() {
    error.value = '';
    submitting.value = true;
    const data = new FormData();
    data.append('prompt', prompt.value);
    data.append('language', language.value);
    files.value.forEach((f, i) => {
        data.append('images[]', f.file);
        data.append(`notes[${i}]`, f.note);
    });
    try {
        run.value = await api.post('/api/agent-runs', data);
        watch();
        loadRuns();
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
}

function watch() {
    clearInterval(timer);
    timer = setInterval(async () => {
        if (!run.value) return clearInterval(timer);
        try {
            run.value = await api.get(`/api/agent-runs/${run.value.id}`);
        } catch (e) {
            error.value = e.message;
        }
        if (!busy.value) {
            clearInterval(timer);
            loadRuns();
        }
    }, 2500);
}

function select(r) {
    run.value = r;
    if (['queued', 'running'].includes(r.status)) watch();
}

async function openOutput(out) {
    try {
        emit('open', await api.get(`/api/generations/${out.id}`));
    } catch (e) {
        error.value = e.message;
    }
}

async function remove(r) {
    if (!confirm('Устгах уу?')) return;
    await api.delete(`/api/agent-runs/${r.id}`);
    runs.value = runs.value.filter((x) => x.id !== r.id);
    if (run.value?.id === r.id) run.value = null;
}

const statusLabel = { queued: 'Дараалалд', running: 'Ажиллаж байна', done: 'Дууссан', failed: 'Алдаа' };
const statusClass = {
    queued: 'badge-muted',
    running: 'bg-amber-400/15 text-amber-300',
    done: 'badge-new',
    failed: 'bg-red-500/20 text-red-300',
};
const toolLabel = {
    load_skill: 'Skill ачаалах',
    generate_image: 'Зураг үүсгэх',
    generate_video: 'Видео үүсгэх',
    create_poster: 'Постер бүтээх',
    create_reel: 'Reels бүтээх',
    finish: 'Дуусгах',
};
const short = (s, n = 160) => (s && s.length > n ? s.slice(0, n) + '…' : s);
</script>

<template>
    <div>
        <!-- Hero when nothing is running -->
        <section v-if="!run" class="mx-auto max-w-[1400px]">
            <div class="relative mx-auto flex min-h-[58vh] max-w-3xl flex-col items-center justify-center text-center">
                <span class="mb-5 inline-flex items-center gap-2 rounded-full bg-lime/15 px-3 py-1 text-sm font-medium text-lime"><Icon name="sparkles" size="14" /> Claude Fable агент</span>
                <h1 class="display text-4xl leading-[0.95] md:text-6xl">
                    Даалгавраа бич.<br />
                    <span class="text-lime">Агент бүгдийг хийнэ.</span>
                </h1>
                <p class="mt-4 max-w-xl text-base text-zinc-400 md:text-lg">
                    Зургаа хавсаргаад юу хэрэгтэйгээ бичнэ үү. Fable даалгаврыг уншаад GPT Image, Gemini, Seedance-ийн алийг нь хэрэглэхээ өөрөө шийдэж постер, reels бэлдэнэ.
                </p>
                <p v-if="!claudeReady" class="mt-5 rounded-xl bg-amber-950/60 px-4 py-2 text-xs text-amber-200">Агент ажиллахын тулд <code>ANTHROPIC_API_KEY</code> шаардлагатай.</p>
                <p v-if="error" class="mt-4 rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            </div>

            <!-- feature cards, like the model grid on higgsfield.ai -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button v-for="(ex, i) in examples" :key="i" class="feature-card text-left" @click="prompt = ex">
                    <div class="mb-6 flex items-start justify-between">
                        <Icon :name="['image', 'layers', 'video'][i]" size="20" class="text-zinc-300" />
                        <span class="badge badge-dark">Жишээ</span>
                    </div>
                    <div class="flex items-center gap-2 text-[15px] font-semibold">{{ ['Бүтээгдэхүүний постер + reels', 'Кафены нээлт', 'Фитнес видео клип'][i] }} <span v-if="i === 0" class="badge badge-top">Top</span></div>
                    <div class="mt-1 line-clamp-2 text-sm text-zinc-400">{{ ex }}</div>
                </button>
            </div>

            <div v-if="runs.length" class="mt-8">
                <div class="mb-3 flex items-center gap-2"><h2 class="display text-xl">Өмнөх даалгаврууд</h2><span class="badge badge-dark">{{ runs.length }}</span></div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="r in runs" :key="r.id" class="group feature-card cursor-pointer" @click="select(r)">
                        <div class="mb-3 flex items-center justify-between">
                            <span class="badge" :class="statusClass[r.status]">{{ statusLabel[r.status] }}</span>
                            <button class="text-zinc-500 opacity-0 group-hover:opacity-100 hover:text-fg" @click.stop="remove(r)"><Icon name="x" size="14" /></button>
                        </div>
                        <div class="line-clamp-3 text-sm text-zinc-300">{{ r.prompt }}</div>
                        <div class="mt-2 text-[11px] text-muted">{{ new Date(r.created_at).toLocaleString() }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Run feed -->
        <section v-else class="mx-auto max-w-5xl space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <button class="btn btn-ghost btn-sm" @click="run = null"><Icon name="arrow" size="12" class="rotate-180" /> Бүх даалгавар</button>
                <span class="badge" :class="statusClass[run.status]">{{ statusLabel[run.status] }}</span>
                <span class="text-xs text-muted">{{ run.model }} · {{ run.input_tokens + run.output_tokens }} tok</span>
                <span v-if="busy" class="spinner text-lime" />
            </div>
            <h1 class="display text-2xl leading-tight md:text-3xl">{{ run.prompt }}</h1>

            <p v-if="run.error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ run.error }}</p>

            <div v-if="run.outputs.length" class="grid gap-3 sm:grid-cols-2">
                <button v-for="o in run.outputs" :key="o.type + o.id" class="feature-card flex items-center gap-3 text-left" @click="openOutput(o)">
                    <span class="grid size-10 place-items-center rounded-xl bg-lime text-ink"><Icon :name="o.type === 'reel' ? 'video' : 'image'" size="18" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ o.title || (o.type === 'reel' ? 'Reels' : 'Постер') }}</span>
                        <span class="block text-[11px] text-muted">{{ o.type === 'reel' ? 'Reels' : 'Постер' }} · засварлагчид нээх</span>
                    </span>
                    <Icon name="arrow" size="16" class="text-zinc-500" />
                </button>
            </div>

            <div v-if="run.summary" class="panel">
                <div class="mb-1 text-sm font-semibold">Тайлан</div>
                <p class="whitespace-pre-line text-sm text-zinc-300">{{ run.summary }}</p>
            </div>

            <div v-if="run.assets.some((a) => a.source === 'generated')">
                <div class="mb-2 text-sm font-semibold">Үүсгэсэн медиа</div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <div v-for="a in run.assets.filter((a) => a.source === 'generated')" :key="a.id" class="group media-card">
                        <a :href="a.url" target="_blank" class="block">
                            <video v-if="a.kind === 'video'" :src="a.url" class="aspect-[9/16] w-full object-cover" controls muted playsinline />
                            <img v-else :src="a.url" class="w-full object-cover" />
                        </a>
                        <span class="badge badge-muted absolute top-2 left-2 backdrop-blur">{{ a.provider }}</span>
                        <div class="media-overlay pointer-events-none">
                            <div class="text-xs font-semibold">{{ a.id }}{{ a.duration ? ` · ${a.duration}s` : '' }}</div>
                            <div class="line-clamp-2 text-[11px] text-zinc-300">{{ a.note || a.prompt }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="mb-3 text-sm font-semibold">Алхамууд</div>
                <div v-if="!run.steps.length" class="flex items-center gap-2 text-xs text-muted"><span class="spinner text-lime" /> Fable даалгаврыг судалж байна…</div>
                <ol class="relative space-y-3 border-l border-white/10 pl-4">
                    <li v-for="(s, i) in run.steps" :key="i" class="relative text-xs">
                        <span class="absolute -left-[21px] top-1 size-2.5 rounded-full" :class="s.error ? 'bg-red-400' : s.type === 'note' ? 'bg-zinc-600' : 'bg-lime'" />
                        <template v-if="s.type === 'note'"><span class="whitespace-pre-line text-zinc-400">{{ s.text }}</span></template>
                        <template v-else>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ toolLabel[s.name] || s.name }}</span>
                                <span v-if="s.input?.provider" class="badge badge-muted">{{ s.input.provider }}</span>
                                <span v-if="s.result" class="text-lime">{{ s.result }}</span>
                                <span v-if="s.error" class="text-red-300">{{ s.error }}</span>
                            </div>
                            <div v-if="s.input?.purpose" class="mt-1 text-zinc-300">{{ s.input.purpose }}</div>
                            <div v-if="s.input?.prompt" class="mt-1 text-muted">{{ short(s.input.prompt) }}</div>
                            <div v-if="s.input?.reference_image_ids?.length" class="mt-1 text-muted">Reference: {{ s.input.reference_image_ids.join(', ') }}</div>
                            <div v-if="s.name === 'create_poster'" class="mt-1 text-zinc-300">„{{ s.input.headline }}“ — {{ s.input.layout }}, {{ s.input.format }}</div>
                            <div v-if="s.name === 'create_reel'" class="mt-1 text-zinc-300">{{ s.input.scenes?.length }} үзэгдэл · {{ s.input.hook }}</div>
                            <div v-if="s.name === 'finish'" class="mt-1 whitespace-pre-line text-zinc-300">{{ s.input.summary }}</div>
                            <div v-if="s.name === 'load_skill'" class="mt-1 text-muted">{{ s.input.name }}</div>
                        </template>
                    </li>
                </ol>
            </div>
        </section>

        <Composer v-model="prompt" placeholder="Юу хийлгэх вэ? Жишээ: Энэ бүтээгдэхүүний зургийг ашиглаад Instagram постер ба 15 секундын reels хий…" :busy="submitting || busy" busy-label="Агент ажиллаж байна" :disabled="!claudeReady" label="Generate" @generate="submit">
            <template #top>
                <div v-if="files.length" class="mb-2 flex flex-wrap gap-2 px-1">
                    <div v-for="(f, i) in files" :key="f.url" class="group relative">
                        <img :src="f.url" class="size-16 rounded-xl object-cover ring-1 ring-white/10" />
                        <input v-model="f.note" class="absolute inset-x-0 bottom-0 rounded-b-xl bg-black/70 px-1 py-0.5 text-[10px] outline-none placeholder-zinc-400" placeholder="тайлбар" />
                        <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeFile(i)"><Icon name="x" size="10" /></button>
                    </div>
                </div>
            </template>
            <label class="chip w-9 cursor-pointer justify-center px-0" title="Зураг хавсаргах">
                <Icon name="plus" size="16" />
                <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" @change="addFiles" />
            </label>
            <button type="button" class="chip" @click="language = language === 'mn' ? 'en' : 'mn'">{{ language === 'mn' ? 'Монгол' : 'English' }}</button>
            <span class="chip cursor-default"><Icon name="bolt" size="14" /> Claude Fable <span class="badge badge-dark ml-0.5">Top</span></span>
            <span v-for="m in models" :key="m.id" class="chip cursor-default hidden sm:inline-flex"><span class="size-1.5 rounded-full bg-lime" /> {{ m.label }}</span>
        </Composer>
    </div>
</template>
