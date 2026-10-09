---
name: video-prompting
description: Writing prompts for the Seedance video model — camera moves, subject motion, duration, image-to-video from a still, and what it cannot do. Load before generate_video.
---

# Video prompting (Seedance)

## Prompt skeleton
`[subject + what it does], [setting], [camera move], [lighting/mood], [style], [speed/tempo]`

Example: "A barista pours steamed milk into a latte, close-up, slow dolly-in on the cup, warm window light, cinematic shallow depth of field, slow motion."

## Camera vocabulary (pick one per clip)
- `slow dolly-in` / `dolly-out` — reveals, hooks
- `orbit around the product` / `turntable rotation` — product beauty shots
- `slow pan left/right` — venues, landscapes
- `handheld follow` — people, energy
- `static camera` — when the subject itself moves (steam, pouring, splash)
- `crane up` — establishing shots

## Subject motion ideas
Steam rising, liquid pouring, fabric moving in wind, hair in slow motion, sparks, confetti falling, lights turning on, door opening, hands unboxing, neon flicker, snow/rain, bokeh drifting.

## Duration
- 5 s is the default and enough for one beat (hook, reveal, CTA background).
- 10 s only for a continuous action the brief asks for (walkthrough, full pour).

## Image-to-video
- Generate and review the still first (product fidelity!), then pass it as `first_frame_image_id`. The clip's aspect follows the frame, so make the still 9:16 for reels.
- Describe only the motion in the prompt ("camera slowly pushes in, steam rises from the cup"); the look is already in the frame.
- This is the safest way to get a real product moving — Seedance from text alone will invent a product.

## Limits
- No on-screen text, logos or readable labels — they will be garbled; text is overlaid later.
- Faces of real people from references are not reliable; use product/venue shots instead.
- Generation takes 1–4 minutes; batch independent clips in one turn when you need more than one.
- Aspect: `9:16` for reels/TikTok, `16:9` for YouTube/Facebook, `1:1` for feed.
