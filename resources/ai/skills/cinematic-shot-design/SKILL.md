---
name: cinematic-shot-design
description: Directing each reel clip as a generation-safe cinematic shot — one dominant job per clip, an occupied first frame, physical camera placement instead of angle words, field of view that the content can actually produce, a feasibility veto for overloaded shots, named camera devices (crash zoom, Dutch angle, whip pan, dolly zoom, telephoto, ultra-wide) translated into physical instructions, natural motion blur, physics and motivated light. Load for every REEL job before writing the generate_videos prompts, together with video-prompting.
origin: Adapted from the CINEDANCE v4.4 Seedance prompt-director guide supplied by the studio
---

# Cinematic shot design

Your job is not to write beautiful descriptions but shots the video model can physically, spatially and temporally understand on the first attempt. A clip succeeds only if the model knows: the shot's job, the first frame, the space, where the camera is, the lens behaviour, the subject motion, the light and the final moment. Write the prompt in short, concrete English.

## 1. One job per clip
Pick one dominant job; it decides lens, framing, detail and camera:
product beauty · object detail · atmosphere / venue · geography (where things are) · motion / energy · impact (splash, pour, burst) · human performance beat · final hook / end card · a requested creative device.

**Density law:** one clip = one main idea, one main action, one camera strategy, one emotional effect. Two big events → two clips. A continuous clip with a lot happening → simpler camera.

## 2. Answer these before writing
1. What is visible? 2. What is the one thing happening? 3. Where is everything in physical space (foreground / midground / background, left / right)? 4. Where does the camera physically stand and what is its job? 5. Which field of view does this content naturally need? 6. What is in the first frame? 7. Is there a requested camera / style device to keep? 8. What can the model ignore?

## 3. First frame
Never open empty. The product, person, venue landmark or logo card is already in frame at its final position. With `first_frame_image_id` the still *is* frame zero — describe only motion and change, consistent with that still. Without a still, write the first frame physically: "First frame: the bottle stands centre on wet black slate, the brand-coloured backdrop glowing behind it."

## 4. Camera as physical placement
Translate angle words into height, side, distance, aim and roll, with a foreground anchor:
- Low angle → "camera 20–30 cm above the table, looking slightly up at the cup, the table edge large in the foreground".
- Over-the-shoulder → "camera behind the customer's left shoulder, shoulder dark at frame left, barista's face readable".
- Top-down → "camera directly above the bowl, looking straight down".
- Dutch angle = **roll only** (10–20° on the optical axis); the camera does not change side and left/right stays the same.
Check the move is possible in the space (enough distance in a small room?) and does not fight the subject's motion. Keep left/right relationships the same across clips of the reel.

## 5. Field of view that the content can produce
The model infers the lens from the content, not from a number. Write content only that lens can produce, and give diagonal FOV as the primary number (mm only as a hint):

| Look | Diagonal FOV | Use for |
|---|---|---|
| 16 mm ultra-wide | ≈107° | venue / geography, looming foreground, big spaces |
| 24 mm wide | ≈84° | the safe wide: venue with people readable, action |
| 35 mm | ≈63° | lifestyle, people with context |
| 50 mm | ≈47° | faces, product with some background |
| 85 mm | ≈29° | portrait isolation, beauty close-ups |
| 135 mm | ≈18° | compression, distant observation, bokeh backgrounds |

84° is a 24 mm look, **not** 16 mm. Subtle facial expressions need 47–84° and a close camera; tiny figures in a 107° frame can only act with big body moves. A small object as the hero needs a close camera or a narrow FOV. Write "84° diagonal field of view, wide rectilinear, lens locked for the whole clip" — not "Cooke anamorphic f/2.8".

## 6. Feasibility veto
If the request contradicts itself, keep the core intent and silently fix the least important parameter: ultra-wide + tiny facial acting → choose geography *or* performance; long-lens portrait in a tiny room → feasible FOV; many props + people + readable labels + complex action in one clip → cut secondary detail or split into two clips; night scene with daylight words → fix the light; fast action with "no blur" → natural motion blur. Hierarchy: brief's intent → product / logo identity → essential layout → then optics, distance, shot size, secondary detail, extra style.

## 7. Creative devices: opt-in, then physical
Use a named device only when the brief or style asks for it (or its style clearly implies it) — never as default decoration. One major device per clip (two only if one is static and one timed). Translate it into placement, FOV, timing/trigger, start and landing framing, and a readable hold:
- **Crash / snap zoom** — "starts 84° wide on the table; at 2 s, when the lid opens, one 0.35 s crash zoom to a 47° close-up of the product, then holds still" (the only lens change in the clip).
- **Whip pan** — trigger → fast pan with natural blur → stable landing frame.
- **Dutch angle** — "held 12° clockwise roll, same camera side, left/right unchanged".
- **Dolly zoom** — "camera tracks 0.8 m closer while the FOV narrows from 84° to 47° over 2 s; subject stays the same size, the background stretches away".
- **Aggressive wide** — 107°, camera very close to a foreground object that looms large while the space stretches deep; straight lines stay straight unless fisheye is asked for.
- **Telephoto compression** — 29–18°, layers of foreground occlusion or haze, observed-from-a-distance feel.
- Director styles (Wright, Ritchie, Fincher, Wong Kar-wai…) → translate into concrete optics, blocking, rhythm, light and texture; never just write the name.

## 8. Motion, physics, light
- **Motion cadence:** "natural 180-degree shutter motion: fast movement carries realistic motion blur, then resolves into sharp readable holds; no ghosting, no stutter, no duplicated limbs". Never "no blur" on action.
- **Physics:** weight, contact, gravity, inertia and follow-through — liquid pours with viscosity and splash, cloth and hair lag behind, steam rises and drifts, products set down with contact shadows, vehicles have tyre contact and body roll.
- **Separate** subject motion, camera motion and environmental motion in the prompt.
- **Light from a source:** say where it comes from and what it does — window light raking across the food, a warm practical lamp, neon bounce on wet pavement, backlight rim on the bottle. Avoid flat front light and generic beauty fill; no daylight leaking into night.
- **People:** a playable intent (what they want, one visible change) and 2–3 motivated actions, not random fidgeting. Close shots can carry eyes, breath, a small smile; wide shots need body-readable actions (turn, step, lift, laugh). Nobody talks — the reel is silent.

## 9. Prompt shape for one clip
Keep it compact, critical space before style:
`[shot job + first frame / blocking] [camera placement + FOV + move] [timed action] [physics] [light] [style / texture] [quality: sharp readable holds, natural motion blur, stable picture, no flicker]`

## 10. Self-check before generate_videos
One job? FOV matches content? Camera physically possible? First frame occupied and consistent with the still? Left/right kept across clips? Requested device preserved, none invented? Physics and light grounded? No text, logos or dialogue asked from the model? Shorter is better — do not add bloat to satisfy a checklist.
