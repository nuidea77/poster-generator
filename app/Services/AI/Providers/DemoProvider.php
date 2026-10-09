<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\TextProvider;
use Illuminate\Support\Str;

/**
 * Offline provider used when no API key is configured. Produces
 * deterministic placeholder content so the editor can be tried out.
 */
class DemoProvider implements ImageProvider, TextProvider
{
    public function generateJson(string $system, string $prompt): array
    {
        preg_match('/Brief:\s*(.+)/u', $prompt, $m);
        $topic = Str::limit(trim($m[1] ?? $prompt), 40, '');
        $palette = ['background' => '#0f172a', 'primary' => '#f97316', 'accent' => '#facc15', 'text' => '#ffffff'];

        if (str_contains($system, 'short-form vertical video')) {
            return [
                'title' => $topic,
                'hook' => 'Энийг алгасаж болохгүй!',
                'caption' => "{$topic} — дэлгэрэнгүйг профайлаас үзээрэй.",
                'hashtags' => ['#mongolia', '#reels', '#ai'],
                'music_mood' => 'upbeat electronic',
                'font' => 'bold',
                'palette' => $palette,
                'scenes' => [
                    ['duration' => 3, 'text' => 'Энийг алгасаж болохгүй!', 'subtext' => '', 'voiceover' => 'Энийг алгасаж болохгүй!', 'image_prompt' => 'abstract neon gradient', 'motion' => 'zoom-in'],
                    ['duration' => 4, 'text' => $topic, 'subtext' => 'Шинэ боломж', 'voiceover' => $topic, 'image_prompt' => 'city lights at night', 'motion' => 'pan-left'],
                    ['duration' => 4, 'text' => 'Хурдан. Хялбар. Найдвартай.', 'subtext' => '', 'voiceover' => 'Хурдан, хялбар, найдвартай.', 'image_prompt' => 'modern workspace', 'motion' => 'zoom-out'],
                    ['duration' => 4, 'text' => 'Одоо туршаад үз', 'subtext' => 'Линк профайлд', 'voiceover' => 'Одоо туршаад үзээрэй.', 'image_prompt' => 'happy people', 'motion' => 'pan-right'],
                ],
            ];
        }

        return [
            'tagline' => 'ШИНЭ',
            'headline' => $topic,
            'subheadline' => 'Таны хүлээж байсан боломж',
            'body' => 'Demo горим: .env файлд API түлхүүрээ оруулбал жинхэнэ AI агуулга үүснэ.',
            'cta' => 'Дэлгэрэнгүй',
            'font' => 'bold',
            'layout' => 'bottom',
            'palette' => $palette,
            'image_prompt' => 'abstract neon gradient',
            'caption' => "{$topic} ✨",
            'hashtags' => ['#mongolia', '#poster', '#ai'],
        ];
    }

    public function generateImage(string $prompt, string $aspect): ?array
    {
        return null;
    }
}
