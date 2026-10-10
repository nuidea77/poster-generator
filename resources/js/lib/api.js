const BASE = '/api/v1';

// Laravel refreshes the XSRF-TOKEN cookie on every response, so reading it per
// request keeps working after login/logout rotates the session token.
function xsrf() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.status = status;
        this.code = data.code ?? null;
        this.errors = data.errors ?? {};
    }
}

async function request(method, path, body) {
    const isForm = body instanceof FormData;

    const res = await fetch(BASE + path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
            ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
    });

    if (res.status === 204) return null;

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors).flat()[0] : null;
        const fallback = { 401: 'Нэвтэрнэ үү.', 403: 'Эрх хүрэхгүй байна.', 404: 'Олдсонгүй.', 419: 'Хуудсаа шинэчлээд дахин оролдоно уу.', 429: 'Түр хүлээгээд дахин оролдоно уу.' }[res.status];
        throw new ApiError(first || data.message || fallback || `Алдаа гарлаа (${res.status})`, res.status, data);
    }

    return data;
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    put: (path, body) => request('PUT', path, body),
    delete: (path) => request('DELETE', path),
};

export const money = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '₮';

// Formatted by hand: many browsers ship without Mongolian locale data.
export const date = (d) => {
    if (!d) return '';
    const t = new Date(d);
    return `${t.getFullYear()}.${String(t.getMonth() + 1).padStart(2, '0')}.${String(t.getDate()).padStart(2, '0')}`;
};
