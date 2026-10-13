---
name: poster-typography
description: Putting the message on the poster — headline, offer badge, price, date/time/place, call to action and the brand line set in real fonts with `add_text` (exact Mongolian letters, never misspelled) on a text-free visual: hierarchy, offer and price, layout zones per poster type, fonts and colours by mood, two-tone headlines, hero numbers, pills and rows, the fact block, the add_text parameters, checking and fixing. Load for every POSTER job whose brief contains any words, numbers, price, discount, date, place or call to action.
---

# Poster typography

A poster without its message is just a product photo. When the brief gives words or numbers — price, discount, dates, place, a product name, a slogan, a phone number — they belong on the poster.

**Image models misspell Mongolian** (Ө/Ү/Э/Е, ₮). The poster's words are **set with `add_text`** in real fonts on a visual generated **without any text**. Never ask an image model to write words, and never describe fonts in the image prompt — the type comes only from add_text.

Related: `poster-layouts` (pills, cards, rows, recipes), `graphic-elements` (icons, scrims), `poster-taste` (what makes it look generic), `mongolian-ad-copy` (wording).

## 1. Hierarchy first
| Level | Content | Role | Example |
|---|---|---|---|
| 1. Hook | The offer or headline — read first | `display` / `title` | `-18%`, `ШИНЭ`, `ЗУНЫ ХЯМДРАЛ`, `НЭЭЛТ` |
| 2. Support | Product name or one short line | `subtitle` | `UV ариутгагч`, `Шинэ серум` |
| 3. Offer & price | Price, old → new, the offer badge | `price` (pill) | `150,000₮ → 123,000₮`, `2 ДАХЬ АЯГА 50% ХЯМД` |
| 4. Action | CTA | `subtitle` pill with `arrow` | `Захиалах →` |
| 5. Facts | date, time, place, phone, web | `footer` (`small`, phone `subtitle` bold) | |
| 6. Brand | the logo with `add_logo` | — | see `logo-placement` |

- **Max ~12 words** outside the footer; headline ≤ 4 words (two lines of `title` beat one cramped line); one hook only.
- **Above the headline at most one small line** (the brand *or* a kicker chip), never two stacked labels.
- **The offer is never a footnote.** An offer ("2 дахь нь 50% хямд", 1+1, -30%, gift, free delivery for a period) is the hook or an accent pill right under it, **at least as big as the price**. A long offer pill goes under the price pill (same role and colour); side by side (`same_line`) only when both are short (≤ 12 characters).
- **Never invent** prices, dates, phones, % or claims. Short headlines you may write yourself when the brief implies them (sale → `ХЯМДРАЛ`, opening → `НЭЭЛТ`, new → `ШИНЭ`, greeting → the occasion). Compute derived prices exactly (150,000₮ − 18% = 123,000₮).

## 2. Layout by poster type (zones keep text off the subject)
| Poster type | Layout |
|---|---|
| **Sale / promo** | `display` `-18%` or a big badge in the top third; product hero in the middle; price pill(s); CTA; footer |
| **New product / launch** | product large; `ШИНЭ` kicker + product name headline in the top band; one benefit line; CTA |
| **Event / opening** | event name in the top third; key visual; date · time · place in the footer (row of 3) |
| **Greeting / holiday** | occasion name over calm space; symbolic still life (`mongolian-culture`); brand small at the bottom |
| **Anniversary / milestone** | the number is the poster (§4) |
| **Hiring** | `АЖИЛД УРЬЖ БАЙНА`; position; 2–3 short lines; contact footer |
| **Menu / price list** | hero dish; 3–5 items with prices on a calm panel (`card`) |
| **Service / clinic / agency** | see `poster-layouts` recipes (checklist, chips, CTA, footer) |

Keep text inside the safe area (`format-composition`), never over faces, the product or busy texture.

## 3. Type, colour and craft
**Fonts** (`font`): `bold_sans` (Montserrat ExtraBold — default headlines, retail, prices), `sans` (Montserrat Medium — secondary lines), `condensed` (Oswald — sport, bold offers, big numbers), `rounded` (Nunito — kids, food, friendly), `serif` (Playfair Display — only for genuinely editorial / luxury / heritage / greeting work; no ₮ glyph and old-style digits, so prices and digits switch automatically). Max two fonts per poster; the same fonts in every ratio.

