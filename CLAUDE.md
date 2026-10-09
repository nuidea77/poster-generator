# Poster Generator

Laravel 13 + Vue 3 (SPA via Vite) + Tailwind 4. AI poster & reels generator using Claude (Anthropic), OpenAI and Gemini.

- AI providers live in `app/Services/AI/Providers`; register new ones in `AiManager` and `config/ai.php`.
- Prompts and response normalization are in `app/Services/ContentGenerator.php`. Keep the JSON schema there in sync with `resources/js/lib/render.js` and the Vue editors.
- Rendering (posters, reel frames) happens client-side on `<canvas>`; video export uses `MediaRecorder`, optional ffmpeg conversion in `VideoController`.
- Tests: `php artisan test` (AI calls are faked with `Http::fake`). Build: `npm run build`.
- UI copy is in Mongolian.
