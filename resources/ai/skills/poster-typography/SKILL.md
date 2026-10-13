---
name: poster-typography
description: Putting the message on the poster — headline, offer badge, price, date/time/place, call to action and the brand line rendered inside the image by the image model: hierarchy, layout zones per poster type, type styles by mood, exact-text prompting (Mongolian Cyrillic, ₮, %, dates), the two-step visual-then-typography method, letter-by-letter checking and the fallback ladder when text comes out wrong. Load for every POSTER job whose brief contains any words, numbers, price, discount, date, place or call to action, and for reel title / end cards.
---

# Poster typography

A poster without its message is just a product photo. When the brief gives words or numbers — price, discount, dates, place, a product name, a slogan, a phone number — they belong on the poster, rendered **inside the image by the image model**. Nothing is added on top later, so the image must already be the finished ad.

## 1. Build the message hierarchy first
From the brief, pick at most these, in this order of size:

| Level | Content | Size | Example |
|---|---|---|---|
| 1. Hook | The offer or headline — the thing read first | huge | `-18%`, `ШИНЭ`, `ЗУНЫ ХЯМДРАЛ`, `НЭЭЛТ` |
| 2. Support | Product name or one short line | medium | `UV ариутгагч`, `Шинэ серум` |
| 3. Facts | Price, old/new price, date · time · place | medium-small, bold numbers | `150,000₮ → 123,000₮`, `10.15 · 15:00 · Shangri-La` |
| 4. Action | CTA or contact | small | `Захиалах: 9911-2233`, `Одоо ирээрэй` |
| 5. Brand | The logo (from the reference), small | small | — |

Rules: **max ~12 words on the whole poster**; headline ≤ 4 words; one hook only. Headlines, CTAs, dates and prices in Mongolian: follow `mongolian-ad-copy`. Never invent prices, dates, phone numbers, % or claims — use only what the brief says. You may write a short Mongolian headline yourself when the brief clearly implies it (sale → `ХЯМДРАЛ`, opening → `НЭЭЛТ`, new item → `ШИНЭ`, greeting → the occasion's name) — spelled correctly.

Compute derived numbers exactly (150,000₮ with 18% off = 123,000₮) and show both only when the brief gives the price and the discount.

## 2. Pick a layout (zones keep text off the subject)
| Poster type | Layout |
|---|---|
| **Sale / promo** | Big `-18%` badge (circle or bold block) top-right or across the top third; product hero in the middle; price row in the bottom band (old price struck through, new price large); CTA under it |
| **New product / launch** | Product large and centred; `ШИНЭ` or product name as a clean headline in the top band; one benefit line under the product; logo small bottom or top corner |
| **Event / opening / open day** | Headline (event name) top third; key visual centre; a **date · time · place block** in a solid colour band at the bottom, numbers large |
| **Greeting / holiday** | Occasion name as an elegant title (top or centre over calm space); symbolic still life; brand line + logo small at the bottom |
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
Name the style concretely in the prompt ("bold condensed sans-serif in white, all caps").

## 4. How to prompt text (exactness)
- Put every string in double quotes and say "render exactly this text, letter for letter": `headline text exactly "ЗУНЫ ХЯМДРАЛ"`.
- Say where, how big, what colour, on what background: `"‑18%" in a large red circle badge in the top-right corner, white bold numbers`.
- Mongolian Cyrillic needs care: name tricky letters if present ("note the Cyrillic letters Ө and Ү"). Prefer short words. Numbers, ₮ and % render far more reliably than words.
- Write dates and prices in a compact form: `10.15 · 15:00`, `150,000₮`, `-18%`.
- List all text in one block at the end of the prompt, then "No other text, no other letters or numbers, no watermark."

## 5. Two-step method (recommended)
1. **Visual first** — make and approve the image without text but **with the empty zones** the layout needs ("leave the top third as calm empty space for a headline; leave a clean band at the bottom").
2. **Typography second** — pass the approved image (and the logo) as references and ask the model to "keep the photo exactly as it is and add the following typography" with the exact strings, positions, sizes and style. Use `gemini_pro` for this step (best lettering, Mongolian Cyrillic and ₮); `gemini` / `openai` only when it is unavailable.
One-shot (visual + text in one prompt) is fine for simple graphic posters.

**Every edit redraws the whole image** — the logo and any text already in it are re-rendered and degrade a little each time. So: put the logo and all text in the **same final step**, do not edit a finished poster again to fix one detail (go back to the clean visual and redo the typography step), and keep a wordmark logo big enough to stay legible (≥ 18–20% of the frame width; a small logo turns its letters into mush).

## 6. Check every letter
Zoom in mentally on each string and compare **character by character** with the brief: missing/extra letters, Ө/Ү/Ё wrong, digits swapped, ₮ and % present, no invented extra words, no pseudo-letters elsewhere. **Check the logo's own letters too** — a misspelled brand name is the worst error on the poster. Also check the hierarchy reads in one second on a phone.

Currency: the tugrik sign is `₮`. A plain `T`, `₸`, `₿` or `Т` in its place is wrong — retry once with "the Mongolian tugrik sign ₮ (a T with two diagonal strokes)", then fall back to the number alone (`123,000`) or `123,000 төг`; never ship a wrong symbol.

## 7. Fallback ladder (when text keeps failing)
1. Regenerate the typography step with fewer, shorter strings and larger size.
2. Keep only numbers and symbols (`-18%`, `150,000₮`, `10.15 · 15:00`) — they almost always render.
3. Keep the headline in a simple badge or band with one or two words.
4. Last resort: deliver the clean visual without text and say so in `finish`.
Never ship misspelled text.
