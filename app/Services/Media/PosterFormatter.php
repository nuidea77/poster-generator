<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;

/**
 * Зургийг постерын форматын яг пиксел хэмжээнд "cover" байдлаар тайрна.
 */
class PosterFormatter
{
    /**
     * Зургийг хэлбэр алдагдуулахгүйгээр томруулж, илүүдлийг төвөөс нь тайраад JPEG болгоно.
     *
     * @return array{data: string, mime: string}
     */
    public function fit(string $data, int $width, int $height): array
    {
        $src = @imagecreatefromstring($data);

        if (! $src) {
            throw new AiException('Image could not be decoded.');
        }

        // Хоёр тэнхлэгийн том масштабыг авч хүрээг бүрэн дүүргэнэ; эх зураг дээрх
        // тайрах хэсгийн хэмжээ ($cw×$ch) ба төвлөрсөн эхлэл ($cx,$cy)-ийг тооцно.
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($width / $sw, $height / $sh);
        $cw = (int) round($width / $scale);
        $ch = (int) round($height / $scale);
        $cx = (int) floor(($sw - $cw) / 2);
        $cy = (int) floor(($sh - $ch) / 2);

        $dst = imagecreatetruecolor($width, $height);
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $width, $height, $cw, $ch);

        // JPEG-ийг санах ойд бичнэ (чанар 92).
        ob_start();
        imagejpeg($dst, null, 92);

        return ['data' => ob_get_clean(), 'mime' => 'image/jpeg'];
    }
}
