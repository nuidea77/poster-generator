<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api, money } from '../lib/api';
import Icon from './Icon.vue';

const props = defineProps({ payment: { type: Object, required: true } });
const emit = defineEmits(['close', 'paid']);

const current = ref(props.payment);
const error = ref('');
let timer;

const qr = computed(() => (current.value.qr_image ? `data:image/png;base64,${current.value.qr_image}` : null));

async function poll() {
    try {
        current.value = (await api.get(`/payments/${current.value.id}`)).data;
        if (current.value.status === 'paid') {
            clearInterval(timer);
            emit('paid', current.value);
        }
        if (['expired', 'failed'].includes(current.value.status)) clearInterval(timer);
    } catch (e) {
        error.value = e.message;
    }
}

async function simulate() {
    current.value = (await api.post(`/payments/${current.value.id}/simulate`)).data;
    emit('paid', current.value);
}

onMounted(() => (timer = setInterval(poll, 3000)));
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-4 backdrop-blur-sm" @click.self="emit('close')">
        <div class="w-full max-w-md rounded-[20px] border border-white/10 bg-[#141414] p-5 shadow-2xl">
            <div class="mb-4 flex items-start justify-between">
                <div>
                    <div class="display text-xl">QPay-ээр төлөх</div>
                    <div class="mt-0.5 text-sm text-zinc-400">{{ current.plan.name }} багц · {{ money(current.amount) }}</div>
                </div>
                <button class="text-zinc-500 hover:text-fg" @click="emit('close')"><Icon name="x" size="18" /></button>
            </div>

            <div v-if="current.status === 'paid'" class="py-6 text-center">
                <div class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-lime text-ink"><Icon name="check" size="26" /></div>
                <div class="text-lg font-semibold">Төлбөр амжилттай</div>
                <p class="mt-1 text-sm text-zinc-400">Багц идэвхжлээ.</p>
            </div>

            <div v-else-if="current.status !== 'pending'" class="py-6 text-center text-sm text-zinc-400">Нэхэмжлэхийн хугацаа дууссан. Дахин оролдоно уу.</div>

            <template v-else>
                <div class="grid place-items-center rounded-2xl bg-white p-4">
                    <img v-if="qr" :src="qr" alt="QPay QR" class="size-56" />
                    <div v-else class="grid size-56 place-items-center text-center text-sm text-zinc-500">Туршилтын горим<br />(QR байхгүй)</div>
                </div>
                <p class="mt-3 text-center text-sm text-zinc-400">Банкны аппаараа QR уншуулна уу. Төлбөр орж ирмэгц автоматаар идэвхжинэ.</p>

                <div v-if="current.urls?.length" class="mt-4 grid grid-cols-4 gap-2 sm:hidden">
                    <a v-for="u in current.urls" :key="u.name" :href="u.link" class="flex flex-col items-center gap-1 rounded-xl bg-surface-2 p-2 text-center text-[10px] text-zinc-300">
                        <img :src="u.logo" :alt="u.name" class="size-8 rounded-lg" />
                        <span class="line-clamp-1">{{ u.description || u.name }}</span>
                    </a>
                </div>

                <div class="mt-4 flex items-center justify-center gap-2 text-xs text-muted"><span class="spinner size-3 text-lime" /> Төлбөрийг хүлээж байна…</div>

                <button v-if="current.fake" class="btn btn-outline mt-4 w-full" @click="simulate">Туршилт: төлсөнд тооцох</button>
            </template>

            <p v-if="error" class="mt-3 rounded-xl bg-red-950/70 p-2 text-xs text-red-200">{{ error }}</p>
        </div>
    </div>
</template>
