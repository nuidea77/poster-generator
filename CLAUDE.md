# Poster Studio

Laravel 13 + Vue 3 SPA (vue-router, served by Laravel) + Tailwind 4. Subscription service that makes Instagram/Facebook posters and reels (reel length = sum of the clips, max 180 s). Requirements: `docs/SRS.md`.

- Flow: `POST /api/v1/creations` → `RunCreation` job → `CreativeAgent` (Claude Fable + tools) → posters cropped by `PosterFormatter` / reel assembled by `ReelAssembler` (ffmpeg) → `outputs`.
- Fable picks the image (GPT Image, Gemini) and video (Seedance, Veo via Gemini API) provider per call; video providers declare their clip lengths in `durations()`. Never expose provider/model names to customers: customer responses go through `CreationResource`; internals (`assets`, `steps`, `summary`, `error_detail`) only via `AdminCreationResource`. A feature test checks for leaks.
- No text is overlaid on posters; the generated image is the deliverable.
- Fable API rules: no `thinking` param, no forced `tool_choice`, effort via `output_config`, `fallbacks: "default"` with the `server-side-fallback-2026-07-01` beta header. Pass assistant content (incl. thinking blocks) back unchanged.
- Skills: bundled in `resources/ai/skills/<name>/SKILL.md` (frontmatter `name`, `description`, optional `origin`), custom ones in the `skills` table (admin, can import a Claude `SKILL.md`). `SkillLibrary` builds the index and serves `load_skill`. Several bundled skills are adapted from Claude skills; keep their `origin` line and LICENSE files (Apache-2.0 for theme-factory/canvas-design). Recommended skill sets per job type are listed in `resources/ai/creative-director.md`.
- Billing: QPay v2 (`QPayClient`); never trust callback payloads, always verify with `payment/check`. `BillingService::markPaid` is idempotent and extends from the current end date. `QPAY_FAKE=true` for local only.
- API is under `/api/v1` with the session guard + CSRF (`X-XSRF-TOKEN` from cookie). Middleware alias: `admin`. Plan limits are checked per type in `CreationService` via `User::allowance()` (free tier = active plan priced 0, lifetime limits; usage counted per subscription incl. soft-deleted creations, failed ones refund).
- Tests: `php artisan test` (all external HTTP faked with `Http::fake`). Build: `npm run build`. UI copy is Mongolian.
