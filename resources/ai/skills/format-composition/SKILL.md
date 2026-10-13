---
name: format-composition
description: Composing for each aspect ratio the client can order (1:1, 3:4, 4:3, 2:3, 3:2, 4:5, 5:4, 9:16, 16:9, 21:9 and Auto) and for 9:16 / 16:9 reels — where platforms cover the image, safe areas, how to pick the ratio for Auto, and how to recompose one idea across several ratios. Load for every job with more than one ratio, with Auto, or with an unusual ratio (2:3, 3:2, 21:9, 16:9 reel).
---

# Format composition

Every delivered file is centre-cropped to the exact size, so compose **at the ordered aspect** and keep the subject inside the safe area.

## Ratios and where they are used
| Ratio | Size | Used for | Safe area (keep subject + logo inside) |
|---|---|---|---|
| 1:1 | 1080×1080 | feed post, profile grid, carousel | middle 80% |
| 4:5 | 1080×1350 | Instagram / Facebook feed (most screen space) | middle 80% |
| 3:4 | 1080×1440 | Instagram feed (new tall grid) | middle 80%; the grid preview crops to ~3:4 anyway |
| 2:3 | 1080×1620 | poster, Pinterest, print-like | middle 75% |
| 9:16 | 1080×1920 | Story, Reels cover, TikTok | middle 60% vertically: top ~250 px (profile bar) and bottom ~350 px (reply box / captions) are covered |
| 16:9 | 1920×1080 | Facebook cover/video, YouTube, banners | middle 80%; on Facebook link previews a band is cut top and bottom |
| 4:3, 5:4, 3:2 | landscape | horizontal posts, website, print | middle 80% |
| 21:9 | 2520×1080 | wide web banner, event screen, cover | the central third holds the subject; sides are atmosphere |

## Auto
Pick the ratio that suits the brief and the image you will make, then generate at it — the poster takes that aspect.
- Product hero, offer, greeting for the feed → **4:5**.
- Story / announcement meant for Stories → **9:16**.
- Venue, landscape, group of people, event stage → **16:9** or **3:2**.
- Banner, cover, "for the website" → **16:9** or **21:9**.
- Unsure → 4:5 (safe for both Instagram and Facebook feeds).

## One idea, several ratios
1. Make the most important ratio first (usually the one listed first) and review it.
2. For each other ratio call `generate_image` with the same model, that ratio's aspect, and the first clean visual (+ product) as references: "same scene, lighting and style, recomposed for a wide 16:9 frame — extend the background on both sides, keep the product the same size relative to the frame height".
3. Vertical → horizontal: extend the environment sideways, never shrink the product to a dot. Horizontal → vertical: stack — product in the middle band, surface below, sky / wall above.
4. Then `add_text` and `add_logo` on each ratio; keep the logo in the same corner in every ratio.
5. **Re-lay-out the text, do not squeeze it:** vertical (4:5, 9:16) → headline top, product middle, facts at the bottom; square → headline top-left, badge top-right, facts bottom; horizontal (16:9, 21:9) → product on one side, text block on the other half. Re-check every string in every ratio.
6. Request the derived ratios **in one turn** — they generate in parallel.

## Reels
- **9:16**: subject centred, keep faces and products in the middle 60%; nothing important in the bottom fifth (captions and buttons).
- **16:9**: classic horizontal framing; use the width — wide establishing shots, side-by-side compositions, lateral camera moves (truck / pan). Still frames for first-frame images must be generated at 16:9 too.
