<script setup>
import { onMounted, ref } from 'vue';
import { api, money } from '../../lib/api';
import AdminNav from '../../components/AdminNav.vue';

// Credit prices CostMeter derives from unit costs, next to what finished jobs really cost.
const data = ref(null);
const error = ref('');

onMounted(async () => {
    try {
        data.value = await api.get('/admin/costs');
    } catch (e) {
        error.value = e.message;
    }
});

const usd = (v) => `$${Number(v).toFixed(2)}`;
const tone = (m, min) => (m === null ? 'text-muted' : m < min ? 'text-red-300' : 'text-lime');
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <h1 class="display mb-2 text-3xl">Өртөг</h1>
        <p v-if="error" class="rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>

        <template v-if="data">
            <p class="mb-4 max-w-3xl text-sm text-muted">
                Кредитийн үнийг CostMeter нэгж үнээс автоматаар тооцоолно: хамгийн үнэтэй зураг, видео модель (Fable + медиа),
                ашиг муу тохиолдолд ≥ ×{{ data.margin.worst }}, ердийн үед ≥ ×{{ data.margin.typical }}. 1 кредит = {{ money(data.credit_mnt) }}, $1 = {{ money(data.usd_mnt) }}.
                Нэгж үнийг <code>config/pricing.php</code> (эсвэл <code>COST_*</code> env)-д өөрчилбөл үнэ дагаад шинэчлэгдэнэ.
            </p>

            <h2 class="mb-2 font-semibold">Үнийн тооцоо</h2>
            <div class="mb-8 overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-xs text-muted uppercase">
                        <tr>
                            <th class="px-3 py-2">Бүтээл</th><th class="px-3 py-2">Ердийн (Fable + медиа)</th><th class="px-3 py-2">Муу (Fable + медиа)</th>
                            <th class="px-3 py-2">Медиа төсөв</th><th class="px-3 py-2">Кредит</th><th class="px-3 py-2">Орлого</th><th class="px-3 py-2">Ашиг ердийн</th><th class="px-3 py-2">Ашиг муу</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in data.price_book" :key="r.label" class="border-t border-line">
                            <td class="px-3 py-2 font-semibold">{{ r.label }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(r.typical.total) }} <span class="text-muted">({{ usd(r.typical.claude) }} + {{ usd(r.typical.media) }})</span></td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(r.worst.total) }} <span class="text-muted">({{ usd(r.worst.claude) }} + {{ usd(r.worst.media) }})</span></td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(r.budget_usd) }}</td>
                            <td class="px-3 py-2 font-semibold tabular-nums">{{ r.credits }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ money(r.revenue_mnt) }}</td>
                            <td class="px-3 py-2 tabular-nums" :class="tone(r.margin_typical, data.margin.typical)">×{{ r.margin_typical }}</td>
                            <td class="px-3 py-2 tabular-nums" :class="tone(r.margin_worst, data.margin.worst)">×{{ r.margin_worst }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2 class="mb-2 font-semibold">Бодит зардал (дууссан бүтээлүүд)</h2>
            <p v-if="!data.actuals.length" class="rounded-2xl border border-line p-4 text-sm text-muted">Одоогоор дууссан бүтээл алга.</p>
            <div v-else class="overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-xs text-muted uppercase">
                        <tr>
                            <th class="px-3 py-2">Бүтээл</th><th class="px-3 py-2">Тоо</th><th class="px-3 py-2">Дундаж</th><th class="px-3 py-2">Fable</th>
                            <th class="px-3 py-2">Медиа</th><th class="px-3 py-2">Хамгийн их</th><th class="px-3 py-2">Бодит ашиг</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in data.actuals" :key="a.label" class="border-t border-line">
                            <td class="px-3 py-2 font-semibold">{{ a.label }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ a.jobs }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(a.avg_usd) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(a.avg_claude_usd) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(a.avg_media_usd) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(a.max_usd) }}</td>
                            <td class="px-3 py-2 tabular-nums" :class="tone(a.margin, data.margin.worst)">{{ a.margin === null ? '—' : `×${a.margin}` }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
