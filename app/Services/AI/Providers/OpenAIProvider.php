<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Exceptions\AiException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI GPT Image зураг үүсгэгч (images/generations, лавлах зурагтай бол images/edits).
 */
class OpenAIProvider implements ImageProvider
{
    // Харьцааг OpenAI-ийн дэмждэг гурван хэмжээний хамгийн ойрынх руу буулгана.
    private const SIZES = [
        '1:1' => '1024x1024',
        '3:4' => '1024x1536',
        '2:3' => '1024x1536',
        '4:5' => '1024x1536',
        '9:16' => '1024x1536',
        '4:3' => '1536x1024',
        '3:2' => '1536x1024',
        '5:4' => '1536x1024',
        '16:9' => '1536x1024',
        '21:9' => '1536x1024',
    ];

    public function __construct(private array $config, private int $timeout) {}

    // Нэг зургийг шууд (pool-гүй) үүсгэнэ.
    public function generateImage(string $prompt, string $aspect, array $references = []): array
    {
        return $this->parseImage($this->imageRequest(Http::createPendingRequest(), $prompt, $aspect, $references));
    }

    // Үүсгэх хүсэлт илгээнэ; лавлах зураг байвал edits endpoint ашиглана.
    public function imageRequest(PendingRequest $http, string $prompt, string $aspect, array $references = []): Response|PromiseInterface
    {
        $params = [
            'model' => $this->config['image_model'],
            'prompt' => $prompt,
            'size' => self::SIZES[$aspect] ?? '1024x1024',
            'quality' => $this->config['image_quality'],
            'n' => 1,
        ];

        $http = $http->timeout($this->timeout)
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withToken($this->config['key']);

        if (! $references) {
            return $http->post('/images/generations', $params);
        }

        // Лавлах зургууд edits endpoint-оор (multipart) дамжина.
        foreach ($references as $i => $ref) {
            $ext = $ref['mime'] === 'image/jpeg' ? 'jpg' : ($ref['mime'] === 'image/webp' ? 'webp' : 'png');
            $http = $http->attach('image[]', $ref['data'], "ref{$i}.{$ext}", ['Content-Type' => $ref['mime']]);
        }

        return $http->post('/images/edits', $params);
    }

    // Хариунаас зургийг авна: b64_json эсвэл url-аас татаж.
    public function parseImage(Response $response): array
    {
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
}
