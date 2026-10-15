---
name: poster-styles
description: Named poster style directions with exact recipes — Minimal Editorial, Soft Structuralism, Editorial Warmth, Ethereal Tech, Swiss Industrial, Tactical Dark, Colour-field Tech, Generative Abstract, Neo-Brutalism, Acid / Psychedelic, Retro & Y2K and 3D Surreal — each with when to use, palette, visual prompt wording, add_text settings (fonts, roles, shapes) and what to avoid. Load after the design read (poster-taste) when choosing the look of a poster or set.
origin: Adapted from "minimalist-ui", "high-end-visual-design" and "industrial-brutalist-ui" in Leonxlnx/taste-skill (MIT, see LICENSE.txt); the generative section draws on ideas from Anthropic's "algorithmic-art" skill (Apache-2.0) — web/CSS and p5.js rules translated to image prompts + add_text
---

# Poster styles

Pick **one** style per poster set and commit to it; never mix two. The brand's own colours and logo override the palette suggestions.

| Style | Best for | Variance / density / energy |
|---|---|---|
| Minimal Editorial | premium products, architecture, consulting, books, galleries | 4 / 2 / 3 |
| Soft Structuralism | clinics, wellness, finance, insurance, education | 4 / 4 / 4 |
| Editorial Warmth | cafés, bakeries, real estate, lifestyle, fashion | 5 / 3 / 4 |
| Ethereal Tech | apps, AI, fintech, telecom, crypto, SaaS | 6 / 3 / 6 |
| Colour-field Tech | agencies, apps, promos for young audiences | 7 / 5 / 8 |
| Swiss Industrial | events, conferences, architecture, bold campaigns | 8 / 4 / 7 |
| Tactical Dark | gaming, tech launches, security, sports | 7 / 5 / 7 |
| Generative Abstract | announcements without a product photo, abstract brands, music, art | 6 / 2 / 6 |
| Neo-Brutalism | youth brands, streetwear, startups, bold sales | 9 / 5 / 9 |
| Acid / Psychedelic | music festivals, parties, clubs, concerts | 9 / 4 / 10 |
| Retro & Y2K | fashion drops, cafés, music, nostalgia campaigns | 7 / 4 / 7 |
| 3D Surreal | tech launches, apps, beauty, futuristic products | 7 / 2 / 7 |

## Minimal Editorial
- **Palette:** off-white `F7F6F3` or white canvas, charcoal `2F3437` text (never pure black), muted grey `787774`; one washed pastel for tags (pale blue `E1F3FE` / text `1F6C9F`, pale green `EDF3EC` / `346538`).
- **Visual prompt:** "a single object photographed on a plain warm-white background, soft natural daylight, very large empty space around it, desaturated warm tones, subtle paper grain". No gradients, no glow.
- **add_text:** headline `title` in `serif` (editorial) or `bold_sans`, charcoal; secondary `sans` muted grey; tags as small `glass` or pale `pill`; CTA a dark `rect` box (`box_color 111111`, white text); contacts `open` in small charcoal type. Left alignment, lots of air (density 2).
- **Avoid:** big colour fields, pill-shaped large containers, emoji, gradients, 3D glass.

## Soft Structuralism
- **Palette:** white / silver-grey canvas, one calm brand colour (teal, cobalt, green) + one soft accent.
- **Visual prompt:** "clean bright scene, soft diffused light, floating subject with a very soft ambient shadow, airy, white and light-grey background, generous empty space top and bottom".
- **add_text:** massive bold `bold_sans` headline (two-tone with the brand colour), kicker `glass` chip, checklist with `check` icons, CTA `pill` with `arrow`.
- **Avoid:** harsh shadows, busy photos behind text, more than one accent.

## Editorial Warmth
- **Palette (rotate, not always beige):** forest + bone + amber; terracotta + slate; black + tan; olive + brick + paper.
- **Visual prompt:** "warm natural light, real textures (wood, linen, ceramic), shallow depth of field, a calm plain area of wall or table for text, film-like grain".
- **add_text:** `serif` or `bold_sans` headline in deep brown/near-black (never cream on beige), accent pill for price/offer.
- **Avoid:** the default beige + brass + espresso combination unless the brand is truly that.

## Ethereal Tech
- **Palette:** near-black `050505` / deep navy, one glowing accent (emerald, cyan, violet used with intent).
- **Visual prompt:** "deep dark background with one or two soft radial glows of colour, a glossy 3D object or phone floating with rim light, subtle depth, clean negative space for text".
- **add_text:** white `bold_sans` headline with a starred accent word; `glass` kicker chip; CTA `pill` in the accent with `arrow`.
- **Avoid:** purple glow by default, gradient text, many orbs.

