<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;

/**
 * Cover-crops an image to the exact pixel size of a poster format.
 */
class PosterFormatter
{
    /**
     * @return array{data: string, mime: string}
     */
    public function fit(string $data, int $width, int $height): array
    {
        $src = @imagecreatefromstring($data);

        if (! $src) {
            throw new AiException('Image could not be decoded.');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($width / $sw, $height / $sh);
        $cw = (int) round($width / $scale);
        $ch = (int) round($height / $scale);
        $cx = (int) floor(($sw - $cw) / 2);
        $cy = (int) floor(($sh - $ch) / 2);

        $dst = imagecreatetruecolor($width, $height);
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $width, $height, $cw, $ch);

        ob_start();
        imagejpeg($dst, null, 92);

        return ['data' => ob_get_clean(), 'mime' => 'image/jpeg'];
    }
}
