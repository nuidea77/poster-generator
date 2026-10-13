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
    private const SIZES = ['display' => 0.24, 'title' => 0.105, 'price' => 0.095, 'subtitle' => 0.058, 'small' => 0.034];

    /** Average glyph width relative to font size, to shrink lines that would overflow. */
    private const WIDTH = ['bold_sans' => 0.74, 'sans' => 0.6, 'condensed' => 0.5, 'serif' => 0.6, 'rounded' => 0.68];

    /**
     * Where the last burn() put each text block, in pixels — so the caller can check what lies under it.
     *
     * @var list<array{text: string, panel: bool, rect: array{0: int, 1: int, 2: int, 3: int}}>
     */
    public array $placed = [];

    /** The scrim of the last burn(): lines on it are readable like on a panel. */
    private string $lastScrim = 'none';

    public const SCRIMS = ['none', 'top', 'bottom', 'top_bottom'];

    /**
     * @param  list<array{text: string, role: string, position: string, align?: string, font: string, color: string, box: bool, box_color: string, start: float, end: float, animation: string, icon?: string}>  $items
     * @param  bool  $still  an image (poster): static text, PNG out; else a clip: timed text, MP4 out
     * @param  string  $scrim  soft gradient behind the text zones: none | top | bottom | top_bottom
     * @return string Absolute path of the new file (caller moves it).
     */
    public function burn(string $video, array $items, float $duration, bool $still = false, array $reserved = [], string $scrim = 'none', string $scrimColor = '000000'): string
    {
        $this->placed = [];
        $this->lastScrim = $scrim;

        if (! $items) {
            throw new AiException('Give at least one text item.');
        }

        [$w, $h] = $this->size($video);
        $dir = storage_path('app/private/text/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            // Pass 1: size every line; pass 2: lay the zones out top to bottom without overlaps.
            $blocks = [];

            $list = array_values($items);
            foreach ($list as $i => $item) {
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

                // same_line: this item shares the row with the previous one (e.g. phone left, delivery right);
                // each of the pair gets half the width, and a centred pair is split left / right.
                $same = ! empty($item['same_line']) && $i > 0;
                $paired = $same || ! empty($list[$i + 1]['same_line'] ?? false);
                $share = $paired ? 0.5 : 1.0;
                $align = in_array($item['align'] ?? '', ['left', 'right', 'center'], true) ? $item['align'] : 'center';
                if ($paired && $align === 'center') {
                    $align = $same ? 'right' : 'left';
                }

                $longest = max(array_map('mb_strlen', explode("\n", $text)));
                $caps = mb_strtoupper($text) === $text ? 1.12 : 1.0;
                $size = (int) round(min($w, $h) * (self::SIZES[$item['role'] ?? ''] ?? self::SIZES['subtitle']));
                $size = (int) min($size, floor($w * 0.84 * $share / max(1, $longest * self::WIDTH[$font] * $caps)));
                // Then measure the real glyphs (wide serif capitals beat the estimate): the widest
                // line plus its panel must stay inside 86% of the width.
                $fontFile = resource_path('fonts/'.self::FONTS[$font]);
                $widest = max(array_map(fn ($line) => ($box = imagettfbbox(100 * 0.75, 0, $fontFile, $line)) ? $box[2] - $box[0] : 0, explode("\n", $text)));
                $boxed = ! empty($item['box']) ? 0.7 : 0.0;
                $size = (int) max(8, min($size, floor($w * ($paired ? 0.4 : 0.86) / max(0.01, $widest / 100 + $boxed + ($paired && ($item['icon'] ?? 'none') !== 'none' ? 1.3 : 0)))));
                $soft = ! $still && empty($item['box']) && in_array($item['role'] ?? '', ['subtitle', 'small'], true); // soft dark panel on footage
                $pad = (int) round($size * (! empty($item['box']) ? 0.35 : ($soft ? 0.4 : 0)));
                $lines = substr_count($text, "\n") + 1;
                $position = in_array($item['position'] ?? '', ['top', 'center', 'lower_third', 'bottom'], true) ? $item['position'] : 'lower_third';

                $display = ($item['role'] ?? '') === 'display'; // big digits/caps: no descenders, sits tight on the next line
                $blocks[$position][] = compact('i', 'item', 'text', 'font', 'size', 'pad', 'soft', 'same', 'align') + ['width' => (int) min($w * 0.9, $longest * self::WIDTH[$font] * $caps * $size) + 2 * $pad, 'height' => (int) round($size * ($display ? 0.9 : 1.2) * $lines + $size * 0.2 * ($lines - 1)) + 2 * $pad, 'gap' => (int) round($size * ($display ? 0.12 : 0.3))];
            }

            $filters = [];
            $icons = []; // [file, x, y, enable]
            $floor = 0;

            foreach (['top', 'center', 'lower_third', 'bottom'] as $position) {
                $y = max($this->baseY($position, $w, $h, $still), $floor);

                if ($position === 'bottom' && isset($blocks['bottom'])) {
                    $group = array_sum(array_map(fn ($b) => $b['same'] ? 0 : $b['height'] + $b['gap'], $blocks['bottom']));
                    $y = max($floor, min($y, (int) round($h * ($still ? 0.95 : 0.8)) - $group));
                }

                $row = null; // the row the last block started on: ['y' => top, 'end' => next free y]
                foreach ($blocks[$position] ?? [] as $b) {
                    ['i' => $i, 'item' => $item, 'size' => $size, 'pad' => $pad, 'soft' => $soft] = $b;
                    $same = $b['same'] && $row;
                    if ($same) {
                        $y = $row['y'];
                    }

                    // Keep clear of the logo (or any reserved area): move below it when they would overlap.
                    foreach ($same ? [] : $reserved as [$x0, $y0, $x1, $y1]) {
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
                    $left = $b['align'] === 'left';
                    $right = $b['align'] === 'right';
                    $margin = (int) round($w * 0.07);
                    $x = match (true) {
                        $left => (string) ($margin + $pad),
                        $right => 'w-text_w-'.($margin + $pad),
                        default => '(w-text_w)/2',
                    };
                    $width = $b['width'];

                    // An icon sits before a one-line item, the pair centred (or on the left edge) together.
                    $icon = in_array($item['icon'] ?? 'none', IconSet::NAMES, true) && ! str_contains($b['text'], "\n") ? $item['icon'] : null;
                    if ($icon) {
                        $box = imagettfbbox($size * 0.75, 0, resource_path('fonts/'.self::FONTS[$b['font']]), $b['text']);
                        $textW = $box[2] - $box[0];
                        $iconPx = (int) round($size * 0.95);
                        $lead = $iconPx + (int) round($size * 0.35);
                        $iconX = match (true) {
                            $left => $margin,
                            $right => $w - $margin - ($lead + $textW + 2 * $pad),
                            default => (int) round(($w - $lead - $textW - 2 * $pad) / 2),
                        };
                        $x = (string) ($iconX + $lead + $pad);
                        $width = $lead + $textW + 2 * $pad;
                        File::put("{$dir}/i{$i}.png", app(IconSet::class)->png($icon, $iconPx, $this->hex($item['color'] ?? '')));
                        $icons[] = ["i{$i}.png", $iconX, $top + (int) round($size * 0.37 - $iconPx / 2), $still ? null : "between(t,{$start},{$end})"];
                    }
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
                    $x0 = match (true) {
                        $left => $margin,
                        $right => $w - $margin - $width,
                        default => (int) round(($w - $width) / 2),
                    };
                    $this->placed[] = ['text' => $b['text'], 'panel' => $pad > 0, 'rect' => [$x0, $y, $x0 + $width, $y + $b['height']]];
                    $rowY = $y;
                    $y = max($same ? $row['end'] : 0, $y + $b['height'] + $b['gap']);
                    $row = ['y' => $rowY, 'end' => $y];
                }

                $floor = $y + (int) round(min($w, $h) * 0.02);
            }

            if (! $filters) {
                throw new AiException('All text items were empty (only blank lines). Put the real words in "text" as plain UTF-8 letters — no blank-line padding, no \u escapes; to clear a logo, add_logo first and the text moves below it by itself.');
            }

            $ext = $still ? 'png' : 'mp4';
            File::copy($video, "{$dir}/in.".pathinfo($video, PATHINFO_EXTENSION));

            // Graph: [scrim under everything] → text → icons. Images loop on clips; overlays end with the clip.
            $inputs = ['-i', 'in.'.pathinfo($video, PATHINFO_EXTENSION)];
            $image = fn (string $file) => [...($still ? [] : ['-loop', '1']), '-i', $file];
            $end = $still ? '' : ':shortest=1';
            $graph = [];
            $label = '[0:v]';
            $n = 1;

            if (in_array($scrim, ['top', 'bottom', 'top_bottom'], true)) {
                File::put("{$dir}/scrim.png", $this->scrim($w, $h, $scrim, $this->hex($scrimColor)));
                $inputs = [...$inputs, ...$image('scrim.png')];
                $graph[] = "{$label}[{$n}:v]overlay=0:0{$end}[s]";
                [$label, $n] = ['[s]', $n + 1];
            }

            $graph[] = $label.implode(',', $filters).'[t0]';
            $label = '[t0]';
            foreach ($icons as $k => [$file, $ix, $iy, $enable]) {
                $inputs = [...$inputs, ...$image($file)];
                $graph[] = "{$label}[{$n}:v]overlay={$ix}:{$iy}".($enable ? ":enable='{$enable}'" : '')."{$end}[t".($k + 1).']';
                [$label, $n] = ['[t'.($k + 1).']', $n + 1];
            }

            $result = Process::path($dir)->timeout(600)->run($still
                ? [config('ai.ffmpeg'), '-y', ...$inputs, '-filter_complex', implode(';', $graph), '-map', $label, '-frames:v', '1', 'out.png']
                : [
                    config('ai.ffmpeg'), '-y', ...$inputs, '-filter_complex', implode(';', $graph),
                    '-map', $label, '-map', '0:a?', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-c:a', 'copy',
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
            $mid = ($p['rect'][1] + $p['rect'][3]) / 2 / $H;
            $onScrim = ($mid < 0.4 && in_array($this->lastScrim, ['top', 'top_bottom'], true)) || ($mid > 0.55 && in_array($this->lastScrim, ['bottom', 'top_bottom'], true));
            if ($p['panel'] || $onScrim) {
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

    /**
     * A soft gradient (scrim) from the frame edge into the picture, so text reads on a
     * photo without a hard panel: strongest at the edge, gone by the middle.
     */
    private function scrim(int $w, int $h, string $where, string $hex): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');
        $light = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.55;
        $peak = $light ? 0.62 : 0.72;

        for ($y = 0; $y < $h; $y++) {
            $fromTop = in_array($where, ['top', 'top_bottom'], true) ? max(0, 1 - $y / ($h * 0.5)) : 0;
            $fromBottom = in_array($where, ['bottom', 'top_bottom'], true) ? max(0, 1 - ($h - 1 - $y) / ($h * 0.55)) : 0;
            $a = $peak * max($fromTop, $fromBottom) ** 1.6;
            imageline($img, 0, $y, $w - 1, $y, imagecolorallocatealpha($img, $r, $g, $b, 127 - (int) round($a * 127)));
        }

        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function hex(string $hex): string
    {
        return substr($this->color($hex, 'FFFFFF'), 2);
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
