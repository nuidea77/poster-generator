<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { refreshUser, session } from '../lib/session';
import BottomSheet from '../components/BottomSheet.vue';
import ChipMenu from '../components/ChipMenu.vue';
import Composer from '../components/Composer.vue';
import FormatOptions from '../components/FormatOptions.vue';
import Icon from '../components/Icon.vue';

const router = useRouter();
const meta = session.meta;
const maxImages = meta.uploads.max_product_images;
const maxRefs = meta.uploads.max_reference_images;

const form = reactive({
    type: 'poster',
    formats: ['4:5'],
    reelFormat: meta.reel.formats[0].id,
    duration: meta.reel.durations[0].id,
    prompt: '',
    product: { name: '', price: '', description: '' },
});

const logo = ref(null); // { file, url } | { saved: true, url }
const images = ref([]); // [{ file, url }]
const refs = ref([]); // style examples, [{ file, url }]
const rememberLogo = ref(true);
const busy = ref(false);

// Price in credits, charged when the job starts (refunded if it fails). credits null = unlimited (admin).
const prices = meta.credit_prices;
const cost = computed(() => (form.type === 'reel' ? prices.reel[form.duration] : prices.poster + Math.max(0, form.formats.length - 1) * prices.poster_extra_format));
const balance = computed(() => session.user.credits);
const blocked = computed(() => balance.value !== null && balance.value < cost.value);
const error = ref('');

if (session.user?.logo_url) {
    logo.value = { saved: true, url: session.user.logo_url };
}

// Size menu: poster ratios (several, or Auto) or the reel orientation (one).
const formatOptions = computed(() => (form.type === 'reel' ? meta.reel.formats : meta.poster_formats));
const isPicked = (id) => (form.type === 'reel' ? form.reelFormat === id : form.formats.includes(id));

const formatLabel = computed(() => {
    if (form.type === 'reel') return form.reelFormat;
    const picked = meta.poster_formats.filter((f) => form.formats.includes(f.id));
    return picked.length === 1 ? picked[0].label : `${picked.length} хэмжээ`;
});

const types = [
    { id: 'poster', label: 'Постер', icon: 'image' },
    { id: 'reel', label: 'Reels', icon: 'video' },
];
const typeLabel = computed(() => types.find((t) => t.id === form.type).label);
const durationLabel = computed(() => `${form.duration} сек`);

// Phone layout: attachments and settings live in bottom sheets.
const attachOpen = ref(false);
const settingsOpen = ref(false);
const expanded = ref('format'); // open row in the settings sheet
function openSettings(row) {
    expanded.value = row;
    settingsOpen.value = true;
}

const attachCount = computed(() => (logo.value ? 1 : 0) + images.value.length + (form.type === 'poster' ? refs.value.length : 0));

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

