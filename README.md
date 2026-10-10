# Poster Studio — AI постер ба reels

Instagram, Facebook-д тавих **постер** болон **reels видео**-г захиалгаар (subscription) бүтээдэг вэб үйлчилгээ.
Laravel 13 + Vue 3 + Tailwind 4.

Шаардлагын бүрэн тодорхойлолт: [`docs/SRS.md`](docs/SRS.md)

## Ажиллах зарчим

1. Хэрэглэгч prompt бичиж, **лого** болон **бүтээгдэхүүний зураг, мэдээлэл** оруулна. Постерын хувьд Instagram/Facebook хэмжээгээ сонгоно.
2. **Claude Fable 5.1** брифийг **skills**-тэй хамт боловсруулна. Ажил бүрт аль AI-г дуудахыг **өөрөө шийднэ**:
   - **Gemini**: бодит бүтээгдэхүүн, логог хэвээр хадгалж засах (reference)
   - **GPT Image**: фото реалистик, бүтээгдэхүүний зурагт хамаарахгүй дүрслэл
   - **Seedance** (5/10 сек клип): урт, тогтвортой шот, бүтээгдэхүүн эргүүлэх
   - **Veo** (Gemini API, 4/6/8 сек клип): бодит хүн, шингэн, гэрэл, физик
   - Видео клипүүдийг бүгдийг нь зэрэг илгээнэ
3. Fable үүсгэсэн зураг бүрийг (vision-оор) шалгаж, муу бол дахин үүсгэнэ.
4. Үр дүн:
   - **Постер**: сонгосон хэмжээ бүрт яг пикселийн хэмжээтэй JPEG. Дээр нь текст давхарлахгүй.
   - **Reels**: клипүүдийг сервер дээр ffmpeg-ээр угсарч 1080×1920, H.264 + AAC MP4 болгоно. Урт нь клипүүдийн нийлбэр (ихэвчлэн 15–40 сек, дээд тал 180 сек).
5. Вэб дээр харуулж, татах товч гаргана. **Хэрэглэгч аль модель ашигласныг хаана ч харахгүй.** Модель, токен, алхмын лог зөвхөн админд харагдана.

### Skills

Агент ажил бүрийн өмнө хэрэгтэй skill-ээ (`load_skill`) уншина. Дагалдах skill-үүд (`resources/ai/skills/`):

| Skill | Эх сурвалж |
|---|---|
| `poster-art-director` | Claude skill "poster-art-director" |
| `motion-art-director` | Claude skill "motion-graphic-designer" |
| `color-themes` | Claude skill "theme-factory" (Anthropic, Apache-2.0) |
| `visual-concept` | Claude skill "canvas-design" (Anthropic, Apache-2.0) |
| `mongolian-culture` | Claude skills "mining-pr-mongolia", "motion-graphic-designer" |
| `poster-design`, `reels-storyboard`, `product-photography`, `brand-identity`, `video-prompting` | энэ төслийнх |

Claude skill-үүдийг энэ системийн урсгалд тохируулсан: текст давхарлахгүй, ажлын хэрэгслүүд нь `generate_image`, `generate_videos`, `deliver_*`.
Админ **Админ → Skills** хуудаснаас шинэ skill бичих, эсвэл Claude skill-ийн `SKILL.md` файлыг **импортлох** боломжтой. Custom skill нь ерөнхий зааврыг давамгайлна.

Захиалга: **QPay** нэхэмжлэх (QR + банкны апп), callback ирэхэд `payment/check`-ээр баталгаажуулж багцыг идэвхжүүлнэ/сунгана. Багц **кредитээр**: постер 14 кр (+4/хэмжээ), reels 130 кр. **Үнэгүй** 144 кр (1 постер + 1 reels), **Стандарт** 199,000₮ → 160 кр/сар, **Про** 499,000₮ → 440 кр/сар. Хамгийн үнэтэй модель (GPT Image high, Veo 3.1 standard)-оор тооцож, ажил бүрт медиа төсөв тавьсан тул ашиг муу тохиолдолд ч ×2, ердийн үед ×3-аас дээш. Тооцоо: [`docs/PRICING.md`](docs/PRICING.md). Зардлыг fair-use хамгаална: зэрэг ажиллах бүтээл ≤2, өдрийн хязгаарыг тохиргоогоор асааж болно.

## Суулгах

Шаардлага: PHP 8.3+ (GD), Composer, Node 20+, **ffmpeg + ffprobe** (reels-д заавал).

```bash
git clone https://github.com/nuidea77/poster-generator.git
cd poster-generator
composer setup        # install, .env, key, migrate, seed (багцууд), storage:link, build
```

