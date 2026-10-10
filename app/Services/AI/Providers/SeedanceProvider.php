<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * ByteDance Seedance through the BytePlus ModelArk video generation API.
 * POST /contents/generations/tasks → {id}; GET /contents/generations/tasks/{id} → status, content.video_url
 */
class SeedanceProvider implements VideoProvider
{
    public function __construct(private array $config, private int $timeout) {}

    public function submit(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): string
    {
        $duration = $duration >= 8 ? 10 : 5;

        // Image-to-video takes its ratio from the frame; text-to-video needs the flag.
        $text = trim($prompt).' --duration '.$duration.($firstFrame ? '' : ' --ratio '.$aspect);

        $content = [['type' => 'text', 'text' => $text]];

        if ($firstFrame) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:'.$firstFrame['mime'].';base64,'.base64_encode($firstFrame['data'])],
                'role' => 'first_frame',
            ];
        }

        $response = $this->client()->post('/contents/generations/tasks', [
            'model' => $this->config['video_model'],
            'content' => $content,
        ]);

        if ($response->failed() || ! $response->json('id')) {
            throw new AiException('Seedance: '.($response->json('error.message') ?? $response->body()));
        }

        return (string) $response->json('id');
    }

    public function status(string $taskId): array
    {
        $response = $this->client()->get("/contents/generations/tasks/{$taskId}");

        if ($response->failed()) {
            return ['state' => 'pending']; // transient — keep polling until the overall timeout
        }

        return match ($response->json('status')) {
            'succeeded' => ['state' => 'done', 'url' => (string) $response->json('content.video_url')],
            'failed', 'cancelled', 'expired' => ['state' => 'failed', 'error' => $response->json('error.message') ?? $response->json('status')],
            default => ['state' => 'pending'],
        };
    }

    public function download(string $url): array
    {
        $video = Http::timeout($this->timeout)->get($url);

        if ($video->failed()) {
            throw new AiException('Seedance: could not download the clip.');
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
            ->withToken($this->config['key']);
    }
}
