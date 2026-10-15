<script setup>
import { onMounted, ref, watch } from 'vue';
import { api, money } from '../../lib/api';
import AdminNav from '../../components/AdminNav.vue';

// Админ "Өртөг" хуудас: CostMeter-ийн нэгж үнээс тооцсон кредитийн үнийг дууссан ажлуудын бодит өртөгтэй зэрэгцүүлж харуулна.
const data = ref(null);
const error = ref('');
const days = ref(30); // бодит тоо: сүүлийн N хоног, 0 = бүх хугацаа
// Хугацааны шүүлтүүрийн сонголтууд: [хоног, нэр].
const periods = [[7, '7 хоног'], [30, '30 хоног'], [0, 'Бүгд']];

// Өртгийн тайланг (сонгосон хугацаагаар) ачаална.
async function load() {
    try {
        data.value = await api.get(`/admin/costs${days.value ? `?days=${days.value}` : ''}`);
    } catch (e) {
        error.value = e.message;
    }
}

// Нээгдэхэд болон хугацаа солигдоход дахин ачаална.
onMounted(load);
watch(days, load);

// Долларыг 2 оронтой форматлана.
const usd = (v) => `$${Number(v).toFixed(2)}`;
// Ашгийн харьцааны өнгө: байхгүй бол бүдэг, доод хэмжээнээс бага бол улаан, үгүй бол ногоон.
const tone = (m, min) => (m === null ? 'text-muted' : m < min ? 'text-red-300' : 'text-lime');
// Том тоог мянгатаар товчилно (12k).
const k = (n) => (n >= 1000 ? `${Math.round(n / 1000)}k` : String(n ?? 0));
// Сөрөг дүнг хасах тэмдэгтэй мөнгөөр харуулна.
const signed = (n) => (n < 0 ? `−${money(-n)}` : money(n));
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <AdminNav />
        <h1 class="display mb-2 text-3xl">Өртөг</h1>
        <p v-if="error" class="rounded-xl bg-red-950 p-3 text-sm text-red-200">{{ error }}</p>

        <template v-if="data">
            <!-- Нийт зураглал: юу хийсэн, хэд зарцуулсан, хэд орж ирсэн. -->
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <h2 class="font-semibold">Нийт тойм</h2>
                <div class="ml-auto flex gap-1.5">
                    <button v-for="p in periods" :key="p[0]" class="chip chip-sm" :class="{ 'chip-active': days === p[0] }" @click="days = p[0]">{{ p[1] }}</button>
                </div>
            </div>
            <!-- Тоймын картууд: зардал, орж ирсэн мөнгө, ашиг/алдагдал, хийсэн бүтээл -->
            <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="panel">
                    <div class="text-xs text-muted">Нийт зардал (API)</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">{{ money(data.summary.spent_mnt) }}</div>
                    <div class="text-[11px] text-muted">{{ usd(data.summary.spent_usd) }}</div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">Орж ирсэн мөнгө (QPay)</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">{{ money(data.summary.cash_mnt) }}</div>
                    <div class="text-[11px] text-muted">{{ data.summary.payments }} төлбөр<template v-if="data.summary.test_payments.count"> · тест төлбөр {{ data.summary.test_payments.count }} ({{ money(data.summary.test_payments.mnt) }}) тооцоонд ороогүй</template></div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">{{ data.summary.profit_mnt < 0 ? 'Алдагдал' : 'Ашиг' }}</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums" :class="data.summary.profit_mnt < 0 ? 'text-red-300' : 'text-lime'">{{ signed(data.summary.profit_mnt) }}</div>
                    <div class="text-[11px] text-muted">орж ирсэн мөнгө − API зардал</div>
                </div>
                <div class="panel">
                    <div class="text-xs text-muted">Хийсэн бүтээл</div>
                    <div class="mt-1 text-sm">
                        <div><span class="text-xl font-semibold tabular-nums">{{ data.summary.posters.done }}</span> постер <span class="text-xs text-muted">· дундаж {{ money(data.summary.posters.avg_mnt) }}</span></div>
                        <div v-if="data.summary.reels"><span class="text-xl font-semibold tabular-nums">{{ data.summary.reels.done }}</span> reel <span class="text-xs text-muted">· дундаж {{ money(data.summary.reels.avg_mnt) }}</span></div>
                    </div>
                    <div class="text-[11px] text-muted">бүтэлгүйтсэн: {{ data.summary.posters.failed }} постер<template v-if="data.summary.reels">, {{ data.summary.reels.failed }} reel</template></div>
                </div>
            </div>
            <!-- Бүх бүтээлийг үнийн жагсаалтаар төлсөн гэж үзсэн тооцоо -->
            <div class="mb-3 rounded-2xl border border-line p-4">
                <div class="mb-2 text-sm font-semibold">Хэрэв бүгдийг (админы тест орно) хэрэглэгч үнийн жагсаалтаар төлсөн бол</div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div><div class="text-xs text-muted">Орлого (дууссан бүтээл)</div><div class="text-xl font-semibold tabular-nums">{{ money(data.summary.as_customer.revenue_mnt) }}</div></div>
                    <div><div class="text-xs text-muted">Бодит өртөг</div><div class="text-xl font-semibold tabular-nums">{{ money(data.summary.as_customer.cost_mnt) }}</div>
                        <div class="text-[11px] text-muted">үүнээс бүтэлгүйтсэн ажил {{ money(data.summary.as_customer.failed_cost_mnt) }}</div></div>
                    <div><div class="text-xs text-muted">{{ data.summary.as_customer.profit_mnt < 0 ? 'Алдагдал' : 'Ашиг' }}</div>
                        <div class="text-xl font-semibold tabular-nums" :class="data.summary.as_customer.profit_mnt < 0 ? 'text-red-300' : 'text-lime'">{{ signed(data.summary.as_customer.profit_mnt) }}</div></div>
                    <div><div class="text-xs text-muted">Үнэ ÷ өртөг</div>
                        <div class="text-xl font-semibold tabular-nums" :class="tone(data.summary.as_customer.margin, data.margin.typical)">{{ data.summary.as_customer.margin === null ? '—' : `×${data.summary.as_customer.margin}` }}</div>
                        <div class="text-[11px] text-muted">{{ data.summary.as_customer.losing_jobs }} бүтээл үнээсээ их өртөгтэй</div></div>
                </div>
            </div>
            <!-- Зардлын задаргаа: юунд хэд зарцуулсан, постерын нийт -->
            <div class="mb-8 overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-xs text-muted uppercase">
                        <tr><th class="px-3 py-2">Юунд зарцуулсан</th><th class="px-3 py-2">Тоо</th><th class="px-3 py-2">₮</th><th class="px-3 py-2">$</th><th class="px-3 py-2">Хувь</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in data.summary.parts" :key="p.label" class="border-t border-line">
                            <td class="px-3 py-2 font-semibold">{{ p.label }}</td>
                            <td class="px-3 py-2 text-xs tabular-nums text-muted">
                                <template v-if="p.count !== null">{{ p.count }}</template>
                                <template v-else>гаралт {{ k(data.summary.tokens.output) }} · cache унших {{ k(data.summary.tokens.cache_read) }} · бичих {{ k(data.summary.tokens.cache_write) }}</template>
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ money(p.mnt) }}</td>
                            <td class="px-3 py-2 tabular-nums text-muted">{{ usd(p.usd) }}</td>
                            <td class="px-3 py-2 tabular-nums text-muted">{{ data.summary.spent_mnt ? Math.round((p.mnt / data.summary.spent_mnt) * 100) : 0 }}%</td>
                        </tr>
                        <tr class="border-t border-line bg-surface/50">
                            <td class="px-3 py-2 font-semibold">Постер</td><td class="px-3 py-2 tabular-nums">{{ data.summary.posters.done + data.summary.posters.failed }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ money(data.summary.posters.cost_mnt) }}</td><td></td><td></td>
                        </tr>
                        <tr v-if="data.summary.reels" class="border-t border-line bg-surface/50">
                            <td class="px-3 py-2 font-semibold">Reel</td><td class="px-3 py-2 tabular-nums">{{ data.summary.reels.done + data.summary.reels.failed }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ money(data.summary.reels.cost_mnt) }}</td><td></td><td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mb-4 max-w-3xl text-sm text-muted">
                Кредитийн үнийг CostMeter нэгж үнээс автоматаар тооцоолно: хамгийн үнэтэй модель (Claude + зураг),
                ашиг муу тохиолдолд ≥ ×{{ data.margin.worst }}, ердийн үед ≥ ×{{ data.margin.typical }}. 1 кредит = {{ money(data.credit_mnt) }}, $1 = {{ money(data.usd_mnt) }}.
                Нэгж үнийг <code>config/pricing.php</code> (эсвэл <code>COST_*</code> env)-д өөрчилбөл үнэ дагаад шинэчлэгдэнэ.
            </p>

            <!-- Үнийн тооцоо: бүтээл тус бүрийн ердийн/муу өртөг, медиа төсөв, кредит, ашиг -->
            <h2 class="mb-2 font-semibold">Үнийн тооцоо</h2>
            <div class="mb-8 overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-xs text-muted uppercase">
                        <tr>
                            <th class="px-3 py-2">Бүтээл</th><th class="px-3 py-2">Ердийн (Claude + зураг)</th><th class="px-3 py-2">Муу (Claude + зураг)</th>
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

            <!-- Орлого ба алдагдал: бүтэлгүйтсэн ба төлбөргүй ажлын зардал -->
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <h2 class="font-semibold">Орлого ба алдагдал</h2>
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

            <!-- Бүтэлгүйтсэн ажлууд: төрлөөр ба шалтгаанаар -->
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

            <!-- Бодит зардал: бүтээлийн төрөл тус бүрийн дундаж, хамгийн их, таамагтай харьцуулалт -->
            <h2 class="mb-1 font-semibold">Бодит зардал (дууссан бүтээлүүд)</h2>
            <p class="mb-2 max-w-3xl text-xs text-muted">Админы тест, үнэгүй кредитийн ажлыг оролцуулна. Ашиг = тухайн ажлын жагсаалтын үнэ ÷ бодит API зардал. «Таамаг» нь үнийн тооцооны ердийн ажлын зардал — бодит дундаж үүнээс их бол үнэ хангалтгүй.</p>
            <p v-if="!data.actuals.length" class="rounded-2xl border border-line p-4 text-sm text-muted">Одоогоор дууссан бүтээл алга.</p>
            <div v-else class="overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-xs text-muted uppercase">
                        <tr>
                            <th class="px-3 py-2">Бүтээл</th><th class="px-3 py-2">Тоо</th><th class="px-3 py-2">Дундаж</th><th class="px-3 py-2">Fable</th>
                            <th class="px-3 py-2">Медиа</th><th class="px-3 py-2">Хамгийн их</th><th class="px-3 py-2">Таамаг</th>
                            <th class="px-3 py-2">Дундаж token (гаралт · cache унших · бичих)</th><th class="px-3 py-2">Үнэ</th><th class="px-3 py-2">Бодит ашиг</th>
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
                            <td class="px-3 py-2 tabular-nums" :class="a.avg_usd > a.estimate_usd ? 'text-amber-300' : 'text-muted'">{{ usd(a.estimate_usd) }}</td>
                            <td class="px-3 py-2 text-xs tabular-nums">{{ k(a.avg_tokens.output) }} · {{ k(a.avg_tokens.cache_read) }} · {{ k(a.avg_tokens.cache_write) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ money(a.list_mnt) }}</td>
                            <td class="px-3 py-2 tabular-nums" :class="tone(a.margin, data.margin.typical)">{{ a.margin === null ? '—' : `×${a.margin}` }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Сүүлийн бүтээлүүдийн нэг бүрчилсэн зардал -->
            <h2 class="mt-8 mb-2 font-semibold">Бүтээл тус бүрийн бодит зардал</h2>
            <div class="overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-xs text-muted uppercase">
                        <tr>
                            <th class="px-3 py-2">#</th><th class="px-3 py-2">Хэн</th><th class="px-3 py-2">Бүтээл</th><th class="px-3 py-2">Төлөв</th>
                            <th class="px-3 py-2">Token: гаралт · cache унших · бичих</th><th class="px-3 py-2">Fable</th><th class="px-3 py-2">Медиа (зураг)</th>
                            <th class="px-3 py-2">Нийт</th><th class="px-3 py-2">Үнэ</th><th class="px-3 py-2">Төлсөн</th><th class="px-3 py-2">Ашиг</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in data.recent" :key="r.id" class="border-t border-line">
                            <td class="px-3 py-2 tabular-nums text-muted">{{ r.id }}</td>
                            <td class="px-3 py-2 text-xs">{{ r.who }} <span v-if="r.admin" class="ml-1 rounded bg-surface px-1.5 py-0.5 text-[10px] text-muted">админ</span></td>
                            <td class="px-3 py-2 text-xs">{{ r.kind }} · {{ r.formats.join(', ') }}</td>
                            <td class="px-3 py-2 text-xs" :class="r.status === 'failed' ? 'text-red-300' : 'text-muted'">{{ r.status === 'failed' ? 'Амжилтгүй' : 'Дууссан' }}</td>
                            <td class="px-3 py-2 text-xs tabular-nums">{{ k(r.tokens.output) }} · {{ k(r.tokens.cache_read) }} · {{ k(r.tokens.cache_write) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(r.claude_usd) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ usd(r.media_usd) }} <span class="text-xs text-muted">({{ r.images }})</span></td>
                            <td class="px-3 py-2 tabular-nums font-semibold">{{ money(r.cost_mnt) }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ money(r.list_mnt) }}</td>
                            <td class="px-3 py-2 tabular-nums text-muted">{{ money(r.charged_mnt) }}</td>
                            <td class="px-3 py-2 tabular-nums" :class="r.status === 'failed' ? 'text-red-300' : tone(r.margin, data.margin.typical)">{{ r.status === 'failed' ? '—' : `×${r.margin}` }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