`.env`:

```dotenv
ANTHROPIC_API_KEY=...          # заавал. Claude Fable бүх ажлыг удирдана
GEMINI_API_KEY=...             # зураг + Veo видео (дор хаяж нэг зураг модель)
OPENAI_API_KEY=...             # зураг
SEEDANCE_API_KEY=...           # видео, BytePlus ModelArk (reels-д Seedance эсвэл Veo-ийн аль нэг)

QPAY_CLIENT_ID=...
QPAY_CLIENT_SECRET=...
QPAY_INVOICE_CODE=...
QPAY_BASE_URL=https://merchant.qpay.mn     # тест: https://merchant-sandbox.qpay.mn
QPAY_CALLBACK_BASE=https://your-domain.mn  # QPay хүрч чадах нийтийн хаяг

DB_QUEUE_RETRY_AFTER=3700      # job timeout (3600)-аас их
```

Local дээр QPay-гүй туршихдаа `QPAY_FAKE=true` тавина. Төлбөрийн цонхонд "Туршилт: төлсөнд тооцох" товч гарна. Production-д хэзээ ч асаахгүй.

Админ эрх олгох (эхлээд вэбээр бүртгүүлнэ):

```bash
php artisan app:make-admin you@example.com
```

## Ажиллуулах

```bash
composer serve   # http://127.0.0.1:8000 — вэб + queue worker (--timeout=3600)
```

Production:
- `php artisan queue:work --timeout=3600 --tries=1` (Supervisor). Reels нэг job-д 10–25 минут болно.
- `php artisan schedule:run` cron-оор минут бүр. Гацсан бүтээл, хугацаа дууссан нэхэмжлэхийг цэвэрлэнэ.
- php-fpm: `public/.user.ini` upload 200MB.

## Бүтэц

```
app/Services/Agent/CreativeAgent.php   Claude Fable tool-use loop: load_skill, generate_image,
                                       generate_videos (зэрэг), deliver_poster, deliver_reel, finish
app/Services/Agent/SkillLibrary.php    skills: resources/ai/skills/*/SKILL.md + admin-ийн custom (DB)
resources/ai/creative-director.md      system prompt (модель сонгох дүрэм)
app/Services/AI/Providers/             OpenAI, Gemini (зураг), Seedance, Veo (видео: submit + poll)
app/Services/Media/PosterFormatter.php яг пикселийн хэмжээгээр crop
app/Services/Media/ReelAssembler.php   ffmpeg: normalize → concat → AAC (урт = клипүүдийн нийлбэр)
app/Jobs/RunCreation.php               queue job: агент → угсралт → done/failed
app/Services/Billing/                  QPayClient (v2), BillingService (invoice, check, сунгалт),
                                       Credits (хэтэвч, хасах/буцаах), CostMeter (API өртөг)
app/Http/Resources/CreationResource    хэрэглэгчид харагдах (модельгүй)
app/Http/Resources/AdminCreationResource  админд: алхам, модель, токен, видео сек
config/creations.php                   постерын хэмжээ, reels тохиргоо, fair-use
config/pricing.php                     нэгж өртөг, кредитийн үнэ, медиа төсөв
config/qpay.php, config/ai.php
resources/js/pages/                    Home, Create, Creation, Library, Pricing, Account, admin/*
```

## API (v1)

| Method | Path | Эрх |
|---|---|---|
| POST | `/api/v1/auth/register`, `/auth/login`, `/auth/logout` | |
| GET | `/api/v1/me`, `/api/v1/meta` | |
| POST | `/api/v1/me/brand` | auth |
| POST | `/api/v1/payments` → QR | auth |
| GET | `/api/v1/payments/{id}` | эзэн |
| GET/POST | `/api/v1/payments/qpay/callback/{token}` | QPay |
| GET/POST | `/api/v1/creations` | auth (POST: кредит) |
| GET/DELETE | `/api/v1/creations/{id}` | эзэн |
| POST | `/api/v1/creations/{id}/retry` | эзэн, кредит |
| GET | `/api/v1/admin/creations` | admin |
| GET/POST/PUT | `/api/v1/admin/plans` | admin |
| CRUD | `/api/v1/admin/skills` | admin |
| POST | `/api/v1/admin/skills/import` (SKILL.md) | admin |

## Тест

```bash
composer test
```

Claude, Gemini, OpenAI, Seedance, Veo, QPay-г `Http::fake()`-ээр дуурайлгана. Reels-ийн тест бодит ffmpeg-ээр угсралтыг шалгана (ffmpeg байхгүй бол алгасна).
