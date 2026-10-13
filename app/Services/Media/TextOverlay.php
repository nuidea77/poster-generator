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
        'serif' => 'PlayfairDisplay-Bold.ttf',   // no ₮ glyph (falls back to bold_sans); old-style digits (fall back to condensed)
        'rounded' => 'Nunito-ExtraBold.ttf',
    ];

    /** Font size as a share of the frame's short side. */
    private const SIZES = ['display' => 0.24, 'title' => 0.105, 'price' => 0.095, 'subtitle' => 0.058, 'small' => 0.04];

    /** Average glyph width relative to font size, to shrink lines that would overflow. */
    private const WIDTH = ['bold_sans' => 0.74, 'sans' => 0.6, 'condensed' => 0.5, 'serif' => 0.6, 'rounded' => 0.68];

    /**
     * @param  list<array{text: string, role: string, position: string, align?: string, font: string, color: string, box: bool, box_color: string, start: float, end: float, animation: string}>  $items
     * @param  bool  $still  an image (poster): static text, PNG out; else a clip: timed text, MP4 out
     * @return string Absolute path of the new file (caller moves it).
     */
    /**
     * Where the last burn() put each text block, in pixels — so the caller can check what lies under it.
     *
     * @var list<array{text: string, panel: bool, rect: array{0: int, 1: int, 2: int, 3: int}}>
     */
    public array $placed = [];

    public function burn(string $video, array $items, float $duration, bool $still = false, array $reserved = []): string
    {
        $this->placed = [];

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
                } elseif ($font === 'serif' && preg_match('/\d/', $text)) {
                    $font = 'condensed'; // Playfair's old-style digits sink below the capitals ("15 ЖИЛ" looked weak)
                }

                $longest = max(array_map('mb_strlen', explode("\n", $text)));
                $caps = mb_strtoupper($text) === $text ? 1.12 : 1.0;
                $size = (int) round(min($w, $h) * (self::SIZES[$item['role'] ?? ''] ?? self::SIZES['subtitle']));
                $size = (int) min($size, floor($w * 0.84 / max(1, $longest * self::WIDTH[$font] * $caps)));
                $soft = ! $still && empty($item['box']) && in_array($item['role'] ?? '', ['subtitle', 'small'], true); // soft dark panel on footage
                $pad = (int) round($size * (! empty($item['box']) ? 0.35 : ($soft ? 0.4 : 0)));
                $lines = substr_count($text, "\n") + 1;
                $position = in_array($item['position'] ?? '', ['top', 'center', 'lower_third', 'bottom'], true) ? $item['position'] : 'lower_third';

                $display = ($item['role'] ?? '') === 'display'; // big digits/caps: no descenders, sits tight on the next line
                $blocks[$position][] = compact('i', 'item', 'text', 'font', 'size', 'pad', 'soft') + ['width' => (int) min($w * 0.9, $longest * self::WIDTH[$font] * $caps * $size) + 2 * $pad, 'height' => (int) round($size * ($display ? 0.9 : 1.2) * $lines + $size * 0.2 * ($lines - 1)) + 2 * $pad, 'gap' => (int) round($size * ($display ? 0.12 : 0.3))];
            }

            $filters = [];
            $floor = 0;

            foreach (['top', 'center', 'lower_third', 'bottom'] as $position) {
                $y = max($this->baseY($position, $w, $h, $still), $floor);

                if ($position === 'bottom' && isset($blocks['bottom'])) {
                    $group = array_sum(array_map(fn ($b) => $b['height'] + $b['gap'], $blocks['bottom']));
                    $y = max($floor, min($y, (int) round($h * ($still ? 0.95 : 0.8)) - $group));
                }

                foreach ($blocks[$position] ?? [] as $b) {
                    ['i' => $i, 'item' => $item, 'size' => $size, 'pad' => $pad, 'soft' => $soft] = $b;

                    // Keep clear of the logo (or any reserved area): move below it when they would overlap.
                    foreach ($reserved as [$x0, $y0, $x1, $y1]) {
                        $top0 = $y0 * $h - $size * 0.3;
                        $bottom0 = $y1 * $h + $size * 0.3;
                        if ($y < $bottom0 && $y + $b['height'] > $top0) {
                            $y = (int) round($bottom0);
                        }
                    }
                    File::copy(resource_path('fonts/'.self::FONTS[$b['font']]), "{$dir}/f{$i}.ttf");

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
                    $left = ($item['align'] ?? 'center') === 'left';
                    $x = $left ? (string) round($w * 0.07 + $pad) : '(w-text_w)/2';
                    $style = match (true) {
                        // Small lines get a soft dark panel so they stay readable over busy footage.
                        $soft => ":box=1:boxcolor=black@0.38:boxborderw={$pad}:shadowcolor=black@0.4:shadowx=0:shadowy=1",
                        $pad > 0 => ":box=1:boxcolor={$this->color($item['box_color'] ?? '', '000000')}@0.82:boxborderw={$pad}",
                        $this->light($item['color'] ?? '') => ':shadowcolor=black@0.55:shadowx=0:shadowy='.max(2, round($size * 0.05)),
                        default => '',
                    };
                    $common = "expansion=none:fontsize={$size}:fontcolor={$this->color($item['color'] ?? '', 'FFFFFF')}:x={$x}:alpha='{$alpha}'{$style}";

                    // drawtext centres a multi-line text as one block, leaving its lines flush left
                    // inside it; centred text is drawn line by line on a shared baseline grid.
                    $lines = $left ? [$b['text']] : explode("\n", $b['text']);
                    foreach ($lines as $k => $line) {
                        File::put("{$dir}/t{$i}_{$k}.txt", $line);
                        $lineY = count($lines) === 1 ? $yExpr : "({$yExpr})+".($k * (int) round($size * 1.4) + (int) round($size * 0.92)).'-ascent';
                        $filters[] = "drawtext=fontfile=f{$i}.ttf:textfile=t{$i}_{$k}.txt:line_spacing=".round($size * 0.2).":y='{$lineY}':{$common}";
                    }
                    $x0 = $left ? (int) round($w * 0.07) : (int) round(($w - $b['width']) / 2);
                    $this->placed[] = ['text' => $b['text'], 'panel' => $pad > 0, 'rect' => [$x0, $y, $x0 + $b['width'], $y + $b['height']]];
                    $y += $b['height'] + $b['gap'];
                }

                $floor = $y + (int) round(min($w, $h) * 0.02);
            }

            if (! $filters) {
                throw new AiException('All text items were empty (only blank lines). Put the real words in "text" as plain UTF-8 letters — no blank-line padding, no \u escapes; to clear a logo, add_logo first and the text moves below it by itself.');
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

    /**
     * Lines of the last burn() that sit on a detailed part of the clean image (the
     * product, a face, hair, busy texture) without a panel — most likely overlapping
     * the subject. Measured per 32 px tile as the mean brightness step between pixels.
     *
     * @return list<string>
     */
    public function busyLines(string $cleanImage): array
    {
        $img = @imagecreatefromstring((string) file_get_contents($cleanImage));
        if (! $img) {
            return [];
        }

        $W = imagesx($img);
        $H = imagesy($img);
        $grey = function (int $x, int $y) use ($img): float {
            $c = imagecolorat($img, $x, $y);

            return ($c >> 16 & 255) * 0.3 + ($c >> 8 & 255) * 0.59 + ($c & 255) * 0.11;
        };

        $busy = [];
        foreach ($this->placed as $p) {
            if ($p['panel']) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = [max(0, $p['rect'][0]), max(0, $p['rect'][1]), min($W - 3, $p['rect'][2]), min($H - 3, $p['rect'][3])];
            $tiles = [];
            for ($ty = $y0; $ty < $y1; $ty += 32) {
                for ($tx = $x0; $tx < $x1; $tx += 32) {
                    [$sum, $n] = [0.0, 0];
                    for ($y = $ty; $y < min($y1, $ty + 32); $y += 2) {
                        for ($x = $tx; $x < min($x1, $tx + 32); $x += 2) {
                            $v = $grey($x, $y);
                            $sum += abs($grey($x + 2, $y) - $v) + abs($grey($x, $y + 2) - $v);
                            $n++;
                        }
                    }
                    $tiles[] = $n ? $sum / $n : 0.0;
                }
            }
            $share = count(array_filter($tiles, fn ($t) => $t > 6)) / max(1, count($tiles));
            if ($share >= 0.2 || (max($tiles ?: [0]) >= 20 && $share >= 0.05)) {
                $busy[] = str_replace("\n", ' / ', $p['text']);
            }
        }

        return $busy;
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
