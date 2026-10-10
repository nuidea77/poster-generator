<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\Http;

class OpenAIProvider implements ImageProvider
{
    private const SIZES = [
        '1:1' => '1024x1024',
        '4:5' => '1024x1536',
        '9:16' => '1024x1536',
        '16:9' => '1536x1024',
    ];

    public function __construct(private array $config, private int $timeout) {}

    public function generateImage(string $prompt, string $aspect, array $references = []): array
    {
        $params = [
            'model' => $this->config['image_model'],
            'prompt' => $prompt,
            'size' => self::SIZES[$aspect] ?? '1024x1024',
            'n' => 1,
        ];

        if ($references) {
            // Reference images go through the edits endpoint (multipart).
            $client = $this->client();
            foreach ($references as $i => $ref) {
                $ext = $ref['mime'] === 'image/jpeg' ? 'jpg' : ($ref['mime'] === 'image/webp' ? 'webp' : 'png');
                $client = $client->attach('image[]', $ref['data'], "ref{$i}.{$ext}", ['Content-Type' => $ref['mime']]);
            }
            $response = $client->post('/images/edits', $params);
        } else {
            $response = $this->client()->post('/images/generations', $params);
        }

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
