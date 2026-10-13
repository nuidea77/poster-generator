---
name: poster-art-director
description: The poster workflow from brief to delivery — read the brief and extract the facts, write the design read and pick a style, choose what the picture shows, plan the zones (header, hook, offer, CTA, footer), prompt a text-free visual, set every word in one add_text call (two-tone headline, offer pills, arrow CTA, contact strip), place the logo, critique and fix, derive the other ratios; plus templates per poster type and what not to do. Load first for every POSTER job.
origin: Claude skill "poster-art-director" (adapted for this studio)
---

# Poster art director

The client is a shop owner or page admin, not a designer. They write two sentences; you deliver a poster that looks like a good agency made it and **sells in one second on a phone**. A beautiful photo with no message fails; so does a busy flyer with ten messages.

## The workflow
1. **Read the brief** → facts, purpose, audience, assets.
2. **Design read + style** → one line, three dials, one style.
3. **Picture + layout** → what the visual shows and where every block sits.
4. **Visual** → one English prompt with empty zones; review it.
5. **Words** → one complete `add_text` call; read the warnings.
6. **Logo** → `add_logo`.
7. **Critique and fix** → at most two rounds.
8. **Other ratios** → derived from the approved one; deliver each.

## 1. Read the brief
| Extract | Notes |
|---|---|
| Business, what it sells | Names exactly as written; Mongolian typed in Latin ("zunii hyamdral") means Cyrillic ("Зуны хямдрал") |
| Purpose | Sale, new product, event/opening, greeting, anniversary, hiring, menu, service, app/agency (see Templates) |
| Facts | Offer, price, old/new price, dates, time, place, phone, web, social — **these go on the poster** |
| Audience & mood | Who buys (young, family, premium, B2B) and the feeling the client named |
| Assets | Product photo (stays identical), logo (`add_logo`), style reference |
| Format | Ratios or `auto` — see `format-composition` |

Never invent facts, prices, phone numbers, discounts or claims.

**Then think like a designer, not a typesetter:**
- **Who exactly is it for?** Name the audience in one phrase (students preparing for IELTS, young parents, office workers at lunch, rock fans 30–45) and what they care about. Their taste decides the style, not the business category.
- **Condense the story.** A client gives ten facts; the poster carries one message. Write the message in ≤ 8 words, then rank the rest: what goes big, what goes small, what goes to the caption.
- **Look past the cliché.** List the first idea every designer would have (clinic → smiling woman on blue; restaurant → plate on dark wood; sale → red burst) — then find a fresher angle: a symbol, an unexpected crop, a bold typographic idea, a metaphor (`visual-concept`). Use the cliché only if nothing better serves the message.

## 2. Design read and style
Write a note: **"Reading this as: <kind> for <audience>, <mood>, leaning toward <style>."** Dials — variance (centred 1 … asymmetric 10), density (one word 1 … packed 10), energy (muted 1 … loud 10): clinic/finance 4/4/4, premium 5/2/3, café 5/3/5, retail sale 6/6/8, youth/agency 8/5/8.
**Style** — one of Minimal Editorial, Soft Structuralism, Editorial Warmth, Ethereal Tech, Colour-field Tech, Swiss Industrial, Tactical Dark, Generative Abstract (`poster-styles`). The brand's colours win over any preset.
**Avoid the AI defaults** (`poster-taste`): purple glow by reflex, everything centred, glass on everything, beige + brass as "premium", neon, gradient text, cliché words (`Шилдэг`, `Хамгийн`, `Дэлхийн жишигт`), emoji, model-drawn text or fake UI.

## 3. Picture and layout
**What the picture shows** (pick one): product hero on a brand-colour field · lifestyle moment with the product · person + device · food close-up · venue/atmosphere · symbolic still life · 3D objects · abstract/generative. One **concept twist** makes it memorable (`visual-concept`): steam forming a heart, the product as a monument, a summer world around a T-shirt.

**Pick a composition map** from `poster-design` §4 (hero type · split · frame/arch · bottom-heavy · centred icon · diagonal · grid · full-bleed mood) that fits *this* picture and message — not the same top-left stack every time. Write the big-idea sentence first (`poster-design` §2).

