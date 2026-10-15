<script setup>
// Миний бүртгэл: багц/кредитийн төлөв, брэндийн багц (нэр, өнгө, фонт, өнгө аяс, тэмдэглэл, лого), гарах.
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, date } from '../lib/api';
import { logout, session } from '../lib/session';
import Icon from '../components/Icon.vue';

const router = useRouter();

// Өнгө аясын бэлэн сонголтууд; эдгээрээс өөр бол "Бусад" + чөлөөт текст.
const tones = ['Албан ёсны', 'Залуулаг', 'Тоглоомтой', 'Тансаг', 'Дулаан, найрсаг', 'Эрч хүчтэй'];
const OTHER = '__other';
const MAX_COLORS = 5;
const HEX = /^#?[0-9A-Fa-f]{6}$/;

const user = session.user;
const brand = reactive({
    name: user.brand_name || '',
    colors: (user.brand_colors || []).map((c) => '#' + c),
    fonts: user.brand_fonts || '',
    tone: user.brand_tone && !tones.includes(user.brand_tone) ? OTHER : user.brand_tone || '',
    toneOther: user.brand_tone && !tones.includes(user.brand_tone) ? user.brand_tone : '',
    notes: user.brand_notes || '',
});

const saving = ref(false);
const logoBusy = ref(false);
const message = ref('');
const error = ref('');

// Буруу hex кодтой өнгө байгаа эсэх (хадгалах товчийг хаана).
const badColor = computed(() => brand.colors.some((c) => !HEX.test(c.trim())));

// Шинэ өнгө нэмнэ (дээд тал нь 5).
function addColor() {
    if (brand.colors.length < MAX_COLORS) brand.colors.push('#1E3A8A');
}

// Color picker-ээс ирсэн утгыг том үсгээр хадгална.
function pickColor(i, e) {
    brand.colors[i] = e.target.value.toUpperCase();
}

// Hex текстийг color picker-т өгөх боломжтой хэлбэрт оруулна (буруу бол хар).
const pickerValue = (c) => (HEX.test(c.trim()) ? '#' + c.trim().replace('#', '').toLowerCase() : '#000000');

// Үйлдлийн үр дүнг харуулна.
function done(ok, text) {
    message.value = ok ? text : '';
    error.value = ok ? '' : text;
}

// Брэндийн багцыг JSON-оор хадгална.
async function save() {
    saving.value = true;
    done(true, '');
    try {
        session.user = (
            await api.put('/me/brand', {
                brand_name: brand.name,
                brand_colors: brand.colors.map((c) => c.trim()),
                brand_fonts: brand.fonts,
                brand_tone: brand.tone === OTHER ? brand.toneOther : brand.tone,
                brand_notes: brand.notes,
            })
        ).data;
        done(true, 'Брэндийн мэдээлэл хадгалагдлаа.');
    } catch (e) {
        done(false, e.message);
    } finally {
        saving.value = false;
    }
}

// Сонгосон лого файлыг шууд хадгална; input-ийг цэвэрлэж ижил файлыг дахин сонгох боломжтой болгоно.
async function pickLogo(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const data = new FormData();
    data.append('logo', file);
    await logoAction(() => api.post('/me/brand/logo', data), 'Лого хадгалагдлаа.');
}

// Хадгалсан логог устгана.
function removeLogo() {
    return logoAction(() => api.delete('/me/brand/logo'), 'Лого устгагдлаа.');
}

// Логоны хүсэлтийг илгээж, хэрэглэгчийн мэдээллийг шинэчилнэ.
async function logoAction(call, ok) {
    logoBusy.value = true;
    done(true, '');
    try {
        session.user = (await call()).data;
        done(true, ok);
    } catch (e) {
        done(false, e.message);
    } finally {
        logoBusy.value = false;
    }
}

