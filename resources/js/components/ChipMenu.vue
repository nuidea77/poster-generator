<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

// A composer chip that opens a popover above itself (the composer sits at the bottom).
defineProps({
    label: { type: String, required: true },
    icon: { type: String, default: '' },
    active: { type: Boolean, default: false },
    width: { type: String, default: 'w-72' },
});

const open = ref(false);
const root = ref(null);

const onDoc = (e) => {
    if (root.value && !root.value.contains(e.target)) open.value = false;
};
onMounted(() => document.addEventListener('mousedown', onDoc));
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc));

defineExpose({ close: () => (open.value = false) });
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" class="chip" :class="{ 'chip-active': active, 'bg-[#2a2a2a]': open && !active }" @click="open = !open">
            <Icon v-if="icon" :name="icon" size="14" />
            <span class="max-w-40 truncate">{{ label }}</span>
            <Icon name="chevron" size="12" class="opacity-60 transition" :class="open ? '' : 'rotate-180'" />
        </button>
        <transition enter-active-class="transition duration-100" enter-from-class="translate-y-1 opacity-0" leave-active-class="transition duration-75" leave-to-class="opacity-0">
            <div v-if="open" class="absolute bottom-full left-0 z-50 mb-2 max-h-[70vh] overflow-y-auto rounded-2xl border border-white/10 bg-[#161616] p-2 shadow-2xl scroll-thin" :class="width" @click="$emit('pick')">
                <slot :close="() => (open = false)" />
            </div>
        </transition>
    </div>
</template>
