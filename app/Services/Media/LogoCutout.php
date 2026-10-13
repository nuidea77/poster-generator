<?php

namespace App\Services\Media;

/**
 * Removes the plain background around a logo (white on a JPG, or any single
 * flat colour) without touching the logo itself: only background connected to
 * the image border is cleared (flood fill), so white or light parts inside
 * the logo stay. Edges get a soft alpha so the cut-out has no halo.
 */
class LogoCutout
{
    /** Squared RGB distance that still counts as background. */
    private const NEAR = 45 ** 2;

    /** Up to this distance, edge pixels fade instead of cutting hard. */
    private const FEATHER = 110;

    private const MAX_SIDE = 1400;

    /**
     * @return string|null PNG with a transparent background, or null when the
     *                     logo is already transparent or has no flat background.
     */
    public function cut(string $data): ?string
    {
        $src = @imagecreatefromstring($data);

        if (! $src) {
            return null;
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));

        if ($scale < 1) {
            $src = imagescale($src, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC);
            [$w, $h] = [imagesx($src), imagesy($src)];
        }

        imagepalettetotruecolor($src);
        $px = fn (int $x, int $y) => imagecolorat($src, $x, $y);

        // Already transparent at the border → keep as is.
        $border = [];
        for ($x = 0; $x < $w; $x++) {
            $border[] = [$x, 0];
            $border[] = [$x, $h - 1];
        }
        for ($y = 1; $y < $h - 1; $y++) {
            $border[] = [0, $y];
            $border[] = [$w - 1, $y];
        }

        $alphaEdge = count(array_filter($border, fn ($p) => (($px(...$p) >> 24) & 0x7F) > 60));
        if ($alphaEdge > count($border) * 0.3) {
            return null;
        }

        // The background colour = the border's dominant colour; it must cover most of the border.
        $rgb = fn (int $c) => [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        $sum = [0, 0, 0];
        foreach ($border as $p) {
            $c = $rgb($px(...$p));
            $sum = [$sum[0] + $c[0], $sum[1] + $c[1], $sum[2] + $c[2]];
        }
        $bg = array_map(fn ($v) => $v / count($border), $sum);
        $dist = fn (array $c) => ($c[0] - $bg[0]) ** 2 + ($c[1] - $bg[1]) ** 2 + ($c[2] - $bg[2]) ** 2;

        $flat = count(array_filter($border, fn ($p) => $dist($rgb($px(...$p))) <= self::NEAR));
        if ($flat < count($border) * 0.9) {
            return null; // photo or busy background: leave it, use a plate instead
        }

        // Flood fill from the border through background-coloured pixels.
        $isBg = [];
        $queue = new \SplQueue;
        foreach ($border as [$x, $y]) {
            $k = $y * $w + $x;
            if (! isset($isBg[$k]) && $dist($rgb($px($x, $y))) <= self::NEAR) {
                $isBg[$k] = true;
                $queue->enqueue($k);
            }
        }
        while (! $queue->isEmpty()) {
            $k = $queue->dequeue();
            [$x, $y] = [$k % $w, intdiv($k, $w)];
            foreach ([[$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]] as [$nx, $ny]) {
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $nk = $ny * $w + $nx;
                if (! isset($isBg[$nk]) && $dist($rgb($px($nx, $ny))) <= self::NEAR) {
                    $isBg[$nk] = true;
                    $queue->enqueue($nk);
                }
            }
        }

        // Nothing (or almost nothing) left would mean the "background" is the logo itself.
        if (count($isBg) > $w * $h * 0.97) {
            return null;
        }

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (isset($isBg[$y * $w + $x])) {
                    imagesetpixel($out, $x, $y, $clear);

                    continue;
                }

                $c = $rgb($px($x, $y));
                $alpha = 0;
                $touchesBg = isset($isBg[$y * $w + $x + 1]) || isset($isBg[$y * $w + $x - 1]) || isset($isBg[($y + 1) * $w + $x]) || isset($isBg[($y - 1) * $w + $x]);

                if ($touchesBg) {
                    // Anti-aliased edge: the closer to the background colour, the more transparent.
                    $alpha = (int) round(127 * max(0, 1 - sqrt($dist($c)) / self::FEATHER));
                }

                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $c[0], $c[1], $c[2], $alpha));
            }
        }

        ob_start();
        imagepng($out);

        return ob_get_clean();
    }
}