By mood: **bold retail** — heavy sans or condensed, caps, high contrast, one strong badge. **Premium / beauty** — sans or serif headline, small sizes, lots of air, one muted accent. **Fresh / food** — rounded or bold sans, warm colours. **Corporate / clinic / event** — clean sans, left-aligned grid, colour bands. **Kids / playful** — rounded, bright, sticker-like pills.

Craft:
- **Scale:** hook ≈ 3× the facts; one element clearly dominates; never two equally big lines.
- **Alignment:** one axis — all centred (events, greetings, short headlines) or all left on the 7% edge (retail, corporate, long headlines). Never mix within a zone.
- **Line length:** ≤ 18 characters per headline line on 9:16 / 4:5, ≤ 28 on 16:9; break with `\n` at word boundaries, balanced. Letter-spacing cannot be set — air comes from size and margins.
- **Colour:** one brand colour + one accent; the accent marks only the offer, the price pill, the CTA and the starred word. The headline is the most contrasted thing: deep brown / near-black on light warm photos, white on dark. Pastel or cream text on a beige photo reads weak — add_text measures contrast and warns.
- **Legibility on photos:** calm area first; else a `scrim`, a band or a panel.

### Two-tone headline
Wrap **one** key word in stars and pass `accent_color`: `Мөрөөдлөө *бодит* болго`, `Таны бизнес *харагдахгүй* байна уу?`, `*15* ЖИЛИЙН ОЙ`. Same font, only the colour changes; never star a whole line; at most two starred runs on a long headline. The accent word must contrast as strongly as the rest: on light backgrounds a deep accent (burnt orange `C2410C`, deep red, teal, cobalt), on dark ones a bright one (amber, lime, yellow) — yellow on cream disappears (add_text warns).

## 4. A number as the hero (anniversary, %, year, count)
The number alone in a `display` item (about twice a title), the word under it as its own item: `15` → `ЖИЛИЙН ОЙ` → name → wish. Digits in `condensed` or `bold_sans`; the number takes the accent colour (gold for anniversaries), no box; centred in the calmest large area, ~25–35% of the frame height; the visual must leave that space. One display item per poster.

## 5. Pills, cards, rows, facts
- `box: true` + `box_shape`: `pill` (accent badge, CTA), `glass` (frosted chip, labels), `card` (rounded content card for a short paragraph on a photo), `rect` (square panel). The CTA uses `icon: arrow` (it trails the text).
- Rows: the 2nd–4th item of a row gets `same_line: true`. A pair sits on the two text edges; 3–4 items share equal columns with one size.
- Icons before one-line facts: one fact per item, never several facts joined by `·` behind one icon.
- **Contact facts go in the footer** (`position: footer`); its design is free per poster.

## 6. The add_text call
1. **Visual** — generated with no text, with calm zones matching the layout ("the top third is calm empty sky for the headline; the bottom 15% is a plain surface"). End the prompt with "No text, no letters, no numbers, no logos."
2. **Items** in reading order: `text`, `role`, `position` (`top`, `center`, `lower_third`, `bottom`, `footer`), `align`, `same_line`, `font`, `color`, `box`, `box_color`, `box_shape`, `icon`, `icon_style`; on images `start`/`end`/`animation` = 0, 0, `none`. Call-level: `scrim`, `scrim_color`, `footer_style`, `footer_color`.
3. **Read the returned poster and its warnings** (detailed area under a line, low contrast, footer layout): treat them as true unless clearly intended. Fix by calling `add_text` again **on the same clean visual** (never on the texted image).
4. Deliver the texted image. Several ratios: each ratio's clean visual, then add_text on each, same choices.

Logos are never typed or drawn — `add_logo` with the client's file (`logo-placement` for order and corner). Without a logo, the brand name may be a `small` line.

## 7. Check and fix
- Before the call: every string against the brief (names, digits, ₮, %, Ө/Ү).
- After: one element clearly biggest, nothing over the product or a face, margins clear, readable at phone size, no model-drawn letters in the visual.
- **Fit the text to the visual, not the visual to the text.** Regenerate a visual for space at most once; then adapt the type: fewer lines (≤ 6 items + footer), shorter strings, smaller roles, a scrim, another zone.
- Too many words → cut to hook, offer, CTA, footer; details go to the caption. A long headline shrinks → two lines or a shorter synonym (`mongolian-ad-copy`).
