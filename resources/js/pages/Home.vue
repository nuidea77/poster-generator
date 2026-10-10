<script setup>
import { session } from '../lib/session';
import { money } from '../lib/api';
import Icon from '../components/Icon.vue';

const steps = [
    { icon: 'edit', title: 'Санаагаа бич', text: 'Юу сурталчлах, хэнд, ямар уур амьсгалтай байхыг энгийн үгээр бичнэ.' },
    { icon: 'upload', title: 'Лого, бүтээгдэхүүн', text: 'Логогоо, бүтээгдэхүүнийхээ зургийг хавсаргана. AI яг тэр бүтээгдэхүүнийг ашиглана.' },
    { icon: 'sparkles', title: 'Бэлэн болно', text: 'Постер хэдэн минутад, 1:30 минутын reels видео бүтнээрээ гарч ирнэ. Татаад шууд нийтэл.' },
];
const cards = ['from-orange-500 to-amber-700', 'from-sky-500 to-indigo-700', 'from-pink-500 to-rose-700', 'from-lime-400 to-emerald-700'];
</script>

<template>
    <div class="mx-auto max-w-[1400px]">
        <section class="relative mx-auto flex min-h-[70vh] max-w-4xl flex-col items-center justify-center text-center">
            <div class="mb-10 flex items-end justify-center gap-2">
                <div v-for="(c, i) in cards" :key="i" class="h-32 rounded-xl border border-white/20 bg-gradient-to-br shadow-xl md:h-44" :class="[c, i === 1 ? 'w-20 md:w-24' : 'w-24 md:w-32']" :style="{ transform: `rotate(${(i - 1.5) * 6}deg) translateY(${Math.abs(i - 1.5) * 10}px)` }" />
            </div>
            <h1 class="display text-5xl leading-[0.92] md:text-7xl">
                Постер, reels-ээ<br />
                <span class="text-lime">AI-аар хийлгэ.</span>
            </h1>
            <p class="mt-5 max-w-2xl text-base text-zinc-400 md:text-lg">
                Instagram, Facebook-д тохирсон постер болон 1:30 минутын reels видео. Дизайнер, видеографчгүйгээр, хэдхэн минутад.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <RouterLink :to="session.user ? '/create' : '/register'" class="btn btn-lime px-6 py-3 text-base">Бүтээж эхлэх</RouterLink>
                <RouterLink to="/pricing" class="btn btn-white px-6 py-3 text-base">Багц үзэх</RouterLink>
            </div>
        </section>

        <section class="grid gap-3 md:grid-cols-3">
            <div v-for="(s, i) in steps" :key="i" class="feature-card">
                <div class="mb-8 flex items-start justify-between">
                    <Icon :name="s.icon" size="22" class="text-zinc-300" />
                    <span class="badge badge-dark">0{{ i + 1 }}</span>
                </div>
                <div class="text-[15px] font-semibold">{{ s.title }}</div>
                <div class="mt-1 text-sm text-zinc-400">{{ s.text }}</div>
            </div>
        </section>

        <section class="mt-16 grid gap-3 md:grid-cols-2">
            <div class="feature-card">
                <div class="mb-6 flex items-center gap-2"><Icon name="image" size="20" /><span class="badge badge-new">Постер</span></div>
                <h2 class="display text-3xl">Бүх хэмжээ нэг дор</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span v-for="f in session.meta.poster_formats" :key="f.id" class="chip chip-sm cursor-default">{{ f.label }} · {{ f.platforms }}</span>
                </div>
            </div>
            <div class="feature-card">
                <div class="mb-6 flex items-center gap-2"><Icon name="video" size="20" /><span class="badge badge-top">Reels</span></div>
                <h2 class="display text-3xl">1:30 видео бүтнээрээ</h2>
                <p class="mt-3 text-sm text-zinc-400">9:16 босоо, 1080×1920, Instagram Reels болон Facebook-д шууд тавих MP4.</p>
            </div>
        </section>

        <section v-if="session.meta.plans.length" class="mt-16 text-center">
            <h2 class="display text-3xl">Хязгааргүй хэрэглээ</h2>
            <p class="mt-2 text-zinc-400">{{ money(session.meta.plans[0].price) }}-өөс эхэлнэ</p>
            <RouterLink to="/pricing" class="btn btn-lime mt-5">Багцууд</RouterLink>
        </section>
    </div>
</template>
