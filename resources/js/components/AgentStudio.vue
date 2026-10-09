<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import Icon from './Icon.vue';

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
    done: 'badge-top',
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
    <div class="grid gap-5 lg:grid-cols-[400px_1fr]">
        <!-- Composer -->
        <aside class="space-y-4 lg:sticky lg:top-5 lg:max-h-[calc(100vh-40px)] lg:overflow-y-auto lg:pr-1 scroll-thin">
            <div class="panel space-y-5">
                <div class="flex items-center gap-2">
                    <div class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-orange-400 to-amber-600 text-xs font-black">C</div>
                    <div>
                        <h1 class="text-base font-bold leading-tight">Агент <span class="badge badge-top ml-1">top</span></h1>
                        <div class="text-[11px] text-zinc-500">Claude Fable · зураг/видео моделиудыг өөрөө сонгоно</div>
                    </div>
                </div>

                <p v-if="!claudeReady" class="rounded-xl bg-amber-950/60 p-3 text-xs text-amber-200">Агент ажиллахын тулд <code>ANTHROPIC_API_KEY</code> шаардлагатай.</p>

                <!-- prompt box with attachments inside, Higgsfield-style -->
                <div class="rounded-2xl border border-line bg-surface-2 p-2 transition focus-within:border-zinc-500">
                    <textarea v-model="prompt" rows="5" class="w-full resize-none bg-transparent px-2 py-1.5 text-sm placeholder-zinc-500 outline-none" placeholder="Юу хийлгэх вэ? Жишээ: Энэ бүтээгдэхүүний зургийг ашиглаад Instagram постер ба 15 секундын reels хий…" />
                    <div class="flex flex-wrap items-center gap-1.5 px-1 pb-1">
                        <div v-for="(f, i) in files" :key="f.url" class="group relative">
                            <img :src="f.url" class="size-12 rounded-lg object-cover ring-1 ring-white/10" :title="f.note" />
                            <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeFile(i)"><Icon name="x" size="10" /></button>
                        </div>
                        <label v-if="files.length < 6" class="grid size-12 cursor-pointer place-items-center rounded-lg border border-dashed border-line-2 text-zinc-400 hover:border-white hover:text-white" title="Зураг хавсаргах">
                            <Icon name="plus" size="16" />
                            <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" @change="addFiles" />
                        </label>
                        <span class="ml-auto text-[11px] text-zinc-500">{{ files.length }}/6 зураг</span>
                    </div>
                </div>

                <div v-if="files.length" class="space-y-1.5">
                    <label class="label">Хавсралтын тайлбар</label>
                    <div v-for="f in files" :key="f.url + 'n'" class="flex items-center gap-2">
                        <img :src="f.url" class="size-7 rounded-md object-cover" />
                        <input v-model="f.note" class="field py-1.5 text-xs" placeholder="лого / бүтээгдэхүүн / орчин…" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <button v-for="(ex, i) in examples" :key="i" class="chip" :title="ex" @click="prompt = ex">Жишээ {{ i + 1 }}</button>
                </div>

                <div class="grid grid-cols-2 gap-1.5">
                    <button class="chip justify-center" :class="{ 'chip-active': language === 'mn' }" @click="language = 'mn'">Монгол</button>
                    <button class="chip justify-center" :class="{ 'chip-active': language === 'en' }" @click="language = 'en'">English</button>
                </div>

                <div>
                    <label class="label">Агентын боломжит моделиуд</label>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="m in models" :key="m.id" class="chip cursor-default"><span class="size-1.5 rounded-full bg-lime-400" /> {{ m.label }}</span>
                        <span v-if="!models.length" class="text-xs text-amber-300">зураг/видео загварын түлхүүр алга</span>
                    </div>
                </div>

                <button class="btn btn-primary w-full py-3" :disabled="submitting || busy || !claudeReady || !prompt.trim()" @click="submit">
                    <span v-if="submitting || busy" class="spinner" /><Icon v-else name="bolt" size="16" />
                    {{ busy ? 'Ажиллаж байна…' : 'Даалгавар өгөх' }}
                </button>
                <p v-if="error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            </div>

            <div v-if="runs.length" class="panel space-y-1">
                <div class="mb-2 flex items-center gap-2 text-sm font-semibold"><Icon name="clock" size="14" /> Өмнөх даалгаврууд</div>
                <div v-for="r in runs" :key="r.id" class="group flex cursor-pointer items-start gap-2 rounded-xl px-2 py-1.5 hover:bg-white/5" :class="{ 'bg-white/5': run?.id === r.id }" @click="select(r)">
                    <span class="badge mt-0.5" :class="statusClass[r.status]">{{ statusLabel[r.status] }}</span>
                    <span class="line-clamp-2 flex-1 text-xs text-zinc-300">{{ r.prompt }}</span>
                    <button class="hidden text-zinc-500 group-hover:block" @click.stop="remove(r)"><Icon name="x" size="12" /></button>
                </div>
            </div>
        </aside>

        <!-- Feed -->
        <section class="min-w-0 space-y-4">
            <div v-if="!run" class="panel grid min-h-[70vh] place-items-center text-center">
                <div class="max-w-md">
                    <div class="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-white/5"><Icon name="sparkles" size="26" /></div>
                    <p class="font-semibold">Даалгавраа бичээд зургаа хавсаргана</p>
                    <p class="mt-1 text-sm text-zinc-500">Fable даалгаврыг уншаад GPT image, Gemini, Seedance-ийн алийг нь хэрэглэхээ шийдэж, зураг/видео үүсгэн постер, reels бэлдэнэ. Алхам бүр энд харагдана.</p>
                </div>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="badge" :class="statusClass[run.status]">{{ statusLabel[run.status] }}</span>
                    <span class="line-clamp-1 flex-1 text-sm text-zinc-300">{{ run.prompt }}</span>
                    <span class="text-xs text-zinc-500">{{ run.model }} · {{ run.input_tokens + run.output_tokens }} tok</span>
                    <span v-if="busy" class="spinner" />
                </div>

                <p v-if="run.error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ run.error }}</p>

                <div v-if="run.outputs.length" class="grid gap-3 sm:grid-cols-2">
                    <button v-for="o in run.outputs" :key="o.type + o.id" class="panel flex items-center gap-3 text-left transition hover:border-line-2" @click="openOutput(o)">
                        <span class="grid size-10 place-items-center rounded-xl bg-white text-black"><Icon :name="o.type === 'reel' ? 'video' : 'image'" size="18" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ o.title || (o.type === 'reel' ? 'Reels' : 'Постер') }}</span>
                            <span class="block text-[11px] text-zinc-500">{{ o.type === 'reel' ? 'Reels' : 'Постер' }} · засварлагчид нээх</span>
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
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
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
                    <div v-if="!run.steps.length" class="flex items-center gap-2 text-xs text-zinc-500"><span class="spinner" /> Fable даалгаврыг судалж байна…</div>
                    <ol class="relative space-y-3 border-l border-line pl-4">
                        <li v-for="(s, i) in run.steps" :key="i" class="relative text-xs">
                            <span class="absolute -left-[21px] top-1 size-2.5 rounded-full" :class="s.error ? 'bg-red-400' : s.type === 'note' ? 'bg-zinc-600' : 'bg-white'" />
                            <template v-if="s.type === 'note'">
                                <span class="whitespace-pre-line text-zinc-400">{{ s.text }}</span>
                            </template>
                            <template v-else>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ toolLabel[s.name] || s.name }}</span>
                                    <span v-if="s.input?.provider" class="badge badge-muted">{{ s.input.provider }}</span>
                                    <span v-if="s.result" class="text-lime-300">{{ s.result }}</span>
                                    <span v-if="s.error" class="text-red-300">{{ s.error }}</span>
                                </div>
                                <div v-if="s.input?.purpose" class="mt-1 text-zinc-300">{{ s.input.purpose }}</div>
                                <div v-if="s.input?.prompt" class="mt-1 text-zinc-500">{{ short(s.input.prompt) }}</div>
                                <div v-if="s.input?.reference_image_ids?.length" class="mt-1 text-zinc-500">Reference: {{ s.input.reference_image_ids.join(', ') }}</div>
                                <div v-if="s.name === 'create_poster'" class="mt-1 text-zinc-300">„{{ s.input.headline }}“ — {{ s.input.layout }}, {{ s.input.format }}</div>
                                <div v-if="s.name === 'create_reel'" class="mt-1 text-zinc-300">{{ s.input.scenes?.length }} үзэгдэл · {{ s.input.hook }}</div>
                                <div v-if="s.name === 'finish'" class="mt-1 whitespace-pre-line text-zinc-300">{{ s.input.summary }}</div>
                                <div v-if="s.name === 'load_skill'" class="mt-1 text-zinc-500">{{ s.input.name }}</div>
                            </template>
                        </li>
                    </ol>
                </div>
            </template>
        </section>
    </div>
</template>
