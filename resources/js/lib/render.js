// Canvas rendering for posters and reel frames. Everything is drawn at full
// export resolution; the <canvas> is scaled down with CSS for preview.

export const FONTS = {
    modern: { family: 'Inter', weight: 800, body: 'Inter', bodyWeight: 400 },
    elegant: { family: 'Playfair Display', weight: 700, body: 'Inter', bodyWeight: 400 },
    bold: { family: 'Montserrat', weight: 900, body: 'Montserrat', bodyWeight: 500 },
    playful: { family: 'Comfortaa', weight: 700, body: 'Comfortaa', bodyWeight: 500 },
};

export const SIZES = {
    '1:1': [1080, 1080],
    '4:5': [1080, 1350],
    '9:16': [1080, 1920],
    '16:9': [1920, 1080],
};

const imageCache = new Map();

export function loadImage(url) {
    if (!url) return Promise.resolve(null);
    if (/\.(mp4|webm|mov)(\?|$)/i.test(url)) return loadVideo(url);
    if (!imageCache.has(url)) {
        imageCache.set(
            url,
            new Promise((resolve) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = () => {
                    imageCache.delete(url);
                    resolve(null);
                };
                img.src = url;
            }),
        );
    }
    return imageCache.get(url);
}

// Video scene sources are HTMLVideoElements; drawCover reads their frame.
export function loadVideo(url) {
    if (!imageCache.has(url)) {
        imageCache.set(
            url,
            new Promise((resolve) => {
                const v = document.createElement('video');
                v.muted = true;
                v.playsInline = true;
                v.loop = true;
                v.preload = 'auto';
                v.crossOrigin = 'anonymous';
                v.onloadeddata = () => resolve(v);
                v.onerror = () => {
                    imageCache.delete(url);
                    resolve(null);
                };
                v.src = url;
                v.load();
            }),
        );
    }
    return imageCache.get(url);
}

export const isVideo = (el) => el instanceof HTMLVideoElement;

let fontsReady;
export function ensureFonts() {
    // Include Cyrillic glyphs so the Cyrillic subset gets downloaded too.
    fontsReady ??= Promise.all(
        Object.values(FONTS).flatMap((f) => [
            document.fonts.load(`${f.weight} 48px "${f.family}"`, 'AaАаӨөҮү'),
            document.fonts.load(`${f.bodyWeight} 48px "${f.body}"`, 'AaАаӨөҮү'),
            document.fonts.load(`700 48px "${f.body}"`, 'AaАаӨөҮү'),
        ]),
    ).catch(() => {});
    return fontsReady;
}

const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
const easeOut = (t) => 1 - Math.pow(1 - t, 3);

function drawCover(ctx, img, rect, scale = 1, panX = 0) {
    const nw = img.videoWidth || img.width;
    const nh = img.videoHeight || img.height;
    const r = Math.max(rect.w / nw, rect.h / nh) * scale;
    const iw = nw * r;
    const ih = nh * r;
    const dx = rect.x + (rect.w - iw) / 2 + (panX * (iw - rect.w)) / 2;
    const dy = rect.y + (rect.h - ih) / 2;
    ctx.save();
    ctx.beginPath();
    ctx.rect(rect.x, rect.y, rect.w, rect.h);
    ctx.clip();
    ctx.drawImage(img, dx, dy, iw, ih);
    ctx.restore();
}

// Used when there is no generated image (demo mode / still loading).
function drawFallback(ctx, rect, palette, seed = 0, shift = 0) {
    ctx.save();
    ctx.beginPath();
    ctx.rect(rect.x, rect.y, rect.w, rect.h);
    ctx.clip();

    const g = ctx.createLinearGradient(rect.x, rect.y, rect.x + rect.w, rect.y + rect.h);
    g.addColorStop(0, palette.primary);
    g.addColorStop(1, palette.background);
    ctx.fillStyle = g;
    ctx.fillRect(rect.x, rect.y, rect.w, rect.h);

    const blobs = [
        [0.2, 0.25, 0.45, palette.accent],
        [0.85, 0.7, 0.55, palette.primary],
        [0.5, 0.95, 0.4, palette.accent],
    ];
    blobs.forEach(([bx, by, br, color], i) => {
        const angle = seed * 1.7 + i * 2.1 + shift * 2;
        const cx = rect.x + rect.w * (bx + Math.cos(angle) * 0.08);
        const cy = rect.y + rect.h * (by + Math.sin(angle) * 0.08);
        const radius = Math.max(rect.w, rect.h) * br;
        const rg = ctx.createRadialGradient(cx, cy, 0, cx, cy, radius);
        rg.addColorStop(0, hexAlpha(color, 0.55));
        rg.addColorStop(1, hexAlpha(color, 0));
        ctx.fillStyle = rg;
        ctx.fillRect(rect.x, rect.y, rect.w, rect.h);
    });
    ctx.restore();
}

