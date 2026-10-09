<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api, debounce, downloadBlob, mapLimit } from '../lib/api';
import { SIZES, drawReelFrame, ensureFonts, loadImage, reelDuration } from '../lib/render';
import BriefForm from './BriefForm.vue';
import CaptionBox from './CaptionBox.vue';
import ImageControls from './ImageControls.vue';

const props = defineProps({
    config: { type: Object, required: true },
    initial: { type: Object, default: null },
    active: { type: Boolean, default: true },
});

const form = ref({
    prompt: props.initial?.prompt ?? '',
    style: props.initial?.options?.style ?? '',
    language: props.initial?.options?.language ?? 'mn',
    duration: props.initial?.options?.duration ?? 20,
    scenes: props.initial?.options?.scenes ?? 5,
    text_provider: props.initial?.text_provider ?? props.config.default_text,
    image_provider: props.initial?.image_provider ?? props.config.default_image,
});

const generation = ref(props.initial ? structuredClone(props.initial) : null);
const reel = computed(() => generation.value?.content);
const total = computed(() => (reel.value ? reelDuration(reel.value) : 0));

const busyText = ref(false);
const sceneBusy = reactive({});
const error = ref('');
const canvas = ref(null);
const images = ref([]);

const playing = ref(false);
const time = ref(0);
const recording = ref(false);
const converting = ref(false);
const music = ref(null); // { name, url }

async function generate() {
    error.value = '';
    busyText.value = true;
    stop();
    try {
        generation.value = await api.post('/api/generate/reel', form.value);
    } catch (e) {
        error.value = e.message;
        return;
    } finally {
        busyText.value = false;
    }
    await mapLimit(reel.value.scenes, 3, (_, i) => generateSceneImage(i));
}

async function generateSceneImage(i) {
    const scene = reel.value.scenes[i];
    if (!scene?.image_prompt) return;
    sceneBusy[i] = true;
    try {
        const { url } = await api.post('/api/images', {
            prompt: scene.image_prompt,
            aspect: '9:16',
            provider: form.value.image_provider,
        });
        scene.image_url = url;
    } catch (e) {
        error.value = `Үзэгдэл ${i + 1}: ${e.message}`;
    } finally {
        sceneBusy[i] = false;
    }
}

const anyImageBusy = computed(() => Object.values(sceneBusy).some(Boolean));

function addScene() {
    reel.value.scenes.push({ duration: 3, text: 'Шинэ үзэгдэл', subtext: '', voiceover: '', image_prompt: '', motion: 'zoom-in', image_url: null });
}

function removeScene(i) {
    reel.value.scenes.splice(i, 1);
}

function moveScene(i, dir) {
    const s = reel.value.scenes;
    const j = i + dir;
    if (j < 0 || j >= s.length) return;
    [s[i], s[j]] = [s[j], s[i]];
}

// --- rendering & playback -------------------------------------------------

async function loadImages() {
    if (!reel.value) return;
    await ensureFonts();
    images.value = await Promise.all(reel.value.scenes.map((s) => loadImage(s.image_url)));
    draw();
}

function draw() {
    if (!reel.value || !canvas.value) return;
    const [w, h] = SIZES['9:16'];
    if (canvas.value.width !== w) {
        canvas.value.width = w;
        canvas.value.height = h;
    }
    drawReelFrame(canvas.value.getContext('2d'), reel.value, images.value, time.value);
}

let raf = 0;
let startedAt = 0;

function loop(now) {
    time.value = (now - startedAt) / 1000;
    if (time.value >= total.value) {
        time.value = total.value;
        draw();
        if (recording.value) return finishRecording();
        playing.value = false;
        return;
    }
    draw();
    raf = requestAnimationFrame(loop);
}

function play() {
    if (!total.value) return;
    if (time.value >= total.value) time.value = 0;
    startedAt = performance.now() - time.value * 1000;
    playing.value = true;
    raf = requestAnimationFrame(loop);
}

function stop() {
    cancelAnimationFrame(raf);
    playing.value = false;
}

function toggle() {
    playing.value ? stop() : play();
}

function seek(e) {
    stop();
    time.value = Number(e.target.value);
    draw();
}

// --- export -----------------------------------------------------------------

let recorder;
let chunks = [];
let audio;
let audioCtx;

