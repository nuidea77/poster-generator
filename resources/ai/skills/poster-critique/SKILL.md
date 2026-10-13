---
name: poster-critique
description: A structured self-critique of a finished poster before delivering — 2-second first impression, message and offer, visual hierarchy and reading order, consistency (one accent, two fonts, same choices across ratios), legibility and contrast at phone size, cultural/brand correctness — with severity and a priority fix list, and when to fix vs deliver. Load at the review step of every poster job (after add_text / add_logo).
origin: Adapted from Anthropic's "design-critique" skill in anthropics/knowledge-work-plugins (Apache-2.0, see LICENSE.txt) — the UI critique framework applied to ad posters
---

# Poster critique

Look at the returned poster the way a customer scrolling Instagram would, then like a senior designer. Write the critique as a short note; fix the top issues; deliver.

## 1. First impression (2 seconds)
- What draws the eye first? It must be the hook (headline, offer or hero number) or the product — never a footer, a logo or a decoration.
- Is the purpose clear in 2 seconds (what is offered, by whom)?
- Emotional read: does it match the design read (`poster-taste`)?

## 2. Message and offer
- Every brief fact present, spelled exactly (Ө/Ү, ₮, digits), nothing invented.
- The offer is prominent (badge or hook), the CTA is visible, the contact is findable.

## 3. Visual hierarchy
- Reading order: hook → offer/price → CTA → facts. One clearly dominant element; no two equally loud ones.
- Whitespace: text has room; nothing touches an edge; the product is not covered (add_text warnings).
- Typography: ≤ 2 fonts, sizes in clear steps, line breaks balanced.

## 4. Consistency
- One brand colour + one accent; the accent only on offer / CTA / starred word / key icons.
- Same panel style for the same job (all chips glass, all CTAs pills); the footer style matches the brand's series.
- Several ratios: the same choices in each.

## 5. Legibility and correctness
- Contrast: headline strongest; no pale text on pale areas (add_text contrast warning).
- Phone-size test: the smallest fact still readable (≥ `small`, not shrunk — footer warning).
- Image: no model-drawn letters/logos, faces and hands natural, product true to the reference, culture correct (`mongolian-culture`).

## 6. Severity and fixes
| Severity | Examples | Action |
|---|---|---|
| **Critical** | misspelling, wrong price/date/phone, text over the product or a face, illegible headline, model-drawn fake text, wrong cultural item | fix before delivering |
| **Moderate** | weak offer, two loud elements, footer crowded or shrunk, accent overused, pale headline | fix if one add_text call can do it |
| **Minor** | spacing a bit uneven, a slightly long line | note it; deliver |

Write the note as: **Overall** (one line: what works, the biggest opportunity) → **Fixes** (≤ 3, most impactful first, each with the concrete add_text change) → **Works well** (one line). Then make the critical/moderate fixes — on the clean visual, in one add_text call — and deliver. Do not loop more than twice on the same poster; budget and time matter more than perfection.
