---
name: product-photography
description: Prompt recipes for product, food and venue shots, and how to use the client's reference photos so the real product stays identical. Load when a reference image of a product/dish/place is attached or a product shot is needed.
---

# Product photography with AI

## When a reference photo is attached
- Use `gemini` with `reference_image_ids`. Phrase the prompt as an **edit**: "Keep this exact product unchanged (shape, label, colours). Place it on …, lighting …, background …". Gemini preserves identity; GPT image reinvents.
- Never describe the product's appearance in words that could contradict the photo. Describe only the *new* context.
- Never pass the client's logo to the model to place it — it goes on afterwards with `add_logo`, pixel-exact. (A logo printed on the real product's packaging stays as part of the product photo.)
- Look at the result. If the product changed (label text, shape, colour) → regenerate with a stronger instruction ("do not alter the product in any way") or a plainer scene.

## When there is no reference
Use `openai` for photorealism. Prompt skeleton:

`[product] on [surface], [environment], [lighting], [lens], [mood/colours], [composition + calm zones for the poster text], no text, no logos` (the poster's typography is added in a second step — see `poster-typography`)

Examples:
- Coffee: "A ceramic latte cup with latte art on a light oak table, warm morning window light from the left, 85mm lens, shallow depth of field, cosy beige and brown palette, cup in the upper half, soft empty table surface in the lower third"
- Cosmetics: "Minimal glass serum bottle on a wet slate slab, soft studio light, water droplets, pastel pink gradient background, product centred-right, empty space on the left"
- Food: "Overhead shot of a bowl of buuz with steam, dark stoneware, rustic wooden table, side light, rich warm tones, bowl in the top half, empty table in the bottom half"
- Venue / interior: "Wide-angle photo of a cosy café interior, warm pendant lights, wooden furniture, evening, soft bokeh, empty wall space upper third"

## Surfaces & light cheatsheet
- Premium: marble, slate, black acrylic, rim light, dark background.
- Natural / organic: linen, oak, stone, window light, warm tones.
- Tech: brushed metal, matte black, blue-cyan accent light, gradient background.
- Fresh / healthy: white marble, green leaves, bright daylight.

## Negative space
Words and the logo are set on the image afterwards (`add_text`, `add_logo`), so leave calm space for them. State it explicitly ("product in the upper 60%, plain surface in the lower 40%").
