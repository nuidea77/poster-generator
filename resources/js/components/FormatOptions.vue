<script setup>
import Icon from './Icon.vue';

// Size choices (ratio outline, label, what it is for, check mark). Used by the
// desktop size menu and the phone settings sheet.
defineProps({
    options: { type: Array, required: true },
    picked: { type: Function, required: true },
});

defineEmits(['pick']);

// Outline of a ratio icon, fitted in a 14×14 box.
const ratioBox = (f) => {
    if (!f.width) return null;
    const m = Math.max(f.width, f.height);
    return { width: `${Math.round((f.width / m) * 14)}px`, height: `${Math.round((f.height / m) * 14)}px` };
};
</script>

<template>
    <button
        v-for="f in options"
        :key="f.id"
        type="button"
        class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left text-sm transition hover:bg-white/5"
        :class="picked(f.id) ? 'bg-white/5 text-fg' : 'text-zinc-300'"
        @click="$emit('pick', f.id)"
    >
        <span class="grid size-7 place-items-center rounded-lg" :class="picked(f.id) ? 'bg-white/10' : ''">
            <span v-if="ratioBox(f)" class="block rounded-[3px] border-[1.5px] border-current" :style="ratioBox(f)" />
            <span v-else class="block size-3.5 rounded-[3px] border-[1.5px] border-dashed border-current" />
        </span>
        <span class="flex-1">
            <span class="block font-semibold">{{ f.label }}</span>
            <span class="block text-[11px] text-muted">{{ f.hint }}</span>
        </span>
        <Icon v-if="picked(f.id)" name="check" size="14" />
    </button>
</template>
