<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\Http;

/**
 * ByteDance Seedance through the BytePlus ModelArk video generation API.
 * Creating a task returns an id; the clip is polled until "succeeded".
 */
class SeedanceProvider implements VideoProvider
{
    public function __construct(private array $config, private int $timeout) {}

    public function generateVideo(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): array
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

        $created = $this->client()->post('/contents/generations/tasks', [
            'model' => $this->config['video_model'],
            'content' => $content,
        ]);

        if ($created->failed()) {
            throw new AiException('Seedance: '.($created->json('error.message') ?? $created->body()));
        }

        $id = $created->json('id');
        $deadline = time() + $this->config['poll_timeout'];

        while (time() < $deadline) {
            sleep($this->config['poll_interval']);

            $task = $this->client()->get("/contents/generations/tasks/{$id}");

            if ($task->failed()) {
                throw new AiException('Seedance: '.($task->json('error.message') ?? $task->body()));
            }

            $status = $task->json('status');

            if ($status === 'succeeded') {
                $url = $task->json('content.video_url');
                $video = Http::timeout($this->timeout)->get($url);

                if ($video->failed()) {
                    throw new AiException('Seedance: could not download the video.');
                }

                return ['data' => $video->body(), 'mime' => 'video/mp4'];
            }

            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                throw new AiException('Seedance: task '.$status.' — '.($task->json('error.message') ?? 'no details'));
            }
        }

        throw new AiException('Seedance: video generation timed out.');
    }

    private function client()
    {
        return Http::timeout($this->timeout)
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withToken($this->config['key']);
    }
}
