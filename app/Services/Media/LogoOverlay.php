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
     * @param  string  $plate  'none' | 'light' (solid white card — for logos on a white background) | 'dark' panel behind the logo
     * @return string Absolute path of the new file (PNG for images, MP4 for clips).
     */
    public function place(string $base, string $logo, string $position, float $width, string $plate, bool $still): string
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
            $chain = "[1:v]format=rgba,scale={$logoW}:-1:flags=lanczos";

            if (in_array($plate, ['light', 'dark'], true)) {
                $pad = (int) round($logoW * 0.12);
                $color = $plate === 'light' ? 'white' : 'black@0.6';
                $chain .= ',pad=iw+'.(2 * $pad).':ih+'.(2 * $pad).":{$pad}:{$pad}:color={$color}";
            }

            $filter = "{$chain}[lg];[0:v][lg]overlay=x={$x}:y={$y}:format=auto";
            $out = $still ? 'out.png' : 'out.mp4';

            $result = Process::path($dir)->timeout(600)->run($still
                ? [config('ai.ffmpeg'), '-y', '-i', "base.{$baseExt}", '-i', $logoFile, '-filter_complex', $filter, '-frames:v', '1', $out]
                : [
                    config('ai.ffmpeg'), '-y', '-i', "base.{$baseExt}", '-loop', '1', '-i', $logoFile,
                    '-filter_complex', $filter.':shortest=1',
                    '-map', '0:a?', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-c:a', 'copy',
                    $out,
                ]);

            if ($result->failed()) {
                throw new AiException('Logo overlay failed: '.Str::limit(trim($result->errorOutput()), 300));
            }

            $keep = storage_path('app/private/logo/'.Str::uuid().'.'.pathinfo($out, PATHINFO_EXTENSION));
            File::move("{$dir}/{$out}", $keep);

            return $keep;
        } finally {
            File::deleteDirectory($dir);
        }
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
