<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { refreshUser, session } from '../lib/session';
import ChipMenu from '../components/ChipMenu.vue';
import Composer from '../components/Composer.vue';
import Icon from '../components/Icon.vue';

const router = useRouter();
const meta = session.meta;
const maxImages = meta.uploads.max_product_images;

const form = reactive({
    type: 'poster',
    formats: ['feed_portrait'],
    prompt: '',
    product: { name: '', price: '', description: '' },
});

const logo = ref(null); // { file, url } | { saved: true, url }
const images = ref([]); // [{ file, url }]
const rememberLogo = ref(true);
const busy = ref(false);

// Price in credits, charged when the job starts (refunded if it fails). credits null = unlimited (admin).
const prices = meta.credit_prices;
const cost = computed(() => (form.type === 'reel' ? prices.reel : prices.poster + Math.max(0, form.formats.length - 1) * prices.poster_extra_format));
const balance = computed(() => session.user.credits);
const blocked = computed(() => balance.value !== null && balance.value < cost.value);
const error = ref('');

if (session.user?.logo_url) {
    logo.value = { saved: true, url: session.user.logo_url };
}

const formatLabel = computed(() => {
    if (form.type === 'reel') return 'Reels 9:16';
    const picked = meta.poster_formats.filter((f) => form.formats.includes(f.id));
    return picked.length === 1 ? picked[0].label : `${picked.length} хэмжээ`;
});

const productFilled = computed(() => Object.values(form.product).some((v) => v.trim()));

const examples = {
    poster: [
        'Шинэ кофе шопын нээлт. Дулаан, тухтай уур амьсгал, манай латтег гол дүр болгоорой.',
        'Арьс арчилгааны шинэ серум. Цэвэр, тансаг, ягаан өнгө давамгайлсан.',
        'Хүүхдийн хувцасны дэлгүүрийн зуны хямдрал. Хөгжилтэй, тод өнгөтэй.',
    ],
    reel: [
        'Фитнес клубын сурталчилгаа. Эрч хүчтэй, залуучуудад зориулсан, сүүлд нь логогоор төгсгө.',
        'Манай ресторанны шинэ цэс. Хоол бэлтгэх, уур савсах ойрын кадрууд, бүлээн гэрэлтүүлэг.',
        'Гоо сайхны салоны үйлчилгээ. Тайван, тансаг, үр дүнг харуулсан.',
    ],
};

function toggleFormat(id) {
    const i = form.formats.indexOf(id);
    if (i === -1) form.formats.push(id);
    else if (form.formats.length > 1) form.formats.splice(i, 1);
}

function pickLogo(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (logo.value?.file) URL.revokeObjectURL(logo.value.url);
    logo.value = { file, url: URL.createObjectURL(file) };
}

function pickImages(e) {
    for (const file of e.target.files ?? []) {
        if (images.value.length >= maxImages) break;
        images.value.push({ file, url: URL.createObjectURL(file) });
    }
    e.target.value = '';
}

function removeImage(i) {
    URL.revokeObjectURL(images.value[i].url);
    images.value.splice(i, 1);
}

function removeLogo() {
    if (logo.value?.file) URL.revokeObjectURL(logo.value.url);
    logo.value = null;
}

onBeforeUnmount(() => {
    images.value.forEach((i) => URL.revokeObjectURL(i.url));
    if (logo.value?.file) URL.revokeObjectURL(logo.value.url);
});

