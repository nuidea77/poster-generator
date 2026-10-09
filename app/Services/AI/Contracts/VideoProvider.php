<?php

namespace App\Services\AI\Contracts;

interface VideoProvider
{
    /**
     * Generate a short video clip and return its raw binary contents.
     *
     * @param  string  $aspect  One of "9:16", "16:9", "1:1".
     * @param  array{data: string, mime: string}|null  $firstFrame  Optional image to animate (image-to-video).
     * @return array{data: string, mime: string}
     */
    public function generateVideo(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): array;
}
