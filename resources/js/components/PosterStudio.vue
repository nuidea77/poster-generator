<script setup>
import { computed, reactive, ref, watch } from 'vue';
import Icon from './Icon.vue';
import { api, debounce, downloadBlob } from '../lib/api';
import { SIZES, drawPoster, ensureFonts, loadImage } from '../lib/render';
import BriefForm from './BriefForm.vue';
import ChipMenu from './ChipMenu.vue';
import Composer from './Composer.vue';
import CaptionBox from './CaptionBox.vue';
import ImageControls from './ImageControls.vue';

const props = defineProps({
    config: { type: Object, required: true },
    initial: { type: Object, default: null },
    active: { type: Boolean, default: true },
});

const heroCards = ['from-orange-500 to-amber-700', 'from-sky-500 to-indigo-700', 'from-pink-500 to-rose-700', 'from-lime-400 to-emerald-700'];

// Items made by the agent carry provider 'agent', which is not a selectable option.
const known = (config, id, kind) => config.providers.find((p) => p.id === id && p[kind] && p.configured)?.id;

const form = ref({
    prompt: props.initial?.prompt ?? '',
    style: props.initial?.options?.style ?? '',
    language: props.initial?.options?.language ?? 'mn',
    format: props.initial?.options?.format ?? '4:5',
    text_provider: known(props.config, props.initial?.text_provider, 'text') ?? props.config.default_text,
    image_provider: known(props.config, props.initial?.image_provider, 'image') ?? props.config.default_image,
});

const generation = ref(props.initial ? JSON.parse(JSON.stringify(props.initial)) : null);
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
    <div>
        <!-- Hero (empty state), higgsfield create page style -->
        <section v-if="!content" class="mx-auto flex min-h-[62vh] max-w-3xl flex-col items-center justify-center text-center">
            <div class="mb-8 flex items-end justify-center gap-2">
                <div v-for="(c, i) in heroCards" :key="i" class="h-32 w-24 rounded-xl border border-white/20 bg-gradient-to-br shadow-xl md:h-40 md:w-28" :class="c" :style="{ transform: `rotate(${(i - 1.5) * 6}deg) translateY(${Math.abs(i - 1.5) * 8}px)` }" />
            </div>
            <h1 class="display text-4xl leading-[0.95] md:text-6xl">
                Постер бүтээ<br />
                <span class="text-lime">AI-тай хамт</span>
            </h1>
            <p class="mt-4 max-w-xl text-base text-zinc-400 md:text-lg">Сэдэв, үйл явдлаа бичнэ үү — текст, өнгө, зохиомж, арын зургийг нэг дор бэлдэнэ</p>
            <p v-if="error" class="mt-4 rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
        </section>

        <!-- Workspace -->
        <section v-else class="mx-auto max-w-[1400px]">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <h1 class="display text-2xl">Постер</h1>
                <span class="badge badge-dark">{{ content.format }}</span>
                <span class="ml-auto"><button class="btn btn-lime" @click="download"><Icon name="download" size="16" /> PNG татах</button></span>
            </div>
            <p v-if="error" class="mb-3 rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>

            <div class="grid gap-4 xl:grid-cols-[300px_1fr_300px]">
                <div class="space-y-4">
                    <div class="panel space-y-3">
                        <h3 class="text-sm font-semibold">Текст</h3>
                        <div><label class="label">Шошго</label><input v-model="content.tagline" class="field" /></div>
                        <div><label class="label">Гарчиг</label><textarea v-model="content.headline" rows="2" class="field resize-none" /></div>
                        <div><label class="label">Дэд гарчиг</label><input v-model="content.subheadline" class="field" /></div>
                        <div><label class="label">Тайлбар</label><textarea v-model="content.body" rows="3" class="field resize-none" /></div>
                        <div><label class="label">Товч (CTA)</label><input v-model="content.cta" class="field" /></div>
                    </div>
                </div>

                <div class="media-card relative grid place-items-center bg-[radial-gradient(circle_at_center,#1c1c1c,#0b0b0b)] p-6">
                    <canvas ref="canvas" class="max-h-[72vh] w-auto max-w-full rounded-xl shadow-[0_30px_80px_rgba(0,0,0,.7)]" />
                    <div v-if="busy.image" class="absolute inset-0 grid place-items-center rounded-2xl bg-black/60 text-sm">
                        <div class="flex items-center gap-2"><span class="spinner text-lime" /> Зураг үүсгэж байна…</div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="panel space-y-4">
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
                                <label v-for="(label, key) in { background: 'Дэвсгэр', primary: 'Үндсэн', accent: 'Тод', text: 'Текст' }" :key="key" class="text-center text-[10px] text-muted">
                                    <input v-model="content.palette[key]" type="color" class="h-9 w-full cursor-pointer rounded-lg border border-line bg-transparent" />
                                    {{ label }}
                                </label>
                            </div>
                        </div>
                    </div>
                    <ImageControls v-model:prompt="content.image_prompt" v-model:url="content.image_url" :busy="busy.image" @regenerate="generateImage" @error="error = $event" />
                    <CaptionBox :caption="content.caption" :hashtags="content.hashtags" />
                </div>
            </div>
        </section>

        <Composer v-if="active" v-model="form.prompt" placeholder="Постерын санаагаа бичнэ үү — үйл явдал, бүтээгдэхүүн, огноо, үнэ…" :busy="busy.text" busy-label="AI бичиж байна" label="Generate" @generate="generate">
            <BriefForm v-model="form" :config="config" />
            <ChipMenu :label="form.format" icon="grid" width="w-56">
                <div class="label px-2 pt-1">Хэмжээ</div>
                <button v-for="f in formats" :key="f.id" class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-white/5" :class="{ 'text-lime': form.format === f.id }" @click="form.format = f.id">
                    {{ f.label }} <Icon v-if="form.format === f.id" name="check" size="14" />
                </button>
            </ChipMenu>
        </Composer>
    </div>
</template>
