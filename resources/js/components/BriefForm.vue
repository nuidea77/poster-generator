<script setup>
import ModelPicker from './ModelPicker.vue';

// Shared "brief" inputs: prompt, style, language and which AI to use.
const form = defineModel({ type: Object, required: true });

defineProps({
    config: { type: Object, required: true },
    placeholder: { type: String, default: '' },
});

const styles = ['Минимал', 'Неон', 'Ретро 80-аад', 'Люкс', 'Фото реалистик', '3D рендер', 'Хүүхэлдэйн', 'Монгол хээ'];

function toggleStyle(s) {
    form.value.style = form.value.style === s ? '' : s;
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <label class="label">Prompt</label>
            <textarea v-model="form.prompt" rows="4" class="field resize-none" :placeholder="placeholder" />
        </div>

        <div>
            <label class="label">Стиль</label>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="s in styles" :key="s" type="button" class="chip" :class="{ 'chip-active': form.style === s }" @click="toggleStyle(s)">
                    {{ s }}
                </button>
            </div>
            <input v-model="form.style" class="field mt-2 py-2 text-xs" placeholder="Эсвэл өөрөө бичих…" />
        </div>

        <div class="grid grid-cols-2 gap-1.5">
            <button type="button" class="chip justify-center" :class="{ 'chip-active': form.language === 'mn' }" @click="form.language = 'mn'">Монгол</button>
            <button type="button" class="chip justify-center" :class="{ 'chip-active': form.language === 'en' }" @click="form.language = 'en'">English</button>
        </div>

        <ModelPicker v-model="form.text_provider" :config="config" kind="text" label="Текст модель" />
        <ModelPicker v-model="form.image_provider" :config="config" kind="image" label="Зураг модель" />
    </div>
</template>
