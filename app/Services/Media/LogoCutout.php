<?php

namespace App\Services\Media;

/**
 * Логоны эргэн тойрны энгийн дэвсгэрийг (JPG дээрх цагаан, эсвэл ямар нэг
 * нэгэн өнгө) логонд нь хүрэлгүйгээр арилгана: зөвхөн зургийн хүрээтэй
 * холбогдсон дэвсгэрийг цэвэрлэдэг (flood fill) тул логоны доторх цагаан/цайвар
 * хэсгүүд үлдэнэ. Ирмэгт зөөлөн alpha өгч, тайрсан хэсэгт гэрэлт хүрээ (halo) үлдээхгүй.
 */
class LogoCutout
{
    /** Дэвсгэрт тооцогдох RGB зайны квадрат. */
    private const NEAR = 45 ** 2;

    /** Энэ зай хүртэлх ирмэгийн пикселүүд хатуу тайрагдахын оронд аажмаар тунана. */
    private const FEATHER = 110;

    // Боловсруулалт хурдан байхын тулд урт талыг энэ px хүртэл багасгана.
    private const MAX_SIDE = 1400;

    /**
     * Логоны нэгэн өнгөт дэвсгэрийг тунгалаг болгоно.
     *
     * @return string|null Тунгалаг дэвсгэртэй PNG, эсвэл лого аль хэдийн
     *                     тунгалаг / нэгэн өнгөт дэвсгэргүй бол null.
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

        // Палитрт зургийг truecolor болгож, пикселийн ARGB утгыг шууд уншина.
        imagepalettetotruecolor($src);
        $px = fn (int $x, int $y) => imagecolorat($src, $x, $y);

        // Хүрээний бүх пикселийн координатыг цуглуулна (булангуудыг давхардуулахгүй).
        // Хүрээ аль хэдийн тунгалаг бол → байгаагаар нь үлдээнэ.
        $border = [];
        for ($x = 0; $x < $w; $x++) {
            $border[] = [$x, 0];
            $border[] = [$x, $h - 1];
        }
        for ($y = 1; $y < $h - 1; $y++) {
            $border[] = [0, $y];
            $border[] = [$w - 1, $y];
        }

        // GD-ийн alpha 0–127 (127 = бүрэн тунгалаг); хүрээний 30%-аас дээш нь тунгалаг бол хэрэггүй.
        $alphaEdge = count(array_filter($border, fn ($p) => (($px(...$p) >> 24) & 0x7F) > 60));
        if ($alphaEdge > count($border) * 0.3) {
            return null;
        }

        // Дэвсгэрийн өнгө = хүрээний давамгай (дундаж) өнгө; хүрээний ихэнхийг (90%) эзлэх ёстой.
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
            return null; // зураг эсвэл ээдрээтэй дэвсгэр: хэвээр үлдээж, оронд нь plate ашиглана
        }

        // Хүрээнээс эхлэн дэвсгэр өнгөтэй пикселүүдээр flood fill (BFS, 4 хөрш) хийнэ.
        // Пикселийг $k = y*w + x гэсэн нэг индексээр тэмдэглэнэ.
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

        // Юу ч (бараг юу ч) үлдэхгүй бол "дэвсгэр" нь лого өөрөө гэсэн үг.
        if (count($isBg) > $w * $h * 0.97) {
            return null;
        }

        // Alpha суваг хадгалах гаралтын зураг; blending унтраалттай тул alpha шууд бичигдэнэ.
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);

        // Дэвсгэр пиксел → бүрэн тунгалаг; лого пиксел → хэвээр, дэвсгэртэй хиллэсэн бол зөөлөн alpha.
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
                    // Anti-aliased ирмэг: дэвсгэрийн өнгөнд ойр байх тусам илүү тунгалаг.
                    $alpha = (int) round(127 * max(0, 1 - sqrt($dist($c)) / self::FEATHER));
                }

                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $c[0], $c[1], $c[2], $alpha));
            }
        }

        // PNG-ийг санах ойд бичиж буцаана.
        ob_start();
        imagepng($out);

        return ob_get_clean();
    }
}
