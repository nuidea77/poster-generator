---
name: style-reference
description: Using the client's style reference images ("make it look like this") — reading what to borrow (palette, light, composition, mood, graphic treatment), passing them to the image model without copying their content, and checking the result. Load whenever a style reference is attached.
---

# Style references

A style reference is an example the client likes — a competitor's post, a Pinterest image, an earlier campaign. It tells you the **look**, not the content. Their own product photos and logo stay the subject.

## 1. Read the reference before prompting
Write down (for yourself) what makes it work, in concrete visual words:
- **Palette** — dominant and accent colours, saturation, warm / cool.
- **Light** — soft daylight, hard flash, studio rim light, neon, golden hour, low-key.
- **Composition** — centred hero, flat lay, close-up crop, lots of negative space, diagonal, symmetry.
- **Set & props** — surface, background, props, plants, textures.
- **Treatment** — photo vs 3D vs illustration, film grain, matte, high contrast, pastel, editorial.
- **Mood** — calm, luxurious, playful, energetic.

If there are several references, keep what they share and ignore one-offs.

## 2. Prompt
- Use `gemini` with `reference_image_ids` = [style refs…, product…, logo]. Say which image plays which role:
  "Use image 1 only as a style reference (palette, lighting, composition, mood). Do not copy its objects, people, text or logo. The subject is the product in image 2, kept exactly as it is. Place the logo from image 3 small in the top-left."
- Also spell out the borrowed qualities in words (from step 1) — models follow words more reliably than "like this".
- With `openai` (no strict product reference needed), describe the borrowed look in words only.

## 3. Never copy
- No text, slogans, watermarks, brand names or logos from the reference.
- No recognisable people, characters or distinctive artwork from it; no near-duplicate composition of a competitor's ad.
- If the reference is itself a famous artwork or another brand's campaign, borrow only the general style.

## 4. Review
Compare side by side: does it feel like the same family (palette, light, mood)? Is it clearly the client's own product and not a copy of the reference's subject? If it drifted to generic, regenerate naming the missing quality ("harder side light", "more negative space").

For reels: use the reference for the first-frame stills and describe its pacing/camera feel in the video prompts.