function pickMime() {
    // Generic "video/mp4" last: Chrome may put VP9 inside it, Safari records H.264.
    const candidates = ['video/mp4;codecs=avc1', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    return candidates.find((m) => window.MediaRecorder?.isTypeSupported(m));
}

async function exportVideo() {
    const mime = pickMime();
    if (!mime) {
        error.value = 'Таны браузер видео бичлэг дэмжихгүй байна. Chrome эсвэл Edge ашиглана уу.';
        return;
    }
    stop();
    await loadImages();
    time.value = 0;
    draw();

    const stream = canvas.value.captureStream(30);

    if (music.value) {
        audio = new Audio(music.value.url);
        audioCtx = new AudioContext();
        const source = audioCtx.createMediaElementSource(audio);
        const dest = audioCtx.createMediaStreamDestination();
        source.connect(dest);
        source.connect(audioCtx.destination);
        dest.stream.getAudioTracks().forEach((t) => stream.addTrack(t));
        await audio.play().catch(() => {});
    }

    chunks = [];
    recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 8_000_000 });
    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    recorder.onstop = async () => {
        recording.value = false;
        playing.value = false;
        const blob = new Blob(chunks, { type: mime.split(';')[0] });
        const name = `reel-${generation.value.id}`;

        if (mime.includes('avc1') || !props.config.ffmpeg) {
            downloadBlob(blob, `${name}.${mime.startsWith('video/mp4') ? 'mp4' : 'webm'}`);
            return;
        }

        converting.value = true;
        try {
            downloadBlob(await convertToMp4(blob), `${name}.mp4`);
        } catch (e) {
            error.value = `MP4 хөрвүүлэлт амжилтгүй, WebM-ээр татлаа. (${e.message})`;
            downloadBlob(blob, `${name}.webm`);
        } finally {
            converting.value = false;
        }
    };
    recorder.start(250);
    recording.value = true;
    play();
}

