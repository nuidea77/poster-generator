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
- 5 s: one quick beat.
- 10 s for most reel beats (a 90 s reel = 9 × 10 s); 5 s for quick cuts.

## Image-to-video
- Generate and review the still first (product fidelity!), then pass it as `first_frame_image_id`. The clip's aspect follows the frame, so make the still 9:16 for reels.
- Describe only the motion in the prompt ("camera slowly pushes in, steam rises from the cup"); the look is already in the frame.
- This is the safest way to get a real product moving — Seedance from text alone will invent a product.

## Limits
- No on-screen text, logos or readable labels from text prompts — they come out garbled. Show a logo only by animating a still that already contains it.
- Faces of real people from references are not reliable; use product/venue shots instead.
- Generation takes 1–4 minutes per clip; always request all clips of a reel in a single `generate_videos` call so they render in parallel.
- Reels are 9:16. Still frames used as `first_frame_image_id` must be 9:16 too, or the clip is cropped.
