---
name: reels-storyboard
description: Planning a complete reel (9:16 or 16:9, at the length the client ordered) from AI video clips — message, beat structure per length, title and info cards that carry the offer / date / place as text on held stills, real product and logo shots from stills, continuity, and the parallel stills → clips → deliver flow. Load for every REEL job.
---

# Reel storyboard

The reel is assembled from your clips in order and is exactly as long as they are together. The client ordered the length (15, 30, 45 or 60 s) and the orientation (9:16 or 16:9); the clips must add up to the ordered length ± 3 s. Reels autoplay **muted** and nothing is overlaid later, so the pictures — and the text cards you build into them — must carry the whole message. Veo clips keep their own sound (ambience, foley, a soft music bed): use Veo for the whole reel when sound adds to it, with one consistent **Audio:** line in every prompt.

## 1. Message first
One sentence: what the viewer must know and do at the end ("Snow-leopard open day, 15 Oct, 15:00, Shangri-La — come"). Every fact the brief gives (offer, price, date, time, place, phone) must appear **on screen** somewhere, as text on a held card.

## 2. Beats by length
| Length | Structure (clip lengths: Seedance 5/10 s, Veo 4/6/8 s) |
|---|---|
| **15 s** | Hook (4–5 s) → product/hero (5–6 s) → **end card with message** (4–5 s) |
| **30 s** | Hook → world/desire → product hero → detail or in use → **end card** (5–6 clips) |
| **45 s** | Hook → world → desire → hero → detail → in use → result → **end card** |
| **60 s** | As 45 s plus a second hero angle and a mid-reel **info card** (offer or date) |

Beat menu: **Hook** (most striking image in the first 1–2 s — never the logo, never empty) · **World** (venue, city, kitchen) · **Desire** (a person who wants it) · **Product hero** (real product, from a still) · **Detail** (macro: texture, steam, pour) · **In use** · **Result / joy** · **Info card** · **End card**.

## 3. Text on screen = `add_text` (real, animated typography)
Video models garble letters, so never ask them for text. Put every word on screen with **`add_text`**: it burns real fonts onto a clip — letters exactly as you write them (Ө, Ү, ₮), timed fade / slide-up — and returns a new clip id to deliver instead.
- **Hook title over live action:** on the first clip, `title` at `top`, slide-up at 0.2–0.4 s, held to the end of the clip (`ШИНЭ КРУАССАН`, `НЭЭЛТТЭЙ ӨДӨРЛӨГ`, `-30%`).
- **Facts over a hero clip:** price (`price` + box in the brand/accent colour) or date line at `lower_third`, appearing ~1 s into the clip.
- **End card:** make a clean still for it (brand-colour background or a calm hero frame, **no text, no logo in the image**), `animate_still` it (`push_in`, 4–6 s), `add_logo` (`top_center` or `bottom_center`, 25–35% wide), then `add_text`: title, the fact line(s) (`10.15 · 15:00 · Shangri-La`, `4,500₮`), CTA/phone `small` at `bottom` — staggered starts (0.2 s, 0.6 s, 1.0 s …) so they build up.
- Read time: ≥ 1 s per 3 words + 1 s; ≤ 8 words on screen at once; one title per clip.
- Fonts by mood: `bold_sans` (default, retail, sale), `condensed` (sport, bold offers), `serif` (premium, beauty, events — no ₮ glyph, prices switch to sans automatically), `rounded` (kids, food, playful), `sans` for secondary lines. Max two fonts per reel; same font roles in every clip.
- Colour: white with shadow over photos; `box` with the brand colour for prices and on busy footage; dark text only on a light box.
- Positions avoid the app UI automatically; do not stack more than 3 items in one zone.
- Write the copy per `mongolian-ad-copy`.

## 4. Real product & logo shots
Video models invent products from text. For the client's real product or venue photo: make a still with `gemini` + the reference at the reel's aspect, review it, then use it as `first_frame_image_id` and describe only the motion.

## 5. Continuity
- One style line repeated in every clip prompt: palette, light, grade ("warm golden-hour light, teal-orange grade, shallow depth of field").
- Vary scale and camera move between neighbours (wide → close; push-in → orbit → static with subject motion).
- Carry something across cuts: a colour, a direction, a shape.
- Design each clip per `cinematic-shot-design`; write prompts per `video-prompting`.

## 6. When video fails — still deliver
If a video model fails or is paused (quota/credits), do not retry it. Use another video model if one is offered; otherwise build the missing beats with `animate_still` from the stills you already made (`push_in`, `pan_left`/`pan_right` on hero frames) so the clips still add up to the ordered length. A reel made partly of animated stills beats no reel.

## 7. Flow (parallel)
1. Load `motion-art-director`, `cinematic-shot-design`, `video-prompting`, `mongolian-ad-copy` and the business skill.
2. Request **all stills in one turn** (hero frames + title/info/end cards) — they generate in parallel. Review; fix only the failures.
3. Call `generate_videos` **once** with all live-action clips, and `animate_still` for the end card (same turn).
4. `add_text` on the hook, the fact clip(s) and the end card (several calls in one turn).
5. Replace failed clips with one more `generate_videos` call.
6. `deliver_reel` with the **texted** clip ids in order (lengths must add up), then `finish`.