async function submit() {
    if (blocked.value) {
        router.push({ name: 'pricing', query: { next: '/create' } });
        return;
    }

    error.value = '';
    busy.value = true;

    const data = new FormData();
    data.append('type', form.type);
    data.append('prompt', form.prompt);
    if (form.type === 'poster') form.formats.forEach((f) => data.append('formats[]', f));
    Object.entries(form.product).forEach(([k, v]) => v.trim() && data.append(`product[${k}]`, v.trim()));
    if (logo.value?.file) {
        data.append('logo', logo.value.file);
        data.append('remember_logo', rememberLogo.value ? '1' : '0');
    } else if (logo.value?.saved) {
        data.append('use_saved_logo', '1');
    }
    images.value.forEach((i) => data.append('images[]', i.file));

    try {
        const { data: creation } = await api.post('/creations', data);
        refreshUser();
        router.push({ name: 'creation', params: { id: creation.id } });
    } catch (e) {
        if (e.code === 'subscription_required') {
            router.push({ name: 'pricing', query: { next: '/create' } });
            return;
        }
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="pb-64">
        <section class="mx-auto flex min-h-[52vh] max-w-3xl flex-col items-center justify-center text-center">
            <div class="mb-6 inline-flex rounded-2xl bg-surface-2 p-1">
                <button class="rounded-xl px-5 py-2 text-sm font-semibold transition" :class="form.type === 'poster' ? 'bg-lime text-ink' : 'text-zinc-400 hover:text-fg'" @click="form.type = 'poster'">
                    Постер
                </button>
                <button class="rounded-xl px-5 py-2 text-sm font-semibold transition" :class="form.type === 'reel' ? 'bg-lime text-ink' : 'text-zinc-400 hover:text-fg'" @click="form.type = 'reel'">
                    Reels
                </button>
            </div>

            <h1 v-if="form.type === 'poster'" class="display text-4xl leading-[0.95] md:text-6xl">
                Постер бүтээ<br /><span class="text-lime">Instagram · Facebook</span>
            </h1>
            <h1 v-else class="display text-4xl leading-[0.95] md:text-6xl">
                Reels видео<br /><span class="text-lime">Instagram · Facebook</span>
            </h1>
            <p class="mt-4 max-w-xl text-base text-zinc-400">
                <template v-if="form.type === 'poster'">Юу сурталчлахаа бичээд, лого, бүтээгдэхүүнийхээ зургийг нэмнэ үү. Бэлэн постер хэдэн минутад гарна.</template>
                <template v-else>9:16 босоо, бэлэн видео. Урт нь санаанаасаа хамаарна, ихэвчлэн 15–40 секунд. Бүтээхэд 10–20 минут болно. Хуудсаа хааж болно, бэлэн болмогц "Миний бүтээлүүд"-д гарна.</template>
            </p>

            <!-- Poster format picker -->
            <div v-if="form.type === 'poster'" class="mt-6 flex flex-wrap justify-center gap-2">
                <button
                    v-for="f in meta.poster_formats"
                    :key="f.id"
                    class="flex items-center gap-2 rounded-xl border px-3 py-2 text-left text-sm transition"
                    :class="form.formats.includes(f.id) ? 'border-lime bg-lime/10 text-fg' : 'border-line bg-surface-2 text-zinc-400 hover:border-line-2'"
                    @click="toggleFormat(f.id)"
                >
                    <span class="grid h-7 place-items-center" :style="{ width: '28px' }">
                        <span class="block rounded-[3px] border-2" :class="form.formats.includes(f.id) ? 'border-lime' : 'border-zinc-500'" :style="{ width: `${Math.round((f.width / Math.max(f.width, f.height)) * 22)}px`, height: `${Math.round((f.height / Math.max(f.width, f.height)) * 22)}px` }" />
                    </span>
                    <span>
                        <span class="block font-semibold">{{ f.label }}</span>
                        <span class="block text-[11px] text-muted">{{ f.platforms }}</span>
                    </span>
                </button>
            </div>

            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <button v-for="(ex, i) in examples[form.type]" :key="i" class="chip chip-sm max-w-xs truncate" :title="ex" @click="form.prompt = ex">{{ ex }}</button>
            </div>

            <p v-if="blocked" class="mt-6 rounded-xl bg-lime/10 px-4 py-2 text-sm text-lime">
                Энэ бүтээлд <b>{{ cost }}</b> кредит хэрэгтэй, танд <b>{{ balance }}</b> байна.
                <RouterLink to="/pricing" class="font-semibold underline">Кредит нэмэх</RouterLink>
            </p>
            <p v-else-if="balance !== null" class="mt-6 rounded-xl bg-surface-2 px-4 py-2 text-sm text-zinc-300">
                Энэ бүтээл <b class="text-fg">{{ cost }}</b> кредит · Үлдэгдэл <b class="text-fg">{{ balance }}</b> кредит
                <span class="text-muted">· Амжилтгүй болбол кредит буцна</span>
            </p>
        </section>

        <Composer
            v-model="form.prompt"
            :placeholder="form.type === 'poster' ? 'Юу сурталчлах вэ? Үйл явдал, бүтээгдэхүүн, мэдрэмж…' : 'Reels-ээр юуг, хэнд, ямар мэдрэмжээр харуулах вэ?'"
            :busy="busy"
            busy-label="Илгээж байна"
            :label="balance === null ? 'Generate' : `Generate · ${cost}`"
            @generate="submit"
        >
            <template #top>
                <p v-if="error" class="mb-2 rounded-xl bg-red-950/70 px-3 py-2 text-sm text-red-200">{{ error }}</p>
                <div v-if="logo || images.length" class="mb-2 flex flex-wrap items-end gap-2 px-1">
                    <div v-if="logo" class="group relative">
                        <img :src="logo.url" class="size-16 rounded-xl bg-white/5 object-contain p-1 ring-1 ring-lime/50" alt="Лого" />
                        <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 rounded bg-lime px-1 text-[9px] font-bold text-ink">ЛОГО</span>
                        <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeLogo"><Icon name="x" size="10" /></button>
                    </div>
                    <div v-for="(img, i) in images" :key="img.url" class="group relative">
                        <img :src="img.url" class="size-16 rounded-xl object-cover ring-1 ring-white/10" alt="Бүтээгдэхүүн" />
                        <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeImage(i)"><Icon name="x" size="10" /></button>
                    </div>
                    <label v-if="logo?.file" class="ml-1 flex cursor-pointer items-center gap-1.5 text-[11px] text-muted">
                        <input v-model="rememberLogo" type="checkbox" class="accent-lime" /> Логог хадгалах
                    </label>
                </div>
            </template>

            <label class="chip cursor-pointer" :class="{ 'chip-active': logo }" title="Лого хавсаргах">
                <Icon name="plus" size="14" /> Лого
                <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pickLogo" />
            </label>
            <label class="chip cursor-pointer" :class="{ 'opacity-50': images.length >= maxImages }" title="Бүтээгдэхүүний зураг">
                <Icon name="image" size="14" /> <span class="hidden sm:inline">Бүтээгдэхүүн</span><span class="sm:hidden">Зураг</span> {{ images.length ? `${images.length}/${maxImages}` : '' }}
                <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" :disabled="images.length >= maxImages" @change="pickImages" />
            </label>
            <ChipMenu label="Мэдээлэл" icon="edit" :active="productFilled" width="w-80">
                <div class="space-y-2 p-1">
                    <div class="label">Бүтээгдэхүүний мэдээлэл</div>
                    <input v-model="form.product.name" class="field py-2 text-sm" placeholder="Нэр" />
                    <input v-model="form.product.price" class="field py-2 text-sm" placeholder="Үнэ (жиш. 25,000₮)" />
                    <textarea v-model="form.product.description" rows="3" class="field resize-none py-2 text-sm" placeholder="Онцлог, давуу тал" />
                </div>
            </ChipMenu>
            <span class="chip hidden cursor-default sm:inline-flex"><Icon :name="form.type === 'poster' ? 'grid' : 'video'" size="14" /> {{ formatLabel }}</span>
        </Composer>
    </div>
</template>