// Several ratios can be picked; Auto stands alone.
function toggleFormat(id) {
    if (form.type === 'reel') {
        form.reelFormat = id;
        return;
    }
    if (id === 'auto' || form.formats.includes('auto')) {
        form.formats = [id];
        return;
    }
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

// `list` is the (template-unwrapped) reactive array of images or refs.
function pickImages(list, max, e) {
    for (const file of e.target.files ?? []) {
        if (list.length >= max) break;
        list.push({ file, url: URL.createObjectURL(file) });
    }
    e.target.value = '';
}

function removeImage(list, i) {
    URL.revokeObjectURL(list[i].url);
    list.splice(i, 1);
}

function removeLogo() {
    if (logo.value?.file) URL.revokeObjectURL(logo.value.url);
    logo.value = null;
}

onBeforeUnmount(() => {
    [...images.value, ...refs.value].forEach((i) => URL.revokeObjectURL(i.url));
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
    (form.type === 'poster' ? form.formats : [form.reelFormat]).forEach((f) => data.append('formats[]', f));
    if (form.type === 'reel') data.append('duration', form.duration);
    Object.entries(form.product).forEach(([k, v]) => v.trim() && data.append(`product[${k}]`, v.trim()));
    if (logo.value?.file) {
        data.append('logo', logo.value.file);
        data.append('remember_logo', rememberLogo.value ? '1' : '0');
    } else if (logo.value?.saved) {
        data.append('use_saved_logo', '1');
    }
    images.value.forEach((i) => data.append('images[]', i.file));
    if (form.type === 'poster') refs.value.forEach((i) => data.append('references[]', i.file));

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
            <h1 v-if="form.type === 'poster'" class="display text-4xl leading-[0.95] md:text-6xl">
                Постер бүтээ<br /><span class="text-lime">Instagram · Facebook</span>
            </h1>
            <h1 v-else class="display text-4xl leading-[0.95] md:text-6xl">
                Reels видео<br /><span class="text-lime">Instagram · Facebook</span>
            </h1>
            <p class="mt-4 max-w-xl text-base text-zinc-400">
                <template v-if="form.type === 'poster'">Юу сурталчлахаа бичээд, лого, бүтээгдэхүүнийхээ зургийг нэмнэ үү. Бэлэн постер хэдэн минутад гарна.</template>
                <template v-else>Босоо 9:16 эсвэл хэвтээ 16:9, 15–60 секундын бэлэн видео. Урт нь сонгосон хугацаагаар гарна. Бүтээхэд 10–20 минут болно. Хуудсаа хааж болно, бэлэн болмогц "Миний бүтээлүүд"-д гарна.</template>
            </p>

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
            <p v-else class="mt-6 rounded-xl bg-surface-2 px-4 py-2 text-sm text-zinc-300">
                Энэ бүтээл <b class="text-fg">{{ cost }}</b> кредит <span class="text-muted">· Админ тул кредит хасагдахгүй</span>
            </p>
        </section>

        <Composer
            v-model="form.prompt"
            :placeholder="form.type === 'poster' ? 'Юу сурталчлах вэ? Үйл явдал, бүтээгдэхүүн, мэдрэмж…' : 'Reels-ээр юуг, хэнд, ямар мэдрэмжээр харуулах вэ?'"
            :busy="busy"
            busy-label="Илгээж байна"
            :label="`Generate · ${cost}`"
            :compact-label="String(cost)"
            @generate="submit"
        >
            <template #top>
                <p v-if="error" class="mb-2 rounded-xl bg-red-950/70 px-3 py-2 text-sm text-red-200">{{ error }}</p>
                <div v-if="logo || images.length || (form.type === 'poster' && refs.length)" class="mb-2 flex flex-wrap items-end gap-2 px-1">
                    <div v-if="logo" class="group relative">
                        <img :src="logo.url" class="size-16 rounded-xl bg-white/5 object-contain p-1 ring-1 ring-lime/50" alt="Лого" />
                        <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 rounded bg-lime px-1 text-[9px] font-bold text-ink">ЛОГО</span>
                        <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeLogo"><Icon name="x" size="10" /></button>
                    </div>
                    <div v-for="(img, i) in images" :key="img.url" class="group relative">
                        <img :src="img.url" class="size-16 rounded-xl object-cover ring-1 ring-white/10" alt="Бүтээгдэхүүн" />
                        <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeImage(images, i)"><Icon name="x" size="10" /></button>
                    </div>
                    <div v-for="(img, i) in form.type === 'poster' ? refs : []" :key="img.url" class="group relative">
                        <img :src="img.url" class="size-16 rounded-xl object-cover ring-1 ring-sky-400/60" alt="Жишээ" />
                        <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 rounded bg-sky-400 px-1 text-[9px] font-bold text-ink">ЖИШЭЭ</span>
                        <button class="absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full bg-white text-black opacity-0 transition group-hover:opacity-100" @click="removeImage(refs, i)"><Icon name="x" size="10" /></button>
                    </div>
                    <label v-if="logo?.file" class="ml-1 flex cursor-pointer items-center gap-1.5 text-[11px] text-muted">
                        <input v-model="rememberLogo" type="checkbox" class="accent-lime" /> Логог хадгалах
                    </label>
                </div>
            </template>

            <!-- Phone: [+] attachments · type/size · settings, like a native app. -->
            <div class="contents sm:hidden">
                <button type="button" class="chip relative w-9 shrink-0 justify-center px-0" aria-label="Хавсралт" @click="attachOpen = true">
                    <Icon name="plus" size="16" />
                    <span v-if="attachCount" class="absolute -top-1 -right-1 grid size-4 place-items-center rounded-full bg-lime text-[10px] font-bold text-ink">{{ attachCount }}</span>
                </button>
                <button type="button" class="chip min-w-0" @click="openSettings('format')">
                    <Icon :name="form.type === 'poster' ? 'image' : 'video'" size="14" />
                    <span class="truncate">{{ typeLabel }} · {{ formatLabel }}<template v-if="form.type === 'reel'"> · {{ durationLabel }}</template></span>
                </button>
                <button type="button" class="chip w-9 shrink-0 justify-center px-0" :class="{ 'chip-active': productFilled }" aria-label="Тохиргоо" @click="openSettings('type')">
                    <Icon name="sliders" size="15" />
                </button>
            </div>

            <div class="hidden sm:contents">
                <!-- What to make: first control in the row, so the choices after it read as its settings. -->
                <div class="inline-flex rounded-xl bg-white/5 p-0.5">
                    <button
                        v-for="t in [['poster', 'Постер', 'image'], ['reel', 'Reels', 'video']]"
                        :key="t[0]"
                        type="button"
                        class="flex h-8 items-center gap-1.5 rounded-[10px] px-2.5 text-sm font-semibold transition"
                        :class="form.type === t[0] ? 'bg-lime text-ink' : 'text-zinc-400 hover:text-fg'"
                        @click="form.type = t[0]"
                    >
                        <Icon :name="t[2]" size="14" /> {{ t[1] }}
                    </button>
                </div>
                <label class="chip cursor-pointer" :class="{ 'chip-active': logo }" title="Лого хавсаргах">
                    <Icon name="plus" size="14" /> Лого
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pickLogo" />
                </label>
                <label class="chip cursor-pointer" :class="{ 'opacity-50': images.length >= maxImages }" title="Бүтээгдэхүүний зураг">
                    <Icon name="image" size="14" /> Бүтээгдэхүүн {{ images.length ? `${images.length}/${maxImages}` : '' }}
                    <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" :disabled="images.length >= maxImages" @change="pickImages(images, maxImages, $event)" />
                </label>
                <label v-if="form.type === 'poster'" class="chip cursor-pointer" :class="{ 'chip-active': refs.length, 'opacity-50': refs.length >= maxRefs }" title="Дуурайх жишээ зураг: ийм харагдах, ийм өнгө аястай болгоно">
                    <Icon name="image" size="14" /> Жишээ {{ refs.length ? `${refs.length}/${maxRefs}` : '' }}
                    <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" :disabled="refs.length >= maxRefs" @change="pickImages(refs, maxRefs, $event)" />
                </label>
                <ChipMenu label="Мэдээлэл" icon="edit" :active="productFilled" width="w-80">
                    <div class="space-y-2 p-1">
                        <div class="label">Бүтээгдэхүүний мэдээлэл</div>
                        <input v-model="form.product.name" class="field py-2 text-sm" placeholder="Нэр" />
                        <input v-model="form.product.price" class="field py-2 text-sm" placeholder="Үнэ (жиш. 25,000₮)" />
                        <textarea v-model="form.product.description" rows="3" class="field resize-none py-2 text-sm" placeholder="Онцлог, давуу тал" />
                    </div>
                </ChipMenu>
                <ChipMenu :label="formatLabel" :icon="form.type === 'poster' ? 'grid' : 'video'" width="w-72">
                    <div class="px-2 pt-1 pb-2 text-xs text-muted">Хэмжээ</div>
                    <FormatOptions :options="formatOptions" :picked="isPicked" @pick="toggleFormat" />
                </ChipMenu>
                <ChipMenu v-if="form.type === 'reel'" :label="durationLabel" icon="clock" width="w-72">
                    <div class="px-2 pt-1 pb-2 text-xs text-muted">Үргэлжлэх хугацаа</div>
                    <button
                        v-for="d in meta.reel.durations"
                        :key="d.id"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-sm transition hover:bg-white/5"
                        :class="form.duration === d.id ? 'bg-white/5 text-fg' : 'text-zinc-300'"
                        @click="form.duration = d.id"
                    >
                        <span class="flex-1">
                            <span class="block font-semibold">{{ d.label }}</span>
                            <span class="block text-[11px] text-muted">{{ d.hint }}</span>
                        </span>
                        <span class="text-xs text-zinc-400">{{ prices.reel[d.id] }} кредит</span>
                        <Icon v-if="form.duration === d.id" name="check" size="14" />
                    </button>
                </ChipMenu>
            </div>
        </Composer>

        <BottomSheet v-model="attachOpen" title="Хавсралт">
            <div class="space-y-1 pb-1">
                <label class="sheet-row cursor-pointer">
                    <img v-if="logo" :src="logo.url" class="size-9 rounded-lg bg-white/5 object-contain p-0.5" alt="" />
                    <span v-else class="sheet-icon"><Icon name="plus" size="15" /></span>
                    <span class="flex-1"><b class="block text-sm">Лого</b><span class="text-[11px] text-muted">{{ logo ? 'Солих бол дарна уу' : 'Брэндийн лого' }}</span></span>
                    <button v-if="logo" type="button" class="sheet-icon" aria-label="Лого хасах" @click.prevent="removeLogo"><Icon name="x" size="13" /></button>
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pickLogo" />
                </label>
                <label class="sheet-row cursor-pointer" :class="{ 'opacity-50': images.length >= maxImages }">
                    <span class="sheet-icon"><Icon name="image" size="15" /></span>
                    <span class="flex-1"><b class="block text-sm">Бүтээгдэхүүний зураг</b><span class="text-[11px] text-muted">Бодит бүтээгдэхүүн хэвээрээ гарна</span></span>
                    <span class="text-sm text-zinc-400">{{ images.length }}/{{ maxImages }}</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" :disabled="images.length >= maxImages" @change="pickImages(images, maxImages, $event)" />
                </label>
                <label v-if="form.type === 'poster'" class="sheet-row cursor-pointer" :class="{ 'opacity-50': refs.length >= maxRefs }">
                    <span class="sheet-icon"><Icon name="layers" size="15" /></span>
                    <span class="flex-1"><b class="block text-sm">Жишээ зураг</b><span class="text-[11px] text-muted">Ийм өнгө аяс, хэв маягтай болгоно</span></span>
                    <span class="text-sm text-zinc-400">{{ refs.length }}/{{ maxRefs }}</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" :disabled="refs.length >= maxRefs" @change="pickImages(refs, maxRefs, $event)" />
                </label>
            </div>
        </BottomSheet>

        <BottomSheet v-model="settingsOpen" title="Тохиргоо">
            <div class="divide-y divide-white/5 pb-1">
                <div>
                    <button type="button" class="sheet-row w-full" @click="expanded = expanded === 'type' ? '' : 'type'">
                        <Icon :name="form.type === 'poster' ? 'image' : 'video'" size="16" class="text-zinc-400" />
                        <span class="flex-1 text-left text-sm font-semibold">Төрөл</span>
                        <span class="text-sm font-semibold">{{ typeLabel }}</span>
                        <span class="sheet-chevron"><Icon name="chevron" size="12" :class="{ 'rotate-180': expanded === 'type' }" /></span>
                    </button>
                    <div v-if="expanded === 'type'" class="grid grid-cols-2 gap-2 px-1 pb-3">
                        <button v-for="t in types" :key="t.id" type="button" class="flex items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold" :class="form.type === t.id ? 'bg-lime text-ink' : 'bg-white/5 text-zinc-300'" @click="form.type = t.id">
                            <Icon :name="t.icon" size="14" /> {{ t.label }}
                        </button>
                    </div>
                </div>
                <div>
                    <button type="button" class="sheet-row w-full" @click="expanded = expanded === 'format' ? '' : 'format'">
                        <Icon name="grid" size="16" class="text-zinc-400" />
                        <span class="flex-1 text-left text-sm font-semibold">Хэмжээ</span>
                        <span class="text-sm font-semibold">{{ formatLabel }}</span>
                        <span class="sheet-chevron"><Icon name="chevron" size="12" :class="{ 'rotate-180': expanded === 'format' }" /></span>
                    </button>
                    <div v-if="expanded === 'format'" class="pb-2"><FormatOptions :options="formatOptions" :picked="isPicked" @pick="toggleFormat" /></div>
                </div>
                <div v-if="form.type === 'reel'">
                    <button type="button" class="sheet-row w-full" @click="expanded = expanded === 'duration' ? '' : 'duration'">
                        <Icon name="clock" size="16" class="text-zinc-400" />
                        <span class="flex-1 text-left text-sm font-semibold">Үргэлжлэх хугацаа</span>
                        <span class="text-sm font-semibold">{{ durationLabel }}</span>
                        <span class="sheet-chevron"><Icon name="chevron" size="12" :class="{ 'rotate-180': expanded === 'duration' }" /></span>
                    </button>
                    <div v-if="expanded === 'duration'" class="pb-2">
                        <button
                            v-for="d in meta.reel.durations"
                            :key="d.id"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-sm transition hover:bg-white/5"
                            :class="form.duration === d.id ? 'bg-white/5 text-fg' : 'text-zinc-300'"
                            @click="form.duration = d.id"
                        >
                            <span class="flex-1">
                                <span class="block font-semibold">{{ d.label }}</span>
                                <span class="block text-[11px] text-muted">{{ d.hint }}</span>
                            </span>
                            <span class="text-xs text-zinc-400">{{ prices.reel[d.id] }} кредит</span>
                            <Icon v-if="form.duration === d.id" name="check" size="14" />
                        </button>
                    </div>
                </div>
                <div>
                    <button type="button" class="sheet-row w-full" @click="expanded = expanded === 'product' ? '' : 'product'">
                        <Icon name="edit" size="16" class="text-zinc-400" />
                        <span class="flex-1 text-left text-sm font-semibold">Бүтээгдэхүүний мэдээлэл</span>
                        <span class="max-w-28 truncate text-sm font-semibold">{{ form.product.name.trim() || (productFilled ? 'Бөглөсөн' : 'Нэмэх') }}</span>
                        <span class="sheet-chevron"><Icon name="chevron" size="12" :class="{ 'rotate-180': expanded === 'product' }" /></span>
                    </button>
                    <div v-if="expanded === 'product'" class="space-y-2 px-1 pb-3">
                        <input v-model="form.product.name" class="field py-2 text-sm" placeholder="Нэр" />
                        <input v-model="form.product.price" class="field py-2 text-sm" placeholder="Үнэ (жиш. 25,000₮)" />
                        <textarea v-model="form.product.description" rows="3" class="field resize-none py-2 text-sm" placeholder="Онцлог, давуу тал" />
                    </div>
                </div>
            </div>
        </BottomSheet>
    </div>
</template>
