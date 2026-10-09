<script setup>
// Shared "brief" inputs: prompt, style, language and which AI to use.
const form = defineModel({ type: Object, required: true });

defineProps({
    config: { type: Object, required: true },
    placeholder: { type: String, default: '' },
});

const styles = ['Минимал', 'Неон', 'Ретро 80-аад', 'Люкс', 'Фото реалистик', '3D рендер', 'Хүүхэлдэйн', 'Монгол уламжлалт хээ'];

function toggleStyle(s) {
    form.value.style = form.value.style === s ? '' : s;
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <label class="label">Юу хийх вэ?</label>
            <textarea v-model="form.prompt" rows="4" class="field resize-none" :placeholder="placeholder" />
        </div>

        <div>
            <label class="label">Стиль</label>
            <div class="mb-2 flex flex-wrap gap-1.5">
                <button
                    v-for="s in styles"
                    :key="s"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-xs transition"
                    :class="form.style === s ? 'border-fuchsia-500 bg-fuchsia-500/20 text-fuchsia-200' : 'border-zinc-700 text-zinc-400 hover:border-zinc-500'"
                    @click="toggleStyle(s)"
                >
                    {{ s }}
                </button>
            </div>
            <input v-model="form.style" class="field" placeholder="Эсвэл өөрөө бичих…" />
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div>
                <label class="label">Хэл</label>
                <select v-model="form.language" class="field">
                    <option value="mn">Монгол</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div>
                <label class="label">Текст AI</label>
                <select v-model="form.text_provider" class="field">
                    <option v-for="p in config.providers.filter((p) => p.text)" :key="p.id" :value="p.id" :disabled="!p.configured">
                        {{ p.label }}{{ p.configured ? '' : ' (түлхүүргүй)' }}
                    </option>
                </select>
            </div>
            <div>
                <label class="label">Зураг AI</label>
                <select v-model="form.image_provider" class="field">
                    <option v-for="p in config.providers.filter((p) => p.image)" :key="p.id" :value="p.id" :disabled="!p.configured">
                        {{ p.label }}{{ p.configured ? '' : ' (түлхүүргүй)' }}
                    </option>
                </select>
            </div>
        </div>
    </div>
</template>
