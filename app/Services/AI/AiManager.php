<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AI\Providers\SeedanceProvider;

class AiManager
{
    public function image(string $name): ImageProvider
    {
        return match ($this->configured($name, 'image')) {
            'openai' => new OpenAIProvider(config('ai.providers.openai'), config('ai.timeout')),
            'gemini' => new GeminiProvider(config('ai.providers.gemini'), config('ai.timeout')),
        };
    }

    public function video(string $name): VideoProvider
    {
        return match ($this->configured($name, 'video')) {
            'seedance' => new SeedanceProvider(config('ai.providers.seedance'), config('ai.timeout')),
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
            ->filter(fn ($p) => ($p['kind'] ?? null) === $kind && filled($p['key'] ?? null))
            ->keys()
            ->values()
            ->all();
    }

    public function claudeReady(): bool
    {
        return filled(config('ai.providers.anthropic.key'));
    }

    private function configured(string $name, string $kind): string
    {
        if (! in_array($name, $this->available($kind), true)) {
            throw new AiException("Provider [{$name}] is not a configured {$kind} model.");
        }

        return $name;
    }
}
