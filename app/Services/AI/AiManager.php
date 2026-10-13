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
            'gemini' => new GeminiProvider($this->settings('gemini'), config('ai.timeout')),
            'gemini_pro' => new GeminiProvider($this->settings('gemini_pro'), config('ai.timeout')),
        };
    }

    public function video(string $name): VideoProvider
    {
        return match ($this->configured($name, 'video')) {
            'seedance' => new SeedanceProvider(config('ai.providers.seedance'), config('ai.timeout')),
            'veo' => new VeoProvider($this->settings('veo'), config('ai.timeout')),
        };
    }

    /**
     * Configured provider names of a kind ("image" | "video").
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

        // A pinned provider (AI_IMAGE_ONLY / AI_VIDEO_ONLY) is the only one offered while it works;
        // when it is paused or not set up, the others step in.
        $only = config("ai.only.{$kind}");

        return $pinned && $only && in_array($only, $names, true) ? [$only] : $names;
    }

    /**
     * Pause a provider for a while when it says it is out of quota or credits,
     * so jobs stop offering it to Fable and stop wasting turns on it.
     *
     * @return bool whether the error was a quota / billing one (provider paused)
     */
    public function noteFailure(string $name, string $error): bool
    {
        // Our own "is paused" refusal must not extend the pause forever.
        if (str_contains($error, "Provider [{$name}] is paused")) {
            return true;
        }

        // Out of quota / credits pauses the provider; a transient "try again later" rate limit does not.
        if (preg_match('/try again later/i', $error) || ! preg_match('/quota|no credits|credits are depleted|prepayment|credit balance|billing|insufficient_quota/i', $error)) {
            return false;
        }

        Cache::put($this->pauseKey($name), $error, now()->addMinutes(config('ai.pause_minutes', 30)));

        return true;
    }

    public function paused(string $name): bool
    {
        return Cache::has($this->pauseKey($name));
    }

    /**
     * Provider config with the route it will really take: a provider set to run
     * via Vertex falls back to the Gemini API while Vertex has no credentials.
     */
    private function settings(string $name): array
    {
        $provider = config("ai.providers.{$name}");

        if (($provider['via'] ?? null) === 'vertex' && ! $this->vertexReady($provider) && filled($provider['key'] ?? null)) {
            $provider['via'] = 'gemini_api';
        }

        return $provider;
    }

    /** Pauses are per route, so moving a provider to Vertex is not blocked by a Gemini-API outage. */
    private function pauseKey(string $name): string
    {
        $via = config("ai.providers.{$name}") ? ($this->settings($name)['via'] ?? 'direct') : 'direct';

        return "ai:paused:{$name}:{$via}";
    }

    /** A provider can be called: Vertex AI credentials when it runs via Vertex, else its API key. */
    private function ready(array $provider): bool
    {
        return (($provider['via'] ?? null) === 'vertex' && $this->vertexReady($provider)) || filled($provider['key'] ?? null);
    }

    private function vertexReady(array $provider): bool
    {
        return Vertex::fromConfig()->ready(needsServiceAccount: ($provider['kind'] ?? null) === 'video');
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

        if (! in_array($name, $this->available($kind, false), true)) {
            throw new AiException("Provider [{$name}] is not a configured {$kind} model.");
        }

        return $name;
    }
}
