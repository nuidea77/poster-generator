<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../lib/api';
import { session } from '../lib/session';
import AuthCard from '../components/AuthCard.vue';

const route = useRoute();
const router = useRouter();
const form = reactive({ email: '', password: '' });
const error = ref('');
const busy = ref(false);

async function submit() {
    error.value = '';
    busy.value = true;
    try {
        session.user = (await api.post('/auth/login', form)).data;
        router.push(route.query.next || '/create');
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthCard title="Нэвтрэх" subtitle="Постер, reels бүтээхээ үргэлжлүүлээрэй.">
        <form class="space-y-4" @submit.prevent="submit">
            <div><label class="label">Имэйл</label><input v-model="form.email" type="email" autocomplete="email" required class="field" /></div>
            <div><label class="label">Нууц үг</label><input v-model="form.password" type="password" autocomplete="current-password" required class="field" /></div>
            <p v-if="error" class="rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>
            <button class="btn btn-lime w-full py-2.5" :disabled="busy"><span v-if="busy" class="spinner" /> Нэвтрэх</button>
        </form>
        <template #footer>Бүртгэлгүй юу? <RouterLink :to="{ name: 'register', query: route.query }" class="text-lime hover:underline">Бүртгүүлэх</RouterLink></template>
    </AuthCard>
</template>
