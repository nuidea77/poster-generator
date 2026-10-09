<?php

namespace App\Services\AI\Contracts;

interface ImageProvider
{
    /**
     * Generate an image and return its raw binary contents.
     *
     * @param  string  $aspect  One of "1:1", "4:5", "9:16", "16:9".
     * @return array{data: string, mime: string}|null Null when the provider renders no image (demo).
     */
    public function generateImage(string $prompt, string $aspect): ?array;
}
