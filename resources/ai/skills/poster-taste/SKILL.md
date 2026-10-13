---
name: poster-taste
description: Anti-generic rules for posters — read the brief into a one-line design read, set three dials (variance, density, energy), and avoid the defaults that make AI ads look templated (purple glow gradients, everything centred, glass on everything, beige-and-brass "premium", neon glows, gradient text, three equal cards, cliché words, fake numbers, emoji). Load for every poster and reel cover, before choosing the style.
origin: Adapted from the "design-taste-frontend" skill in Leonxlnx/taste-skill (MIT, see LICENSE.txt) — web rules translated to posters made with image models + add_text
---

# Poster taste (anti-template)

Most AI-made posters look alike because the model jumps to a default look instead of reading the brief. Read the room first, then choose on purpose.

## 1. Design read (one line, before the first prompt)
Write it as a note: **"Reading this as: <poster kind> for <audience>, <mood>, leaning toward <style family>."**
- *Clinic package for working adults 35–60, trustworthy and clean, leaning toward Soft Structuralism with a teal accent.*
- *Café autumn drink for students, warm and cosy, leaning toward Editorial Warmth with an orange accent.*
- *Agency lead ad for SME owners, confident and modern, leaning toward Colour-field Tech with a lime accent.*

Signals to read: the business type, the words the client used ("тансаг", "хөгжилтэй", "минимал", "албан ёсны"), the audience, existing brand colours/logo, and quiet constraints (health, kids, finance, government → trust first, calmer).

## 2. Three dials
| Dial | 1 | 10 | Typical |
|---|---|---|---|
| **Variance** (layout boldness) | centred, symmetric | asymmetric, cropped, overlapping | retail 6, premium 5, agency/youth 8, clinic/finance 4, greeting 3 |
| **Density** (how much is on it) | one word and a picture | packed promo | premium 2, greeting 2, product 4, promo 6, menu 7 |
| **Energy** (colour & contrast) | quiet, muted | loud, saturated | luxury 3, clinic 4, café 5, retail sale 8, youth 9 |

The dials decide the layout family, the number of items, and the palette strength. A high-density promo still keeps one dominant element.

## 3. Defaults to avoid (AI tells)
Avoid unless the brief asks for them:
- **AI purple/blue glow** gradient backgrounds with floating orbs as the automatic choice. If the brand is violet, use it with intent: one gradient, harmonised neutrals.
- **Everything centred** on every poster. With variance ≥ 5, try left-aligned text with the subject on the right, a split, or an asymmetric crop.
- **Glass or pills on everything.** Panels only where they carry hierarchy; the rest sits on calm space.
- **"Premium" = beige + brass + espresso** every time. Rotate: cold luxury (silver, smoke), forest (deep green + bone + amber), black and tan, cobalt + cream, terracotta + slate, olive + brick, monochrome + one bright pop.
- **Neon outer glows, gradient-filled headline text, pure #000 black** on light designs.
- **Three equal boxes in a row** as the only idea. Use a dominant element + supporting row, or a checklist.
- **Mixed-family emphasis** (a random serif word inside a sans headline). Emphasise with the same font in the accent colour (two-tone headline) or bold.
- **Oversized headlines that just shout.** Hierarchy comes from scale *and* weight *and* colour, not size alone.
- **Clichés:** "Шилдэг", "Хамгийн", "Дэлхийн жишигт", "Тэргүүлэгч", "Шинэ түвшин", "Seamless / Elevate / Next-gen". Say the concrete benefit instead.
- **Fake-perfect numbers or invented names/testimonials.** Only the brief's real facts.
- **Emoji** in poster text — use add_text icons instead.
- **Model-drawn text, logos or UI** in the visual (fake app screens with letters): ask for blank screens or abstract UI shapes.

## 4. One palette, one accent — locked
Pick the brand colour + one accent (and neutrals). The accent appears only on: the offer/price pill, the CTA, the starred headline word, key icons. Every ratio and every poster of the brand uses the same pair. If the brand has colours (logo), they win over any style preset.

## 5. Variety across jobs
Do not reuse the same composition and palette for different clients. Within one client's series, keep the look identical; across clients, change at least the layout family (§2 dials) or the palette family.

## 6. Pre-flight before delivering
- [ ] The design read was written and the result matches it.
- [ ] One dominant element; one accent colour; ≤ 2 fonts.
- [ ] None of the §3 tells unless intended.
- [ ] The poster would not be mistaken for a template of another business.
