<script setup>
import { onBeforeUnmount, watch } from 'vue';
import Icon from './Icon.vue';

// Phone settings sheet: slides up from the bottom over a dimmed page.
const open = defineModel({ type: Boolean, default: false });

defineProps({
    title: { type: String, required: true },
    done: { type: String, default: 'Болсон' },
});

const onKey = (e) => e.key === 'Escape' && (open.value = false);
watch(open, (v) => (v ? document.addEventListener('keydown', onKey) : document.removeEventListener('keydown', onKey)));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-[60] bg-black/60" @click="open = false" />
        </transition>
        <transition enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-full" leave-active-class="transition duration-150 ease-in" leave-to-class="translate-y-full">
            <div v-if="open" class="fixed inset-x-0 bottom-0 z-[61] flex max-h-[85vh] flex-col rounded-t-3xl border-t border-white/10 bg-[#161616] pb-[max(1rem,env(safe-area-inset-bottom))]" role="dialog" :aria-label="title">
                <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-white/20" />
                <div class="relative px-4 pt-3 pb-2 text-center text-sm font-semibold">
                    {{ title }}
                    <button type="button" class="absolute top-1.5 right-3 grid size-8 place-items-center rounded-full bg-white/5 text-zinc-300" aria-label="Хаах" @click="open = false"><Icon name="x" size="14" /></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-4 scroll-thin">
                    <slot />
                </div>
                <div class="px-4 pt-3">
                    <button type="button" class="w-full rounded-full bg-lime py-3 text-sm font-semibold text-ink" @click="open = false">{{ done }}</button>
                </div>
            </div>
        </transition>
    </Teleport>
</template>
