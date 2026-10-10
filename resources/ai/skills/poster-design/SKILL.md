---
name: poster-design
description: How to make a finished, text-free advertising poster image for Instagram / Facebook formats — composition, product hero shots, logo use, colour, and deriving a matching set across sizes. Load for every POSTER job.
---

# Poster design (image-only)

The delivered image is the whole poster. No headline or button is added later, so the picture must sell on its own: one clear subject, a strong idea, brand colours, and the logo when one is given.

## Formats and safe areas
| Format | Aspect generated | Final | Keep the subject in |
|---|---|---|---|
| `feed_portrait` | 4:5 | 1080×1350 | the middle 80% |
| `feed_square` | 1:1 | 1080×1080 | the middle 80% |
| `story` | 9:16 | 1080×1920 | the middle 60% vertically (top/bottom are covered by app UI) |
| `fb_landscape` | 16:9 | 1200×628 (cropped from 16:9 → 1.91:1, a thin band is cut top & bottom) | the middle band |

## Composition patterns
- **Product hero** — the product large, sharp, centred or on a third; clean surface; soft shadow; brand-coloured background or a scene that tells the use.
- **Lifestyle** — the product in use by a person or in its real setting (café table, gym, kitchen); product still clearly readable.
- **Flat lay** — overhead arrangement of the product with props that suggest the story (ingredients, accessories).
- **Event / opening** — the venue or atmosphere (lights, crowd, ribbon, balloons, confetti) with the product or brand colours dominant.
- **Offer** — make abundance or value visible (stacked products, gift wrapping, bundle) instead of writing "-30%".

## Logo
- If a logo is attached, include it via `reference_image_ids` and ask for it **small, clean and unaltered**, e.g. "place the provided logo exactly as given, small, in the top-left corner on a calm area". Check the result: a warped or redrawn logo means regenerate (or leave the logo out rather than ship a broken one).
- Never invent a logo when none is given.

## Colour and mood
- Take the palette from the logo / brand / product packaging. State it in the prompt ("deep navy and warm gold palette").
- Match mood to the business: premium → dark, moody, rim light; fresh/food → bright daylight; youth/sport → saturated, high contrast; family → warm, soft.

## Text
Default: no words in the image. Only when the client explicitly asks for specific words (e.g. a price) put them in, short and exact, and verify spelling on the result. Cyrillic is often rendered poorly — if it comes out wrong, deliver without it.

## A matching set
1. Generate the most important format first (usually `feed_portrait`).
2. Review it. Then for each other format call `generate_image` with the same model, aspect of that format, and the first image (plus logo/product) as references: "same scene and style, recomposed for a vertical 9:16 story".
3. `deliver_poster` each format with its own image.
