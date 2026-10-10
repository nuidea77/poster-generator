<script setup>
import { onMounted, ref } from 'vue';
import { api, money } from '../../lib/api';
import { loadSession, session } from '../../lib/session';
import AdminNav from '../../components/AdminNav.vue';

const plans = ref([]);
const error = ref('');
const saved = ref(null);
const prices = session.meta.credit_prices;
// Worst-case API cost per credit is ≈500₮, so 2× margin needs ≥1,000₮ per credit.
const floor = 1000;

async function load() {
    plans.value = (await api.get('/admin/plans')).data.map((p) => ({ ...p, featuresText: (p.features || []).join('\n') }));
}

onMounted(load);

function add() {
    plans.value.push({ id: null, slug: '', name: '', price: 0, period_days: 30, credits: 0, is_active: true, sort: plans.value.length + 1, featuresText: '' });
}

async function save(p) {
    error.value = '';
    const body = { ...p, features: p.featuresText.split('\n').map((s) => s.trim()).filter(Boolean) };
    try {
        const { data } = p.id ? await api.put(`/admin/plans/${p.id}`, body) : await api.post('/admin/plans', body);
        Object.assign(p, data);
        saved.value = p.id;
        setTimeout(() => (saved.value = null), 1500);
        loadSession(true);
    } catch (e) {
        error.value = e.message;
    }
}
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <div class="mb-4 flex items-center gap-3">
            <h1 class="display text-3xl">Багц, үнэ</h1>
            <button class="btn btn-lime ml-auto" @click="add">+ Багц</button>
        </div>
        <p class="mb-4 text-sm text-muted">Үнэ 0 бол үнэгүй багц: шинэ хэрэглэгч бүр кредитийг нь нэг удаа авна, хугацаагүй. Төлбөртэй багцын кредит тухайн хугацаанд хүчинтэй. Бүтээлийн үнэ: постер {{ prices.poster }} (+{{ prices.poster_extra_format }}/хэмжээ), reels {{ prices.reel }} кредит. 1 кредитийн үнэ ≥ {{ money(floor) }} байвал хамгийн муу тохиолдолд ч ашиг 2 дахин байна (docs/PRICING.md).</p>
        <p v-if="error" class="mb-3 rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>

        <div class="grid gap-4 md:grid-cols-3">
            <div v-for="(p, i) in plans" :key="p.id ?? 'new' + i" class="panel space-y-3">
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">Нэр</label><input v-model="p.name" class="field" /></div>
                    <div><label class="label">Slug</label><input v-model="p.slug" class="field" /></div>
                    <div><label class="label">Үнэ (₮)</label><input v-model.number="p.price" type="number" class="field" /></div>
                    <div><label class="label">Хоног</label><input v-model.number="p.period_days" type="number" class="field" /></div>
                    <div><label class="label">Кредит</label><input v-model.number="p.credits" type="number" min="0" class="field" /></div>
                    <div><label class="label">1 кредит</label><div class="field tabular-nums" :class="p.price && p.credits && p.price / p.credits < floor ? 'text-red-300' : 'text-muted'">{{ p.price && p.credits ? money(Math.round(p.price / p.credits)) : '—' }}</div></div>
                </div>
                <div><label class="label">Онцлог (мөр бүрт нэг)</label><textarea v-model="p.featuresText" rows="4" class="field resize-none text-xs" /></div>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="p.is_active" type="checkbox" class="accent-lime" /> Идэвхтэй</label>
                    <span class="text-xs text-muted">{{ p.price ? money(p.price) : 'Үнэгүй' }}</span>
                    <button class="btn btn-lime btn-sm ml-auto" @click="save(p)">{{ saved === p.id ? 'Хадгаллаа' : 'Хадгалах' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
