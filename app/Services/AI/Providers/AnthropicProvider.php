<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\JsonExtractor;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements TextProvider
{
    public function __construct(private array $config, private int $timeout) {}

    public function generateJson(string $system, string $prompt): array
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'x-api-key' => $this->config['key'],
                'anthropic-version' => '2023-06-01',
            ])
            ->post(rtrim($this->config['base_url'], '/').'/v1/messages', [
                'model' => $this->config['text_model'],
                'max_tokens' => 8000,
                'system' => $system."\n\nRespond with a single valid JSON object only. No markdown, no commentary.",
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new AiException('Claude: '.($response->json('error.message') ?? $response->body()));
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return JsonExtractor::decode($text);
    }
}
