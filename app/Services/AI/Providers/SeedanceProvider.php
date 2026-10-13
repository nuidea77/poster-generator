<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * ByteDance Seedance-ийг BytePlus ModelArk видео үүсгэх API-аар ашиглана.
 * POST /contents/generations/tasks → {id}; GET /contents/generations/tasks/{id} → status, content.video_url
 */
class SeedanceProvider implements VideoProvider
{
    public function __construct(private array $config, private int $timeout) {}

    // Seedance-ийн дэмждэг клипийн уртууд (секунд).
    public function durations(): array
    {
        return [5, 10];
    }

    // Видео үүсгэх даалгавар илгээж, даалгаврын id буцаана.
    public function submit(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): string
    {

        // Image-to-video харьцаагаа зурагнаас авна; text-to-video-д туг хэрэгтэй.
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

    // Даалгаврын төлөвийг Seedance-ийн статусаас pending/done/failed болгон хөрвүүлнэ.
    public function status(string $taskId): array
    {
        $response = $this->client()->get("/contents/generations/tasks/{$taskId}");

        if ($response->failed()) {
            return ['state' => 'pending']; // түр зуурын алдаа — нийт хугацаа дуустал шалгасаар байна
        }

        return match ($response->json('status')) {
            'succeeded' => ['state' => 'done', 'url' => (string) $response->json('content.video_url')],
            'failed', 'cancelled', 'expired' => ['state' => 'failed', 'error' => $response->json('error.message') ?? $response->json('status')],
            default => ['state' => 'pending'],
        };
    }

    // Бэлэн клипийг URL-аас татна.
    public function download(string $url): array
    {
        $video = Http::timeout($this->timeout)->get($url);

        if ($video->failed()) {
            throw new AiException('Seedance: could not download the clip.');
        }

        return ['data' => $video->body(), 'mime' => 'video/mp4'];
    }

    // Төлөв шалгах хоорондын зай (config-оос).
    public function pollInterval(): int
    {
        return $this->config['poll_interval'];
    }

    // Төлөв шалгах нийт хугацааны хязгаар (config-оос).
    public function pollTimeout(): int
    {
        return $this->config['poll_timeout'];
    }

    // ModelArk API-д зориулсан base URL ба token-той HTTP client.
    private function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withToken($this->config['key']);
    }
}
