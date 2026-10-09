# AI Poster & Reels Generator

Laravel 13 + Vue 3 + Tailwind CSS 4 дээр бүтээсэн, **Claude Fable**, **OpenAI GPT** болон **Google Gemini** API ашиглан
сошиал медиа **постер** болон **Reels/TikTok видео** үүсгэдэг AI хэрэгсэл.

## Боломжууд

**Постер**
- Санаагаа бичихэд AI гарчиг, дэд гарчиг, тайлбар, CTA, өнгөний палитр, фонт, зохиомжийг гаргана
- OpenAI `gpt-image-1` эсвэл Gemini (`gemini-2.5-flash-image`) арын зураг зурна
- 4:5, 1:1, 9:16, 16:9 хэмжээ; 4 төрлийн зохиомж (доор / голд / дээр / хуваасан)
- Бүх текст, өнгө, фонтыг шууд засна, өөрийн зургийг оруулж болно
- 1080px өндөр нягтралтай PNG татна; пост бичвэр + hashtag-ийг хуулна

**Reels видео**
- AI сценари бичнэ: hook, үзэгдэл тус бүрийн текст, voiceover, хөдөлгөөн (zoom / pan)
- Үзэгдэл бүрт 9:16 зураг үүсгэнэ (зэрэг 3-аар)
- Хөдөлгөөнт текст, шилжилт, story маягийн progress bar бүхий preview
- Үзэгдэл нэмэх/устгах/эрэмбэлэх, хугацаа засах, өөрийн хөгжим нэмэх
- 1080×1920 видео татна (сервер дээр ffmpeg байвал Instagram-д тохирох H.264 MP4 болгоно)

**Бусад**
- Монгол (кирилл) болон англи хэл
- Бүх ажил хадгалагдаж, “Түүх” хэсгээс дахин нээж засна
- API түлхүүргүй үед **Demo** горимоор UI-г туршиж болно

## Суулгах

Шаардлага: PHP 8.3+, Composer, Node 20+, (сонголтоор) ffmpeg.

```bash
git clone https://github.com/nuidea77/poster-generator.git
cd poster-generator
composer setup          # install, .env, key, migrate, storage:link, npm build
```

`.env` файлд API түлхүүрээ оруулна (дор хаяж нэг нь хангалттай):

```dotenv
ANTHROPIC_API_KEY=sk-ant-...     # Claude Fable — текст
OPENAI_API_KEY=sk-...            # GPT — текст + зураг
GEMINI_API_KEY=...               # Gemini — текст + зураг

AI_DEFAULT_TEXT_PROVIDER=anthropic   # anthropic | openai | gemini | demo
AI_DEFAULT_IMAGE_PROVIDER=openai     # openai | gemini | demo
```

Моделиудыг `ANTHROPIC_MODEL`, `OPENAI_MODEL`, `OPENAI_IMAGE_MODEL`, `GEMINI_MODEL`, `GEMINI_IMAGE_MODEL`-оор солино.

## Ажиллуулах

```bash
composer serve     # http://127.0.0.1:8000  (видео upload-д 200MB хүртэл зөвшөөрнө)
npm run dev        # фронтенд засаж байгаа бол (өөр терминалд)
```

> `php artisan serve` нь PHP-ийн default 2MB upload хязгаартай тул видеог MP4 болгох үед
> алдаа өгч WebM-ээр татна. Production дээр php-fpm-д `public/.user.ini` (200MB) уншигдана.

## Бүтэц

```
app/Services/AI/
  AiManager.php                 провайдер сонгох, тохиргоо
  Providers/AnthropicProvider   Claude Messages API (текст)
  Providers/OpenAIProvider      Chat Completions + Images API
  Providers/GeminiProvider      generateContent (текст + зураг)
  Providers/DemoProvider        API-гүй туршилтын горим
app/Services/ContentGenerator   постер/reels prompt, JSON-ийг цэвэрлэх, зураг хадгалах
app/Http/Controllers/Api/       generate, images, uploads, generations CRUD, videos/convert
resources/js/
  lib/render.js                 canvas дээр постер, reels frame зурах
  components/PosterStudio.vue   постер засварлагч
  components/ReelStudio.vue     reels засварлагч + MediaRecorder экспорт
```

## API

| Method | Path | Тайлбар |
| --- | --- | --- |
| GET | `/api/config` | Провайдерууд (түлхүүргүйгээр) |
| POST | `/api/generate/poster` | `prompt, language, style, format, text_provider, image_provider` |
| POST | `/api/generate/reel` | `prompt, language, style, duration, scenes, text_provider, image_provider` |
| POST | `/api/images` | `prompt, aspect, provider` → `{ url }` |
| POST | `/api/uploads` | Өөрийн зураг |
| POST | `/api/videos/convert` | WebM → H.264 MP4 (ffmpeg) |
| GET/PUT/DELETE | `/api/generations/{id}` | Түүх |

## Тест

```bash
composer test
```

AI API-уудыг `Http::fake()`-ээр дуурайлгаж шалгадаг тул түлхүүр шаардлагагүй.
