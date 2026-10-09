<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Providers\AnthropicProvider;
use App\Services\AI\Providers\DemoProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;

class AiManager
{
    public const TEXT_PROVIDERS = ['anthropic', 'openai', 'gemini', 'demo'];

    public const IMAGE_PROVIDERS = ['openai', 'gemini', 'demo'];

    public function text(?string $name = null): TextProvider
    {
        $name ??= config('ai.default_text');

        if (! in_array($name, self::TEXT_PROVIDERS, true)) {
            throw new AiException("Unknown text provider [{$name}].");
        }

        return $this->make($name);
    }

    public function image(?string $name = null): ImageProvider
    {
        $name ??= config('ai.default_image');

        if (! in_array($name, self::IMAGE_PROVIDERS, true)) {
            throw new AiException("Unknown image provider [{$name}].");
        }

        return $this->make($name);
    }

    public function isConfigured(string $name): bool
    {
        return $name === 'demo' || filled(config("ai.providers.{$name}.key"));
    }

    /**
     * Provider list for the frontend (never exposes keys).
     */
    public function describe(): array
    {
        $providers = collect(config('ai.providers'))
            ->map(fn (array $p, string $name) => [
                'id' => $name,
                'label' => $p['label'],
                'configured' => $this->isConfigured($name),
                'text' => in_array($name, self::TEXT_PROVIDERS, true),
                'image' => in_array($name, self::IMAGE_PROVIDERS, true),
                'text_model' => $p['text_model'] ?? null,
                'image_model' => $p['image_model'] ?? null,
            ])
            ->values()
            ->push([
                'id' => 'demo', 'label' => 'Demo', 'configured' => true,
                'text' => true, 'image' => true, 'text_model' => null, 'image_model' => null,
            ]);

        return [
            'providers' => $providers,
            'default_text' => $this->firstConfigured(config('ai.default_text'), self::TEXT_PROVIDERS),
            'default_image' => $this->firstConfigured(config('ai.default_image'), self::IMAGE_PROVIDERS),
        ];
    }

    private function firstConfigured(string $preferred, array $candidates): string
    {
        if ($this->isConfigured($preferred)) {
            return $preferred;
        }

        foreach ($candidates as $name) {
            if ($this->isConfigured($name)) {
                return $name;
            }
        }

        return 'demo';
    }

    private function make(string $name): TextProvider|ImageProvider
    {
        if ($name === 'demo') {
            return new DemoProvider;
        }

        if (! $this->isConfigured($name)) {
            throw new AiException("Provider [{$name}] has no API key. Set it in .env.");
        }

        $config = config("ai.providers.{$name}");
        $timeout = config('ai.timeout');

        return match ($name) {
            'anthropic' => new AnthropicProvider($config, $timeout),
            'openai' => new OpenAIProvider($config, $timeout),
            'gemini' => new GeminiProvider($config, $timeout),
        };
    }
}
