---
name: poster-footer
description: The contact footer of a poster — address, phone, hours, date, website, e-mail, Instagram/Facebook/chat handles — built with add_text position footer: band or rounded bar (footer_style, footer_color), rows of 2–3 facts, icons plain or in circles (icon_style circle), a label above the phone, social rows, what to cut, and seven footer patterns studied from professional posters. Load for every poster that carries contact facts.
---

# The contact footer

The footer is where the customer acts: it must be **found in one glance and read at phone size**, and it must never compete with the headline or cover the product. Professional posters solve it the same way again and again: a separate strip at the foot, facts in a tidy row, icons, the phone the strongest.

## 1. Mechanics (add_text)
- Every contact fact gets `position: footer`. The call sets `footer_style` (`band` = full-width strip at the foot; `pill` = rounded bar inset from the edges) and `footer_color` (the strip's colour).
- Rows: the first fact of a row is a normal item, the next 1–2 have `same_line: true`. A row of 2 = left edge + right edge; a row of 3 = left / centre / right. **Max 2 rows.**
- Icons: `icon` per fact (phone, location, clock, calendar, web, mail, instagram, facebook, chat, user, delivery). `icon_style: circle` puts the icon in a disc of the text colour with the glyph in `icon_color` (pass the strip colour) — the polished look of most agency posters.
- Sizes: facts `small`; the **phone `subtitle` bold** (it is the action). Pairs keep their own sizes; a row is centred vertically.
- The visual must leave the foot free: "the bottom 15% is a plain area / simple surface; the product ends above it".

## 2. The default footer
Unless the brief or the style clearly calls for another pattern, use **pattern 2**: `footer_style: pill`, `footer_color` = the brand's main colour (or its dark tone), white text, every fact with `icon_style: circle` (white discs, `icon_color` = the footer colour), one row of 2–3 facts with the phone bold on the right. It is the most finished-looking footer and the studio's house style.

**Footer colour ≠ CTA colour.** The CTA pill takes the accent (orange, lime, yellow); the footer takes the brand's main or dark tone (violet, navy, deep green, near-black). Two blocks of the same colour stacked at the bottom merge into one blob. Keep a clear gap between the CTA and the footer.

```
add_text(footer_style: pill, footer_color: "4C1D95", items: [
  footer · small · web · circle · icon_color 4C1D95 · "orgilmedia.mn"
  footer · small · instagram · circle · same_line · "@orgilmedia"
  footer · small bold · phone · circle · same_line · "9911-4567"
])
```

## 2b. Seven footer patterns (from professional posters)
| # | Pattern | Recipe | When |
|---|---|---|---|
| 1 | **Brand band, address + big phone** (clinic) | `band`, brand-dark colour; row 1: `location` address left (small, may be 2 short lines) · `phone` number right (subtitle bold) | clinics, services, shops with one address |
| 2 | **Circle-icon row** (agency, Nashville / LoftyFly) | `pill` or `band` in the brand colour; one row of 3: `web` · `mail` · `phone`, all `icon_style: circle`, white discs, `icon_color` = strip colour | agencies, B2B, online services |
| 3 | **White contact bar** (app / fintech, myOTCplug) | `pill`, `footer_color: FFFFFF`, dark text; one row of 3: `web` · `instagram` handle · `phone`; icons as brand-colour discs (`icon_style: circle`, text/disc colour = brand, `icon_color: FFFFFF`) — or the text dark and the discs in the brand colour via a second colour on the icon | apps, online shops, light/colourful posters |
| 4 | **Social row** (school / community, WhatsApp channel) | row 1: a small accent pill label (`Мэдээлэл авах`) · `instagram` / `facebook` handle · `phone`; no heavy band — a calm dark bottom or a light `band` | communities, schools, NGOs, channels |
| 5 | **Label + number** (eye-clinic "FOR MORE INFO") | the label as its own `small` item above, then the number `subtitle` bold with `phone`/`chat` icon — both in the footer, the label row first: `Захиалга, лавлах:` / `7711-5566` | when the phone is the only fact |
| 6 | **Brand + link** (Nürnberg) | the logo with `add_logo` `bottom_left` small; the website as a glass pill (`box_shape: glass`) on the right in the footer | premium, health, minimal series |
| 7 | **Event facts** | row 1: `calendar` date · `clock` time · `location` venue (3 short facts); row 2: `phone` · `web` | events, openings, courses |

## 3. Writing the facts short
- Address: the shortest findable form — `Их тойруу 15`, `Номин тауэр, 3 давхар`; drop the district and khoroo when the building or street is known. Never more than ~28 characters on one line.
- Hours `07:30–22:00`, dates `10.20–11.20`, phone `8800-3344` (one number; a second only if the brief insists).
- Web without `https://` and `www.`; handles as `@orgilmedia`.
- Labels only when they add meaning (`Захиалга:` before a number that is not obviously a phone). No "Утас:" when the phone icon is there.

## 4. Choosing
- One footer style per poster and the **same footer on every poster of a brand** (series look).
- Dark/brand strip with white text on light or busy photos; a white bar on colourful or dark posters.
- Too many facts → keep the action ones (phone, place or web); move delivery, extra numbers and social handles to the caption or a pill elsewhere.
- add_text warns when the footer has more than 2 rows or a row had to shrink — fix it, don't ignore it.
