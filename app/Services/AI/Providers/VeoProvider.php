<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google Veo through the Gemini API (same key as Gemini images).
 * POST /models/{model}:predictLongRunning → {name}; GET /{name} → done, response.generateVideoResponse.generatedSamples[0].video.uri
 */
class VeoProvider implements VideoProvider
{
    public function __construct(private array $config, private int $timeout) {}

    public function durations(): array
    {
        return [4, 6, 8];
    }

    public function submit(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): string
    {
        $instance = ['prompt' => trim($prompt)];

        if ($firstFrame) {
            $instance['image'] = ['inlineData' => ['mimeType' => $firstFrame['mime'], 'data' => base64_encode($firstFrame['data'])]];
        }

        // 1080p is only rendered for 8 s clips; shorter clips fall back to 720p.
        $resolution = $duration === 8 ? $this->config['resolution'] : '720p';

        $response = $this->client()->post("/models/{$this->config['video_model']}:predictLongRunning", [
            'instances' => [$instance],
            'parameters' => [
                'aspectRatio' => $aspect === '16:9' ? '16:9' : '9:16',
                'durationSeconds' => (string) $duration,
                'resolution' => $resolution,
            ],
        ]);

        if ($response->failed() || ! $response->json('name')) {
            throw new AiException('Veo: '.($response->json('error.message') ?? $response->body()));
        }

        return (string) $response->json('name');
    }

    public function status(string $taskId): array
    {
        $response = $this->client()->get('/'.ltrim($taskId, '/'));

        if ($response->failed() || ! $response->json('done')) {
            return ['state' => 'pending']; // transient errors keep polling until the overall timeout
        }

        if ($error = $response->json('error.message')) {
            return ['state' => 'failed', 'error' => $error];
        }

        $uri = $response->json('response.generateVideoResponse.generatedSamples.0.video.uri');

        if (! $uri) {
            $reasons = (array) $response->json('response.generateVideoResponse.raiMediaFilteredReasons', []);

            return ['state' => 'failed', 'error' => $reasons ? 'filtered: '.implode('; ', $reasons) : 'no video returned'];
        }

        return ['state' => 'done', 'url' => (string) $uri];
    }

    public function download(string $url): array
    {
        $video = Http::timeout($this->timeout)
            ->withHeaders(['x-goog-api-key' => $this->config['key']])
            ->get($url);

        if ($video->failed()) {
            throw new AiException('Veo: could not download the clip.');
        }

        return ['data' => $video->body(), 'mime' => 'video/mp4'];
    }

    public function pollInterval(): int
    {
        return $this->config['poll_interval'];
    }

    public function pollTimeout(): int
    {
        return $this->config['poll_timeout'];
    }

    private function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withHeaders(['x-goog-api-key' => $this->config['key']]);
    }
}
