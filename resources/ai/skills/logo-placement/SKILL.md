---
name: logo-placement
description: Where and how big to put the client's logo with add_logo on posters and reels — reading the logo type (symbol, wordmark, combination, badge), size by type and format, choosing the corner so it never collides with the headline or the product, aligning it to the text grid, clear space, contrast and background removal, keeping it identical across ratios, reel end cards, co-branding, and the after-check. Load whenever a logo is attached.
---

# Logo placement

The logo **signs** the ad; it is not the headline. It goes on with `add_logo` — the client's own file, pixel-exact, only scaled evenly — never drawn by a model.

Order: on posters usually `add_text` then `add_logo` (pick a corner away from the headline). When the logo shares a zone with text (reel end cards, a centred logo above a title) do **`add_logo` first, then `add_text`** — text added afterwards is moved below the logo automatically. Never pad text with empty lines to push it around.

## 1. Read the logo first
Look at the attached file and name its type:
| Type | Looks like | Reads best |
|---|---|---|
| **Symbol** | an icon / mark only | small, a corner |
| **Wordmark** | the name in letters | wider, so the letters read on a phone |
| **Combination** | icon + name side by side or stacked | as a wordmark (letters decide the size) |
| **Badge / seal** | round or square emblem with text inside | medium; its inner text must stay legible |

Also note: is it **dark or light**, does the file have a **plain background** (white JPG) or transparency.

## 2. Size (`width` = share of the frame width)
| Type | Poster 1:1 / 4:5 / 3:4 | 9:16 Story / reel | 16:9 and wider | End card (reel) |
|---|---|---|---|---|
| Symbol | 0.10–0.14 | 0.14–0.18 | 0.07–0.10 | 0.30–0.38 |
| Wordmark / combination | 0.20–0.28 | 0.28–0.36 | 0.14–0.20 | 0.40 |
| Badge / seal | 0.16–0.22 | 0.22–0.28 | 0.10–0.14 | 0.35–0.40 |

Smaller than this and the letters turn to mush on a phone; bigger and it competes with the headline. When the brief is about the brand itself (opening, greeting, brand launch) use the upper end; for a product/price offer the lower end.

## 3. Position — never in the headline's zone
Pick the corner from the layout you chose in `poster-typography`:
| Layout | Headline zone | Logo |
|---|---|---|
| Headline **top, left-aligned** | top band | `bottom_left` (same left edge as the text) or `bottom_right` |
| Headline **top, centred** | top band | `bottom_center` (on/above the info band) |
| Headline **centre / lower third** | middle | `top_left` (left-aligned text) or `top_center` (centred text) |
| Price / facts band at the bottom | bottom band | `top_left` / `top_center` / `top_right` |
| Product fills the bottom half | — | a top corner on the calm side |
| Product is on one side | — | the opposite corner from the product |

- Same alignment as the text: left-aligned text → a `_left` corner (the logo shares the text's 7% left edge); centred text → `_center`.
- Never over the product, a face, the headline, the price, or a busy texture.
- Keep it inside the safe area; on 9:16 the tool already keeps clear of the app UI.

## 4. Clear space and contrast
- Clear space around it ≥ the height of its letters — nothing touches it.
- `remove_background: true` (normal) clears a white/plain file background; the logo's own white parts stay.
- Contrast: a dark logo on a light, calm area or a light logo on a dark area. If the area behind it is mid-tone or busy, choose another corner first; only then use `plate` (`light` for dark logos, `dark` for light logos).
- The plate follows the logo's shape: a round badge gets a circle (it reads as the badge's own outer ring), a wordmark a rounded card. A dark badge on a dark night/sky poster → `light` plate is the right call; never leave a dark logo invisible on a dark area.
- Never recolour, outline, rotate, crop, stretch, add shadows or effects — the tool forbids it and so do brand rules.

## 5. Every ratio the same
In a set (4:5 + 9:16 + 16:9 …) keep the same corner and the same visual size relative to the frame (use the table per format), so the set looks like one campaign.

## 6. Reels
- Not in the hook (the first seconds belong to the product/idea); usually only on the **end card**, `center` or `top_center` at end-card size, with the CTA text below it.
- Optionally a small corner logo (symbol size) on the hero clip; never on every clip.
- End card order: clean still → `animate_still` → `add_logo` → `add_text` (the text stacks under the logo by itself).

## 7. Two logos (co-branding, sponsors)
Only when the brief asks: same visual height, side by side in one band (`bottom_center` area), the client's logo first/left. If only one file was given, the partner's name goes as a `small` text line.

## 8. Check after placing
Look at the returned image: letters readable at phone size, nothing overlapped, the corner matches the text grid, the logo is the only brand mark (the visual must contain no model-drawn logo or letters). Wrong corner or size → call `add_logo` again on the **pre-logo** image, not on top of the placed one.