## Colour-field Tech
- **Palette:** a saturated brand field (royal blue, violet, green) + a contrasting accent (orange, lime, yellow) — the international social-template look.
- **Visual prompt:** "solid {brand colour} background with a soft gradient, the product / phone / smiling person cut out on one side, one curved accent swoosh in {accent} crossing a corner, the other side empty".
- **add_text:** white two-tone headline (accent word), feature pills, CTA accent `pill` with `arrow`.
- **Avoid:** contacts and CTA in the same colour; three accents.

## Swiss Industrial
- **Palette:** newsprint off-white `F4F4F0`, carbon ink `111111`, **one** hazard red `E61919`.
- **Visual prompt:** "flat off-white paper background with a strict grid of thin black rules, one bold black-and-white photo cropped hard into a rectangle, halftone texture, no gradients".
- **add_text:** huge uppercase `condensed` or `bold_sans` headline left-aligned, tight lines; numbers as `display`; small uppercase facts; red only for one element (a box, the key number); square `rect` boxes only — no pills, no rounded cards.
- **Avoid:** rounded shapes, soft shadows, more than one accent, mixing light and dark modes.

## Tactical Dark
- **Palette:** off-black `0A0A0A`, white-phosphor `EAEAEA` text, red `E61919` accent (optional single green status).
- **Visual prompt:** "dark technical scene, scanline / grain texture, thin HUD-like lines and crosshairs as decoration without any letters or numbers, one dramatic subject".
- **add_text:** uppercase `condensed` headline, `sans` small facts with generous spacing via short strings, square boxes.
- **Avoid:** fake readable data, model-drawn numbers, pastel colours.

## Generative Abstract
For posters without a product photo: the visual is an original generative-art-like image the image model paints from a clear "algorithmic" idea.
- **Prompt ideas:** "flowing field of thousands of fine lines following a smooth noise field, {two brand colours} on {background}, calm and dense in one half, sparse in the other"; "particles drifting into a spiral, soft depth of field"; "concentric ripples in a grid of dots, one area disturbed"; "layered translucent waves of {colour}". State "abstract, no text, no letters, no symbols".
- **add_text:** the headline over the sparse half; few items (density 2–3).
- **Avoid:** copying a known artist's style; busy texture under the text.


## Neo-Brutalism
- **Palette:** flat saturated blocks (acid yellow `F5E50B`, hot red `FF3B30`, cobalt `2547F4`) on off-white, pure black `000000` for type and outlines.
- **Visual prompt:** "flat bold colour blocks, hard black outlines around the product, hard drop shadow offset down-right with no blur, raw collage cut-outs, deliberately unpolished, high contrast, no gradients".
- **add_text:** huge `condensed` caps headline, black on a flat colour; `rect` boxes with black text; everything left on a hard grid; one sticker-like `pill` at most.
- **Avoid:** soft shadows, glass, pastel, rounded friendliness.

## Acid / Psychedelic
- **Palette:** clashing neons (acid green `B6FF00`, magenta `FF2BD6`, electric blue `2E5BFF`) on black, or a liquid chrome gradient.
- **Visual prompt:** "warped liquid shapes, melting chrome and iridescent swirls, 60s–70s psychedelic curves, grainy, vivid saturated colours, the centre left calm and dark for the title".
- **add_text:** massive `condensed` or `rounded` headline in white or one neon on the calm dark centre; date and venue small `sans`; no boxes.
- **Avoid:** using it for clinics, finance, food — and more than one neon behind text.

## Retro & Y2K
- **Palette:** faded cream, tomato red, mustard, teal (retro) — or silver, baby blue, lilac, chrome (Y2K).
- **Visual prompt (retro):** "70s print feel, halftone dots, paper grain, faded warm colours, rounded sunburst behind the product". **(Y2K):** "early-2000s digital look, glossy chrome and translucent plastic shapes, soft star sparkles, lilac and baby-blue gradient sky".
- **add_text:** `rounded` or `bold_sans` headline, two-tone; small `pill` tags; retro → cream type on red, Y2K → white type with a dark scrim.
- **Avoid:** mixing the two eras; clean minimal layouts with a retro filter only.

## 3D Surreal
- **Palette:** soft gradient field (peach→lilac, mint→sky) or deep night blue, one glossy accent.
- **Visual prompt:** "surreal 3D render, the product floating among abstract glossy shapes (spheres, arches, stairs), soft studio light, dreamlike impossible scale, clean background with a calm area for the headline".
- **add_text:** white or deep-navy `bold_sans` headline over the calm area; few items (density 2–3); one `glass` chip.
- **Avoid:** busy shapes behind the text, more than one accent.

## After choosing
Write the chosen style in the design read note, keep it for every ratio, and check the result with `poster-critique`.
