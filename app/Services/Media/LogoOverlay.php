<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Places the client's own logo file on a poster image or a reel clip with
 * ffmpeg — pixel-exact: the shape, proportions, letters and colours never
 * change; it is only scaled uniformly. Image models redraw logos, so the
 * logo is never painted by them.
 */
class LogoOverlay
{
    public const POSITIONS = ['top_left', 'top_center', 'top_right', 'center', 'bottom_left', 'bottom_center', 'bottom_right'];

    /**
     * @param  float  $width  logo width as a share of the frame width (0.08–0.4)
     * @param  string  $plate  'none' | 'light' (white plate) | 'dark' (translucent dark plate) behind the logo, shaped
     *                         like the logo: a circle for round badges, a rounded rectangle otherwise
     * @return array{path: string, box: array{0: float, 1: float, 2: float, 3: float}} new file (PNG for images, MP4 for clips) and the area
     *                                                                                 the logo covers as x0, y0, x1, y1 shares of the frame
     */
    public function place(string $base, string $logo, string $position, float $width, string $plate, bool $still): array
    {
        [$w, $h] = $this->size($base);
        $position = in_array($position, self::POSITIONS, true) ? $position : 'top_left';
        $width = max(0.08, min(0.4, $width));
        $logoW = (int) (round($w * $width / 2) * 2);
        // Side margin = the text grid's left margin (7% of the width), so a
        // left-aligned headline and a top-left logo share one edge.
        $side = (int) round($w * 0.07);
        $edge = (int) round($h * 0.05);

        // Reels and Stories: keep clear of the app UI at the top and bottom.
        $vertical = $h > $w * 1.5;
        $top = $vertical ? (int) round($h * 0.13) : $edge;
        $bottom = $vertical ? (int) round($h * 0.33) : $edge;

        [$x, $y] = [
            str_ends_with($position, '_left') ? (string) $side : (str_ends_with($position, '_right') ? "W-w-{$side}" : '(W-w)/2'),
            match (true) {
                $position === 'center' => '(H-h)/2',
                str_starts_with($position, 'top') => (string) $top,
                default => "H-h-{$bottom}",
            },
        ];

        $dir = storage_path('app/private/logo/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            $baseExt = pathinfo($base, PATHINFO_EXTENSION) ?: ($still ? 'png' : 'mp4');
            File::copy($base, "{$dir}/base.{$baseExt}");
            File::copy($logo, "{$dir}/logo.".(pathinfo($logo, PATHINFO_EXTENSION) ?: 'png'));
            $logoFile = 'logo.'.(pathinfo($logo, PATHINFO_EXTENSION) ?: 'png');

            // Uniform scale only (height follows the width) — the logo is never stretched or recoloured.
            $chain = "[1:v]format=rgba,scale={$logoW}:-1:flags=lanczos[lg];";
            $inputs = ['-i', "base.{$baseExt}", ...($still ? [] : ['-loop', '1']), '-i', $logoFile];

            if (in_array($plate, ['light', 'dark'], true)) {
                File::put("{$dir}/plate.png", $this->plate($logo, $logoW, $plate));
                $inputs = [...$inputs, ...($still ? [] : ['-loop', '1']), '-i', 'plate.png'];
                $chain .= '[2:v]format=rgba[pl];[pl][lg]overlay=x=(W-w)/2:y=(H-h)/2:format=auto[lg];';
            }

            $filter = "{$chain}[0:v][lg]overlay=x={$x}:y={$y}:format=auto";
            $out = $still ? 'out.png' : 'out.mp4';

            $result = Process::path($dir)->timeout(600)->run($still
                ? [config('ai.ffmpeg'), '-y', ...$inputs, '-filter_complex', $filter, '-frames:v', '1', $out]
                : [
                    config('ai.ffmpeg'), '-y', ...$inputs,
                    '-filter_complex', $filter.':shortest=1',
                    '-map', '0:a?', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-c:a', 'copy',
                    $out,
                ]);

            if ($result->failed()) {
                throw new AiException('Logo overlay failed: '.Str::limit(trim($result->errorOutput()), 300));
            }

            $keep = storage_path('app/private/logo/'.Str::uuid().'.'.pathinfo($out, PATHINFO_EXTENSION));
            File::move("{$dir}/{$out}", $keep);

            return ['path' => $keep, 'box' => $this->box($logo, $position, $logoW, $w, $h, $side, $top, $bottom, $plate)];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * A plate that follows the logo's outline instead of a hard square card: a circle
     * for a round badge (square file with transparent corners), else a rounded rectangle.
     * Drawn 4× and scaled down for smooth edges.
     */
    private function plate(string $logo, int $logoW, string $tone): string
    {
        [$bw, $bh, $round] = $this->plateShape($logo, $logoW, $tone);

        $k = 4;
        $big = imagecreatetruecolor($bw * $k, $bh * $k);
        imagesavealpha($big, true);
        imagealphablending($big, false);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        $fill = $tone === 'light' ? imagecolorallocatealpha($big, 255, 255, 255, 0) : imagecolorallocatealpha($big, 0, 0, 0, 51);

        if ($round) {
            imagefilledellipse($big, (int) ($bw * $k / 2), (int) ($bh * $k / 2), $bw * $k, $bh * $k, $fill);
        } else {
            $r = (int) round(min($bw, $bh) * 0.22 * $k);
            [$W, $H] = [$bw * $k, $bh * $k];
            imagefilledrectangle($big, $r, 0, $W - $r - 1, $H - 1, $fill);
            imagefilledrectangle($big, 0, $r, $W - 1, $H - $r - 1, $fill);
            foreach ([[$r, $r], [$W - $r - 1, $r], [$r, $H - $r - 1], [$W - $r - 1, $H - $r - 1]] as [$cx, $cy]) {
                imagefilledellipse($big, $cx, $cy, 2 * $r, 2 * $r, $fill);
            }
        }

        $plate = imagecreatetruecolor($bw, $bh);
        imagesavealpha($plate, true);
        imagealphablending($plate, false);
        imagefill($plate, 0, 0, imagecolorallocatealpha($plate, 0, 0, 0, 127));
        imagecopyresampled($plate, $big, 0, 0, 0, 0, $bw, $bh, $bw * $k, $bh * $k);

        ob_start();
        imagepng($plate);

        return (string) ob_get_clean();
    }

    /**
     * Size of the logo with its plate, and whether the plate is a circle: a near-square
     * file whose corners are transparent (after background removal) is a round badge.
     *
     * @return array{0: int, 1: int, 2: bool}
     */
    private function plateShape(string $logo, int $logoW, string $plate): array
    {
        [$lw, $lh] = (@getimagesize($logo) ?: [1, 1]);
        $pad = in_array($plate, ['light', 'dark'], true) ? (int) round($logoW * 0.12) : 0;
        [$bw, $bh] = [$logoW + 2 * $pad, (int) round($logoW * $lh / max(1, $lw)) + 2 * $pad];

        if (! $pad || abs($lw / max(1, $lh) - 1) > 0.15 || ! ($src = @imagecreatefromstring((string) file_get_contents($logo)))) {
            return [$bw, $bh, false];
        }

        $clear = fn (float $fx, float $fy) => (imagecolorat($src, (int) ($lw * $fx), (int) ($lh * $fy)) >> 24 & 0x7F) > 100;
        $round = $clear(0.06, 0.06) && $clear(0.94, 0.06) && $clear(0.06, 0.94) && $clear(0.94, 0.94);

        return $round ? [max($bw, $bh), max($bw, $bh), true] : [$bw, $bh, false];
    }

    /** The logo's area (with its plate) as shares of the frame, so text can stay clear of it. */
    private function box(string $logo, string $position, int $logoW, int $w, int $h, int $side, int $top, int $bottom, string $plate): array
    {
        [$bw, $bh] = $this->plateShape($logo, $logoW, $plate);

        $x = str_ends_with($position, '_left') ? $side : (str_ends_with($position, '_right') ? $w - $bw - $side : ($w - $bw) / 2);
        $y = match (true) {
            $position === 'center' => ($h - $bh) / 2,
            str_starts_with($position, 'top') => $top,
            default => $h - $bh - $bottom,
        };

        return [round($x / $w, 4), round($y / $h, 4), round(($x + $bw) / $w, 4), round(($y + $bh) / $h, 4)];
    }

    /** @return array{0: int, 1: int} */
    private function size(string $path): array
    {
        $out = Process::timeout(60)->run([config('ai.ffprobe'), '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $path])->output();
        [$w, $h] = array_map('intval', explode(',', trim($out)) + [0, 0]);

        if ($w < 16 || $h < 16) {
            throw new AiException('Could not read the image/clip size.');
        }

        return [$w, $h];
    }
}
