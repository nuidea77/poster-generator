<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Vertex;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements ImageProvider
{
    public function __construct(private array $config, private int $timeout) {}

    public function generateImage(string $prompt, string $aspect, array $references = []): array
    {
        return $this->parseImage($this->imageRequest(Http::createPendingRequest(), $prompt, $aspect, $references));
    }

    public function imageRequest(PendingRequest $http, string $prompt, string $aspect, array $references = []): Response|PromiseInterface
    {
        $parts = array_map(fn (array $ref) => [
            'inlineData' => ['mimeType' => $ref['mime'], 'data' => base64_encode($ref['data'])],
        ], $references);
        $parts[] = ['text' => $prompt];

        $model = $this->config['image_model'];

        if (($this->config['via'] ?? null) === 'vertex') {
            $vertex = Vertex::fromConfig();
            [$http, $url] = [$vertex->authorize($http), $vertex->generateContentUrl($model)];
        } else {
            [$http, $url] = [$http->withHeaders(['x-goog-api-key' => $this->config['key']]), rtrim($this->config['base_url'], '/')."/models/{$model}:generateContent"];
        }

        return $http->timeout($this->timeout)
            ->post($url, [
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => ['aspectRatio' => $aspect] + (filled($this->config['image_size'] ?? null) ? ['imageSize' => $this->config['image_size']] : []),
                ],
            ]);
    }

    public function parseImage(Response $response): array
    {
        if ($response->failed()) {
            throw new AiException('Gemini: '.($response->json('error.message') ?? $response->body()));
        }

        foreach ($response->json('candidates.0.content.parts') ?? [] as $part) {
            if (isset($part['inlineData']['data'])) {
                return [
                    'data' => base64_decode($part['inlineData']['data']),
                    'mime' => $part['inlineData']['mimeType'] ?? 'image/png',
                ];
            }
        }

        throw new AiException('Gemini image: no image returned.');
    }
}
