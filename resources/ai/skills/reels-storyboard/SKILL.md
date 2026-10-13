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

## 3. Text on screen = cards made from stills
Video models garble text in motion, so text lives only on **held cards**:
1. Make the card as a still with `generate_image` at the reel's aspect, with the exact strings per `poster-typography` (e.g. title card: event name over the key visual; end card: logo + `10.15 · 15:00 · Shangri-La` + CTA on a brand-colour background or a darkened hero frame).
2. Check every letter.
3. Turn it into a clip with **`animate_still`** (free, instant, no quota; text stays perfectly sharp): `push_in` or `still`, 3–6 s. Do not send text cards to a video model — it blurs and garbles letters.
- Hook cards may carry a 1–3 word title over the first image (e.g. `НЭЭЛТТЭЙ ӨДӨРЛӨГ`) when the reel is an event or offer.
- Keep text inside the safe area (9:16: away from the top 14% and bottom 35%).

## 4. Real product & logo shots
Video models invent products from text. For the client's real product, venue photo or logo: make a still with `gemini` + the reference at the reel's aspect, review it, then use it as `first_frame_image_id` and describe only the motion.

## 5. Continuity
- One style line repeated in every clip prompt: palette, light, grade ("warm golden-hour light, teal-orange grade, shallow depth of field").
- Vary scale and camera move between neighbours (wide → close; push-in → orbit → static with subject motion).
- Carry something across cuts: a colour, a direction, a shape.
- Design each clip per `cinematic-shot-design`; write prompts per `video-prompting`.

## 6. When video fails — still deliver
If a video model fails or is paused (quota/credits), do not retry it. Use another video model if one is offered; otherwise build the missing beats with `animate_still` from the stills you already made (`push_in`, `pan_left`/`pan_right` on hero frames) so the clips still add up to the ordered length. A reel made partly of animated stills beats no reel.

## 7. Flow (parallel)
1. Load `motion-art-director`, `cinematic-shot-design`, `video-prompting`, `poster-typography` and `mongolian-ad-copy` (for cards) and the business skill.
2. Request **all stills in one turn** (hero frames + title/info/end cards) — they generate in parallel. Review; fix only the failures.
3. Call `generate_videos` **once** with all live-action clips, and `animate_still` for the cards (same turn).
4. Replace failed clips with one more `generate_videos` call.
5. `deliver_reel` with ids in order (lengths must add up), then `finish`.
