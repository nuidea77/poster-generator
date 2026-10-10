import { reactive } from 'vue';
import { api } from './api';

// App-wide state: the signed-in user and public metadata (formats, plans).
export const session = reactive({
    user: null,
    meta: null,
    ready: false,
});

let loading;

export function loadSession(force = false) {
    if (loading && !force) return loading;

    loading = Promise.all([api.get('/me'), session.meta && !force ? { ...session.meta } : api.get('/meta')]).then(([me, meta]) => {
        session.user = me.data;
        session.meta = meta;
        session.ready = true;
    });

    return loading;
}

export async function refreshUser() {
    session.user = (await api.get('/me')).data;
}

export async function logout() {
    await api.post('/auth/logout');
    session.user = null;
}
