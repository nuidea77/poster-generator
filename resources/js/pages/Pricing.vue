<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, date, money } from '../lib/api';
import { refreshUser, session } from '../lib/session';
import Icon from '../components/Icon.vue';
import PaymentDialog from '../components/PaymentDialog.vue';

const route = useRoute();
const router = useRouter();
const payment = ref(null);
const busy = ref(null);
const error = ref('');

const perMonth = (p) => Math.round((p.price / p.period_days) * 30);

async function buy(plan) {
    if (!session.user) {
        router.push({ name: 'register', query: { next: '/pricing' } });
        return;
    }
    error.value = '';
    busy.value = plan.id;
    try {
        payment.value = (await api.post('/payments', { plan_id: plan.id })).data;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = null;
    }
}

async function paid() {
    await refreshUser();
    setTimeout(() => {
        payment.value = null;
        router.push(route.query.next || '/create');
    }, 1200);
}
</script>

<template>
    <div class="mx-auto max-w-5xl">
        <section class="mx-auto max-w-2xl py-10 text-center">
            <h1 class="display text-4xl leading-[0.95] md:text-6xl">Хязгааргүй<br /><span class="text-lime">бүтээ.</span></h1>
            <p class="mt-4 text-zinc-400">Нэг багц — постер ба reels хязгааргүй. QPay-ээр төлнө.</p>
            <p v-if="session.user?.subscription" class="mt-4 inline-block rounded-xl bg-lime/10 px-4 py-2 text-sm text-lime">
                Таны {{ session.user.subscription.plan }} багц {{ date(session.user.subscription.ends_at) }} хүртэл идэвхтэй. Одоо авбал хугацаа нь үргэлжилж сунгагдана.
            </p>
        </section>

        <p v-if="error" class="mb-4 rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>

        <div class="grid gap-4 md:grid-cols-3">
            <div v-for="(p, i) in session.meta.plans" :key="p.id" class="feature-card flex flex-col" :class="{ 'border-lime/60': i === 1 }">
                <div class="mb-4 flex items-center justify-between">
                    <span class="text-[15px] font-semibold">{{ p.name }}</span>
                    <span v-if="i === 1" class="badge badge-top">Top</span>
                </div>
                <div class="display text-4xl">{{ money(p.price) }}</div>
                <div class="mt-1 text-sm text-muted">{{ p.period_days }} хоног<template v-if="p.period_days > 31"> · сард {{ money(perMonth(p)) }}</template></div>
                <ul class="mt-5 flex-1 space-y-2 text-sm text-zinc-300">
                    <li v-for="f in p.features" :key="f" class="flex items-start gap-2"><Icon name="check" size="16" class="mt-0.5 shrink-0 text-lime" /> {{ f }}</li>
                </ul>
                <button class="btn mt-6 w-full py-2.5" :class="i === 1 ? 'btn-lime' : 'btn-white'" :disabled="busy === p.id" @click="buy(p)">
                    <span v-if="busy === p.id" class="spinner" /> Авах
                </button>
            </div>
        </div>

        <PaymentDialog v-if="payment" :payment="payment" @close="payment = null" @paid="paid" />
    </div>
</template>
