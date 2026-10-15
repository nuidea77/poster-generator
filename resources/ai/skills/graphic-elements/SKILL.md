---
name: graphic-elements
description: Using icons, patterns, gradient scrims and simple shapes on posters and reel cards — when they help and when they are clutter: `icon` on add_text fact lines (phone, location, clock, calendar, web, mail, delivery, gift, star, check, heart, percent, arrow, leaf), `scrim` gradients so text reads on a photo, decorative patterns (geometric, Mongolian ulzii / khee borders) asked from the image model as calm low-contrast texture, badges and bands. Load for posters and cards with contact/date/place lines, busy photos behind text, or a flat-looking design.
---

# Graphic elements — icons, patterns, gradients

Graphics support the message; they never compete with it. Rule of thumb: **one kind of decoration per poster**, used consistently. If you cannot say what an element does for the reader, leave it out.

## 1. Icons (`icon` on an `add_text` item)
Icons make **fact lines** scannable: the eye finds "where / when / how to order" without reading.

| Line | Icon |
|---|---|
| Phone / booking number | `phone` |
| Address, branch, venue | `location` |
| Opening hours, time | `clock` |
| Date, event day | `calendar` |
| Website, online order | `web` |
| E-mail | `mail` |
| Delivery available | `delivery` |
| Gift / bonus with purchase | `gift` |
| Rating, bestseller | `star` |
| Benefit list (✓ natural, ✓ warranty) | `check` |
| Charity, care, kids | `heart` |
| Discount line (not the hero %) | `percent` |
| CTA ("Захиалах →") | `arrow` |

- Use on **`small` / `subtitle` one-line facts only**. Never on the headline, the display number or the price.
- **All fact lines get an icon, or none do.** Same colour as their text (the tool does this). 2–4 icon facts is the sweet spot; more reads like a form.
- Keep icon facts compact: two short facts share a row (`same_line`; aligns `left` + `right` spread them to the edges, both `center` keeps them together in the middle) — see `poster-typography` §3d. Never a tall centred column of icon lines.
- One fact per line: `Их Дэлгүүр, 3 давхар` with `location`, then `9900-7788` with `phone` — not both on one line with "·".
- Premium / minimal / greeting posters usually look better **without** icons; retail, services, events, clinics, restaurants, hiring benefit from them.
- Left-aligned grids: icons line up on the 7% left edge like bullets. Centred layouts: each icon+line pair is centred.

## 2. Gradient scrim (`scrim` on `add_text`)
A soft gradient from the frame edge (strongest at the edge, gone by mid-frame) so text reads on a photo **without** a hard box.

- `bottom` — facts/CTA at the bottom over a landscape, street, crowd or footage. `top` — headline over a bright sky or busy top. `top_bottom` — both (common on 9:16 Stories and reel cards).
- `scrim_color`: `000000` with white text (default, cinematic); a light tone (`FFFFFF`, cream) with dark text on bright/airy photos; a deep brand tone for a branded feel.
- Prefer a scrim over many `box` panels on photos; prefer a calm area in the visual over both. Don't scrim a flat studio background — it only muddies it.
- On reels a `top_bottom` scrim on the hook clip + end card keeps every caption readable over moving footage.
- If lines sit on a detailed area, a scrim on that zone is often the fix (a scrim or panel keeps text readable but does not stop it hiding the product).

## 3. Patterns (ask the image model, in the visual)
Patterns add identity and texture to flat or minimal designs. They are part of the **visual prompt**, never drawn over text.

- **When:** flat colour backgrounds (sale, holiday, kids, food), cultural / national occasions, brand-colour fields, empty calm zones that feel bare.
- **When not:** behind the product or faces, in photographic scenes, on premium minimal posters, anywhere text sits (keep text zones plain).
- **How to prompt:** "a subtle tone-on-tone geometric pattern (thin diagonal lines / small dots / soft waves) in the background, low contrast, only around the edges, the centre and the top band stay plain for text".
- **Mongolian motifs** (names, meanings and exact prompt wording in `mongolian-culture` → Mongolian ornaments): ulzii (endless knot), khee (traditional border), cloud scrolls, as a thin border or a faint corner ornament — tone-on-tone gold on deep blue/red for Tsagaan Sar, Naadam, national days, anniversaries, culture brands. Respectful use: no motifs on discount junk, no motifs cut by the frame edge mid-shape.
- Brand patterns: when the brand has a shape (leaf, wave, dot grid), echo it as a faint pattern in one corner.
- Image models sometimes turn patterns into letters — add "no text, no letters, no symbols that look like writing".

## 3b. Image direction from strong social templates
- **Colour field + cut-out subject:** a flat or gradient brand-colour field with the subject (product, phone, person) on one side; text gets the other side. Ask: "solid deep-blue background, the phone floating on the right, the left half empty".
- **Shaped photo:** the photo inside a rounded rectangle, an arch or a circle on a colour field ("the photo framed in a tall rounded arch on a white background").
- **Accent swoosh / arc:** one curved band or arc in the accent colour crossing a corner — gives energy without clutter.
- **Glowing 3D objects** for tech, finance, security (coins, shields, phones, padlocks) on a dark blue field with soft light.
- **Person + device:** a smiling person holding the phone with the app or pointing at the empty side where the headline goes.
- Always: "no text, no letters, no logos" — every word comes from add_text.

## 4. Shapes, bands, badges
- **Badge:** one circle/pill for `-30%` / `ШИНЭ` — via `box` on the price item, or a circle shape requested in the visual. One badge per poster.
- **Lines and dividers:** in the visual ("a thin gold line divides the headline from the facts") — sparingly.
- Accent shapes (blobs, arcs, confetti) only in playful/kids/food archetypes.

## 5. Combinations that work
| Poster | Recipe |
|---|---|
| Retail sale | photo + `bottom` scrim + display `-20%` + 3 icon facts (`location`, `phone`, `delivery`) |
| Clinic / service | clean light visual + icon facts (`clock`, `phone`, `location`) |
| Event / opening | dark visual + `top_bottom` scrim + `calendar`, `clock`, `location` lines |
| Holiday / national | flat brand colour + faint ulzii/khee border pattern, no icons |
| Premium beauty | soft photo, no pattern, no icons, at most a light scrim |
| Kids / food | playful pattern of dots/shapes at the edges, `check` benefit lines |

## 6. Check
After add_text, look: icons the same size and colour as their lines, nothing decorative under the headline or over the product, pattern faint enough that text stays the loudest thing, one decoration language for the whole set (same choices in every ratio).
