<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AI\Providers\SeedanceProvider;
use App\Services\AI\Providers\VeoProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Зураг/видео үйлчилгээ үзүүлэгчдийг нэрээр нь үүсгэж, аль нь тохируулагдсан,
 * аль нь quota/credit дууссаны улмаас түр зогссоныг хянана.
 */
class AiManager
{
    // Нэрээр нь зураг үүсгэгч буцаана (тохируулаагүй эсвэл зогссон бол алдаа).
    public function image(string $name): ImageProvider
    {
        return match ($this->configured($name, 'image')) {
            'openai' => new OpenAIProvider(config('ai.providers.openai'), config('ai.timeout')),
            'gemini' => new GeminiProvider($this->settings('gemini'), config('ai.timeout')),
            'gemini_pro' => new GeminiProvider($this->settings('gemini_pro'), config('ai.timeout')),
        };
    }

    // Нэрээр нь видео үүсгэгч буцаана (тохируулаагүй эсвэл зогссон бол алдаа).
    public function video(string $name): VideoProvider
    {
        return match ($this->configured($name, 'video')) {
            'seedance' => new SeedanceProvider(config('ai.providers.seedance'), config('ai.timeout')),
            'veo' => new VeoProvider($this->settings('veo'), config('ai.timeout')),
        };
    }

    /**
     * Тухайн төрлийн ("image" | "video") тохируулагдсан үйлчилгээ үзүүлэгчдийн нэрс.
     *
     * @return list<string>
     */
    public function available(string $kind, bool $pinned = true): array
    {
        $names = collect(config('ai.providers'))
            ->filter(fn ($p, $name) => ($p['kind'] ?? null) === $kind && $this->ready($p) && ! $this->paused($name))
            ->keys()
            ->values()
            ->all();

        // Тогтоосон үйлчилгээ үзүүлэгч (AI_IMAGE_ONLY / AI_VIDEO_ONLY) ажиллаж байхад зөвхөн тэр санал болгогдоно;
        // түр зогссон эсвэл тохируулаагүй бол бусад нь орлоно.
        $only = config("ai.only.{$kind}");

        return $pinned && $only && in_array($only, $names, true) ? [$only] : $names;
    }

    /**
     * Үйлчилгээ үзүүлэгч quota эсвэл credit дууссан гэвэл түүнийг хэсэг хугацаанд
     * зогсооно; ингэснээр ажлууд Fable-д үүнийг санал болгож, алхам дэмий үрэхээ болино.
     *
     * @return bool алдаа quota / billing-тэй холбоотой байсан эсэх (үйлчилгээ үзүүлэгч зогссон)
     */
    public function noteFailure(string $name, string $error): bool
    {
        // Бидний өөрсдийн "is paused" татгалзал зогсолтыг үүрд сунгах ёсгүй.
        if (str_contains($error, "Provider [{$name}] is paused")) {
            return true;
        }

        // Google "Sorry… automated queries" хуудас: сүлжээг түр блоклосон — 10 минут зогсоож,
        // тогтоосон (AI_IMAGE_ONLY) загварыг тайлж бусад загвар руу шилжүүлнэ.
        if (preg_match('/automated queries|unusual traffic|<title>Sorry/i', $error)) {
            Cache::put($this->pauseKey($name), $error, now()->addMinutes(10));

            return true;
        }

        // Quota / credit дууссан бол үйлчилгээ үзүүлэгчийг зогсооно; түр зуурын "try again later" rate limit бол үгүй.
        // Vertex-ийн 429 "Resource has been exhausted (e.g. check quota)" нь минутын хязгаар, billing биш.
        if (preg_match('/try again later|Resource has been exhausted/i', $error) || ! preg_match('/quota|no credits|credits are depleted|prepayment|credit balance|billing|insufficient_quota/i', $error)) {
            return false;
        }

        Cache::put($this->pauseKey($name), $error, now()->addMinutes(config('ai.pause_minutes', 30)));

        return true;
    }

    // Үйлчилгээ үзүүлэгч одоогоор түр зогссон эсэх (cache дахь тэмдэглэгээгээр).
    public function paused(string $name): bool
    {
        return Cache::has($this->pauseKey($name));
    }

    /**
     * Үйлчилгээ үзүүлэгчийн config-ийг бодит замтай нь буцаана: Vertex-ээр ажиллахаар
     * тохируулсан ч Vertex-ийн credentials байхгүй бол Gemini API руу шилжинэ.
     */
    private function settings(string $name): array
    {
        $provider = config("ai.providers.{$name}");

        if (($provider['via'] ?? null) === 'vertex' && ! $this->vertexReady($provider) && filled($provider['key'] ?? null)) {
            $provider['via'] = 'gemini_api';
        }

        return $provider;
    }

    /** Зогсолт нь зам тус бүрээр тул Gemini API-ийн доголдол үйлчилгээ үзүүлэгчийг Vertex руу шилжүүлэхэд саад болохгүй. */
    private function pauseKey(string $name): string
    {
        $via = config("ai.providers.{$name}") ? ($this->settings($name)['via'] ?? 'direct') : 'direct';

        return "ai:paused:{$name}:{$via}";
    }

    /** Үйлчилгээ үзүүлэгчийг дуудаж болох эсэх: Vertex-ээр бол Vertex AI credentials, эс бөгөөс өөрийн API key. */
    private function ready(array $provider): bool
    {
        return (($provider['via'] ?? null) === 'vertex' && $this->vertexReady($provider)) || filled($provider['key'] ?? null);
    }

    // Vertex бэлэн эсэх; видео (Veo) нь service account шаарддаг, зурагт API key хангалттай.
    private function vertexReady(array $provider): bool
    {
        return Vertex::fromConfig()->ready(needsServiceAccount: ($provider['kind'] ?? null) === 'video');
    }

    // Claude (Anthropic) API key тохируулагдсан эсэх.
    public function claudeReady(): bool
    {
        return filled(config('ai.providers.anthropic.key'));
    }

    // Нэрийг шалгана: зогссон эсвэл энэ төрөлд тохируулаагүй бол AiException шиднэ.
    private function configured(string $name, string $kind): string
    {
        if ($this->paused($name)) {
            throw new AiException("Provider [{$name}] is paused (out of quota/credits or temporarily blocked). Use another model.");
        }

        if (! in_array($name, $this->available($kind, false), true)) {
            throw new AiException("Provider [{$name}] is not a configured {$kind} model.");
        }

        return $name;
    }
}
