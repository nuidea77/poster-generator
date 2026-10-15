<template>
    <div class="mb-5 flex gap-1.5">
        <!-- Админ хуудсуудын chip холбоосууд; идэвхтэйг нь тодруулна -->
        <RouterLink v-for="l in links" :key="l.to" :to="l.to" class="chip chip-sm" active-class="chip-active">{{ l.label }}</RouterLink>
    </div>
</template>

<script setup>
// Админ хуудсуудын дээрх таб хэлбэрийн навигаци.
// Админ хэсгийн холбоосууд.
const links = [
    { to: '/admin/creations', label: 'Бүтээлүүд' },
    { to: '/admin/costs', label: 'Өртөг' },
    { to: '/admin/plans', label: 'Багц, үнэ' },
    { to: '/admin/skills', label: 'Skills' },
    { to: '/admin/references', label: 'Жишээ сан' },
];
</script>
