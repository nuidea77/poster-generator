---
name: poster-typography
description: Putting the message on the poster — headline, offer badge, price, date/time/place, call to action and the brand line set in real fonts with `add_text` (exact Mongolian letters, never misspelled) on a text-free visual: hierarchy, layout zones per poster type, fonts and colours by mood, alignment, and checking the result. Load for every POSTER job whose brief contains any words, numbers, price, discount, date, place or call to action.
---

# Poster typography

A poster without its message is just a product photo. When the brief gives words or numbers — price, discount, dates, place, a product name, a slogan, a phone number — they belong on the poster.

**Image models misspell Mongolian** (Ө/Ү/Э/Е, ₮). So the poster's words are **set with `add_text`** in real fonts (Montserrat, Oswald, Playfair Display, Nunito — exact letters every time) on a visual that was generated **without any text**. Never ask an image model to write the poster's words.

## 1. Build the message hierarchy first
From the brief, pick at most these, in this order of size:

| Level | Content | Size | Example |
|---|---|---|---|
| 1. Hook | The offer or headline — the thing read first | huge | `-18%`, `ШИНЭ`, `ЗУНЫ ХЯМДРАЛ`, `НЭЭЛТ` |
| 2. Support | Product name or one short line | medium | `UV ариутгагч`, `Шинэ серум` |
| 3. Facts | Price, old/new price, date · time · place | medium-small, bold numbers | `150,000₮ → 123,000₮`, `10.15 · 15:00 · Shangri-La` |
| 4. Action | CTA or contact | small | `Захиалах: 9911-2233`, `Одоо ирээрэй` |
| 5. Brand | The logo — `add_logo`, pixel-exact | small | — |

