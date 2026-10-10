<script setup>
defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: '' } });
</script>

<template>
    <div class="mx-auto flex min-h-[75vh] max-w-sm flex-col justify-center">
        <h1 class="display text-4xl leading-none">{{ title }}</h1>
        <p v-if="subtitle" class="mt-2 text-sm text-zinc-400">{{ subtitle }}</p>
        <div class="panel mt-6 space-y-4">
            <slot />
        </div>
        <div class="mt-4 text-center text-sm text-zinc-400"><slot name="footer" /></div>
    </div>
</template>
