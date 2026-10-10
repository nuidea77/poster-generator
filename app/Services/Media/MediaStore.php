<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files live on the public disk under unguessable UUID names. URLs are
 * relative so the SPA loads them same-origin.
 */
class MediaStore
{
    public const EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /**
     * @return array{path: string, url: string}
     */
    public static function put(string $dir, string $data, string $mime): array
    {
        $path = trim($dir, '/').'/'.Str::uuid().'.'.(self::EXT[$mime] ?? 'bin');
        Storage::disk('public')->put($path, $data);

        return ['path' => $path, 'url' => self::url($path)];
    }

    public static function url(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    public static function absolute(string $path): string
    {
        return Storage::disk('public')->path($path);
    }

    /**
     * @return array{data: string, mime: string}
     */
    public static function read(string $path): array
    {
        $disk = Storage::disk('public');

        return ['data' => $disk->get($path), 'mime' => $disk->mimeType($path) ?: 'application/octet-stream'];
    }

    public static function deleteDirectory(string $dir): void
    {
        Storage::disk('public')->deleteDirectory($dir);
    }
}
