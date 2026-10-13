<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Burns real typography with ffmpeg drawtext: exact letters (Mongolian
 * Cyrillic, ₮) in bundled OFL fonts. On reel clips with timed fade / slide-up;
 * on poster images as a static layout. Image and video models misspell
 * Mongolian, so every word the customer must read can be set this way.
 */
class TextOverlay
{
    public const FONTS = [
        'bold_sans' => 'Montserrat-ExtraBold.ttf',
        'sans' => 'Montserrat-Medium.ttf',
        'condensed' => 'Oswald-Bold.ttf',
        'serif' => 'PlayfairDisplay-Bold.ttf',   // no ₮ glyph: strings with ₮ fall back to bold_sans
        'rounded' => 'Nunito-ExtraBold.ttf',
    ];

    /** Font size as a share of the frame's short side. */
    private const SIZES = ['title' => 0.105, 'price' => 0.095, 'subtitle' => 0.058, 'small' => 0.04];

    /** Average glyph width relative to font size, to shrink lines that would overflow. */
    private const WIDTH = ['bold_sans' => 0.74, 'sans' => 0.6, 'condensed' => 0.5, 'serif' => 0.6, 'rounded' => 0.68];

    /**
     * @param  list<array{text: string, role: string, position: string, align?: string, font: string, color: string, box: bool, box_color: string, start: float, end: float, animation: string}>  $items
     * @param  bool  $still  an image (poster): static text, PNG out; else a clip: timed text, MP4 out
     * @return string Absolute path of the new file (caller moves it).
     */
    public function burn(string $video, array $items, float $duration, bool $still = false): string
    {
        if (! $items) {
            throw new AiException('Give at least one text item.');
        }

        [$w, $h] = $this->size($video);
        $dir = storage_path('app/private/text/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            // Pass 1: size every line; pass 2: lay the zones out top to bottom without overlaps.
            $blocks = [];

            foreach (array_values($items) as $i => $item) {
                $text = $this->clean($item['text'] ?? '');
                if ($text === '') {
                    continue;
                }

                $font = array_key_exists($item['font'] ?? '', self::FONTS) ? $item['font'] : 'bold_sans';
                if ($font === 'serif' && str_contains($text, '₮')) {
                    $font = 'bold_sans';
                }

                $longest = max(array_map('mb_strlen', explode("\n", $text)));
                $caps = mb_strtoupper($text) === $text ? 1.12 : 1.0;
                $size = (int) round(min($w, $h) * (self::SIZES[$item['role'] ?? ''] ?? self::SIZES['subtitle']));
                $size = (int) min($size, floor($w * 0.84 / max(1, $longest * self::WIDTH[$font] * $caps)));
                $soft = ! $still && empty($item['box']) && in_array($item['role'] ?? '', ['subtitle', 'small'], true); // soft dark panel on footage
                $pad = (int) round($size * (! empty($item['box']) ? 0.35 : ($soft ? 0.4 : 0)));
                $lines = substr_count($text, "\n") + 1;
                $position = in_array($item['position'] ?? '', ['top', 'center', 'lower_third', 'bottom'], true) ? $item['position'] : 'lower_third';

                $blocks[$position][] = compact('i', 'item', 'text', 'font', 'size', 'pad', 'soft') + ['height' => (int) round($size * 1.2 * $lines + $size * 0.2 * ($lines - 1)) + 2 * $pad];
            }

            $filters = [];
            $floor = 0;

            foreach (['top', 'center', 'lower_third', 'bottom'] as $position) {
                $y = max($this->baseY($position, $w, $h, $still), $floor);

                if ($position === 'bottom' && isset($blocks['bottom'])) {
                    $group = array_sum(array_map(fn ($b) => $b['height'] + (int) round($b['size'] * 0.3), $blocks['bottom']));
                    $y = max($floor, min($y, (int) round($h * ($still ? 0.95 : 0.8)) - $group));
                }

                foreach ($blocks[$position] ?? [] as $b) {
                    ['i' => $i, 'item' => $item, 'size' => $size, 'pad' => $pad, 'soft' => $soft] = $b;
                    File::copy(resource_path('fonts/'.self::FONTS[$b['font']]), "{$dir}/f{$i}.ttf");
                    File::put("{$dir}/t{$i}.txt", $b['text']);

                    $top = $y + $pad;
                    $start = max(0.0, (float) ($item['start'] ?? 0));
                    $end = min($duration, max($start + 0.5, (float) ($item['end'] ?? $duration)));
                    $in = 0.35;
                    $alpha = match (true) {
                        $still => '1',
                        ($item['animation'] ?? 'fade') === 'none' => "if(between(t,{$start},{$end}),1,0)",
                        default => "if(lt(t,{$start}),0,if(lt(t,{$start}+{$in}),(t-{$start})/{$in},if(lt(t,{$end}-0.3),1,if(lt(t,{$end}),({$end}-t)/0.3,0))))",
                    };
                    $yExpr = ! $still && ($item['animation'] ?? '') === 'slide_up'
                        ? "{$top}+".round($size * 0.6)."*(1-min(1,max(0,(t-{$start})/{$in})))"
                        : (string) $top;
                    $x = ($item['align'] ?? 'center') === 'left' ? (string) round($w * 0.07 + $pad) : '(w-text_w)/2';

                    $filter = "drawtext=fontfile=f{$i}.ttf:textfile=t{$i}.txt:expansion=none:fontsize={$size}:line_spacing=".round($size * 0.2)
                        .":fontcolor={$this->color($item['color'] ?? '', 'FFFFFF')}:x={$x}:y='{$yExpr}':alpha='{$alpha}'";
                    $filter .= match (true) {
                        // Small lines get a soft dark panel so they stay readable over busy footage.
                        $soft => ":box=1:boxcolor=black@0.38:boxborderw={$pad}:shadowcolor=black@0.4:shadowx=0:shadowy=1",
                        $pad > 0 => ":box=1:boxcolor={$this->color($item['box_color'] ?? '', '000000')}@0.82:boxborderw={$pad}",
                        $this->light($item['color'] ?? '') => ':shadowcolor=black@0.55:shadowx=0:shadowy='.max(2, round($size * 0.05)),
                        default => '',
                    };

                    $filters[] = $filter;
                    $y += $b['height'] + (int) round($size * 0.3);
                }

                $floor = $y + (int) round(min($w, $h) * 0.02);
            }

            if (! $filters) {
                throw new AiException('All text items were empty.');
            }

            $ext = $still ? 'png' : 'mp4';
            File::copy($video, "{$dir}/in.".pathinfo($video, PATHINFO_EXTENSION));
            $input = 'in.'.pathinfo($video, PATHINFO_EXTENSION);
            $result = Process::path($dir)->timeout(600)->run($still
                ? [config('ai.ffmpeg'), '-y', '-i', $input, '-vf', implode(',', $filters), '-frames:v', '1', 'out.png']
                : [
                    config('ai.ffmpeg'), '-y', '-i', $input, '-vf', implode(',', $filters),
                    '-map', '0:v', '-map', '0:a?', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-c:a', 'copy',
                    'out.mp4',
                ]);

            if ($result->failed()) {
                throw new AiException('Text overlay failed: '.Str::limit(trim($result->errorOutput()), 300));
            }

            $keep = storage_path('app/private/text/'.Str::uuid().".{$ext}");
            File::move("{$dir}/out.{$ext}", $keep);

            return $keep;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Top of the text block; keeps clear of app UI on reels and Stories (9:16). */
    private function baseY(string $position, int $w, int $h, bool $still = false): int
    {
        $story = $h > $w * 1.5;
        $ui = ! $still || $story;

        return (int) round($h * match ($position) {
            'top' => $ui ? ($h > $w ? 0.15 : 0.09) : 0.07,
            'center' => 0.42,
            'bottom' => $ui ? ($h > $w ? 0.62 : 0.78) : 0.82,
            default => $ui ? ($h > $w ? 0.55 : 0.66) : 0.64, // lower_third
        });
    }

    /** Light text needs a shadow to separate it from photos; dark text does not. */
    private function light(string $hex): bool
    {
        $hex = substr($this->color($hex, 'FFFFFF'), 2);
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.55;
    }

    private function color(string $hex, string $fallback): string
    {
        $hex = ltrim(trim($hex), '#');

        return '0x'.(preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : $fallback);
    }

    private function clean(string $text): string
    {
        $text = str_replace(['\\n', "\r"], ["\n", ''], $text);
        $text = preg_replace('/[^\P{C}\n]/u', '', $text);

        return Str::limit(trim($text), 90, '');
    }

    /** @return array{0: int, 1: int} */
    private function size(string $video): array
    {
        $out = Process::timeout(60)->run([config('ai.ffprobe'), '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $video])->output();
        [$w, $h] = array_map('intval', explode(',', trim($out)) + [0, 0]);

        if ($w < 16 || $h < 16) {
            throw new AiException('Could not read the clip size.');
        }

        return [$w, $h];
    }
}
