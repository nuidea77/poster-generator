import { reactive } from 'vue';
import { api } from './api';

// Аппын нийтлэг төлөв: нэвтэрсэн хэрэглэгч болон нийтийн metadata (форматууд, багцууд).
export const session = reactive({
    user: null,
    meta: null,
    ready: false,
});

// Ачаалж буй (эсвэл ачаалсан) session-ийн Promise — давхар хүсэлт явуулахгүйн тулд.
let loading;

// Хэрэглэгч (/me) болон metadata (/meta)-г ачаална. Нэг удаа л ачаална; force=true бол дахин татна.
export function loadSession(force = false) {
    if (loading && !force) return loading;

    loading = Promise.all([api.get('/me'), session.meta && !force ? { ...session.meta } : api.get('/meta')]).then(([me, meta]) => {
        session.user = me.data;
        session.meta = meta;
        session.ready = true;
    });

    return loading;
}

// Хэрэглэгчийн мэдээллийг (кредит, багц г.м.) шинэчилнэ.
export async function refreshUser() {
    session.user = (await api.get('/me')).data;
}

// Системээс гарч, локал хэрэглэгчийг цэвэрлэнэ.
export async function logout() {
    await api.post('/auth/logout');
    session.user = null;
}
