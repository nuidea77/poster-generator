# Poster Studio — AI постер ба 1:30 reels

Instagram, Facebook-д тавих **постер** болон **90 секундын reels видео**-г захиалгаар (subscription) бүтээдэг вэб үйлчилгээ.
Laravel 13 + Vue 3 + Tailwind 4.

Шаардлагын бүрэн тодорхойлолт: [`docs/SRS.md`](docs/SRS.md)

## Ажиллах зарчим

1. Хэрэглэгч prompt бичиж, **лого** болон **бүтээгдэхүүний зураг, мэдээлэл** оруулна. Постерын хувьд Instagram/Facebook хэмжээгээ сонгоно.
2. **Claude Fable 5.1** брифийг **skills**-тэй хамт боловсруулна. Ажил бүрт аль AI-г дуудахыг **өөрөө шийднэ**:
   - **Gemini**: бодит бүтээгдэхүүн, логог хэвээр хадгалж засах (reference)
   - **GPT Image**: фото реалистик, бүтээгдэхүүний зурагт хамаарахгүй дүрслэл
   - **Seedance**: видео клипүүд (бүгдийг зэрэг илгээнэ)
3. Fable үүсгэсэн зураг бүрийг (vision-оор) шалгаж, муу бол дахин үүсгэнэ.
4. Үр дүн:
   - **Постер**: сонгосон хэмжээ бүрт яг пикселийн хэмжээтэй JPEG. Дээр нь текст давхарлахгүй.
   - **Reels**: клипүүдийг сервер дээр ffmpeg-ээр угсарч **яг 90.0 секунд**, 1080×1920, H.264 + AAC MP4 болгоно.
5. Вэб дээр харуулж, татах товч гаргана. **Хэрэглэгч аль модель ашигласныг хаана ч харахгүй.** Модель, токен, алхмын лог зөвхөн админд харагдана.

Захиалга: **QPay** нэхэмжлэх (QR + банкны апп), callback ирэхэд `payment/check`-ээр баталгаажуулж багцыг идэвхжүүлнэ/сунгана. Багц хязгааргүй. Зардлыг fair-use хамгаална: зэрэг ажиллах бүтээл ≤2, өдрийн хязгаарыг тохиргоогоор асааж болно.

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
GEMINI_API_KEY=...             # зураг (дор хаяж нэг зураг модель)
OPENAI_API_KEY=...             # зураг
SEEDANCE_API_KEY=...           # видео (reels-д заавал), BytePlus ModelArk

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
- `php artisan queue:work --timeout=3600 --tries=1` (Supervisor). Reels нэг job-д 15–25 минут болно.
- `php artisan schedule:run` cron-оор минут бүр. Гацсан бүтээл, хугацаа дууссан нэхэмжлэхийг цэвэрлэнэ.
- php-fpm: `public/.user.ini` upload 200MB.

## Бүтэц

```
app/Services/Agent/CreativeAgent.php   Claude Fable tool-use loop: load_skill, generate_image,
                                       generate_videos (зэрэг), deliver_poster, deliver_reel, finish
app/Services/Agent/SkillLibrary.php    skills: resources/ai/skills/*/SKILL.md + admin-ийн custom (DB)
resources/ai/creative-director.md      system prompt (модель сонгох дүрэм)
app/Services/AI/Providers/             OpenAI, Gemini (зураг), Seedance (видео: submit + poll)
app/Services/Media/PosterFormatter.php яг пикселийн хэмжээгээр crop
app/Services/Media/ReelAssembler.php   ffmpeg: normalize → concat → pad/trim 90 сек → AAC
app/Jobs/RunCreation.php               queue job: агент → угсралт → done/failed
app/Services/Billing/                  QPayClient (v2), BillingService (invoice, check, сунгалт)
app/Http/Resources/CreationResource    хэрэглэгчид харагдах (модельгүй)
app/Http/Resources/AdminCreationResource  админд: алхам, модель, токен, видео сек
config/creations.php                   постерын хэмжээ, reels тохиргоо, fair-use
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
| GET/POST | `/api/v1/creations` | auth / subscribed |
| GET/DELETE | `/api/v1/creations/{id}` | эзэн |
| POST | `/api/v1/creations/{id}/retry` | subscribed |
| GET | `/api/v1/admin/creations` | admin |
| GET/POST/PUT | `/api/v1/admin/plans` | admin |
| CRUD | `/api/v1/admin/skills` | admin |

## Тест

```bash
composer test
```

Claude, Gemini, OpenAI, Seedance, QPay-г `Http::fake()`-ээр дуурайлгана. Reels-ийн тест бодит ffmpeg-ээр угсралтыг шалгана (ffmpeg байхгүй бол алгасна).
