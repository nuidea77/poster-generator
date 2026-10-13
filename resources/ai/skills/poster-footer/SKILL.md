---
name: poster-footer
description: The contact part of a poster — address, phone, hours, date, website, e-mail, Instagram/Facebook/chat handles — goes in the footer (add_text position footer). What the tool can draw there (no strip, band, rounded bar, colours, rows, icons, dividers) and ideas from professional posters; the design itself is yours, different for every poster. Load for every poster that carries contact facts.
---

# The contact footer

**The one rule:** the contact facts — address, phone, social handles, website, e-mail, hours, date and place — belong to the **footer**: every one gets `position: footer`. Everything else (hook, offer, CTA) stays above it.

**How it looks is yours to design, new for every poster.** There is no house layout. Look at the picture, the brand and the mood and make the footer belong to *this* poster: a quiet line of white text on a dark foot, a bold brand band, a white bar, a centred order line, an elegant single row for a premium brand, a loud strip for a sale. The patterns below are ideas, not templates.

## What the tool can do (add_text)
- `footer_style`: `open` (no strip, the facts sit on the picture; add `scrim: bottom` if the foot is busy), `band` (full-width strip), `pill` (rounded bar inset from the edges). `footer_color` = the strip colour.
- Rows: a fact with `same_line: true` joins the row above. A row of 2 sits at the left and right edges; 3+ become justified columns with thin dividers. You choose how many rows and what goes where; if you set no `same_line` at all, the tool arranges them for you.
- Icons per fact: phone, location, clock, calendar, web, mail, instagram, facebook, chat, user, delivery. `icon_style: circle` puts the glyph in a disc (`icon_color` = the glyph colour), or plain icons.
- Any role, font, colour and `align` per fact; a label item (`Захиалга:`) above a number; a glass chip for a link.

## Ideas from professional posters
| Idea | How |
|---|---|
| Brand band, address + phone | `band` in the brand's dark tone; address left, phone right, bold |
| Circle-icon row | one row of web · mail · phone, circle icons in the strip colour |
| White contact bar | `pill`, `footer_color: FFFFFF`, dark text, brand-colour icon discs |
| Facts + order line | place · hours on one row; the phone alone below, centred (`Захиалга: 8899-3311`) |
| Label + number | `Мэдээлэл авах:` small, then the number bold — when the phone is the only fact |
| Brand + link | logo small `bottom_left` (`add_logo`); the website as a glass chip on the right |
| Event facts | date · time · venue on one row; phone · web below |
| Open / minimal | `open`, white or cream text straight on a dark calm foot, plain icons |

## Keep it working
- Readable at phone size: facts short (`Их тойруу 15`, `07:30–22:00`, `8800-3344`, `@push_musclelab`, web without `https://www.`); no "Утас:" when the phone icon is there.
- It must not steal the eye from the hook or cover the product: ask the visual for a calm foot ("the bottom 15% is a plain surface; the product ends above it").
- A strip in a different colour from the CTA pill, with some air between them.
- Too many facts for the space → keep the action ones (phone, place or web) and leave the rest to the caption.