## 4. The visual
English prompt, art-directed per `poster-design` §3 (colour world in hex, painted graphic structure, intentional crop, one light idea): subject, setting, composition **with the type zones named** ("the top 35% is calm empty wall for the headline; the product stands in the middle; the bottom 12% is a plain table surface for the contact strip"), light, palette (brand colour + accent), style words from the chosen style. With a product photo: `gemini` + reference, phrased as an edit ("keep the product exactly as in the reference; change only the scene"). Never pass the logo. End with "No text, no letters, no numbers, no logos." Review it (`image-review`); regenerate once at most for space — after that adapt the text.

## 5. The words — one complete add_text call
Every item in reading order, real strings, nothing invented (`poster-typography` for roles and parameters, `poster-layouts` for blocks):
- **Kicker / brand** — one small plain line (a chip only if it carries meaning), or none.
- **Headline** — `title` (or `display` for a hero number), two-tone: one `*starred*` word + `accent_color`.
- **Offer & price** — the strongest after the headline: a big plain number, or **one** accent shape (`box: true`). At most 2 filled shapes on the poster besides the footer (`poster-design` §1).
- **CTA** — `subtitle` pill with `icon: arrow`, accent colour.
- **Contacts** — every contact fact (address, phone, social, web, hours) with `position: footer`; its look is yours to design for this poster (`footer_style` open/band/pill, `footer_color`, rows via `same_line`, icons).
Then check it against the quality bar (`poster-design` §8), read its warnings (detail under a line, low contrast, footer layout) and fix them on the **clean visual**.

## 6. Logo
`add_logo` — the client's file, pixel-exact; corner and size per `logo-placement`, never in the headline's zone or on the footer strip. Without a logo the brand name is the kicker line.

## 7. Critique and fix
In a short note (`poster-critique` for the full pass):
1. **2-second test:** the eye lands on the hook or the product first — not the footer, logo or a decoration.
2. **Message:** every fact exact; the offer prominent; CTA and contact findable.
3. **Hierarchy & consistency:** one dominant element, one accent, ≤ 2 fonts.
4. **Legibility:** headline the most contrasted thing; nothing over the product or a face; facts readable at phone size.
Critical (misspelling, wrong fact, text over product, illegible, fake letters, deformed product/hands, warped logo) → fix. Moderate → fix if one add_text call does it. Minor → deliver. Max two rounds.

## 8. Other ratios
Approve the first ratio, then derive the others in one turn (same model, the approved clean visual as reference, re-composed for the new frame), and run the same add_text choices on each. Check the text in every ratio.

## Templates
| Template | Picture | On the poster |
|---|---|---|
| Sale / promo | product hero, energetic brand field | kicker `ХЯМДРАЛ`, `display` `-18%` or offer pill, old → new price pills, CTA, strip |
| New product | product close-up, premium light, one prop telling the benefit | `ШИНЭ` kicker, product name two-tone, one benefit line, price pill, CTA, strip |
| Event / opening | venue or symbol, atmosphere | event name, date · time · place in the strip (row of 3), CTA `Бүгдийг урьж байна` |
| Greeting | symbolic still life (`mongolian-culture`) | occasion title, short wish, brand line; no strip unless asked |
| Anniversary | occasion mood with a big calm area | `display` number + `ЖИЛИЙН ОЙ`, name, wish |
| Hiring | workplace or team, calm brand field | `АЖИЛД УРЬЖ БАЙНА`, position, 2–3 check items, strip |
| Menu / price list | hero dish + calm panel | 3–5 items with prices on a `card`, strip |
| Service / clinic | result or service in action, clean light | kicker, two-tone headline, checklist or row of services, CTA, strip (`poster-layouts`) |
| App / agency / fintech | person + device or 3D object on a brand field | kicker, two-tone question headline, feature pills, CTA arrow, white or brand strip |

## What not to do
- No claims the client did not make (medical effects, "№1", guarantees) — `ad-compliance`.
- Regulated products: literal and conservative; tobacco ads are banned in Mongolia.
- No real person's likeness, other brands' logos or characters, state symbols (`mongolian-culture`).
- Never ship misspelled text, model-drawn letters, or a test/placeholder add_text result.
