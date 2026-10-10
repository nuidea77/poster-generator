<?php

namespace App\Services\AI\Contracts;

/**
 * Video models are asynchronous: submit tasks, then poll. The agent submits
 * all clips of a reel at once and polls them together.
 */
interface VideoProvider
{
    /**
     * @param  array{data: string, mime: string}|null  $firstFrame  Image to animate (image-to-video).
     * @return string Provider task id.
     */
    public function submit(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): string;

    /**
     * @return array{state: 'pending'|'done'|'failed', url?: string, error?: string}
     */
    public function status(string $taskId): array;

    /**
     * @return array{data: string, mime: string}
     */
    public function download(string $url): array;
}
