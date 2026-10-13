// Backend API-тай харилцах туслах: fetch wrapper, алдааны класс, мөнгө/огноо форматлагч.
const BASE = '/api/v1';

// Laravel хариу бүрт XSRF-TOKEN cookie-г шинэчилдэг тул хүсэлт бүрт дахин уншина —
// login/logout хийж session token солигдсон ч CSRF ажилласаар байна.
function xsrf() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

// API-ийн алдаа: HTTP статус, серверийн code болон талбарын validation алдааг хадгална.
export class ApiError extends Error {
    constructor(message, status, data = {}) {
        super(message);
        this.status = status;
        this.code = data.code ?? null;
        this.errors = data.errors ?? {};
    }
}

// Нэг API хүсэлт илгээнэ: JSON эсвэл FormData биет, CSRF толгой, cookie-той.
// Амжилтгүй бол эхний validation алдаа эсвэл статусын монгол мессежтэй ApiError шиднэ.
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

    // 204 — хариунд биет байхгүй.
    if (res.status === 204) return null;

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors).flat()[0] : null;
        const fallback = { 401: 'Нэвтэрнэ үү.', 403: 'Эрх хүрэхгүй байна.', 404: 'Олдсонгүй.', 419: 'Хуудсаа шинэчлээд дахин оролдоно уу.', 429: 'Түр хүлээгээд дахин оролдоно уу.' }[res.status];
        throw new ApiError(first || data.message || fallback || `Алдаа гарлаа (${res.status})`, res.status, data);
    }

    return data;
}

// HTTP аргууд тус бүрийн товч функцууд.
export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    put: (path, body) => request('PUT', path, body),
    delete: (path) => request('DELETE', path),
};

// Мөнгөн дүнг мянгатын таслалтай, ₮ тэмдэгтэй болгоно (жишээ: 12,000₮).
export const money = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '₮';

// Огноог YYYY.MM.DD хэлбэрээр гараар форматлана: олон browser монгол locale өгөгдөлгүй байдаг.
export const date = (d) => {
    if (!d) return '';
    const t = new Date(d);
    return `${t.getFullYear()}.${String(t.getMonth() + 1).padStart(2, '0')}.${String(t.getDate()).padStart(2, '0')}`;
};
