<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../lib/api';
import { session } from '../lib/session';
import AuthCard from '../components/AuthCard.vue';

const route = useRoute();
const router = useRouter();
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
const errors = ref({});
const error = ref('');
const busy = ref(false);

async function submit() {
    error.value = '';
    errors.value = {};
    busy.value = true;
    try {
        session.user = (await api.post('/auth/register', form)).data;
        router.push(route.query.next || '/pricing');
    } catch (e) {
        errors.value = e.errors || {};
        error.value = Object.keys(errors.value).length ? '' : e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthCard title="Бүртгүүлэх" subtitle="Багцаа сонгоод хязгааргүй бүтээгээрэй.">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="label">Нэр / Байгууллага</label>
                <input v-model="form.name" required class="field" autocomplete="name" />
                <p v-if="errors.name" class="mt-1 text-xs text-red-300">{{ errors.name[0] }}</p>
            </div>
            <div>
                <label class="label">Имэйл</label>
                <input v-model="form.email" type="email" required class="field" autocomplete="email" />
                <p v-if="errors.email" class="mt-1 text-xs text-red-300">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label class="label">Нууц үг</label>
                <input v-model="form.password" type="password" required minlength="8" class="field" autocomplete="new-password" />
                <p v-if="errors.password" class="mt-1 text-xs text-red-300">{{ errors.password[0] }}</p>
            </div>
            <div><label class="label">Нууц үг давтах</label><input v-model="form.password_confirmation" type="password" required class="field" autocomplete="new-password" /></div>
            <p v-if="error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            <button class="btn btn-lime w-full py-2.5" :disabled="busy"><span v-if="busy" class="spinner" /> Бүртгүүлэх</button>
        </form>
        <template #footer>Бүртгэлтэй юу? <RouterLink :to="{ name: 'login', query: route.query }" class="text-lime hover:underline">Нэвтрэх</RouterLink></template>
    </AuthCard>
</template>