// Гараад нүүр хуудас руу шилжинэ.
async function signOut() {
    await logout();
    router.push('/');
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="display text-3xl">Миний бүртгэл</h1>

        <!-- Багц ба кредитийн төлөв -->
        <div class="panel">
            <div class="mb-3 text-sm font-semibold">Багц</div>
            <div v-if="session.user.credits !== null" class="mb-3 text-sm">Кредитийн үлдэгдэл: <b class="text-lime">{{ session.user.credits }}</b></div>
            <div v-if="session.user.subscription" class="flex flex-wrap items-center gap-3">
                <span class="badge badge-new">Идэвхтэй</span>
                <span class="text-sm">{{ session.user.subscription.plan }} · {{ date(session.user.subscription.ends_at) }} хүртэл. Ашиглаагүй кредит энэ хугацаанд дуусна.</span>
                <RouterLink to="/pricing" class="btn btn-ghost btn-sm ml-auto">Сунгах</RouterLink>
            </div>
            <div v-else-if="session.user.plan?.free" class="flex flex-wrap items-center gap-3">
                <span class="badge badge-muted">Үнэгүй</span>
                <span class="text-sm">Эхлэлийн кредит (1 үнэгүй постер)</span>
                <RouterLink to="/pricing" class="btn btn-lime btn-sm ml-auto">Багц сонгох</RouterLink>
            </div>
            <div v-else class="flex flex-wrap items-center gap-3">
                <span class="badge badge-muted">Багцгүй</span>
                <RouterLink to="/pricing" class="btn btn-lime btn-sm ml-auto">Багц сонгох</RouterLink>
            </div>
        </div>

        <!-- Брэнд: лого, нэр, өнгө, фонт, өнгө аяс, тэмдэглэл -->
        <div class="panel space-y-4">
            <div>
                <div class="text-sm font-semibold">Брэнд</div>
                <p class="mt-1 text-xs text-muted">Энд хадгалсан мэдээллийг шинэ бүтээл бүрт ашиглаж, постер брэндийн хэв маягтаа нийцнэ. Лого автоматаар хавсрагдана.</p>
            </div>

            <!-- Лого -->
            <div class="flex items-center gap-4">
                <div class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-2xl bg-white/5 ring-1 ring-white/10">
                    <img v-if="session.user.brand_logo_url" :src="session.user.brand_logo_url" class="size-full object-contain p-2" alt="Лого" />
                    <Icon v-else name="image" size="22" class="text-muted" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <label class="btn btn-ghost btn-sm cursor-pointer" :class="{ 'pointer-events-none opacity-60': logoBusy }">
                        <Icon name="upload" size="12" /> {{ session.user.brand_logo_url ? 'Лого солих' : 'Лого оруулах' }}
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pickLogo" />
                    </label>
                    <button v-if="session.user.brand_logo_url" class="btn btn-ghost btn-sm text-red-300" :disabled="logoBusy" @click="removeLogo">Устгах</button>
                </div>
            </div>

            <div>
                <label class="label">Брэндийн нэр</label>
                <input v-model="brand.name" class="field" maxlength="100" placeholder="Жишээ: Nomad Coffee" />
            </div>

            <!-- Өнгө: color picker + hex текст -->
            <div>
                <label class="label">Брэндийн өнгө (дээд тал нь {{ MAX_COLORS }})</label>
                <div class="space-y-2">
                    <div v-for="(c, i) in brand.colors" :key="i" class="flex items-center gap-2">
                        <input type="color" :value="pickerValue(c)" class="size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-surface-2 p-1" :aria-label="`Өнгө ${i + 1}`" @input="pickColor(i, $event)" />
                        <input v-model="brand.colors[i]" class="field max-w-40 font-mono uppercase" maxlength="7" placeholder="#1E3A8A" :class="{ 'border-red-400/60': !HEX.test(c.trim()) }" />
                        <button class="btn btn-ghost btn-sm" :aria-label="`Өнгө ${i + 1}-ийг хасах`" @click="brand.colors.splice(i, 1)"><Icon name="x" size="12" /></button>
                    </div>
                    <button v-if="brand.colors.length < MAX_COLORS" class="btn btn-ghost btn-sm" @click="addColor"><Icon name="plus" size="12" /> Өнгө нэмэх</button>
                </div>
                <p v-if="badColor" class="mt-1 text-xs text-red-300">Өнгийг #1E3A8A хэлбэрийн 6 оронтой hex кодоор оруулна уу.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Фонт</label>
                    <input v-model="brand.fonts" class="field" maxlength="100" placeholder="Жишээ: тод, орчин үеийн sans / Montserrat" />
                </div>
                <div>
                    <label class="label">Өнгө аяс</label>
                    <select v-model="brand.tone" class="field">
                        <option value="">Сонгоогүй</option>
                        <option v-for="t in tones" :key="t" :value="t">{{ t }}</option>
                        <option :value="OTHER">Бусад…</option>
                    </select>
                    <input v-if="brand.tone === OTHER" v-model="brand.toneOther" class="field mt-2" maxlength="100" placeholder="Өөрийн өнгө аясыг бичнэ үү" />
                </div>
            </div>

            <div>
                <label class="label">Тэмдэглэл</label>
                <textarea v-model="brand.notes" rows="4" maxlength="1000" class="field resize-none" placeholder="Слоган, заавал байх зүйл, хэрэглэж болохгүй зүйл гэх мэт" />
                <div class="mt-1 text-right text-[11px] text-muted">{{ brand.notes.length }}/1000</div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button class="btn btn-lime" :disabled="saving || badColor" @click="save">{{ saving ? 'Хадгалж байна…' : 'Хадгалах' }}</button>
                <p v-if="message" class="text-xs text-lime">{{ message }}</p>
                <p v-if="error" class="text-xs text-red-300">{{ error }}</p>
            </div>
        </div>

        <!-- Хэрэглэгчийн мэдээлэл ба гарах товч -->
        <div class="panel flex items-center gap-3">
            <div class="text-sm"><div class="font-semibold">{{ session.user.name }}</div><div class="text-muted">{{ session.user.email }}</div></div>
            <button class="btn btn-ghost btn-sm ml-auto text-red-300" @click="signOut">Гарах</button>
        </div>
    </div>
</template>
