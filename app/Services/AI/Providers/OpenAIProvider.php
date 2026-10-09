<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\JsonExtractor;
use Illuminate\Support\Facades\Http;

class OpenAIProvider implements ImageProvider, TextProvider
{
    private const SIZES = [
        '1:1' => '1024x1024',
        '4:5' => '1024x1536',
        '9:16' => '1024x1536',
        '16:9' => '1536x1024',
    ];

    public function __construct(private array $config, private int $timeout) {}

    public function generateJson(string $system, string $prompt): array
    {
        $response = $this->client()->post('/chat/completions', [
            'model' => $this->config['text_model'],
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system."\n\nRespond with a single valid JSON object only."],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if ($response->failed()) {
            throw new AiException('OpenAI: '.($response->json('error.message') ?? $response->body()));
        }

        return JsonExtractor::decode((string) $response->json('choices.0.message.content'));
    }

    public function generateImage(string $prompt, string $aspect): ?array
    {
        $response = $this->client()->post('/images/generations', [
            'model' => $this->config['image_model'],
            'prompt' => $prompt,
            'size' => self::SIZES[$aspect] ?? '1024x1024',
            'n' => 1,
        ]);

        if ($response->failed()) {
            throw new AiException('OpenAI image: '.($response->json('error.message') ?? $response->body()));
        }

        if ($b64 = $response->json('data.0.b64_json')) {
            return ['data' => base64_decode($b64), 'mime' => 'image/png'];
        }

        if ($url = $response->json('data.0.url')) {
            $image = Http::timeout($this->timeout)->get($url);

            return ['data' => $image->body(), 'mime' => $image->header('Content-Type') ?: 'image/png'];
        }

        throw new AiException('OpenAI image: empty response.');
    }

    private function client()
    {
        return Http::timeout($this->timeout)
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withToken($this->config['key']);
    }
}
