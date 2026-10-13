<script setup>
import { onMounted, ref, watch } from 'vue';
import { api, money } from '../../lib/api';
import AdminNav from '../../components/AdminNav.vue';

// Credit prices CostMeter derives from unit costs, next to what finished jobs really cost.
const data = ref(null);
const error = ref('');
const days = ref(30); // real figures: last N days, 0 = all time
const periods = [[7, '7 хоног'], [30, '30 хоног'], [0, 'Бүгд']];

async function load() {
    try {
        data.value = await api.get(`/admin/costs${days.value ? `?days=${days.value}` : ''}`);
    } catch (e) {
        error.value = e.message;
    }
}

onMounted(load);
watch(days, load);

const usd = (v) => `$${Number(v).toFixed(2)}`;
const tone = (m, min) => (m === null ? 'text-muted' : m < min ? 'text-red-300' : 'text-lime');
const signed = (n) => (n < 0 ? `−${money(-n)}` : money(n));
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

            <div class="mb-3 flex flex-wrap items-center gap-2">
                <h2 class="font-semibold">Орлого ба алдагдал</h2>
                <div class="ml-auto flex gap-1.5">
                    <button v-for="p in periods" :key="p[0]" class="chip chip-sm" :class="{ 'chip-active': days === p[0] }" @click="days = p[0]">{{ p[1] }}</button>
                </div>
            </div>
            <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="panel">
                    <div class="text-xs text-muted">Орлого (төлсөн кредит)</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums">{{ money(data.losses.revenue_mnt) }}</div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">API зардал (бүгд)</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums">{{ money(data.losses.cost_mnt) }}</div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">Цэвэр үр дүн</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums" :class="data.losses.net_mnt < 0 ? 'text-red-300' : 'text-lime'">{{ signed(data.losses.net_mnt) }}</div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">Бүтэлгүйтсэн ажлын алдагдал</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-red-300">{{ money(data.losses.failed.cost_mnt) }}</div>
                    <div class="text-[11px] text-muted">{{ data.losses.failed.jobs }} ажил · Fable {{ usd(data.losses.failed.claude_usd) }} + медиа {{ usd(data.losses.failed.media_usd) }}</div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">Төлбөргүй ашиглалт</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums text-amber-300">{{ money(data.losses.unpaid.cost_mnt) }}</div>
                    <div class="text-[11px] text-muted">{{ data.losses.unpaid.jobs }} ажил <template v-for="w in data.losses.unpaid.by_who" :key="w.label">· {{ w.label }} {{ money(w.cost_mnt) }} </template></div>
                </div>
            </div>
            <p v-if="data.losses.underwater.jobs" class="mb-4 rounded-xl bg-red-950/60 p-3 text-sm text-red-200">
                {{ data.losses.underwater.jobs }} дууссан бүтээл орлогоосоо их зардал гаргасан: нийт {{ money(data.losses.underwater.lost_mnt) }} алдагдал.
            </p>

            <div v-if="data.losses.failed.jobs" class="mb-8 grid gap-4 lg:grid-cols-2">
                <div class="overflow-x-auto rounded-2xl border border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-surface text-xs text-muted uppercase">
                            <tr><th class="px-3 py-2">Бүтэлгүйтсэн</th><th class="px-3 py-2">Тоо</th><th class="px-3 py-2">Алдагдал</th><th class="px-3 py-2">Fable</th><th class="px-3 py-2">Медиа</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in data.losses.failed.by_kind" :key="k.label" class="border-t border-line">
                                <td class="px-3 py-2 font-semibold">{{ k.label }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ k.jobs }}</td>
                                <td class="px-3 py-2 tabular-nums text-red-300">{{ money(k.cost_mnt) }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ usd(k.claude_usd) }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ usd(k.media_usd) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="overflow-x-auto rounded-2xl border border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-surface text-xs text-muted uppercase">
                            <tr><th class="px-3 py-2">Шалтгаан</th><th class="px-3 py-2">Тоо</th><th class="px-3 py-2">Алдагдал</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in data.losses.failed.reasons" :key="r.reason" class="border-t border-line">
                                <td class="px-3 py-2 text-xs">{{ r.reason }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ r.jobs }}</td>
                                <td class="px-3 py-2 tabular-nums text-red-300">{{ money(r.cost_mnt) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
