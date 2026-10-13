---
name: image-review
description: A quality checklist for every generated image and still frame — product fidelity, logo integrity, hands and faces, stray or garbled text, artefacts, crop safety, brand fit — plus how to fix each failure in the next prompt and when to stop retrying. Load before reviewing the first image of a job, or when a result looks off.
---

# Image review

Look at every image the tool returns before you deliver or animate it. Ship nothing you would not post on the client's page.

## Checklist (in this order)
1. **Product fidelity** — same shape, colours, label layout, proportions as the client's photo. A changed label or invented variant is a fail.
2. **Logo** — present only if given; not redrawn, warped, mirrored, duplicated or recoloured; **its own letters spelled exactly like the original** (compare letter by letter — a broken brand name is a fail); in a corner or on the info band, big enough to read on a phone (wordmarks ≥ 18% of the width).
3. **Text** — every string from the brief (offer, price, date, place, CTA) is present and spelled **exactly**, character by character (Ө, Ү, ₮, %, digits); no extra invented words; no stray letters, watermarks or gibberish signage elsewhere (backgrounds, packaging, screens). Hierarchy reads in one second: one thing is clearly biggest.
4. **People** — correct number of fingers, natural hands holding objects, symmetric eyes, no melted faces, believable skin, nobody cut off at a joint.
5. **Physics & artefacts** — objects floating or merging, impossible reflections, duplicated items, smeared areas, seams.
6. **Crop safety** — subject and logo inside the safe area of the ordered ratio (see `format-composition`); nothing important in a Story's top/bottom bands.
7. **Brief & brand fit** — the idea is clear in one second on a phone; palette and mood match the brand; it does not look like generic stock.

## Fixing in the next prompt
| Problem | Fix |
|---|---|
| Product changed | `gemini` + reference, "keep the product exactly unchanged", simpler scene, fewer props |
| Logo broken or misspelled | go back to the clean visual, add logo + text in one fresh step, logo larger on a plain area; or deliver without the logo |
| Wrong currency sign (`T` for `₮`) | retry naming "the tugrik sign ₮"; else number only or `төг` — see `poster-typography` |
| Garbled text in the scene | add "no other text, no signage, blank labels"; remove shops/screens/papers |
| Letters inside the generated image | regenerate the visual with "no text, no letters, no numbers"; all words go on with `add_text` |
| Message missing or weak | add the brief's offer/date/price per `poster-typography`; make one element clearly biggest |
| Bad hands | hide or simplify hands (product on a surface, hand at the edge, holding from below) |
| Face issues | wider shot, person turned away or in soft focus, fewer people |
| Cluttered | name fewer props, state negative space ("plain surface in the lower third") |
| Generic look | load `visual-concept`; name a specific aesthetic and light |

## When to stop
At most 2 retries per asset (budget). After that deliver the best version that passes items 1–3, or simplify the concept (product on a clean coloured background) rather than ship a broken image. Mention the compromise in `finish`.
