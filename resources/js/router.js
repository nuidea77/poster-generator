// Клиент талын router: хуудсуудын замууд болон нэвтрэлт/админ эрхийн шалгалт.
import { createRouter, createWebHistory } from 'vue-router';
import { loadSession, session } from './lib/session';

// Замуудын жагсаалт. meta.auth — нэвтэрсэн байх, meta.admin — админ байх, meta.guest — зөвхөн нэвтрээгүй хэрэглэгчид.
// Хуудсуудыг lazy байдлаар (хэрэгтэй үед нь) ачаална.
const routes = [
    { path: '/', name: 'home', component: () => import('./pages/Home.vue') },
    { path: '/login', name: 'login', component: () => import('./pages/Login.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('./pages/Register.vue'), meta: { guest: true } },
    { path: '/pricing', name: 'pricing', component: () => import('./pages/Pricing.vue') },
    { path: '/create', name: 'create', component: () => import('./pages/Create.vue'), meta: { auth: true } },
    { path: '/library', name: 'library', component: () => import('./pages/Library.vue'), meta: { auth: true } },
    { path: '/c/:id', name: 'creation', component: () => import('./pages/Creation.vue'), meta: { auth: true } },
    { path: '/account', name: 'account', component: () => import('./pages/Account.vue'), meta: { auth: true } },
    // Админ хэсэг: /admin нь бүтээлүүдийн хуудас руу шилжинэ.
    { path: '/admin', redirect: '/admin/creations' },
    { path: '/admin/creations', name: 'admin-creations', component: () => import('./pages/admin/Creations.vue'), meta: { auth: true, admin: true } },
    { path: '/admin/costs', name: 'admin-costs', component: () => import('./pages/admin/Costs.vue'), meta: { auth: true, admin: true } },
    { path: '/admin/plans', name: 'admin-plans', component: () => import('./pages/admin/Plans.vue'), meta: { auth: true, admin: true } },
    { path: '/admin/skills', name: 'admin-skills', component: () => import('./pages/admin/Skills.vue'), meta: { auth: true, admin: true } },
    // Танигдаагүй бүх замыг нүүр хуудас руу буцаана.
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

// Хуудас солих бүрт дээд хэсэг рүү гүйлгэнэ.
export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

// Шилжилт бүрийн өмнө session-ийг ачаалж, эрхийн дагуу чиглүүлнэ:
// нэвтрээгүй бол login руу (next-тэй), админ биш бол /create руу, нэвтэрсэн хүн guest хуудас руу орвол /create руу.
router.beforeEach(async (to) => {
    await loadSession();

    if (to.meta.auth && !session.user) {
        return { name: 'login', query: { next: to.fullPath } };
    }
    if (to.meta.admin && !session.user?.is_admin) {
        return { name: 'create' };
    }
    if (to.meta.guest && session.user) {
        return { name: 'create' };
    }
});
