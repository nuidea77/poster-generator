---
name: reels-storyboard
description: How to structure a 9:16 short-form video (Instagram Reels / TikTok) — hook, scene pacing, overlay text, voiceover and when to use AI video clips vs stills. Load before create_reel.
---

# Reels storyboard

## Structure (15–30 s total, 3–6 scenes)
1. **Hook (2–3 s)** — a pattern interrupt in the first second: a bold claim, a question, a surprising visual. Overlay text ≤ 5 words. This scene decides whether people keep watching.
2. **Problem / context (3–4 s)** — what the viewer wants or struggles with.
3. **Reveal / proof (3–5 s, 1–2 scenes)** — the product, the venue, the result. Use the client's reference photos here if any exist.
4. **Details (3–4 s)** — price, date, offer. Put numbers in the overlay text.
5. **CTA (3 s)** — one action: "Профайл дахь линк", "Захиалга: 9911-xxxx", "Өнөөдөр ирээрэй".

## Visual continuity
- Decide one visual style (lighting, palette, lens) in the first image prompt and repeat the same style sentence in every scene prompt.
- Alternate motion: zoom-in → pan-left → zoom-out → pan-right. Never the same motion twice in a row.
- A video clip (`generate_video`) is worth it for: the hook, a product in motion, steam/pour/splash moments, a venue walkthrough. Stills with ken-burns motion are fine for details and CTA scenes. Typical mix: 1 clip + 3–4 stills.
- When animating a still into a clip, generate the still first, check it, then pass its id as `first_frame_image_id`.

## Overlay text
- ≤ 8 words, one line idea, sentence case, no trailing period. The hook may be ALL CAPS.
- `subtext` is a small pill: a price, a date, a tag. Empty on most scenes.
- Text is drawn at the lower-middle of the frame; prompt the visuals so the lower third is calm.

## Voiceover
- One sentence per scene, spoken naturally, matching the scene duration (≈ 2.5 words per second).
- Same language as the overlay text. The last line repeats the CTA.

## Music mood
Name a mood and tempo the client can search for: "upbeat pop 120 bpm", "lo-fi chill", "cinematic rise", "traditional morin khuur modern beat".

## Checklist before create_reel
- Scene 1 is a hook, last scene is a CTA.
- Every scene has an `asset_id` that exists (image or video).
- Total duration matches the brief (default 15–20 s).
- `title` is a short internal name; `caption` + 5–10 hashtags.
