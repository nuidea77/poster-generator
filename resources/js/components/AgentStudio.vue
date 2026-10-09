<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '../lib/api';

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
    queued: 'bg-zinc-700 text-zinc-200',
    running: 'bg-fuchsia-600/30 text-fuchsia-200',
    done: 'bg-emerald-600/30 text-emerald-200',
    failed: 'bg-red-600/30 text-red-200',
};
const toolLabel = {
    generate_image: '🖼 Зураг үүсгэх',
    generate_video: '🎥 Видео үүсгэх',
    create_poster: '📄 Постер бүтээх',
    create_reel: '🎬 Reels бүтээх',
    finish: '✅ Дуусгах',
};
const short = (s, n = 160) => (s && s.length > n ? s.slice(0, n) + '…' : s);
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[400px_1fr]">
        <aside class="space-y-4">
            <div class="panel space-y-4">
                <div>
                    <div class="mb-1 text-sm font-semibold">Claude Fable агент</div>
                    <p class="text-xs text-zinc-500">
                        Даалгавраа бичээд зургаа хавсаргана. Fable даалгаврыг уншаад GPT image, Gemini, Seedance-ийн алийг нь хэрэглэхээ өөрөө шийдэж, зураг/видео үүсгэн постер, reels бэлдэнэ.
                    </p>
                </div>

                <p v-if="!claudeReady" class="rounded-lg bg-amber-950/60 p-3 text-xs text-amber-200">
                    Агент ажиллахын тулд <code>ANTHROPIC_API_KEY</code> шаардлагатай.
                </p>

                <div>
                    <label class="label">Даалгавар</label>
                    <textarea v-model="prompt" rows="6" class="field resize-none" placeholder="Жишээ: Энэ бүтээгдэхүүний зургийг ашиглаад Instagram постер ба 15 секундын reels хий…" />
                    <div class="mt-1.5 flex flex-wrap gap-1">
                        <button v-for="(ex, i) in examples" :key="i" class="rounded-full border border-zinc-700 px-2 py-0.5 text-[11px] text-zinc-400 hover:border-zinc-500" @click="prompt = ex">
                            Жишээ {{ i + 1 }}
                        </button>
                    </div>
                </div>

                <div>
                    <label class="label">Хавсралт зураг (бүтээгдэхүүн, лого, орчин…)</label>
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="(f, i) in files" :key="f.url" class="space-y-1">
                            <div class="relative aspect-square overflow-hidden rounded-lg bg-zinc-800">
                                <img :src="f.url" class="size-full object-cover" />
                                <button class="absolute top-1 right-1 rounded-full bg-black/70 px-1.5 text-xs" @click="removeFile(i)">✕</button>
                            </div>
                            <input v-model="f.note" class="field px-2 py-1 text-[11px]" placeholder="Тайлбар (лого, бүтээгдэхүүн…)" />
                        </div>
                        <label v-if="files.length < 6" class="grid aspect-square cursor-pointer place-items-center rounded-lg border border-dashed border-zinc-700 text-2xl text-zinc-500 hover:border-zinc-500">
                            +
                            <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" @change="addFiles" />
                        </label>
                    </div>
                </div>

                <div>
                    <label class="label">Хэл</label>
                    <select v-model="language" class="field">
                        <option value="mn">Монгол</option>
                        <option value="en">English</option>
                    </select>
                </div>

                <div class="text-[11px] text-zinc-500">
                    Боломжтой загварууд:
                    <span v-if="models.length">{{ models.map((m) => m.label).join(', ') }}</span>
                    <span v-else class="text-amber-300">зураг/видео загварын түлхүүр алга</span>
                </div>

                <button class="btn btn-primary w-full py-3" :disabled="submitting || busy || !claudeReady || !prompt.trim()" @click="submit">
                    <span v-if="submitting || busy" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                    {{ busy ? 'Агент ажиллаж байна…' : '🤖 Даалгавар өгөх' }}
                </button>
                <p v-if="error" class="rounded-lg bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            </div>

            <div v-if="runs.length" class="panel space-y-1">
                <div class="mb-2 text-sm font-semibold">Өмнөх даалгаврууд</div>
                <div v-for="r in runs" :key="r.id" class="group flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-zinc-800" :class="{ 'bg-zinc-800': run?.id === r.id }" @click="select(r)">
                    <span class="mt-0.5 rounded px-1.5 py-0.5 text-[10px]" :class="statusClass[r.status]">{{ statusLabel[r.status] }}</span>
                    <span class="line-clamp-2 flex-1 text-xs text-zinc-300">{{ r.prompt }}</span>
                    <button class="hidden text-xs text-zinc-500 group-hover:block" @click.stop="remove(r)">✕</button>
                </div>
            </div>
        </aside>

        <section class="min-w-0 space-y-4">
            <div v-if="!run" class="panel grid min-h-[60vh] place-items-center text-center text-zinc-500">
                <div>
                    <div class="mb-3 text-5xl">🤖</div>
                    <p>Даалгавар өгөхөд агент алхам бүрээ энд харуулна.</p>
                </div>
            </div>

            <template v-else>
                <div class="panel flex flex-wrap items-center gap-3">
                    <span class="rounded px-2 py-0.5 text-xs" :class="statusClass[run.status]">{{ statusLabel[run.status] }}</span>
                    <span class="text-xs text-zinc-500">{{ run.model }} · {{ run.input_tokens + run.output_tokens }} токен</span>
                    <span v-if="busy" class="ml-auto size-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                </div>

                <p v-if="run.error" class="rounded-lg bg-red-950/70 p-3 text-sm text-red-200">{{ run.error }}</p>

                <div v-if="run.outputs.length" class="panel">
                    <div class="mb-2 text-sm font-semibold">Бэлэн болсон</div>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="o in run.outputs" :key="o.type + o.id" class="btn btn-primary" @click="openOutput(o)">
                            {{ o.type === 'reel' ? '🎬' : '🖼' }} {{ o.title || (o.type === 'reel' ? 'Reels' : 'Постер') }} →
                        </button>
                    </div>
                </div>

                <div v-if="run.summary" class="panel">
                    <div class="mb-1 text-sm font-semibold">Агентын тайлан</div>
                    <p class="whitespace-pre-line text-sm text-zinc-300">{{ run.summary }}</p>
                </div>

                <div v-if="run.assets.some((a) => a.source === 'generated')" class="panel">
                    <div class="mb-2 text-sm font-semibold">Үүсгэсэн медиа</div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <div v-for="a in run.assets.filter((a) => a.source === 'generated')" :key="a.id" class="space-y-1">
                            <a :href="a.url" target="_blank" class="block overflow-hidden rounded-lg bg-zinc-800">
                                <video v-if="a.kind === 'video'" :src="a.url" class="aspect-[9/16] w-full object-cover" controls muted playsinline />
                                <img v-else :src="a.url" class="w-full object-cover" />
                            </a>
                            <div class="text-[11px] text-zinc-400"><b class="text-zinc-200">{{ a.id }}</b> · {{ a.provider }}{{ a.duration ? ` · ${a.duration}s` : '' }}</div>
                            <div class="line-clamp-2 text-[11px] text-zinc-500" :title="a.prompt">{{ a.note || a.prompt }}</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="mb-2 text-sm font-semibold">Алхамууд</div>
                    <div v-if="!run.steps.length" class="text-xs text-zinc-500">Fable даалгаврыг судалж байна…</div>
                    <ol class="space-y-2">
                        <li v-for="(s, i) in run.steps" :key="i" class="rounded-lg bg-zinc-950/60 p-3 text-xs">
                            <template v-if="s.type === 'note'">
                                <span class="text-zinc-500">💭</span> <span class="whitespace-pre-line text-zinc-300">{{ s.text }}</span>
                            </template>
                            <template v-else>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ toolLabel[s.name] || s.name }}</span>
                                    <span v-if="s.input?.provider" class="rounded bg-zinc-800 px-1.5 py-0.5 text-[10px] uppercase">{{ s.input.provider }}</span>
                                    <span v-if="s.result" class="text-emerald-300">{{ s.result }}</span>
                                    <span v-if="s.error" class="text-red-300">{{ s.error }}</span>
                                </div>
                                <div v-if="s.input?.purpose" class="mt-1 text-zinc-300">{{ s.input.purpose }}</div>
                                <div v-if="s.input?.prompt" class="mt-1 text-zinc-500">{{ short(s.input.prompt) }}</div>
                                <div v-if="s.input?.reference_image_ids?.length" class="mt-1 text-zinc-500">Reference: {{ s.input.reference_image_ids.join(', ') }}</div>
                                <div v-if="s.name === 'create_poster'" class="mt-1 text-zinc-300">„{{ s.input.headline }}“ — {{ s.input.layout }}, {{ s.input.format }}</div>
                                <div v-if="s.name === 'create_reel'" class="mt-1 text-zinc-300">{{ s.input.scenes?.length }} үзэгдэл · {{ s.input.hook }}</div>
                                <div v-if="s.name === 'finish'" class="mt-1 whitespace-pre-line text-zinc-300">{{ s.input.summary }}</div>
                            </template>
                        </li>
                    </ol>
                </div>
            </template>
        </section>
    </div>
</template>