export function hexAlpha(hex, alpha) {
    let h = hex.replace('#', '');
    if (h.length === 3) h = [...h].map((c) => c + c).join('');
    const n = parseInt(h.slice(0, 6), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
}

function wrap(ctx, text, maxWidth) {
    const lines = [];
    for (const paragraph of String(text).split('\n')) {
        let line = '';
        for (const word of paragraph.split(/\s+/).filter(Boolean)) {
            const test = line ? `${line} ${word}` : word;
            if (ctx.measureText(test).width <= maxWidth || !line) {
                line = test;
            } else {
                lines.push(line);
                line = word;
            }
        }
        if (line) lines.push(line);
    }
    return lines;
}

function fit(ctx, text, font, start, min, maxWidth, maxLines) {
    let size = start;
    let lines;
    do {
        ctx.font = font(size);
        lines = wrap(ctx, text, maxWidth);
        const widest = Math.max(0, ...lines.map((l) => ctx.measureText(l).width));
        if (lines.length <= maxLines && widest <= maxWidth) break;
        size -= 2;
    } while (size > min);
    return { size, lines };
}

/** Build a stack of text blocks, measure it, then draw it at a position. */
function textStack(ctx, blocks, maxWidth) {
    const items = blocks
        .filter((b) => b.text && String(b.text).trim())
        .map((b) => {
            if (b.pill) {
                ctx.font = b.font(b.size);
                const padX = b.size * 0.9;
                const padY = b.size * 0.55;
                return { ...b, h: b.size + padY * 2, w: ctx.measureText(b.text).width + padX * 2, padX, padY };
            }
            const { size, lines } = fit(ctx, b.text, b.font, b.size, b.min ?? b.size * 0.5, maxWidth, b.maxLines ?? 4);
            return { ...b, size, lines, h: lines.length * size * (b.lineHeight ?? 1.15) };
        });

    const height = items.reduce((sum, it, i) => sum + it.h + (i ? it.gap ?? 0 : 0), 0);

    const draw = (x, y, align, alpha = 1, offsetY = 0) => {
        let cy = y + offsetY;
        items.forEach((it, i) => {
            if (i) cy += it.gap ?? 0;
            ctx.save();
            ctx.globalAlpha = alpha * (it.alpha ?? 1);
            ctx.textBaseline = 'top';
            ctx.font = it.font(it.size);

            if (it.pill) {
                const px = align === 'center' ? x - it.w / 2 : x;
                ctx.fillStyle = it.bg;
                roundRect(ctx, px, cy, it.w, it.h, it.h / 2);
                ctx.fill();
                ctx.fillStyle = it.color;
                ctx.textAlign = 'left';
                ctx.fillText(it.text, px + it.padX, cy + it.padY + it.size * 0.05);
            } else {
                ctx.fillStyle = it.color;
                ctx.textAlign = align;
                if (it.shadow) {
                    ctx.shadowColor = 'rgba(0,0,0,0.45)';
                    ctx.shadowBlur = it.size * 0.25;
                    ctx.shadowOffsetY = it.size * 0.04;
                }
                it.lines.forEach((line, li) => {
                    ctx.fillText(line, x, cy + li * it.size * (it.lineHeight ?? 1.15));
                });
            }
            ctx.restore();
            cy += it.h;
        });
    };

    return { height, draw };
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

export function drawPoster(ctx, c, img) {
    const [W, H] = SIZES[c.format] ?? SIZES['4:5'];
    const p = c.palette;
    const f = FONTS[c.font] ?? FONTS.modern;
    const u = Math.min(W, H);
    const pad = Math.round(u * 0.08);
    const full = { x: 0, y: 0, w: W, h: H };

    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = p.background;
    ctx.fillRect(0, 0, W, H);

    let area = { x: pad, y: pad, w: W - pad * 2, h: H - pad * 2 };
    let align = c.layout === 'center' ? 'center' : 'left';

    if (c.layout === 'split') {
        const landscape = W > H;
        const imgRect = landscape ? { x: W / 2, y: 0, w: W / 2, h: H } : { x: 0, y: 0, w: W, h: H * 0.55 };
        area = landscape
            ? { x: pad, y: pad, w: W / 2 - pad * 2, h: H - pad * 2 }
            : { x: pad, y: H * 0.55 + pad * 0.7, w: W - pad * 2, h: H * 0.45 - pad * 1.4 };
        img ? drawCover(ctx, img, imgRect) : drawFallback(ctx, imgRect, p, 1);
        // accent bar on the seam
        ctx.fillStyle = p.accent;
        landscape ? ctx.fillRect(W / 2 - u * 0.006, 0, u * 0.012, H) : ctx.fillRect(0, H * 0.55 - u * 0.006, W, u * 0.012);
    } else {
        img ? drawCover(ctx, img, full) : drawFallback(ctx, full, p, 1);

        const shade = 'rgba(0,0,0,0.78)';
        if (c.layout === 'center') {
            ctx.fillStyle = 'rgba(0,0,0,0.38)';
            ctx.fillRect(0, 0, W, H);
        } else {
            const top = c.layout === 'top';
            const g = ctx.createLinearGradient(0, top ? 0 : H * 0.3, 0, top ? H * 0.7 : H);
            g.addColorStop(0, top ? shade : 'rgba(0,0,0,0)');
            g.addColorStop(1, top ? 'rgba(0,0,0,0)' : shade);
            ctx.fillStyle = g;
            ctx.fillRect(0, 0, W, H);
        }
    }

    const head = (s) => `${f.weight} ${s}px "${f.family}"`;
    const body = (s) => `${f.bodyWeight} ${s}px "${f.body}"`;
    const strong = (s) => `700 ${s}px "${f.body}"`;
    const onPanel = c.layout === 'split';

    const stack = textStack(
        ctx,
        [
            { text: (c.tagline || '').toUpperCase(), pill: true, font: strong, size: u * 0.026, bg: p.accent, color: p.background },
            { text: c.headline, font: head, size: u * (onPanel ? 0.1 : 0.12), min: u * 0.05, maxLines: 4, lineHeight: 1.05, color: onPanel ? p.text : p.text, shadow: !onPanel, gap: u * 0.035 },
            { text: c.subheadline, font: strong, size: u * 0.042, maxLines: 3, color: p.accent, shadow: !onPanel, gap: u * 0.025 },
            { text: c.body, font: body, size: u * 0.03, maxLines: 4, lineHeight: 1.35, color: p.text, alpha: 0.88, shadow: !onPanel, gap: u * 0.02 },
            { text: c.cta, pill: true, font: strong, size: u * 0.034, bg: p.primary, color: '#ffffff', gap: u * 0.045 },
        ],
        area.w,
    );

    const x = align === 'center' ? area.x + area.w / 2 : area.x;
    let y = area.y + (area.h - stack.height) / 2;
    if (c.layout === 'bottom') y = area.y + area.h - stack.height;
    if (c.layout === 'top') y = area.y;

    stack.draw(x, y, align);
}

/** Which scene is on screen at time t, and how far into it we are. */
export function sceneAt(reel, t) {
    let acc = 0;
    for (let i = 0; i < reel.scenes.length; i++) {
        const d = Number(reel.scenes[i].duration) || 0;
        if (t < acc + d) return { index: i, local: t - acc };
        acc += d;
    }
    const last = reel.scenes.length - 1;
    return { index: last, local: Number(reel.scenes[last]?.duration) || 0 };
}

export function reelDuration(reel) {
    return reel.scenes.reduce((s, sc) => s + Number(sc.duration || 0), 0);
}

const MOTION = {
    'zoom-in': (t) => ({ scale: 1 + 0.15 * t, panX: 0 }),
    'zoom-out': (t) => ({ scale: 1.15 - 0.15 * t, panX: 0 }),
    'pan-left': (t) => ({ scale: 1.18, panX: 0.85 - 1.7 * t }),
    'pan-right': (t) => ({ scale: 1.18, panX: -0.85 + 1.7 * t }),
};

function drawSceneVisual(ctx, scene, img, progress, rect, palette, index) {
    const m = isVideo(img) ? { scale: 1, panX: 0 } : (MOTION[scene.motion] ?? MOTION['zoom-in'])(progress);
    if (img) drawCover(ctx, img, rect, m.scale, m.panX);
    else drawFallback(ctx, rect, palette, index, progress);
}

/**
 * Draw one reel frame at time t (seconds).
 * images: array of HTMLImageElement|null aligned with reel.scenes.
 */
export function drawReelFrame(ctx, reel, images, t) {
    const [W, H] = SIZES['9:16'];
    const rect = { x: 0, y: 0, w: W, h: H };
    const p = reel.palette;
    const f = FONTS[reel.font] ?? FONTS.bold;
    const scenes = reel.scenes;
    const u = W;

    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = p.background;
    ctx.fillRect(0, 0, W, H);
    if (!scenes.length) return;

    let idx = scenes.length - 1;
    let local = Number(scenes[idx].duration);
    let acc = 0;
    for (let i = 0; i < scenes.length; i++) {
        const d = Number(scenes[i].duration);
        if (t < acc + d) {
            idx = i;
            local = t - acc;
            break;
        }
        acc += d;
    }

    const scene = scenes[idx];
    const dur = Number(scene.duration) || 1;
    const fade = 0.4;

    if (idx > 0 && local < fade) {
        drawSceneVisual(ctx, scenes[idx - 1], images[idx - 1], 1, rect, p, idx - 1);
        ctx.save();
        ctx.globalAlpha = local / fade;
        drawSceneVisual(ctx, scene, images[idx], local / dur, rect, p, idx);
        ctx.restore();
    } else {
        drawSceneVisual(ctx, scene, images[idx], local / dur, rect, p, idx);
    }

    // legibility gradient
    const g = ctx.createLinearGradient(0, H * 0.35, 0, H);
    g.addColorStop(0, 'rgba(0,0,0,0)');
    g.addColorStop(1, 'rgba(0,0,0,0.75)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    // story-style progress bars
    const gap = 10;
    const barW = (W - 80 - gap * (scenes.length - 1)) / scenes.length;
    scenes.forEach((_, i) => {
        const x = 40 + i * (barW + gap);
        ctx.fillStyle = 'rgba(255,255,255,0.3)';
        roundRect(ctx, x, 48, barW, 8, 4);
        ctx.fill();
        const fill = i < idx ? 1 : i === idx ? clamp(local / dur) : 0;
        if (fill) {
            ctx.fillStyle = '#ffffff';
            roundRect(ctx, x, 48, Math.max(8, barW * fill), 8, 4);
            ctx.fill();
        }
    });

    // animated captions
    const appear = easeOut(clamp((local - 0.1) / 0.5));
    const leave = clamp((dur - local) / 0.25);
    const alpha = appear * (idx === scenes.length - 1 ? 1 : leave);
    const head = (s) => `${f.weight} ${s}px "${f.family}"`;
    const strong = (s) => `700 ${s}px "${f.body}"`;
    const isHook = idx === 0;

    const stack = textStack(
        ctx,
        [
            { text: scene.text, font: head, size: u * (isHook ? 0.115 : 0.095), min: u * 0.05, maxLines: 5, lineHeight: 1.08, color: p.text, shadow: true },
            { text: scene.subtext, pill: true, font: strong, size: u * 0.038, bg: p.accent, color: p.background, gap: u * 0.04 },
        ],
        W - 140,
    );

    const y = H * 0.62 - stack.height / 2;

    if (isHook && scene.text) {
        ctx.save();
        ctx.globalAlpha = alpha * 0.85;
        ctx.fillStyle = p.primary;
        roundRect(ctx, 40, y - 40, W - 80, stack.height + 80, 36);
        ctx.fill();
        ctx.restore();
    }

    stack.draw(W / 2, y, 'center', alpha, (1 - appear) * 60);
}