Rules: **max ~12 words on the whole poster**; headline ≤ 4 words; one hook only. Headlines, CTAs, dates and prices in Mongolian: follow `mongolian-ad-copy`. Never invent prices, dates, phone numbers, % or claims — use only what the brief says. You may write a short Mongolian headline yourself when the brief clearly implies it (sale → `ХЯМДРАЛ`, opening → `НЭЭЛТ`, new item → `ШИНЭ`, greeting → the occasion's name) — spelled correctly.

Compute derived numbers exactly (150,000₮ with 18% off = 123,000₮) and show both only when the brief gives the price and the discount.

## 2. Pick a layout (zones keep text off the subject)
| Poster type | Layout |
|---|---|
| **Sale / promo** | Big `-18%` badge (circle or bold block) top-right or across the top third; product hero in the middle; price row in the bottom band (old price struck through, new price large); CTA under it |
| **New product / launch** | Product large and centred; `ШИНЭ` or product name as a clean headline in the top band; one benefit line under the product; logo small bottom or top corner |
| **Event / opening / open day** | Headline (event name) top third; key visual centre; a **date · time · place block** in a solid colour band at the bottom, numbers large |
| **Greeting / holiday** | Occasion name as an elegant title (top or centre over calm space); symbolic still life; brand line + logo small at the bottom |
| **Anniversary / milestone** | The number is the poster: a huge `display` number (`15`) with `ЖИЛИЙН ОЙ` under it, the organisation's name and the wish smaller — see §3c |
| **Hiring** | `АЖИЛД УРЬЖ БАЙНА` headline; position name; 2–3 short lines max; contact; workplace photo or brand-colour field |
| **Menu / price list** | Hero dish; 3–5 items with prices in a clean column on a calm panel; never more than 5 lines |
| **Service / booking** | Result image or the service in action; service name; price "from"; booking contact |

Keep text inside the safe area (see `format-composition`); leave clear space around the headline; never put text over faces, the product or busy texture — give it a calm area or a solid panel/band.

## 3. Type style follows the mood
- **Bold retail** — heavy condensed sans-serif, all caps, high contrast (white on red/orange/black, black on yellow), slanted badge.
- **Premium / beauty** — thin elegant serif or wide light sans, generous letter-spacing, small sizes, lots of air, gold/white/deep colour.
- **Fresh / food** — rounded friendly sans, warm colours, slightly playful layout.
- **Corporate / event** — clean geometric sans, left-aligned grid, solid colour bands.
- **Kids / playful** — rounded chunky letters, bright multi-colour, sticker-like badges.
Name the style concretely in the prompt ("bold condensed sans-serif in white, all caps", "geometric sans like Montserrat ExtraBold", "elegant high-contrast serif like Playfair Display").

## 3b. Typographic craft (what makes it look designed)
- **Scale:** hook ≈ 3× the fact lines, facts ≈ 1.6× the small lines. One element clearly dominates; never two equally big lines.
- **Max two typefaces** (one display + one text); weight and size do the rest. Same fonts in every ratio of the set.
- **Alignment:** pick one axis — all centred (symmetrical, events, greetings) or all left-aligned on a grid (retail, corporate). Never mix.
- **Margins:** keep ≥ 6% of the width free around text; text never touches the edge or the product.
- **Line length:** ≤ 18 characters per line for headlines on 9:16/4:5, ≤ 28 on 16:9; break lines at word boundaries, balanced.
- **Caps headlines**, body text in sentence case. (Letter-spacing cannot be set — get air from size and margins instead.)
- **Numbers sell:** make the price / % the largest or second-largest element; the currency sign smaller than the digits; old price smaller, struck through, muted colour.
- **Legibility on photos:** put text on a calm area; otherwise a solid panel, a band, or a soft dark gradient (scrim) behind it. Contrast at least like white on mid-dark; no thin type on busy texture.
- **Badges:** circle, rounded pill or slanted tag in the accent colour for `-30%`, `ШИНЭ`, the date — one badge per poster.
- **Consistency:** the same colour does the same job (accent = offer/price only).

## 3c. A number as the hero (anniversary, age, %, year, count)
When the message is a number — `15` years, `-30%`, `2026`, `100` guests, `1-р` place — the number **is** the headline. A number at title size next to its word (`15 ЖИЛ` in one line) reads weak and flat.

- **Split it:** the number alone in a `display` item (about twice a title — dominant), the word under it as its own smaller item: `15` → `ЖИЛИЙН ОЙ` (title or subtitle), then the name, then the wish.
  ```
  display  : 15                     (gold E8C77A on dark / deep brand colour on light, condensed or bold_sans)
  title    : ЖИЛИЙН ОЙ              (white, same font family as the rest)
  subtitle : Ирвэс хамгаалах сан
  small    : Ойн баярын мэнд хүргэе!
  ```
- **Font for digits:** `condensed` (tall, elegant — anniversaries, events, premium) or `bold_sans` (retail, %). `serif` has old-style digits that sink below the letters — it is switched to `condensed` automatically for any string with digits, so plan the pair as `condensed` number + `serif`/`sans` words.
- **Colour:** the number takes the one accent colour (gold/champagne for anniversaries and premium, the brand colour otherwise); everything else white or dark. No box behind a display number — it needs air.
- **Placement:** centred in the calmest large area (usually the top half on a portrait, the left/right third on 16:9 beside the subject); the number block takes ~25–35% of the frame height. Keep the subject (animal, product, people) clearly below or beside it, never behind it.
- **The visual** must leave that space: ask for "a large calm, evenly toned area in the top half for a big number" and keep the scene simple; for anniversaries a sense of occasion (warm glow, subtle sparkle, confetti-like snow, dawn light), not a plain landscape.
- One display item per poster; `-30%` on a sale poster can be the display item, the price row then stays `price`.

## 4. Writing the strings
- Copy names, prices, dates, phones exactly from the brief; write them per `mongolian-ad-copy` (`150,000₮`, `-18%`, `10.15 · 15:00`, `9911-2233`).
- Use `\n` to break a long headline into two balanced lines instead of letting it shrink.
- One string per item; the order of items in a zone is the reading order.

## 4b. Reels
Same tool on clips: `add_text` with timed fade / slide-up — see `reels-storyboard` §3.

## 5. Method: text-free visual → `add_text`
1. **Visual** — generate the image **with no text at all** but **with calm zones** where the words will sit, matching the layout ("leave the top third as calm, evenly lit empty sky for a headline; keep a clean, plain area at the bottom"). End the prompt with "No text, no letters, no numbers, no logos." Graphic elements are fine: a colour band, a circle badge shape, a panel — empty.
2. **Words** — call `add_text` on the approved visual with every string, in hierarchy order:
   - `display` for a hero number or 1–2 short words (§3c), `title` for the hook (`ХЯМДРАЛ`, `-30%`, `НЭЭЛТ`), `price` for prices/discounts (often with `box` in the accent colour), `subtitle` for the product line / date line, `small` for CTA, phone, address, brand line.
   - `position` = the calm zone (`top`, `center`, `lower_third`, `bottom`); items in the same zone stack in order. Never over the product or faces.
   - `align`: `left` for retail / corporate grids, `center` for events, greetings, premium.
   - `font` by mood (§3): `bold_sans` default, `condensed` sport/bold offers and big numbers, `serif` premium/beauty/events (no ₮ glyph — prices switch to sans; digits switch to `condensed` automatically), `rounded` kids/food, `sans` for secondary lines. Max two fonts.
   - `color`: dark text on light calm areas, white text on dark/photo areas (white gets a shadow automatically); `box` + `box_color` for prices/badges.
   - On images `start`/`end`/`animation` are ignored (pass 0, 0, `none`).
   - `icon` before one-line facts (phone, location, clock, calendar, web, delivery …) and `scrim` (soft edge gradient) behind text on busy photos — when and how in `graphic-elements`; `none` otherwise. With icons, **one fact per item** (`06:00–23:00` with `clock`, then `9988-1122` with `phone`) — never several facts joined by `·` behind one icon; long joined lines also shrink.
3. **Look at the returned poster** (it is shown to you): hierarchy reads in one second, nothing covers the product, nothing touches an edge. If the result carries a **WARNING** (lines measured on a detailed area — product, face, hair, texture), treat it as true unless the overlap is clearly intended: move those lines, cut lines from that zone, put them on a `box`, or regenerate the visual with a bigger calm area. A tall stack of lines in one zone is the usual cause — on 1:1 the calm band above a centred product holds ~3 lines. Not right → call `add_text` again **on the same clean visual** with changed positions/sizes/colours (never on the already-texted image).
4. Deliver the texted image id. For several ratios: make each ratio's clean visual, then `add_text` on each.

Logos are never typed or drawn — place the client's file with `add_logo` after the text (see `poster-design` → Logo). The brand name may be set as a `small` line when there is no logo.

## 6. Check
`add_text` sets exactly what you write, so check **your strings** against the brief before calling it (names, digits, ₮, %, Ө/Ү). Then check the returned poster: one element clearly biggest, nothing over the product, margins clear, text readable at phone size. The visual itself must contain **no** logo or letters from the model (the real logo goes on with `add_logo`).

## 7. When it does not fit
**Fit the text to the visual, not the visual to the text.** Regenerate a visual for space at most once; after that adapt the type: fewer lines (≤ 6 items on a poster), merge facts into one `·` line (then without icons), smaller roles, a `scrim`, or another zone. Image models keep a reference's geometry, so asking again for "more space" rarely works and burns the budget.

1. Too many words → cut to the hierarchy (hook, one fact line, CTA); move details to the caption.
2. Text over the product or a face → use another zone, or regenerate the visual with a bigger calm area.
3. Low contrast → switch dark/white text, or put the line on a `box` in the brand colour.
4. A long headline shrinks too much → two lines with `\n`, or a shorter synonym from `mongolian-ad-copy`.
