<?php

namespace App\Services\AI\Contracts;

/**
 * Видео үүсгэгчийн гэрээ. Видео загварууд асинхрон: даалгавар илгээгээд дараа нь
 * төлөвийг шалгана. Агент reel-ийн бүх клипийг нэг дор илгээж, хамт шалгадаг.
 */
interface VideoProvider
{
    /**
     * Загварын үүсгэж чадах клипийн уртууд (секунд).
     *
     * @return list<int>
     */
    public function durations(): array;

    /**
     * Клип үүсгэх даалгавар илгээнэ.
     *
     * @param  int  $duration  durations()-ын нэг.
     * @param  array{data: string, mime: string}|null  $firstFrame  Хөдөлгөөнд оруулах зураг (image-to-video).
     * @return string Үйлчилгээ үзүүлэгчийн даалгаврын id.
     */
    public function submit(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): string;

    /**
     * Даалгаврын одоогийн төлөвийг буцаана.
     *
     * @return array{state: 'pending'|'done'|'failed', url?: string, error?: string}
     */
    public function status(string $taskId): array;

    /**
     * Бэлэн болсон клипийг татаж авна.
     *
     * @return array{data: string, mime: string}
     */
    public function download(string $url): array;

    // Төлөв шалгах хоорондын зай (секунд).
    public function pollInterval(): int;

    // Төлөв шалгахаа зогсоох нийт хугацаа (секунд).
    public function pollTimeout(): int;
}
