<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, date } from '../lib/api';
import { logout, session } from '../lib/session';
import Icon from '../components/Icon.vue';

const router = useRouter();
const brandName = ref(session.user.brand_name || '');
const saving = ref(false);
const message = ref('');
const error = ref('');

async function save(extra = {}) {
    saving.value = true;
    message.value = error.value = '';
    const data = new FormData();
    data.append('brand_name', brandName.value);
    Object.entries(extra).forEach(([k, v]) => data.append(k, v));
    try {
        session.user = (await api.post('/me/brand', data)).data;
        message.value = 'Хадгаллаа.';
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

function pickLogo(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (file) save({ logo: file });
}

async function signOut() {
    await logout();
    router.push('/');
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <h1 class="display text-3xl">Миний бүртгэл</h1>

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
                <span class="text-sm">Эхлэлийн кредит (1 постер + 1 reels)</span>
                <RouterLink to="/pricing" class="btn btn-lime btn-sm ml-auto">Багц сонгох</RouterLink>
            </div>
            <div v-else class="flex flex-wrap items-center gap-3">
                <span class="badge badge-muted">Багцгүй</span>
                <RouterLink to="/pricing" class="btn btn-lime btn-sm ml-auto">Багц сонгох</RouterLink>
            </div>
        </div>

        <div class="panel space-y-4">
            <div class="text-sm font-semibold">Брэнд</div>
            <p class="text-xs text-muted">Хадгалсан лого шинэ бүтээл бүрт автоматаар хавсрагдана.</p>
            <div class="flex items-center gap-4">
                <div class="grid size-20 place-items-center overflow-hidden rounded-2xl bg-white/5 ring-1 ring-white/10">
                    <img v-if="session.user.logo_url" :src="session.user.logo_url" class="size-full object-contain p-2" alt="Лого" />
                    <Icon v-else name="image" size="22" class="text-muted" />
                </div>
                <div class="flex gap-2">
                    <label class="btn btn-ghost btn-sm cursor-pointer"><Icon name="upload" size="12" /> Лого солих<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pickLogo" /></label>
                    <button v-if="session.user.logo_url" class="btn btn-ghost btn-sm text-red-300" @click="save({ remove_logo: '1' })">Устгах</button>
                </div>
            </div>
            <div>
                <label class="label">Брэндийн нэр</label>
                <div class="flex gap-2">
                    <input v-model="brandName" class="field" placeholder="Жишээ: Nomad Coffee" />
                    <button class="btn btn-lime" :disabled="saving" @click="save()">Хадгалах</button>
                </div>
            </div>
            <p v-if="message" class="text-xs text-lime">{{ message }}</p>
            <p v-if="error" class="text-xs text-red-300">{{ error }}</p>
        </div>

        <div class="panel flex items-center gap-3">
            <div class="text-sm"><div class="font-semibold">{{ session.user.name }}</div><div class="text-muted">{{ session.user.email }}</div></div>
            <button class="btn btn-ghost btn-sm ml-auto text-red-300" @click="signOut">Гарах</button>
        </div>
    </div>
</template>
