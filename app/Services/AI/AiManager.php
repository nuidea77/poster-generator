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

class AiManager
{
    public function image(string $name): ImageProvider
    {
        return match ($this->configured($name, 'image')) {
            'openai' => new OpenAIProvider(config('ai.providers.openai'), config('ai.timeout')),
            'gemini' => new GeminiProvider(config('ai.providers.gemini'), config('ai.timeout')),
            'gemini_pro' => new GeminiProvider(config('ai.providers.gemini_pro'), config('ai.timeout')),
        };
    }

    public function video(string $name): VideoProvider
    {
        return match ($this->configured($name, 'video')) {
            'seedance' => new SeedanceProvider(config('ai.providers.seedance'), config('ai.timeout')),
            'veo' => new VeoProvider(config('ai.providers.veo'), config('ai.timeout')),
        };
    }

    /**
     * Configured provider names of a kind ("image" | "video").
     *
     * @return list<string>
     */
    public function available(string $kind): array
    {
        return collect(config('ai.providers'))
            ->filter(fn ($p, $name) => ($p['kind'] ?? null) === $kind && $this->ready($p) && ! $this->paused($name))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Pause a provider for a while when it says it is out of quota or credits,
     * so jobs stop offering it to Fable and stop wasting turns on it.
     *
     * @return bool whether the error was a quota / billing one (provider paused)
     */
    public function noteFailure(string $name, string $error): bool
    {
        if (! preg_match('/quota|RESOURCE_EXHAUSTED|no credits|credit balance|billing|insufficient_quota|429/i', $error)) {
            return false;
        }

        Cache::put("ai:paused:{$name}", $error, now()->addMinutes(config('ai.pause_minutes', 30)));

        return true;
    }

    public function paused(string $name): bool
    {
        return Cache::has("ai:paused:{$name}");
    }

    /** A provider can be called: its API key, or Vertex AI credentials when it runs via Vertex. */
    private function ready(array $provider): bool
    {
        if (($provider['via'] ?? null) === 'vertex') {
            return Vertex::fromConfig()->ready(needsServiceAccount: ($provider['kind'] ?? null) === 'video');
        }

        return filled($provider['key'] ?? null);
    }

    public function claudeReady(): bool
    {
        return filled(config('ai.providers.anthropic.key'));
    }

    private function configured(string $name, string $kind): string
    {
        if ($this->paused($name)) {
            throw new AiException("Provider [{$name}] is paused: out of quota/credits. Use another model.");
        }

        if (! in_array($name, $this->available($kind), true)) {
            throw new AiException("Provider [{$name}] is not a configured {$kind} model.");
        }

        return $name;
    }
}
