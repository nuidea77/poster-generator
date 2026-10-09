<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\JsonExtractor;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements ImageProvider, TextProvider
{
    public function __construct(private array $config, private int $timeout) {}

    public function generateJson(string $system, string $prompt): array
    {
        $response = $this->generateContent($this->config['text_model'], [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ]);

        $text = collect($response['candidates'][0]['content']['parts'] ?? [])
            ->pluck('text')
            ->filter()
            ->implode('');

        return JsonExtractor::decode($text);
    }

    public function generateImage(string $prompt, string $aspect, array $references = []): ?array
    {
        $parts = array_map(fn (array $ref) => [
            'inlineData' => ['mimeType' => $ref['mime'], 'data' => base64_encode($ref['data'])],
        ], $references);
        $parts[] = ['text' => $prompt];

        $response = $this->generateContent($this->config['image_model'], [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['aspectRatio' => $aspect],
            ],
        ]);

        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['data'])) {
                return [
                    'data' => base64_decode($part['inlineData']['data']),
                    'mime' => $part['inlineData']['mimeType'] ?? 'image/png',
                ];
            }
        }

        throw new AiException('Gemini image: no image returned.');
    }

    private function generateContent(string $model, array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders(['x-goog-api-key' => $this->config['key']])
            ->post(rtrim($this->config['base_url'], '/')."/models/{$model}:generateContent", $payload);

        if ($response->failed()) {
            throw new AiException('Gemini: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json();
    }
}