async function convertToMp4(blob) {
    const data = new FormData();
    data.append('video', blob, 'reel.webm');
    const res = await fetch('/api/videos/convert', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
        body: data,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.blob();
}

function finishRecording() {
    audio?.pause();
    audioCtx?.close();
    audio = audioCtx = null;
    if (recorder?.state === 'recording') recorder.stop();
}

function pickMusic(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (music.value) URL.revokeObjectURL(music.value.url);
    music.value = { name: file.name, url: URL.createObjectURL(file) };
}

// --- persistence ------------------------------------------------------------

const save = debounce(async () => {
    if (!generation.value?.id) return;
    try {
        await api.put(`/api/generations/${generation.value.id}`, { content: generation.value.content });
    } catch (e) {
        error.value = e.message;
    }
}, 800);

watch(reel, () => {
    loadImages();
    if (!recording.value) save();
}, { deep: true });

watch(canvas, loadImages);
watch(() => props.active, (a) => !a && !recording.value && stop());

onBeforeUnmount(() => {
    stop();
    finishRecording();
});

const fmt = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
const script = computed(() => reel.value?.scenes.map((s, i) => `${i + 1}. ${s.voiceover}`).join('\n') ?? '');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[380px_1fr]">
        <aside class="space-y-4">
            <div class="panel space-y-4">
                <BriefForm v-model="form" :config="config" placeholder="Жишээ: Шинэ фитнес клубын нээлтийг сурталчлах эрч хүчтэй reels, залуучуудад зориулсан" />

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="label">Урт (сек)</label>
                        <input v-model.number="form.duration" type="number" min="5" max="90" class="field" />
                    </div>
                    <div>
                        <label class="label">Үзэгдэл</label>
                        <input v-model.number="form.scenes" type="number" min="2" max="12" class="field" />
                    </div>
                </div>

                <button class="btn btn-primary w-full py-3" :disabled="busyText || !form.prompt.trim()" @click="generate">
                    <span v-if="busyText" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                    {{ busyText ? 'AI сценари бичиж байна…' : '🎬 Reels үүсгэх' }}
                </button>
                <p v-if="error" class="rounded-lg bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            </div>

            <CaptionBox v-if="reel" :caption="reel.caption" :hashtags="reel.hashtags" :extra="reel.music_mood ? `🎵 Хөгжим: ${reel.music_mood}` : ''" />

            <div v-if="script" class="panel space-y-2">
                <div class="text-sm font-semibold">Дуу оруулах текст (voiceover)</div>
                <pre class="whitespace-pre-wrap font-sans text-xs text-zinc-400">{{ script }}</pre>
            </div>
        </aside>

        <section class="min-w-0">
            <div v-if="!reel" class="panel grid min-h-[60vh] place-items-center text-center text-zinc-500">
                <div>
                    <div class="mb-3 text-5xl">🎬</div>
                    <p>Санаагаа бичээд “Reels үүсгэх” дарна уу.</p>
                    <p class="mt-1 text-xs">AI сценари, үзэгдэл бүрийн зураг, хөдөлгөөнт текст бүхий 9:16 видео бэлдэнэ.</p>
                </div>
            </div>

            <div v-else class="grid gap-6 xl:grid-cols-[340px_1fr]">
                <div class="space-y-3">
                    <div class="relative overflow-hidden rounded-2xl border border-zinc-800 bg-black">
                        <canvas ref="canvas" class="aspect-[9/16] w-full cursor-pointer" @click="!recording && toggle()" />
                        <div v-if="recording" class="absolute top-6 left-3 flex items-center gap-1.5 rounded-full bg-red-600 px-2.5 py-1 text-xs font-bold">
                            <span class="size-2 animate-pulse rounded-full bg-white" /> REC
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button class="btn btn-ghost w-12 px-0" :disabled="recording" @click="toggle">{{ playing ? '⏸' : '▶' }}</button>
                        <input type="range" class="flex-1 accent-fuchsia-500" min="0" :max="total" step="0.01" :value="time" :disabled="recording" @input="seek" />
                        <span class="w-20 text-right text-xs tabular-nums text-zinc-400">{{ fmt(time) }} / {{ fmt(total) }}</span>
                    </div>

                    <label class="btn btn-ghost w-full cursor-pointer text-xs">
                        🎵 {{ music ? music.name : 'Хөгжим нэмэх (заавал биш)' }}
                        <input type="file" accept="audio/*" class="hidden" @change="pickMusic" />
                    </label>

                    <button class="btn btn-primary w-full py-3" :disabled="recording || converting || anyImageBusy" @click="exportVideo">
                        <template v-if="recording">Бичиж байна… {{ Math.round((time / total) * 100) }}%</template>
                        <template v-else-if="converting">MP4 болгож байна…</template>
                        <template v-else>⬇ Видео татах {{ config.ffmpeg ? '(MP4)' : '' }}</template>
                    </button>
                    <p class="text-[11px] leading-snug text-zinc-500">
                        Видео бодит хугацаанд бичигдэнэ ({{ fmt(total) }}). Бичиж байх үед энэ табыг нээлттэй байлгаарай.
                    </p>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold">Үзэгдлүүд ({{ reel.scenes.length }})</h3>
                        <div class="flex gap-2">
                            <select v-model="reel.font" class="field w-auto py-1 text-xs">
                                <option value="bold">Bold</option>
                                <option value="modern">Modern</option>
                                <option value="elegant">Elegant</option>
                                <option value="playful">Playful</option>
                            </select>
                            <input v-model="reel.palette.primary" type="color" title="Үндсэн өнгө" class="h-8 w-8 cursor-pointer rounded border border-zinc-700 bg-transparent" />
                            <input v-model="reel.palette.accent" type="color" title="Тод өнгө" class="h-8 w-8 cursor-pointer rounded border border-zinc-700 bg-transparent" />
                            <input v-model="reel.palette.text" type="color" title="Текст өнгө" class="h-8 w-8 cursor-pointer rounded border border-zinc-700 bg-transparent" />
                        </div>
                    </div>

                    <div v-for="(scene, i) in reel.scenes" :key="i" class="panel flex gap-3">
                        <div class="relative w-24 shrink-0">
                            <div class="aspect-[9/16] overflow-hidden rounded-lg bg-zinc-800">
                                <img v-if="scene.image_url" :src="scene.image_url" class="size-full object-cover" />
                                <div v-else class="grid size-full place-items-center text-[10px] text-zinc-500">зураггүй</div>
                            </div>
                            <div v-if="sceneBusy[i]" class="absolute inset-0 grid place-items-center rounded-lg bg-black/60">
                                <span class="size-5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                            </div>
                            <div class="mt-1 text-center text-xs font-bold text-zinc-500">#{{ i + 1 }}</div>
                        </div>

                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex gap-2">
                                <input v-model="scene.text" class="field font-semibold" placeholder="Дэлгэцийн текст" />
                                <input v-model.number="scene.duration" type="number" min="1" max="10" class="field w-16" title="Секунд" />
                            </div>
                            <div class="flex gap-2">
                                <input v-model="scene.subtext" class="field text-xs" placeholder="Жижиг текст" />
                                <select v-model="scene.motion" class="field w-32 text-xs">
                                    <option value="zoom-in">Zoom in</option>
                                    <option value="zoom-out">Zoom out</option>
                                    <option value="pan-left">Pan ←</option>
                                    <option value="pan-right">Pan →</option>
                                </select>
                            </div>
                            <input v-model="scene.voiceover" class="field text-xs" placeholder="Voiceover" />
                            <ImageControls
                                v-model:prompt="scene.image_prompt"
                                v-model:url="scene.image_url"
                                compact
                                :busy="!!sceneBusy[i]"
                                @regenerate="generateSceneImage(i)"
                                @error="error = $event"
                            />
                            <div class="flex justify-end gap-1 text-xs text-zinc-500">
                                <button class="rounded px-2 py-1 hover:bg-zinc-800" @click="moveScene(i, -1)">↑</button>
                                <button class="rounded px-2 py-1 hover:bg-zinc-800" @click="moveScene(i, 1)">↓</button>
                                <button class="rounded px-2 py-1 text-red-400 hover:bg-zinc-800" :disabled="reel.scenes.length < 2" @click="removeScene(i)">Устгах</button>
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-ghost w-full" @click="addScene">+ Үзэгдэл нэмэх</button>
                </div>
            </div>
        </section>
    </div>
</template>
