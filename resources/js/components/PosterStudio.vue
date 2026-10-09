<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, debounce, downloadBlob } from '../lib/api';
import { SIZES, drawPoster, ensureFonts, loadImage } from '../lib/render';
import BriefForm from './BriefForm.vue';
import CaptionBox from './CaptionBox.vue';
import ImageControls from './ImageControls.vue';

const props = defineProps({
    config: { type: Object, required: true },
    initial: { type: Object, default: null },
});

const form = ref({
    prompt: props.initial?.prompt ?? '',
    style: props.initial?.options?.style ?? '',
    language: props.initial?.options?.language ?? 'mn',
    format: props.initial?.options?.format ?? '4:5',
    text_provider: props.initial?.text_provider ?? props.config.default_text,
    image_provider: props.initial?.image_provider ?? props.config.default_image,
});

const generation = ref(props.initial ? structuredClone(props.initial) : null);
const content = computed(() => generation.value?.content);
const busy = reactive({ text: false, image: false });
const error = ref('');
const canvas = ref(null);

const formats = [
    { id: '4:5', label: '4:5 Instagram' },
    { id: '1:1', label: '1:1 Квадрат' },
    { id: '9:16', label: '9:16 Story' },
    { id: '16:9', label: '16:9 Facebook/YouTube' },
];

async function generate() {
    error.value = '';
    busy.text = true;
    try {
        const g = await api.post('/api/generate/poster', form.value);
        g.content.format = form.value.format;
        generation.value = g;
    } catch (e) {
        error.value = e.message;
        return;
    } finally {
        busy.text = false;
    }
    await generateImage();
}

async function generateImage() {
    const c = content.value;
    if (!c?.image_prompt) return;
    error.value = '';
    busy.image = true;
    try {
        const { url } = await api.post('/api/images', {
            prompt: c.image_prompt,
            aspect: c.format,
            provider: form.value.image_provider,
        });
        c.image_url = url;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.image = false;
    }
}

let renderToken = 0;
async function render() {
    const c = content.value;
    if (!c || !canvas.value) return;
    const token = ++renderToken;
    await ensureFonts();
    const img = await loadImage(c.image_url);
    if (token !== renderToken || !canvas.value) return;
    const [w, h] = SIZES[c.format] ?? SIZES['4:5'];
    canvas.value.width = w;
    canvas.value.height = h;
    drawPoster(canvas.value.getContext('2d'), c, img);
}

const save = debounce(async () => {
    if (!generation.value?.id) return;
    try {
        await api.put(`/api/generations/${generation.value.id}`, { content: generation.value.content });
    } catch (e) {
        error.value = e.message;
    }
}, 800);

watch(content, () => {
    render();
    save();
}, { deep: true });

watch(canvas, render);

function download() {
    canvas.value.toBlob((blob) => downloadBlob(blob, `poster-${generation.value.id}.png`), 'image/png');
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[380px_1fr]">
        <aside class="space-y-4">
            <div class="panel space-y-4">
                <BriefForm v-model="form" :config="config" placeholder="Жишээ: 10-р сарын 20-нд Улаанбаатарт болох кофе шопын нээлт, бүх ундаа 30% хямдрал" />

                <div>
                    <label class="label">Хэмжээ</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <button
                            v-for="f in formats"
                            :key="f.id"
                            class="rounded-lg border px-2 py-1.5 text-xs"
                            :class="form.format === f.id ? 'border-fuchsia-500 bg-fuchsia-500/15' : 'border-zinc-700 text-zinc-400'"
                            @click="form.format = f.id"
                        >
                            {{ f.label }}
                        </button>
                    </div>
                </div>

                <button class="btn btn-primary w-full py-3" :disabled="busy.text || !form.prompt.trim()" @click="generate">
                    <span v-if="busy.text" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                    {{ busy.text ? 'AI бичиж байна…' : '✨ Постер үүсгэх' }}
                </button>
                <p v-if="error" class="rounded-lg bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            </div>

            <div v-if="content" class="panel space-y-3">
                <h3 class="text-sm font-semibold">Текст засах</h3>
                <div>
                    <label class="label">Жижиг шошго</label>
                    <input v-model="content.tagline" class="field" />
                </div>
                <div>
                    <label class="label">Гарчиг</label>
                    <textarea v-model="content.headline" rows="2" class="field resize-none" />
                </div>
                <div>
                    <label class="label">Дэд гарчиг</label>
                    <input v-model="content.subheadline" class="field" />
                </div>
                <div>
                    <label class="label">Тайлбар</label>
                    <textarea v-model="content.body" rows="3" class="field resize-none" />
                </div>
                <div>
                    <label class="label">Товч (CTA)</label>
                    <input v-model="content.cta" class="field" />
                </div>
            </div>
        </aside>

        <section class="min-w-0 space-y-4">
            <div v-if="!content" class="panel grid min-h-[60vh] place-items-center text-center text-zinc-500">
                <div>
                    <div class="mb-3 text-5xl">🎨</div>
                    <p>Зүүн талд санаагаа бичээд “Постер үүсгэх” дарна уу.</p>
                    <p class="mt-1 text-xs">AI текст, өнгө, зохиомж, арын зургийг автоматаар бэлдэнэ.</p>
                </div>
            </div>

            <template v-else>
                <div class="grid gap-4 xl:grid-cols-[1fr_300px]">
                    <div class="panel relative grid place-items-center bg-[repeating-conic-gradient(#27272a_0_25%,#18181b_0_50%)] bg-[length:24px_24px]">
                        <canvas ref="canvas" class="max-h-[75vh] w-auto max-w-full rounded-lg shadow-2xl" />
                        <div v-if="busy.image" class="absolute inset-0 grid place-items-center rounded-2xl bg-black/50 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                                Зураг үүсгэж байна…
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="panel space-y-3">
                            <button class="btn btn-primary w-full" @click="download">⬇ PNG татах</button>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="label">Зохиомж</label>
                                    <select v-model="content.layout" class="field">
                                        <option value="bottom">Доор</option>
                                        <option value="center">Голд</option>
                                        <option value="top">Дээр</option>
                                        <option value="split">Хуваасан</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="label">Фонт</label>
                                    <select v-model="content.font" class="field">
                                        <option value="modern">Modern</option>
                                        <option value="bold">Bold</option>
                                        <option value="elegant">Elegant</option>
                                        <option value="playful">Playful</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="label">Хэмжээ</label>
                                <select v-model="content.format" class="field">
                                    <option v-for="f in formats" :key="f.id" :value="f.id">{{ f.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Өнгө</label>
                                <div class="grid grid-cols-4 gap-2">
                                    <label v-for="(label, key) in { background: 'Дэвсгэр', primary: 'Үндсэн', accent: 'Тод', text: 'Текст' }" :key="key" class="text-center text-[10px] text-zinc-500">
                                        <input v-model="content.palette[key]" type="color" class="h-9 w-full cursor-pointer rounded border border-zinc-700 bg-transparent" />
                                        {{ label }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <ImageControls
                            v-model:prompt="content.image_prompt"
                            v-model:url="content.image_url"
                            :busy="busy.image"
                            @regenerate="generateImage"
                            @error="error = $event"
                        />

                        <CaptionBox :caption="content.caption" :hashtags="content.hashtags" />
                    </div>
                </div>
            </template>
        </section>
    </div>
</template>
