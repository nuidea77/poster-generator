<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Vertex;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Gemini зураг үүсгэгч: Gemini API эсвэл Vertex AI-аар (config-ийн 'via') ажиллана.
 */
class GeminiProvider implements ImageProvider
{
    public function __construct(private array $config, private int $timeout) {}

    // Нэг зургийг шууд (pool-гүй) үүсгэнэ.
    public function generateImage(string $prompt, string $aspect, array $references = []): array
    {
        return $this->parseImage($this->imageRequest(Http::createPendingRequest(), $prompt, $aspect, $references));
    }

    // generateContent хүсэлт илгээнэ: лавлах зургууд inlineData, дараа нь prompt текст.
    public function imageRequest(PendingRequest $http, string $prompt, string $aspect, array $references = []): Response|PromiseInterface
    {
        $parts = array_map(fn (array $ref) => [
            'inlineData' => ['mimeType' => $ref['mime'], 'data' => base64_encode($ref['data'])],
        ], $references);
        $parts[] = ['text' => $prompt];

        $model = $this->config['image_model'];

        // Замаас хамаарч auth ба URL сонгоно: Vertex эсвэл Gemini API key.
        if (($this->config['via'] ?? null) === 'vertex') {
            $vertex = Vertex::fromConfig();
            [$http, $url] = [$vertex->authorize($http), $vertex->generateContentUrl($this->config['vertex_image_model'] ?? $model)];
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

    // Хариунаас эхний inlineData зургийг авна; зураг ирээгүй бол шалтгааныг алдаанд оруулна.
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

        // Блоклосон шалтгаан, finishReason болон загварын текст хариуг цуглуулна.
        $why = array_filter([
            $response->json('promptFeedback.blockReason'),
            $response->json('candidates.0.finishReason'),
            $response->json('candidates.0.finishMessage'),
            collect($response->json('candidates.0.content.parts') ?? [])->pluck('text')->filter()->implode(' '),
        ]);

        throw new AiException('Gemini image: no image returned'.($why ? ' ('.Str::limit(implode('; ', $why), 300).')' : '').'. Simplify or rephrase the prompt; if it was blocked for safety, avoid real people, brands or sensitive content.');
    }
}
