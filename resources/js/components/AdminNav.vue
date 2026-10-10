<template>
    <div class="mb-5 flex gap-1.5">
        <RouterLink v-for="l in links" :key="l.to" :to="l.to" class="chip chip-sm" active-class="chip-active">{{ l.label }}</RouterLink>
    </div>
</template>

<script setup>
const links = [
    { to: '/admin/creations', label: 'Бүтээлүүд' },
    { to: '/admin/plans', label: 'Багц, үнэ' },
    { to: '/admin/skills', label: 'Skills' },
];
</script>
