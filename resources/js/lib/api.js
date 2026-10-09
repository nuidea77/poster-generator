const token = () => document.querySelector('meta[name="csrf-token"]')?.content;

async function request(method, url, body) {
    const isForm = body instanceof FormData;

    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token(),
            ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
    });

    if (res.status === 204) return null;

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const errors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
        throw new Error(errors || data.message || `Алдаа гарлаа (${res.status})`);
    }

    return data;
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body),
    put: (url, body) => request('PUT', url, body),
    delete: (url) => request('DELETE', url),
};

export async function mapLimit(items, limit, fn) {
    const queue = items.map((item, i) => [item, i]);
    const workers = Array.from({ length: Math.min(limit, queue.length) }, async () => {
        while (queue.length) {
            const [item, i] = queue.shift();
            await fn(item, i);
        }
    });
    await Promise.all(workers);
}

export function debounce(fn, ms) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}

export function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
