<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { logout, session } from './lib/session';
import Icon from './components/Icon.vue';

const route = useRoute();
const router = useRouter();
const menu = ref(false);

const nav = computed(() => [
    { to: '/create', label: 'Бүтээх', auth: true },
    { to: '/library', label: 'Миний бүтээлүүд', auth: true },
    { to: '/pricing', label: 'Багц' },
    ...(session.user?.is_admin ? [{ to: '/admin', label: 'Админ', badge: 'Admin' }] : []),
].filter((n) => !n.auth || session.user));

const active = (to) => route.path === to || (to !== '/' && route.path.startsWith(to));

watch(() => route.fullPath, () => (menu.value = false));

async function signOut() {
    await logout();
    router.push('/');
}
</script>

<template>
    <div class="min-h-full">
        <header class="sticky top-0 z-30 border-b border-line bg-bg/90 backdrop-blur">
            <div class="flex h-14 items-center gap-1 px-4">
                <RouterLink to="/" class="mr-3 flex items-center gap-2">
                    <span class="grid size-8 place-items-center rounded-lg bg-white text-sm font-black text-ink">P</span>
                    <span class="hidden font-display text-sm font-bold tracking-tight uppercase sm:inline">Poster Studio</span>
                </RouterLink>

                <nav class="flex items-center gap-0.5 overflow-x-auto scroll-thin">
                    <RouterLink
                        v-for="n in nav"
                        :key="n.to"
                        :to="n.to"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium tracking-[0.1px] transition"
                        :class="active(n.to) ? 'text-lime' : 'text-zinc-400 hover:text-fg'"
                    >
                        {{ n.label }}
                        <span v-if="n.badge" class="badge badge-dark">{{ n.badge }}</span>
                    </RouterLink>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <template v-if="session.user">
                        <RouterLink v-if="!session.user.subscribed" to="/pricing" class="btn btn-lime-soft hidden sm:inline-flex">Багц авах</RouterLink>
                        <div class="relative">
                            <button class="flex items-center gap-2 rounded-[10px] bg-surface-2 py-1.5 pr-2.5 pl-1.5 text-sm hover:bg-surface-3" @click="menu = !menu">
                                <span class="grid size-6 place-items-center rounded-md bg-lime text-xs font-bold text-ink">{{ session.user.name.slice(0, 1).toUpperCase() }}</span>
                                <span class="hidden max-w-28 truncate sm:inline">{{ session.user.name }}</span>
                                <Icon name="chevron" size="12" class="opacity-60" />
                            </button>
                            <div v-if="menu" class="absolute right-0 mt-2 w-56 rounded-2xl border border-white/10 bg-[#161616] p-2 shadow-2xl">
                                <div class="px-2 py-1.5 text-xs text-muted">
                                    <template v-if="session.user.subscription">{{ session.user.subscription.plan }} багц</template>
                                    <template v-else>Багцгүй</template>
                                </div>
                                <RouterLink to="/account" class="block rounded-lg px-2 py-2 text-sm hover:bg-white/5">Миний бүртгэл</RouterLink>
                                <button class="block w-full rounded-lg px-2 py-2 text-left text-sm text-red-300 hover:bg-white/5" @click="signOut">Гарах</button>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <RouterLink to="/login" class="btn btn-lime-soft">Нэвтрэх</RouterLink>
                        <RouterLink to="/register" class="btn btn-lime">Бүртгүүлэх</RouterLink>
                    </template>
                </div>
            </div>
        </header>

        <main v-if="session.ready" class="px-4 pt-4 pb-16">
            <RouterView />
        </main>
        <div v-else class="grid min-h-[70vh] place-items-center"><span class="spinner text-lime" /></div>
    </div>
</template>
