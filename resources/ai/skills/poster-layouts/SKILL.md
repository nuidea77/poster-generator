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
| **Two-column stat** | chip row: 2 `small` glass chips (`same_line`) — the **labels**; under it a value row: 2 `subtitle`/`title` bold values (`same_line`) — the **values are bigger than the labels** | prize vs winners, before vs after, two plans |
| **Row of 3–4** | `small` bold items, the 2nd–4th with `same_line: true` → equal columns, one size | services (`ЭХО · РЕНТГЕН · ШИНЖИЛГЭЭ · ҮЗЛЭГ`), gifts, steps, features |
| **Step flow** | 2–3 glass chips in a row, last one an accent pill with an icon | `Unitel app` → `Багц шилжих` → `Бэлэг авах` |
| **Checklist** | 3–4 `small` lines, `icon: check`, `align: left` | benefits of a service or package |
| **Footer info bar** | `footer_style: band` + `footer_color`: address left, phone bold right, a second row for hours/web | one option among open / band / pill |

Rows of 3–4 work best when the **visual already has matching spots** (round photo insets, product tiles, icons in a row) — ask the image model for "a row of four round photo insets evenly spaced across the lower third, empty below each for a caption", then put the captions in `lower_third` / `bottom` as one row. Without matching spots, a row of words alone is still clean.

## 1b. More building blocks (from international social templates)
| Element | How | Use for |
|---|---|---|
| **Two-tone headline** | wrap the key word in stars and set `accent_color`: `Таны бизнес *харагдахгүй* байна уу?`, `Мөрөөдлөө *бодит* болго`, `*Шинэ* цуглуулга` | almost every headline: one accent word — the benefit, the emotion or the product name |
| **Kicker chip** | small pill/glass above the headline: `Шинэ үйлчилгээ`, `Зөвлөгөө`, `Урамшуулал` | tells what kind of post it is before the headline |
| **Header row** | `top`, `small`: brand name left, category right (`same_line`) | series of posts with one look |
| **Content card** | `box: true`, `box_shape: card`, white (or brand) `box_color`, dark text, 1–3 lines | a short paragraph, quote or question on a photo |
| **CTA with arrow** | pill + `icon: arrow` (the arrow trails the text): `Захиалах →`, `Бүртгүүлэх →`, `Дэлгэрэнгүй →` | the action, at the bottom of the content, above the footer |
| **Feature pills** | 3 stacked `small` pills/glass with `icon: check` (or numbers `01` `02` `03` as separate display items) | benefits, steps, what is included |
| **Big stat** | `display` number (`76%`, `4.9★`, `10,000+`) + a `small` label under it | trust, results |
| **Contact strip** | `footer_style: pill` — a rounded bar inset from the edges; one row of 3: web · social · phone | another option; or `open`: plain facts straight on the picture |

## 2. Layout recipes
**Clinic / service package (Medica style)** — 4:5
1. Visual: a clean medical hero (3D anatomy render, device, doctor) in the top half on a soft light-blue/white gradient; a row of 4 round photo insets in the lower third; a calm foot for the contacts.
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

**Tech / fintech / agency (international template style)** — 4:5
1. Visual: brand-colour field (deep blue, violet) with a glowing 3D object or the product (phone with the app, a laptop, a card) floating, a soft light on one side; or a person holding the phone; lots of empty colour field.
2. Text: kicker chip → two-tone headline (one accent word) → 1–2 line `small` subtitle → CTA → contacts in the footer.

**Question / problem-solution post** — 4:5
1. Visual: the problem shown simply (an empty shop, a confused person, a cluttered desk) or a clean colour field.
2. Text: question headline with the accent on the pain word (`Таны бизнес *харагдахгүй* байна уу?`) → a content card with the answer in one sentence → feature pills (3 checks) → CTA pill → contacts.

## 3. Rules from these posters
- **One accent colour**, used only for pills, badges, the key number and the one starred word of the headline. Text is black/dark grey on light, white on dark.
- **A two-colour system**: one brand colour for fields and bands (blue, deep green, violet) + one contrasting accent (orange, yellow, lime) — stated in the visual prompt ("deep royal-blue background with a curved orange accent swoosh in one corner") and reused in add_text (`accent_color`, pill `box_color`).
- **Brand thread, not a template:** keep the brand's colours and fonts recognisable across posts, but vary the layout, picture and footer from post to post (`poster-design` Variety).
- **Headline 2–3 words, huge** (title or display); everything else ≤ 1/3 of its size.
- **Air:** at least a third of the poster is calm background. Don't fill every zone.
- **Facts are elements, not sentences:** chips, pills, rows, a checklist — 1–5 words each.
- **Pills and chips hold one short phrase** (≤ 25 characters). A sentence ("Дараа төлбөрт багцаа ахиулаад хожих боломж") is a plain `subtitle`, never a glass/pill.
- **Panels never cover the product.** A glass chip or pill on the phone/bottle/face hides it as much as plain text does — add_text warns about it; move the element to a calm zone.
- **Badges that sit together match:** a price pill and an offer pill in one row share the role (both `price` or both `subtitle`) and the accent colour family — the offer is never a small pale chip beside a big bold price.
- **Steps are steps:** a step flow holds only the actions in order (`Апп` → `Багц шилжих`); a deadline or date is its own element (a CTA pill or a fact), not the last step.
- **Same element, same style:** all chips glass, all CTAs accent pills, all captions bold small; same in every ratio.
- **Mixed emphasis** ("Зөвхөн **3 ХОНОГ** үргэлжилнэ") is not possible inside one item — make the key part its own item (pill or bold) or keep the line uniform.
- Image models must **not** draw the chips, pills or text themselves — ask for empty shapes or nothing, then add_text.
