---
name: poster-design
description: Visual craft of a finished Instagram / Facebook ad poster — composition with text zones, product hero shots, depth and shadow, logo size and placement, colour contrast for readable type, the archetype recipes (bold retail, premium editorial, lifestyle, 3D playful, graphic, event, food), and deriving a matching set across ratios. Load for every POSTER job.
---

# Poster design

The delivered image is the whole ad: picture **and** message. Design it like a layout, not a photo: a focal point, a reading order (hook → product → facts → action → brand) and calm space where the type will sit.

## Formats and safe areas
Formats are aspect ratios (`4:5`, `1:1`, `9:16`, `16:9` … or `auto`). Generate at the ordered aspect; the file is centre-cropped to it (short side 1080 px). Keep subject and text in the middle 80% — the middle 60% vertically for 9:16, whose top and bottom are covered by app UI. Several ratios, `auto` or unusual ratios → `format-composition`.

## Composition
- **One focal point** — the product or key visual is the largest, sharpest, most contrasted thing.
- **Zones** — decide them in the prompt: e.g. "top 30% calm brand-colour space for the headline; product centred in the middle 45%; clean solid band in the bottom 20% for price and CTA".
- **Depth** — foreground prop slightly out of focus, product sharp, soft background; a real contact shadow under products (no floating unless the style is deliberately playful).
- **Scale** — products should fill 35–60% of the frame height; tiny products read as stock.
- **Contrast for type** — text areas are plain, darker or lighter than the type colour; add a solid panel, band or gradient when the scene is busy.

## Archetype recipes (prompt fragments)
- **Bold retail:** "product cut-out with a crisp drop shadow on a saturated [brand colour] background, a large diagonal colour block, dynamic angle, punchy studio light, high contrast".
- **Premium editorial:** "minimal luxurious set, soft raking light, one accent colour, matte surfaces, vast negative space, magazine-cover feel".
- **Lifestyle + headline:** "real moment of use in [setting], natural light, shallow depth of field, calm sky/wall area in the top third".
- **3D playful:** "glossy 3D render style, floating rounded shapes and props around the product, candy colours, soft studio light, toy-like".
- **Graphic / flat:** "two flat colour fields split diagonally, product sitting on the split line, geometric shapes, clean modern poster".
- **Event key visual:** "atmospheric [scene/symbol], dramatic light, solid [brand colour] band across the bottom fifth for event details".
- **Food close-up:** "appetising macro at 45°, steam, warm side light, dark textured surface, space top-left for a headline".

## Logo
- The logo is placed with **`add_logo`** — the client's own file, pixel-exact (shape, proportions, letters, colours never change; only scaled evenly). Never pass the logo to an image or video model to draw or place it.
- In the visual prompt: "no logos" and leave a calm corner or band where it will sit.
- Size: 12–18% of the width for a symbol, 20–30% for a wordmark (its letters must read on a phone). Position: a corner (`top_left` / `top_right`) or `bottom_center` on the info band — never over the product.
- Background: `remove_background: true` (normal) clears a plain background around the logo file (white JPG etc.) while the logo itself — including its white parts — stays exact. Use `plate: light`/`dark` only when the logo would not stand out from the area behind it.
- Order: visual → `add_text` → `add_logo` (or logo first, then text) — each on the latest image; never send the result back to an image model.
- Never invent a logo when none was given; the brand name may then be a `small` text line.

## Colour
- Palette from the logo / brand / packaging; state it as hex or names. Max 2 main colours + 1 accent; text colour must contrast strongly with its background.
- Mood: premium → dark/neutral + gold or one jewel tone; fresh/food → warm daylight; youth/sport → saturated, high contrast; family → warm, soft; tech → cool with one neon accent.

## Text
Messages, prices, dates and CTAs from the brief go on the poster — follow `poster-typography` (hierarchy, exact strings, two-step method, letter-by-letter check). No invented facts.

## A matching set
1. Make and approve the most important ratio first (the first listed, usually `4:5`).
2. For each other ratio, in **one turn** (they run in parallel): same model, that aspect, the approved **clean visual** (+ product) as references — "same design, scene and typography, re-laid-out for a wide 16:9 frame: product left, text block right".
3. Re-check the text of every ratio; `deliver_poster` each with its own image.
