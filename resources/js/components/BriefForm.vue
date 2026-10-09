<script setup>
import { computed } from 'vue';
import ChipMenu from './ChipMenu.vue';
import ModelPicker from './ModelPicker.vue';

// Shared composer chips: style, language, text model, image model.
const form = defineModel({ type: Object, required: true });

const props = defineProps({
    config: { type: Object, required: true },
});

const styles = ['Минимал', 'Неон', 'Ретро 80-аад', 'Люкс', 'Фото реалистик', '3D рендер', 'Хүүхэлдэйн', 'Монгол хээ'];

const label = (id) => props.config.providers.find((p) => p.id === id)?.label ?? id;
const styleLabel = computed(() => form.value.style || 'Стиль');
</script>

<template>
    <ChipMenu :label="styleLabel" icon="layers" :active="!!form.style" width="w-72">
        <div class="flex flex-wrap gap-1.5 p-1">
            <button v-for="s in styles" :key="s" type="button" class="chip chip-sm" :class="{ 'chip-active': form.style === s }" @click="form.style = form.style === s ? '' : s">{{ s }}</button>
        </div>
        <input v-model="form.style" class="field mt-2 py-2 text-xs" placeholder="Эсвэл өөрөө бичих…" />
    </ChipMenu>

    <button type="button" class="chip" @click="form.language = form.language === 'mn' ? 'en' : 'mn'">
        {{ form.language === 'mn' ? 'Монгол' : 'English' }}
    </button>

    <ChipMenu :label="label(form.text_provider)" icon="bolt" width="w-72">
        <div class="label px-2 pt-1">Текст модель</div>
        <ModelPicker v-model="form.text_provider" :config="config" kind="text" />
    </ChipMenu>

    <ChipMenu :label="label(form.image_provider)" icon="image" width="w-72">
        <div class="label px-2 pt-1">Зураг модель</div>
        <ModelPicker v-model="form.image_provider" :config="config" kind="image" />
    </ChipMenu>
</template>
