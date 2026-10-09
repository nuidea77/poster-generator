---
name: poster-design
description: Layout, typography, colour and composition rules for static social posters (Instagram feed/story, Facebook, YouTube thumbnails). Load before calling create_poster.
---

# Poster design

## Pick the format from the channel
| Channel | format | Notes |
|---|---|---|
| Instagram feed, Facebook feed | `4:5` | Tallest feed format = most screen space |
| Instagram story, TikTok cover | `9:16` | Keep text inside the middle 60% — UI covers top/bottom |
| Marketplace, profile grid, print preview | `1:1` | |
| Facebook cover, YouTube thumbnail, web banner | `16:9` | Headline ≤ 4 words, very large |

## Choose a layout for the image you have
- `bottom` — image has its subject in the upper 2/3, empty or calm lower third. Default for photos.
- `top` — subject sits low (food on a table, product on a surface). Text goes above.
- `center` — abstract / pattern / gradient backgrounds, big announcements, quotes. Image gets a dark overlay, so avoid detailed product shots here.
- `split` — the image is cropped into its own panel and text gets a solid colour panel. Use when the image has no negative space, when a product photo must stay uncropped, or for a clean "catalogue" look.

Always generate the image *for* the layout: tell the image model where the negative space must be ("subject in upper half, soft out-of-focus floor in the lower third").

## Typography
- `bold` (Montserrat 900) — promotions, sport, events, sales, youth.
- `modern` (Inter 800) — tech, services, corporate, apps.
- `elegant` (Playfair) — beauty, luxury, restaurants, weddings, premium.
- `playful` (Comfortaa) — kids, cafés, bakeries, pets, fun brands.

Headline ≤ 7 words (≤ 4 on 16:9). One idea. Numbers beat adjectives ("-30%", "10.20", "3 өдөр") — put them in the headline or tagline, not the body.

## Colour
- Palette = `background` (fallback behind image / split panel), `primary` (CTA button), `accent` (tagline pill + subheadline), `text`.
- Take `primary`/`accent` from the brand or the dominant colours of the reference photo; keep `text` white on photos unless the image is very light.
- Accent must contrast with the image area where the tagline sits. Never put yellow accent on a yellow image — change the accent, not the image.

## Content slots
- `tagline` — ≤ 3 words label: "ШИНЭ", "ХЯМДРАЛ", "НЭЭЛТ", "ЗӨВХӨН ӨНӨӨДӨР". May be empty.
- `subheadline` — the offer or benefit in one line.
- `body` — logistics only: date, time, place, price, phone. Max 2 short sentences. Empty is fine.
- `cta` — imperative, 1–3 words: "Захиалах", "Бүртгүүлэх", "Дэлгэрэнгүй", "Ирээрэй".

## Checklist before create_poster
1. Does the image leave room where the text will sit? If not → regenerate or switch to `split`.
2. Is the real product/logo intact (when a reference was given)?
3. Is every concrete fact from the brief (date/price/place) somewhere on the poster?
4. Caption: 1–3 sentences + CTA + 5–10 hashtags.
