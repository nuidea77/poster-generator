<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Харилцагчийн өөрийн лого файлыг постер зураг эсвэл reel клип дээр ffmpeg-ээр
 * байрлуулна — пиксел яг таг: хэлбэр, харьцаа, үсэг, өнгө хэзээ ч өөрчлөгдөхгүй;
 * зөвхөн жигд масштаблагдана. Зургийн модельүүд логог дахин зурдаг тул логог
 * тэдгээрээр хэзээ ч зуруулахгүй.
 */
class LogoOverlay
{
    // Зөвшөөрөгдсөн байрлалууд; бусад утга 'top_left' болно.
    public const POSITIONS = ['top_left', 'top_center', 'top_right', 'center', 'bottom_left', 'bottom_center', 'bottom_right'];

    /**
     * Логог зураг/клип дээр байрлуулж, шинэ файл болон логоны эзлэх талбайг буцаана.
     *
     * @param  float  $width  логоны өргөн, кадрын өргөнд эзлэх хувиар (0.08–0.4)
     * @param  string  $plate  логоны ард 'none' | 'light' (цагаан plate) | 'dark' (хагас тунгалаг бараан plate),
     *                         логоны хэлбэрийг дагана: дугуй тэмдэгт тойрог, бусдад бөөрөнхий тэгш өнцөгт
     * @return array{path: string, box: array{0: float, 1: float, 2: float, 3: float}} шинэ файл (зурагт PNG, клипэнд MP4) ба логоны
     *                                                                                 эзлэх талбай x0, y0, x1, y1 — кадрын хувиар
     */
    public function place(string $base, string $logo, string $position, float $width, string $plate, bool $still): array
    {
        [$w, $h] = $this->size($base);
        $position = in_array($position, self::POSITIONS, true) ? $position : 'top_left';
        $width = max(0.08, min(0.4, $width));
        // libx264 тэгш хэмжээ шаарддаг тул логоны өргөнийг тэгш тоо болгож бөөрөнхийлнө.
        $logoW = (int) (round($w * $width / 2) * 2);
        // Хажуугийн зай = текстийн торны зүүн зай (өргөний 7%), тэгснээр зүүн
        // тийш тэгшилсэн гарчиг ба зүүн дээд лого нэг ирмэгтэй болно.
        $side = (int) round($w * 0.07);
        $edge = (int) round($h * 0.05);

        // Reels ба Stories: дээд (13%) ба доод (33%) хэсэгт гарах аппын UI-аас зай авна.
        $vertical = $h > $w * 1.5;
        $top = $vertical ? (int) round($h * 0.13) : $edge;
        $bottom = $vertical ? (int) round($h * 0.33) : $edge;

        // ffmpeg overlay-ийн x/y илэрхийлэл (W/H = суурь кадр, w/h = лого).
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

        // Замд хоосон зай/тусгай тэмдэгт байж болох тул файлуудыг түр хавтсанд хуулж,
        // ffmpeg-ийг тэр хавтаснаас энгийн нэрээр ажиллуулна.
        try {
            $baseExt = pathinfo($base, PATHINFO_EXTENSION) ?: ($still ? 'png' : 'mp4');
            File::copy($base, "{$dir}/base.{$baseExt}");
            File::copy($logo, "{$dir}/logo.".(pathinfo($logo, PATHINFO_EXTENSION) ?: 'png'));
            $logoFile = 'logo.'.(pathinfo($logo, PATHINFO_EXTENSION) ?: 'png');

            // Зөвхөн жигд масштаб (өндөр нь өргөнийг дагана) — логог хэзээ ч сунгахгүй, өнгийг нь солихгүй.
            // Клипийн үед зургийн оролтуудыг '-loop 1'-ээр бүх кадрт давтана.
            $chain = "[1:v]format=rgba,scale={$logoW}:-1:flags=lanczos[lg];";
            $inputs = ['-i', "base.{$baseExt}", ...($still ? [] : ['-loop', '1']), '-i', $logoFile];

            // Plate сонгосон бол логог plate-ийн төвд тавьж, нийлбэрийг шинэ [lg] болгоно.
            if (in_array($plate, ['light', 'dark'], true)) {
                File::put("{$dir}/plate.png", $this->plate($logo, $logoW, $plate));
                $inputs = [...$inputs, ...($still ? [] : ['-loop', '1']), '-i', 'plate.png'];
                $chain .= '[2:v]format=rgba[pl];[pl][lg]overlay=x=(W-w)/2:y=(H-h)/2:format=auto[lg];';
            }

            // Эцэст нь логог суурь кадр дээр тооцоолсон байрлалд давхарлана.
            $filter = "{$chain}[0:v][lg]overlay=x={$x}:y={$y}:format=auto";
            $out = $still ? 'out.png' : 'out.mp4';

            // Зураг: нэг кадр гаргана. Клип: давтагдах зургийг shortest=1-ээр клипийн уртаар
            // зогсоож, видеог дахин кодлоод аудиог (байвал) хуулна.
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

            // Түр хавтас устахаас өмнө үр дүнг гадна нь зөөнө.
            $keep = storage_path('app/private/logo/'.Str::uuid().'.'.pathinfo($out, PATHINFO_EXTENSION));
            File::move("{$dir}/{$out}", $keep);

            return ['path' => $keep, 'box' => $this->box($logo, $position, $logoW, $w, $h, $side, $top, $bottom, $plate)];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Хатуу дөрвөлжин картын оронд логоны хүрээг дагасан plate: дугуй тэмдэгт тойрог
     * (булангууд нь тунгалаг дөрвөлжин файл), бусдад бөөрөнхий тэгш өнцөгт.
     * Ирмэг зөөлөн байхын тулд 4× томоор зураад багасгана.
     */
    private function plate(string $logo, int $logoW, string $tone): string
    {
        [$bw, $bh, $round] = $this->plateShape($logo, $logoW, $tone);

        // 4× supersampling: тунгалаг даавуу, light = цул цагаан, dark = ~60% тунгалаг хар (alpha 51/127).
        $k = 4;
        $big = imagecreatetruecolor($bw * $k, $bh * $k);
        imagesavealpha($big, true);
        imagealphablending($big, false);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        $fill = $tone === 'light' ? imagecolorallocatealpha($big, 255, 255, 255, 0) : imagecolorallocatealpha($big, 0, 0, 0, 51);

        if ($round) {
            imagefilledellipse($big, (int) ($bw * $k / 2), (int) ($bh * $k / 2), $bw * $k, $bh * $k, $fill);
        } else {
            // Бөөрөнхий тэгш өнцөгт: богино талын 22% радиус; хоёр загалмай тэгш өнцөгт + 4 булангийн тойрог.
            $r = (int) round(min($bw, $bh) * 0.22 * $k);
            [$W, $H] = [$bw * $k, $bh * $k];
            imagefilledrectangle($big, $r, 0, $W - $r - 1, $H - 1, $fill);
            imagefilledrectangle($big, 0, $r, $W - 1, $H - $r - 1, $fill);
            foreach ([[$r, $r], [$W - $r - 1, $r], [$r, $H - $r - 1], [$W - $r - 1, $H - $r - 1]] as [$cx, $cy]) {
                imagefilledellipse($big, $cx, $cy, 2 * $r, 2 * $r, $fill);
            }
        }

        // Жинхэнэ хэмжээ рүү resample хийж, ирмэгийг гөлгөр болгоно.
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
     * Логоны plate-тэй хамт хэмжээ, мөн plate тойрог эсэх: булангууд нь тунгалаг
     * (дэвсгэр арилгасны дараа) бараг дөрвөлжин файл бол дугуй тэмдэг гэж үзнэ.
     *
     * @return array{0: int, 1: int, 2: bool}
     */
    private function plateShape(string $logo, int $logoW, string $plate): array
    {
        [$lw, $lh] = (@getimagesize($logo) ?: [1, 1]);
        // Plate байвал логоны өргөний 12%-ийн дотор зай нэмнэ.
        $pad = in_array($plate, ['light', 'dark'], true) ? (int) round($logoW * 0.12) : 0;
        [$bw, $bh] = [$logoW + 2 * $pad, (int) round($logoW * $lh / max(1, $lw)) + 2 * $pad];

        // Plate-гүй, харьцаа нь 1:1-ээс 15%-аас илүү зөрүүтэй, эсвэл уншиж чадахгүй бол тэгш өнцөгт.
        if (! $pad || abs($lw / max(1, $lh) - 1) > 0.15 || ! ($src = @imagecreatefromstring((string) file_get_contents($logo)))) {
            return [$bw, $bh, false];
        }

        // Дөрвөн булангийн ойролцоох (6%) пиксел бүгд бараг тунгалаг (alpha > 100) бол дугуй.
        $clear = fn (float $fx, float $fy) => (imagecolorat($src, (int) ($lw * $fx), (int) ($lh * $fy)) >> 24 & 0x7F) > 100;
        $round = $clear(0.06, 0.06) && $clear(0.94, 0.06) && $clear(0.06, 0.94) && $clear(0.94, 0.94);

        return $round ? [max($bw, $bh), max($bw, $bh), true] : [$bw, $bh, false];
    }

    /** Логоны (plate-тэй нь) талбайг кадрын хувиар — текст түүнээс зай авч чадахын тулд. */
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

    /**
     * Зураг/клипийн өргөн, өндрийг ffprobe-оор уншина.
     *
     * @return array{0: int, 1: int}
     */
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
