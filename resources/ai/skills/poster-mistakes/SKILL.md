---
name: poster-mistakes
description: The hard "never do" list from real test posters — forbidden hero words, orphan or unstruck prices, tiny offers, bad line breaks, torn phrases, suffix-only lines, mood words printed as copy, text over decorations or the subject, crammed stacks, escaped letters, multi-word wide type, drawn frames and third-party logos in the visual — each with what to do instead, plus the negative phrases every image prompt needs. Always in the brief for POSTER jobs; a poster that breaks any rule is not delivered.
---

# NEVER — the poster is not delivered if it breaks any of these

Each rule below comes from a real test poster that looked wrong. Before `deliver_poster`, go through the list on the finished image; if one is broken, fix it with `add_text` on the clean visual (or regenerate the visual) first.

## Hierarchy and offer
1. **NEVER make a generic word the hero.** `ШИНЭ`, `ХЯМДРАЛ`, `NEW`, `SALE`, `BLACK FRIDAY`, `АЖИЛД АВНА` alone are not the message. → They are a small kicker chip or small caps; the biggest type is the product name, the offer number or the price.
2. **NEVER let the offer be small on a sale poster.** → The discount (`40%`, `-30%`, `2 дахь нь 50%`) is the biggest element or as big as the headline.
3. **NEVER leave a price orphaned.** A lone number floating on the picture reads as noise. → The price sits with what it prices: under/beside the product name, inside the offer block, or in a labelled pill, on the same text axis.
4. **NEVER show an old price without the strike.** Grey colour alone does not say "was". → Every before/after price (`450,000₮ биш 315,000₮`, `хуучин үнэ 160,000₮`) gets the old one as its own item with `"strike": true`, small, right above or beside the new price — in the first full add_text call. `strike` is an optional field — nothing adds it for you; if you leave it out, the old price is printed as if it were current.

## Copy
5. **NEVER print the brief's mood words.** The adjectives that end a brief (`Найдвартай, хурдан`, `Тансаг, эмэгтэйлэг`, `Хөгжилтэй, аюулгүй`) are direction for the look. → Use them to choose style, colour and type; print them only if the client clearly wrote them as a slogan.
6. **NEVER invent or change facts.** → Every name, number, date, ₮, %, phone and address exactly as in the brief.

## Line breaks and grouping
7. **NEVER start a line with a dash, a unit or a case ending.** No line beginning with `–11.30`, `-аас`, `-өөс`, `-д`, `₮`. → Keep `11.27–11.30` on one line; keep the suffix with its number (`2.5 сая₮-өөс эхэлнэ`).
8. **NEVER stack a short phrase word by word.** → `хүртэл хямд`, `Цаг захиалга` stay on one line; use `\n` only where the phrase naturally splits.
9. **NEVER tear one phrase apart.** `5 сая₮` and `хүртэлх зээл` with a big gap read as two messages. → Lines of one phrase sit tight; the gap inside a phrase is smaller than the gap to the next block (with free `y`: next `y` = this `y` + its line height + a small gap).
10. **NEVER cram stacks.** A plain line squeezed between two pills reads as a mistake. → At least one line-height of air between stacked blocks; merge or drop the extra line.

## Placement over the picture
11. **NEVER put text over a decoration.** A ribbon, swoosh, ring, light streak or edge line behind or through a text line makes it unreadable or looks like a strike-through. → Text fully inside or fully outside such shapes, on calm areas only.
12. **NEVER let type touch the subject.** Headline letters reaching a head, hair, hand or product look cramped. → A visible gap (≥ 2% of the frame); make the type smaller or move it.
13. **NEVER set contacts on a busy object** (a truck side, boxes, a patterned surface). → Contacts on a calm area or a plain panel.

## Type
14. **NEVER set 2+ words in `wide`.** Rubik Mono One is monospaced: spaces become huge gaps (`BREW   LAB`, `SPEED   GARAGE`). → `wide` for one word only; multi-word names in `bold_sans`, `heavy` or `condensed`.
15. **NEVER type escapes.** No `₮`, `·`, `Х`. → Type `₮`, `·`, `Ө`, `Ү`, Cyrillic directly as characters. Nothing decodes escapes for you: `₮` is printed literally or the call is rejected.
16. **NEVER spend calls re-sending the same line.** → Decide every item's role, size, position and `strike` in the first full call; later calls change only what the check found.

## The visual (image prompt)
17. **NEVER call the image a "poster", "flyer" or "ad layout" in the prompt.** Models then draw a framed print, a white border, an inner mat or a fade band. → Always write "full-bleed image filling the whole frame edge to edge, no border, no frame, no margin". If the result has a border, regenerate.
18. **NEVER accept third-party marks.** Models add brand marks to real objects (a Gibson script on a guitar headstock, an Apple on a laptop, a car badge, a Nike tick). → Ask for "unbranded, no logos or lettering on the [headstock / lid / badge]" in the first prompt; regenerate if one appears.
19. **NEVER deliver a poster built on the client's raw upload.** → Generate a visual with the product photo as a reference; if no image model works, finish without delivering (the job is refunded).

### Negative phrases for every image prompt
End each image prompt with: *"Full-bleed, edge to edge, no border, no frame, no margin. No text, no letters, no numbers, no logos, unbranded objects, no watermark."*
