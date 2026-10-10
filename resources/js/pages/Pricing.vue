<script setup>
import { computed, ref } from 'vue';
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

const prices = session.meta.credit_prices;
const perCredit = (p) => Math.round(p.price / p.credits);

// Paid plans come in monthly and yearly versions with the same name.
const isYearly = (p) => p.period_days >= 360;
const yearly = ref(false);
const hasYearly = computed(() => session.meta.plans.some((p) => p.price && isYearly(p)));
const visible = computed(() => session.meta.plans.filter((p) => !p.price || isYearly(p) === yearly.value));
const monthlyOf = (p) => session.meta.plans.find((m) => m.price && !isYearly(m) && m.name === p.name);
const saving = (p) => (monthlyOf(p) ? monthlyOf(p).price * 12 - p.price : 0);

async function buy(plan) {
    if (!session.user) {
        router.push({ name: 'register', query: { next: plan.price ? '/pricing' : '/create' } });
        return;
    }
    if (!plan.price) {
        router.push('/create');
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
            <h1 class="display text-4xl leading-[0.95] md:text-6xl">Кредитээр<br /><span class="text-lime">бүтээ.</span></h1>
            <p class="mt-4 text-zinc-400">Бүртгүүлмэгц 1 постер үнэгүй. Дараа нь сар эсвэл жилийн багцаар кредит аваарай. QPay-ээр төлнө.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2 text-sm">
                <span class="chip"><Icon name="image" size="14" /> Постер · {{ prices.poster }} кредит <span class="text-muted">(+{{ prices.poster_extra_format }} нэмэлт хэмжээ бүрт)</span></span>
                <span class="chip"><Icon name="video" size="14" /> Reels · {{ prices.reel }} кредит</span>
            </div>
            <p class="mt-3 text-xs text-muted">Кредит бүтээл эхлэхэд хасагдана, амжилтгүй болбол буцна. Ашиглаагүй кредит багцын хугацаа (сар эсвэл жил) дуусахад дуусна.</p>
            <p v-if="session.user?.subscription" class="mt-4 inline-block rounded-xl bg-lime/10 px-4 py-2 text-sm text-lime">
                Таны {{ session.user.subscription.plan }} багц {{ date(session.user.subscription.ends_at) }} хүртэл идэвхтэй. Одоо авбал хугацаа нь үргэлжилж сунгагдана.
            </p>
        </section>

        <p v-if="error" class="mb-4 rounded-xl bg-red-950/70 p-3 text-sm text-red-200">{{ error }}</p>

        <div v-if="hasYearly" class="mb-6 flex justify-center">
            <div class="inline-flex rounded-2xl bg-surface-2 p-1">
                <button class="rounded-xl px-5 py-2 text-sm font-semibold transition" :class="!yearly ? 'bg-lime text-ink' : 'text-zinc-400 hover:text-fg'" @click="yearly = false">Сараар</button>
                <button class="rounded-xl px-5 py-2 text-sm font-semibold transition" :class="yearly ? 'bg-lime text-ink' : 'text-zinc-400 hover:text-fg'" @click="yearly = true">Жилээр <span class="ml-1 text-xs opacity-70">хямд</span></button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div v-for="(p, i) in visible" :key="p.id" class="feature-card flex flex-col" :class="{ 'border-lime/60': i === 1 }">
                <div class="mb-4 flex items-center justify-between">
                    <span class="text-[15px] font-semibold">{{ p.name }}</span>
                    <span v-if="i === 1" class="badge badge-top">Top</span>
                </div>
                <div class="display text-4xl">{{ money(p.price) }}<span v-if="p.price" class="ml-1 text-base text-muted">/{{ isYearly(p) ? 'жил' : 'сар' }}</span></div>
                <div v-if="p.price && isYearly(p)" class="mt-1 text-sm text-muted">
                    Сард {{ money(Math.round(p.price / 12)) }} · <b class="text-fg">{{ p.credits.toLocaleString() }} кредит</b>
                    <span v-if="saving(p) > 0" class="ml-1 text-lime">· {{ money(saving(p)) }} хэмнэнэ</span>
                </div>
                <div v-else-if="p.price" class="mt-1 text-sm text-muted">{{ p.period_days }} хоног · <b class="text-fg">{{ p.credits.toLocaleString() }} кредит</b> · 1 кредит {{ money(perCredit(p)) }}</div>
                <div v-else class="mt-1 text-sm text-muted">Нэг удаа · <b class="text-fg">{{ p.credits }} кредит</b></div>
                <ul class="mt-5 flex-1 space-y-2 text-sm text-zinc-300">
                    <li v-for="f in p.features" :key="f" class="flex items-start gap-2"><Icon name="check" size="16" class="mt-0.5 shrink-0 text-lime" /> {{ f }}</li>
                </ul>
                <button v-if="p.price" class="btn mt-6 w-full py-2.5" :class="i === 1 ? 'btn-lime' : 'btn-white'" :disabled="busy === p.id" @click="buy(p)">
                    <span v-if="busy === p.id" class="spinner" /> Авах
                </button>
                <button v-else class="btn btn-ghost mt-6 w-full py-2.5" :disabled="session.user?.plan && !session.user.plan.free" @click="buy(p)">
                    {{ session.user?.plan?.free ? 'Бүтээж эхлэх' : session.user ? 'Таны багц илүү' : 'Үнэгүй эхлэх' }}
                </button>
            </div>
        </div>

        <PaymentDialog v-if="payment" :payment="payment" @close="payment = null" @paid="paid" />
    </div>
</template>
