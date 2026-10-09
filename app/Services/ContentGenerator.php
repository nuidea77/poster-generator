<?php

namespace App\Services;

use App\Services\AI\AiManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentGenerator
{
    public const LANGUAGES = [
        'mn' => 'Mongolian (Cyrillic script)',
        'en' => 'English',
    ];

    public const FONTS = ['modern', 'elegant', 'bold', 'playful'];

    public const LAYOUTS = ['center', 'bottom', 'top', 'split'];

    public const MOTIONS = ['zoom-in', 'zoom-out', 'pan-left', 'pan-right'];

    public function __construct(private AiManager $ai) {}

    /**
     * @param  array{prompt: string, language: string, style?: string|null, format: string}  $input
     */
    public function poster(array $input, string $provider): array
    {
        $system = <<<'TXT'
        You are a senior art director and copywriter who designs high-converting social media posters.
        Return JSON with exactly these keys:
        {
          "tagline": "very short label above the headline, max 3 words",
          "headline": "punchy headline, max 7 words",
          "subheadline": "supporting line, max 12 words",
          "body": "one or two short sentences with key details (date, price, place) if relevant",
          "cta": "call to action, max 3 words",
          "font": "one of: modern | elegant | bold | playful",
          "layout": "one of: center | bottom | top | split",
          "palette": {"background": "#hex", "primary": "#hex", "accent": "#hex", "text": "#hex"},
          "image_prompt": "detailed ENGLISH prompt for the background image (subject, composition, lighting, style). Leave clean negative space for text. Never ask for text, letters or logos in the image.",
          "caption": "social media caption for the post",
          "hashtags": ["#tag", "..."]
        }
        Make sure "text" color has strong contrast against the image/background.
        TXT;

        $prompt = $this->brief($input)."\nPoster format: {$input['format']}.";

        $data = $this->ai->text($provider)->generateJson($system, $prompt);

        return $this->normalizePoster($data);
    }

    /**
     * @param  array{prompt: string, language: string, style?: string|null, duration: int, scenes: int}  $input
     */
    public function reel(array $input, string $provider): array
    {
        $system = <<<'TXT'
        You are a viral short-form vertical video (Instagram Reels / TikTok) creative director.
        Write a storyboard as JSON with exactly these keys:
        {
          "title": "internal title",
          "hook": "the first-second hook line",
          "caption": "post caption",
          "hashtags": ["#tag", "..."],
          "music_mood": "suggested background music mood",
          "font": "one of: modern | elegant | bold | playful",
          "palette": {"background": "#hex", "primary": "#hex", "accent": "#hex", "text": "#hex"},
          "scenes": [
            {
              "duration": seconds as integer (2-6),
              "text": "on-screen overlay text, max 8 words",
              "subtext": "optional smaller line, may be empty",
              "voiceover": "what the narrator says during this scene",
              "image_prompt": "detailed ENGLISH prompt for a vertical 9:16 visual. No text, letters or logos.",
              "motion": "one of: zoom-in | zoom-out | pan-left | pan-right"
            }
          ]
        }
        Scene 1 must be a scroll-stopping hook. The last scene must be a clear call to action.
        Keep the visual style consistent across all scenes.
        TXT;

        $prompt = $this->brief($input)
            ."\nTotal length: about {$input['duration']} seconds."
            ."\nNumber of scenes: {$input['scenes']}.";

        $data = $this->ai->text($provider)->generateJson($system, $prompt);

        return $this->normalizeReel($data);
    }

    /**
     * Generate an image and store it on the public disk. Returns its URL,
     * or null when the provider does not render images (demo).
     */
    public function image(string $prompt, string $aspect, string $provider, array $references = []): ?string
    {
        $prompt = trim($prompt).'. High quality, professional, no text, no letters, no watermark, no logo.';

        $image = $this->ai->image($provider)->generateImage($prompt, $aspect, $references);

        return $image === null ? null : self::store($image);
    }

    /**
     * Generate a short video clip with a video provider and store it.
     */
    public function video(string $prompt, string $aspect, int $duration, string $provider, ?array $firstFrame = null): string
    {
        return self::store($this->ai->video($provider)->generateVideo($prompt, $aspect, $duration, $firstFrame));
    }

    /**
     * Store a generated binary on the public disk and return its URL.
     *
     * @param  array{data: string, mime: string}  $file
     */
    public static function store(array $file): string
    {
        $ext = match ($file['mime']) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'png',
        };

        $path = 'generated/'.now()->format('Y/m').'/'.Str::uuid().'.'.$ext;
        Storage::disk('public')->put($path, $file['data']);

        // Relative URL keeps the file same-origin so the canvas can export it.
        return '/storage/'.$path;
    }

    private function brief(array $input): string
    {
        $language = self::LANGUAGES[$input['language']] ?? 'English';

        return "Brief: {$input['prompt']}"
            .(filled($input['style'] ?? null) ? "\nVisual style: {$input['style']}" : '')
            ."\nWrite all on-screen text, captions and voiceover in {$language}."
            ."\nimage_prompt values must always be in English.";
    }

    public function normalizePoster(array $d): array
    {
        return [
            'tagline' => (string) ($d['tagline'] ?? ''),
            'headline' => (string) ($d['headline'] ?? ''),
            'subheadline' => (string) ($d['subheadline'] ?? ''),
            'body' => (string) ($d['body'] ?? ''),
            'cta' => (string) ($d['cta'] ?? ''),
            'font' => $this->oneOf($d['font'] ?? null, self::FONTS),
            'layout' => $this->oneOf($d['layout'] ?? null, self::LAYOUTS),
            'palette' => $this->palette($d['palette'] ?? []),
            'image_prompt' => (string) ($d['image_prompt'] ?? ''),
            'caption' => (string) ($d['caption'] ?? ''),
            'hashtags' => array_values(array_map('strval', Arr::wrap($d['hashtags'] ?? []))),
            'image_url' => null,
        ];
    }

    public function normalizeReel(array $d): array
    {
        $scenes = collect(Arr::wrap($d['scenes'] ?? []))
            ->filter(fn ($s) => is_array($s))
            ->map(fn (array $s) => [
                'duration' => max(1, min(10, (int) ($s['duration'] ?? 3))),
                'text' => (string) ($s['text'] ?? ''),
                'subtext' => (string) ($s['subtext'] ?? ''),
                'voiceover' => (string) ($s['voiceover'] ?? ''),
                'image_prompt' => (string) ($s['image_prompt'] ?? ''),
                'motion' => $this->oneOf($s['motion'] ?? null, self::MOTIONS),
                'image_url' => null,
                'video_url' => null,
            ])
            ->values()
            ->all();

        return [
            'title' => (string) ($d['title'] ?? ''),
            'hook' => (string) ($d['hook'] ?? ''),
            'caption' => (string) ($d['caption'] ?? ''),
            'hashtags' => array_values(array_map('strval', Arr::wrap($d['hashtags'] ?? []))),
            'music_mood' => (string) ($d['music_mood'] ?? ''),
            'font' => $this->oneOf($d['font'] ?? null, self::FONTS),
            'palette' => $this->palette($d['palette'] ?? []),
            'scenes' => $scenes,
        ];
    }

    private function palette(mixed $p): array
    {
        $defaults = ['background' => '#111827', 'primary' => '#6366f1', 'accent' => '#f59e0b', 'text' => '#ffffff'];
        $p = is_array($p) ? $p : [];

        foreach ($defaults as $key => $fallback) {
            $value = $p[$key] ?? null;
            $defaults[$key] = is_string($value) && preg_match('/^#[0-9a-f]{3,8}$/i', $value) ? $value : $fallback;
        }

        return $defaults;
    }

    private function oneOf(mixed $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }
}
