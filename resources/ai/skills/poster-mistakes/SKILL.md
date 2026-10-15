---
name: poster-mistakes
description: The running list of mistakes found in real test posters — wrong hero, orphan prices, missing strikes, bad line breaks, text over decorations, crammed stacks, model-drawn frames and third-party logos — each with the fix. Always in the brief for POSTER jobs; check every item before deliver_poster.
---

# Mistakes seen in real posters — check each before deliver_poster

## Type and copy
- **A generic word as the hero.** `ШИНЭ`, `ХЯМДРАЛ`, `NEW`, `SALE` are kickers — a small chip or small caps above the headline — never the giant `display` item. The biggest type is the product name, the offer number (`-30%`, `2 дахь нь 50%`) or the price.
- **An orphan price.** A price must sit with what it prices: right under or beside the product name, inside the offer block, or in a labelled pill — on the same axis as the text it belongs to. Never a lone number floating on the picture.
- **Multi-word text in `wide`.** Rubik Mono One is monospaced: spaces become huge gaps (`BREW   LAB`). Use `wide` for one word only; brand names of 2+ words go in `bold_sans`, `heavy` or `condensed`.
- **An old price without the strike.** Whenever the brief gives a before/after price (`450,000₮ биш 315,000₮`, `хуучин үнэ …`), the old one is its own item with `"strike": true` (small, grey or red) right above or beside the new price — a grey colour alone does not read as "was". Set it in the first full add_text call; do not spend extra calls re-sending the same line.
- **Escaped letters.** Write `₮`, `Ө`, `Ү` as plain UTF-8 characters, never `\u20ae`-style escapes.
- **A tiny offer on a sale poster.** On a sale / Black Friday / discount poster the discount (`40%`, `-30%`) is the biggest or as big as the headline — not a side note. Campaign names (`BLACK FRIDAY`) follow the generic-word rule: big is fine only if the number is bigger.
- **Bad line breaks.** Keep a date range on one line (`11.27–11.30`) and never start a line with a dash or a unit; keep short phrases together (`хүртэл хямд` on one line, not stacked word by word). Use `\n` only where the phrase naturally splits.
- **Text over a decoration.** A painted ribbon, swoosh or shape behind a text line makes it unreadable — place lines on calm areas only, or ask the visual to keep the decoration away from the text zone.
- **Crammed stacks.** Between stacked blocks (pill, line, pill) leave at least one line-height of air; a plain line squeezed between two pills reads as a mistake. Two pills in a row rarely need a third line between them — merge or drop it.

## The visual
- **A drawn frame or mock-up.** The image must be full bleed, edge to edge. Never call it a "poster" in the image prompt (models then draw a framed print, a white border, an inner mat or a fade band); say "full-bleed image filling the whole frame, no border, no frame, no margin". If the returned image has a border, regenerate.
- **Third-party marks on real objects.** Models add brand marks to recognisable things (a Gibson script on a guitar headstock, an Apple on a laptop, a car badge). Ask for "unbranded, no logos or lettering on the [part]" from the first prompt.
