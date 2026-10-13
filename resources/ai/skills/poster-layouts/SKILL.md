---
name: poster-layouts
description: Proven layouts from professional Mongolian ad posters (clinics, telecom, retail) and how to build them with add_text — pill badges and chips, frosted glass cards, CTA bars with an icon, benefit checklists, rows of 3–4 steps / services / gifts, a footer info bar (address left, phone right), product callout pills, minimal premium layouts with lots of air. Load for service, clinic, telecom, promo, giveaway and any poster with several offers, steps or benefits.
---

# Poster layouts that work

Studied from professional Mongolian posters (a clinic package, a telecom promo, a giveaway). What they share: **one huge headline, lots of air, one accent colour used only for pills and key numbers, and every secondary fact in a small, tidy element** — never a long centred column of text.

## 1. Building blocks (all with `add_text`)
| Element | How | Use for |
|---|---|---|
| **Pill badge** | `box: true`, `box_shape: pill`, accent `box_color`, white bold text | `30% OFF`, `ШИНЭ`, `-20%`, a short CTA |
| **CTA bar with icon** | pill + `icon` (calendar, gift, phone, arrow) — the icon sits inside the pill | `Зөвхөн 3 ХОНОГ үргэлжилнэ`, `Захиалах 7711-5566` |
| **Glass chip** | `box_shape: glass`, dark text, small | labels above values (`СУПЕР БЭЛГИЙН ЭЗЭН`), app steps, tags |
| **Two-column stat** | chip row (2 items, `same_line`) over a value row (2 items, `same_line`) | prize vs winners, before vs after, two plans |
| **Row of 3–4** | `small` bold items, the 2nd–4th with `same_line: true` → equal columns, one size | services (`ЭХО · РЕНТГЕН · ШИНЖИЛГЭЭ · ҮЗЛЭГ`), gifts, steps, features |
| **Step flow** | 2–3 glass chips in a row, last one an accent pill with an icon | `Unitel app` → `Багц шилжих` → `Бэлэг авах` |
| **Checklist** | 3–4 `small` lines, `icon: check`, `align: left` | benefits of a service or package |
| **Footer info bar** | `small` address with `location`, `align: left`; phone `subtitle` bold with `phone`, `same_line`, `align: right`; `scrim: bottom` or a band in the visual | contact on every service poster |

Rows of 3–4 work best when the **visual already has matching spots** (round photo insets, product tiles, icons in a row) — ask the image model for "a row of four round photo insets evenly spaced across the lower third, empty below each for a caption", then put the captions in `lower_third` / `bottom` as one row. Without matching spots, a row of words alone is still clean.

## 2. Layout recipes
**Clinic / service package (Medica style)** — 4:5
1. Visual: a clean medical hero (3D anatomy render, device, doctor) in the top half on a soft light-blue/white gradient; a row of 4 round photo insets in the lower third; a solid teal band at the very bottom.
2. Text: pill badge `30% OFF` (accent) → huge `title` (condensed, brand teal) `ӨВДӨГНИЙ БАГЦ` → `subtitle` `ҮЗЛЭГ, ОНОШИЛГОО` → 3–4 checklist lines → captions row under the insets → footer bar (address left, phone right, bold).
3. Logo: top-right on a light plate (the logo-placement skill).

**Telecom / promo, minimal (WIN BIG style)** — 1:1
1. Visual: plain light-grey gradient, maybe a frosted rounded card shape and product renders in tiles; nothing else.
2. Text: huge `display`/`title` hook (2 words) → two-column stat (glass chips + bold values) → accent CTA pill with `calendar` → section title → captions row under the tiles.
3. One accent colour (the brand's) for every pill; everything else black/grey.

**Product giveaway (GIVEAWAY style)** — 4:5
1. Visual: the product large and floating, soft abstract swirl background, white lower third.
2. Text: huge thin-geometric `title` under the product → 2-line `subtitle` (how to win) → step flow of chips at the bottom left + date/time (`10.15 | 17:00 цагт`) on the right as a `same_line` pair.
3. A small glass chip with `gift` naming the product (`Apple Watch SE`) in the zone beside the product.

## 3. Rules from these posters
- **One accent colour**, used only for pills, badges and the key number. Text is black/dark grey on light, white on dark.
- **Headline 2–3 words, huge** (title or display); everything else ≤ 1/3 of its size.
- **Air:** at least a third of the poster is calm background. Don't fill every zone.
- **Facts are elements, not sentences:** chips, pills, rows, a checklist — 1–5 words each.
- **Pills hold one short phrase** (≤ 25 characters); a long sentence in a pill looks like a button bar gone wrong.
- **Same element, same style:** all chips glass, all CTAs accent pills, all captions bold small; same in every ratio.
- **Mixed emphasis** ("Зөвхөн **3 ХОНОГ** үргэлжилнэ") is not possible inside one item — make the key part its own item (pill or bold) or keep the line uniform.
- Image models must **not** draw the chips, pills or text themselves — ask for empty shapes or nothing, then add_text.
