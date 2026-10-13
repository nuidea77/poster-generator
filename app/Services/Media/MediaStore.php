<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Файлууд public диск дээр таамаглах боломжгүй UUID нэрээр хадгалагдана.
 * URL нь харьцангуй тул SPA тэдгээрийг ижил origin-оос ачаална.
 */
class MediaStore
{
    // MIME төрөл → файлын өргөтгөл (танихгүй төрөл бол 'bin').
    public const EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /**
     * Өгөгдлийг $dir хавтас дотор шинэ UUID нэртэй файл болгон хадгална.
     *
     * @return array{path: string, url: string}
     */
    public static function put(string $dir, string $data, string $mime): array
    {
        $path = trim($dir, '/').'/'.Str::uuid().'.'.(self::EXT[$mime] ?? 'bin');
        Storage::disk('public')->put($path, $data);

        return ['path' => $path, 'url' => self::url($path)];
    }

    // Диск дээрх замаас SPA-д зориулсан харьцангуй URL гаргана.
    public static function url(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    // ffmpeg/GD-д өгөх файлын системийн бүтэн зам.
    public static function absolute(string $path): string
    {
        return Storage::disk('public')->path($path);
    }

    /**
     * Файлын агуулга болон MIME төрлийг уншина (тодорхойгүй бол octet-stream).
     *
     * @return array{data: string, mime: string}
     */
    public static function read(string $path): array
    {
        $disk = Storage::disk('public');

        return ['data' => $disk->get($path), 'mime' => $disk->mimeType($path) ?: 'application/octet-stream'];
    }

    // Хавтсыг доторх бүх файлтай нь устгана (жишээ нь бүтээлийг устгахад).
    public static function deleteDirectory(string $dir): void
    {
        Storage::disk('public')->deleteDirectory($dir);
    }
}
