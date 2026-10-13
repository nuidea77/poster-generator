---
name: poster-art-director
description: Art direction for a business's Facebook / Instagram ad poster — reading a short brief, extracting the message (offer, price, date, place), choosing a design archetype and layout, making the visual with the real product and logo, adding the typography, reviewing every image, and templates (sale, new product, event, greeting, hiring, menu, service). Load first for every POSTER job, before poster-design and poster-typography.
origin: Claude skill "poster-art-director" (adapted for this studio)
---

# Poster art director

The client is a shop owner or page admin, not a designer. They write two sentences; you deliver a poster that looks like a good agency made it and **sells in one second on a phone**. A beautiful product photo with no message is a failed poster. So is a busy flyer with ten messages.

Three things make this better than typing a prompt into an image model:
1. **A brief, not a prompt.** You extract the facts and the message and decide the design yourself.
2. **Message + design, not just a picture.** The poster carries the hook, the offer and the facts the client gave, laid out with clear hierarchy (see `poster-typography`).
3. **You look before you deliver.** Every image is reviewed (see `image-review`); text is checked letter by letter.

## 1. Read the brief
| Extract | Notes |
|---|---|
| Business, what it sells | Names exactly as written; Mongolian typed in Latin ("zunii hyamdral") means Cyrillic ("Зуны хямдрал") |
| Purpose | Sale, new product, event/opening, greeting, hiring, menu, service (see Templates) |
| Facts | Offer %, price, old/new price, dates, time, place, phone, URL — **these go on the poster** |
| Audience & mood | Who buys (young, family, premium, B2B) and the feeling |
| Assets | Product photo (stays identical), logo (placed pixel-exact with `add_logo`), style reference |
| Format | Aspect ratios or `auto` — see `format-composition` |

Never invent facts, prices, phone numbers, discounts or claims.

## 2. Decide the design (write 5 lines for yourself)
- **Design read** — write it as a note: "Reading this as: <kind> for <audience>, <mood>, leaning toward <style>". Dials: variance (centred 1 … asymmetric 10), density (one word 1 … packed promo 10), energy (muted 1 … loud 10) — clinic/finance 4/4/4, premium 5/2/3, café 5/3/5, retail sale 6/6/8, youth/agency 8/5/8.
- **Style** — one of: Minimal Editorial, Soft Structuralism, Editorial Warmth, Ethereal Tech, Colour-field Tech, Swiss Industrial, Tactical Dark, Generative Abstract (recipes in `poster-styles`; anti-template rules in `poster-taste`). Commit to it for every ratio.
- **Avoid the AI defaults:** purple-glow backgrounds by reflex, everything centred, glass on everything, beige + brass as "premium", neon glows, gradient text, cliché words (`Шилдэг`, `Хамгийн`, `Дэлхийн жишигт`), emoji, model-drawn text or fake UI.
- **Message:** the one thing the viewer must get ("UV sterilizer, 18% off, now 123,000₮").
- **Archetype** (pick one, matching business and mood):
  - *Bold retail* — saturated brand-colour field, big offer badge, product cut-out with shadow, high contrast.
  - *Premium editorial* — calm luxurious photo, lots of air, small elegant type, one accent colour.
  - *Lifestyle photo + headline* — real moment with the product, headline on a calm area.
  - *3D / playful* — glossy 3D props and shapes around the product, bright palette (kids, summer, youth).
  - *Graphic / flat* — colour blocks, geometric shapes, product on a colour split; clean and modern.
  - *Event key visual* — atmospheric scene or symbol + a strong info band (date · time · place).
  - *Food close-up* — appetising macro with warm light and a price tag/badge.
- **Layout:** where the hook, product, facts, CTA and logo sit (layout table in `poster-typography`).
- **Palette & light:** from the logo / brand (see `brand-identity`); otherwise `color-themes`.
- **Concept twist:** one idea that makes it memorable (see `visual-concept` for premium/creative briefs): the product as a monument, a summer world built around a T-shirt, steam forming a heart over coffee…

Avoid the default "product on a podium in front of a plain wall" unless the archetype calls for it.

## 3. Make it
**Visual (step 1):** English prompt — subject, setting, composition **with calm empty zones for the words**, light, style, palette. With a product photo: `gemini` + reference, phrased as an edit ("keep the product exactly as in the reference: same shape, colours, label; change only the scene"). Do not pass the logo; leave a calm corner for it. End with "No text, no letters, no numbers, no logos."
**Words (step 2):** `add_text` on the approved visual — every string in real fonts, letters exact (see `poster-typography` §5). Never let an image model write the poster's words: it misspells Mongolian.
**Logo (step 3):** `add_logo` — the client's file, pixel-exact; corner and size per `logo-placement` (never in the headline's zone). Deliver the final image.
Ask for independent drafts **in the same turn** (they run in parallel) when you want to compare two archetypes.

## 4. Budget
Per format: one strong attempt + up to two corrections. A weak first idea → try a **different archetype**, not another seed. Derive other formats from the approved poster (same scene, re-laid-out text for the new ratio) and check the text again in each.

## 5. Review
Critique the returned poster before delivering (`poster-critique` for the full pass), in a short note:
1. **2-second test:** the eye lands on the hook or the product first — not a footer, logo or decoration.
2. **Message:** every brief fact exact; the offer prominent; CTA and contact findable.
3. **Hierarchy & consistency:** one dominant element, one accent colour, ≤ 2 fonts, same choices in every ratio.
4. **Legibility:** headline the most contrasted thing; nothing over the product or a face; small facts readable at phone size; act on add_text warnings.
Critical issues (spelling, wrong fact, text over product, illegible) → fix; moderate → fix if one add_text call does it; minor → deliver. Max two fix rounds.
Reject or fix when: the message is missing or wrong; any text is misspelled or has extra pseudo-letters; hierarchy is unclear (several things shout); the product differs from the photo; the logo is warped, huge or redrawn; text sits over the product or faces; hands/faces/objects are malformed; something important is outside the safe area (9:16: top 14%, bottom 20%). Change the prompt deliberately when you regenerate.

## Templates
| Template | Picture | On the poster |
|---|---|---|
| Sale / promo (хямдрал, урамшуулал) | Product hero, energetic brand colours, sense of value | `-18%` badge, headline (`ХЯМДРАЛ`), old → new price, short CTA |
| New product (шинэ) | Close-up, premium light, product alone, one prop telling its benefit | `ШИНЭ` or product name, one benefit line, price if given |
| Event / opening (нээлт, өдөрлөг) | Venue, symbol of the cause or the atmosphere | Event name, **date · time · place** band, CTA (`Бүгдийг урьж байна`) |
| Greeting (баярын мэндчилгээ) | Symbolic still life, warm light (see `mongolian-culture`) | Occasion title, short wish, brand line |
| Anniversary (ойн баяр) | Sense of occasion with a big calm area for the number; the brand's symbol/subject below it | Huge `display` number (`15`) + `ЖИЛИЙН ОЙ`, name, wish — `poster-typography` §3c |
| Hiring (ажлын зар) | Workplace or team, or calm brand field | `АЖИЛД УРЬЖ БАЙНА`, position, contact |
| Menu / price list | Hero dish + clean panel | 3–5 items with prices |
| Service / booking | Result or service in action | Service name, "from" price, booking contact |

## What not to do
- No claims the client did not make (medical effects, "№1", guarantees) — see `ad-compliance`.
- Regulated products: literal and conservative; tobacco ads are banned in Mongolia.
- No real person's likeness, other brands' logos or characters, state symbols (see `mongolian-culture`).
- Never ship misspelled text — use the fallback ladder in `poster-typography`.
