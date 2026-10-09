# Poster Generator

Laravel 13 + Vue 3 (SPA via Vite) + Tailwind 4. AI poster & reels generator using Claude (Anthropic), OpenAI and Gemini.

- AI providers live in `app/Services/AI/Providers`; register new ones in `AiManager` and `config/ai.php`.
- Prompts and response normalization are in `app/Services/ContentGenerator.php`. Keep the JSON schema there in sync with `resources/js/lib/render.js` and the Vue editors.
- Rendering (posters, reel frames) happens client-side on `<canvas>`; video export uses `MediaRecorder`, optional ffmpeg conversion in `VideoController`.
- The creative agent (`app/Services/Agent/CreativeAgent.php`) calls Claude Fable with tools and a skill file (`resources/ai/creative-director.md`); it runs as a queued job and the UI polls `/api/agent-runs/{id}`. Fable rules: no `thinking` param, no forced `tool_choice`, effort via `output_config`, `fallbacks: "default"` with the `server-side-fallback-2026-07-01` beta header.
- Tests: `php artisan test` (AI calls are faked with `Http::fake`). Build: `npm run build`.
- UI copy is in Mongolian.
