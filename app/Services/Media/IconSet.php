<?php

namespace App\Services\Media;

/**
 * Кодоор зурсан жижиг хавтгай UI icon-уудын багц (icon font эсвэл SVG renderer
 * багтаагаагүй): утас, байршил, цаг, хуанли, вэб, имэйл, од, чек, зүрх, бэлэг,
 * хүргэлт, хувь, сум гэх мэт. Тус бүрийг 100 нэгжийн торон дээр 4× томоор зураад
 * ирмэгийг гөлгөр болгохоор багасгана; текст шиг тунгалаг дэвсгэр дээр нэг өнгөтэй.
 */
class IconSet
{
    // Дэмжигдэх icon-уудын нэрс; танихгүй нэр сум (arrow) болно.
    public const NAMES = ['phone', 'location', 'clock', 'calendar', 'web', 'mail', 'star', 'check', 'heart', 'gift', 'delivery', 'percent', 'arrow', 'instagram', 'facebook', 'chat', 'user'];

    // Одоо зурж буй 4× томруулсан даавуу.
    private \GdImage $img;

    // Icon-ы өнгө (бэх).
    private int $ink;

    // Бүрэн тунгалаг өнгө — дүрсийн дотор нүх "арчихад" хэрэглэнэ.
    private int $clear;

    // 100 нэгжийн торны нэг нэгж хэдэн px болох.
    private float $u;

    /**
     * Нэрлэсэн icon-ыг $px хэмжээтэй дөрвөлжин PNG болгон зурна.
     *
     * @param  string|null  $circle  icon-ы ард дүүргэсэн тойргийн hex өнгө ("тойрог доторх icon"
     *                               загвар); тэгвэл дүрс $hex өнгөтэй, тойрог дотор жижгээр зурагдана
     * @return string PNG өгөгдөл, $px дөрвөлжин
     */
    public function png(string $name, int $px, string $hex, ?string $circle = null): string
    {
        // Тойрогтой загвар: 4× тойрог зураад багасгаж, дотор нь 56% хэмжээтэй дүрсийг
        // (рекурсив дуудлагаар) төвд нь давхарлана.
        if ($circle !== null) {
            $disc = imagecreatetruecolor($px * 4, $px * 4);
            imagesavealpha($disc, true);
            imagealphablending($disc, false);
            imagefill($disc, 0, 0, imagecolorallocatealpha($disc, 0, 0, 0, 127));
            [$r, $g, $b] = sscanf(preg_match('/^[0-9a-fA-F]{6}$/', $circle) ? $circle : 'FFFFFF', '%02x%02x%02x');
            imagefilledellipse($disc, $px * 2, $px * 2, $px * 4, $px * 4, imagecolorallocatealpha($disc, $r, $g, $b, 0));
            $out = imagecreatetruecolor($px, $px);
            imagesavealpha($out, true);
            imagealphablending($out, false);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $disc, 0, 0, 0, 0, $px, $px, $px * 4, $px * 4);
            $inner = (int) round($px * 0.56);
            $glyph = imagecreatefromstring($this->png($name, $inner, $hex));
            imagealphablending($out, true);
            imagecopy($out, $glyph, (int) round(($px - $inner) / 2), (int) round(($px - $inner) / 2), 0, 0, $inner, $inner);
            ob_start();
            imagepng($out);

            return (string) ob_get_clean();
        }

        // 4× supersampling даавуу (хамгийн багадаа 8px), тунгалаг дэвсгэр, буруу hex бол цагаан.
        $k = 4;
        $S = max(8, $px) * $k;
        $this->img = imagecreatetruecolor($S, $S);
        imagesavealpha($this->img, true);
        imagealphablending($this->img, false);
        $this->clear = imagecolorallocatealpha($this->img, 0, 0, 0, 127);
        imagefill($this->img, 0, 0, $this->clear);
        [$r, $g, $b] = sscanf(preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? $hex : 'FFFFFF', '%02x%02x%02x');
        $this->ink = imagecolorallocatealpha($this->img, $r, $g, $b, 0);
        $this->u = $S / 100;

        // Нэрээр нь тохирох зурах функцийг сонгоно.
        match ($name) {
            'phone' => $this->phone(),
            'location' => $this->location(),
            'clock' => $this->clock(),
            'calendar' => $this->calendar(),
            'web' => $this->web(),
            'mail' => $this->mail(),
            'star' => $this->star(),
            'check' => $this->line([[14, 52], [40, 76], [86, 26]], 13),
            'heart' => $this->heart(),
            'gift' => $this->gift(),
            'delivery' => $this->delivery(),
            'percent' => $this->percent(),
            'instagram' => $this->instagram(),
            'facebook' => $this->facebook(),
            'chat' => $this->chat(),
            'user' => $this->user(),
            default => $this->arrow(),
        };

        // Эцсийн хэмжээ рүү resample хийж, ирмэгийг гөлгөр болгоно.
        $out = imagecreatetruecolor($px, $px);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $this->img, 0, 0, 0, 0, $px, $px, $S, $S);

        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------ icon-ууд (100 нэгжийн тор)

    // Гар утас: бөөрөнхий их бие, тунгалаг дэлгэц, доод товч.
    private function phone(): void
    {
        $this->roundRect(26, 6, 74, 94, 12, $this->ink);
        $this->roundRect(34, 14, 66, 78, 4, $this->clear);
        $this->circle(50, 86, 4, $this->ink);
    }

    // Байршлын тэмдэг: тойрог + доош чиглэсэн гурвалжин, голдоо нүхтэй.
    private function location(): void
    {
        $this->circle(50, 38, 30, $this->ink);
        $this->poly([[23, 50], [77, 50], [50, 95]], $this->ink);
        $this->circle(50, 38, 12, $this->clear);
    }

    // Цаг: цагираг ба хоёр зүү.
    private function clock(): void
    {
        $this->ring(50, 50, 44, 9);
        $this->line([[50, 50], [50, 24]], 8);
        $this->line([[50, 50], [68, 62]], 8);
    }

    // Хуанли: хүрээ, дээд хоёр бариул, таван өдрийн нүд.
    private function calendar(): void
    {
        $this->roundRect(8, 16, 92, 92, 10, $this->ink);
        $this->roundRect(16, 40, 84, 84, 4, $this->clear);
        $this->rect(26, 6, 36, 26, $this->ink);
        $this->rect(64, 6, 74, 26, $this->ink);
        foreach ([[24, 48], [44, 48], [64, 48], [24, 66], [44, 66]] as [$x, $y]) {
            $this->rect($x, $y, $x + 12, $y + 11, $this->ink);
        }
    }

    // Вэб (бөмбөрцөг): гаднах цагираг, меридиан эллипс, өргөргийн гурван шугам.
    private function web(): void
    {
        $this->ring(50, 50, 44, 7);
        $this->ellipseRing(50, 50, 18, 44, 6);
        $this->line([[8, 50], [92, 50]], 6);
        $this->line([[16, 29], [84, 29]], 5);
        $this->line([[16, 71], [84, 71]], 5);
    }

    // Имэйл: дугтуйн хүрээ ба V хэлбэрийн таг.
    private function mail(): void
    {
        $this->roundRect(6, 18, 94, 82, 8, $this->ink);
        $this->roundRect(14, 26, 86, 74, 3, $this->clear);
        $this->line([[12, 26], [50, 56], [88, 26]], 8);
    }

    // Таван хошуут од: 36° тутамд гадна (47) ба дотор (19) радиусыг ээлжлүүлсэн 10 оройтой олон өнцөгт.
    private function star(): void
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $a = deg2rad(-90 + $i * 36);
            $r = $i % 2 ? 19 : 47;
            $points[] = [50 + $r * cos($a), 53 + $r * sin($a)];
        }
        $this->poly($points, $this->ink);
    }

    // Зүрх: хоёр тойрог ба доош чиглэсэн гурвалжин.
    private function heart(): void
    {
        $this->circle(30, 36, 23, $this->ink);
        $this->circle(70, 36, 23, $this->ink);
        $this->poly([[8.5, 44], [91.5, 44], [50, 90]], $this->ink);
    }

    // Бэлэг: таг, хайрцаг, босоо тууз (тунгалаг зурвас) ба хоёр гогцоо.
    private function gift(): void
    {
        $this->rect(8, 30, 92, 46, $this->ink);
        $this->rect(14, 50, 86, 92, $this->ink);
        $this->rect(45, 30, 55, 92, $this->clear);
        $this->ring(36, 20, 12, 6);
        $this->ring(64, 20, 12, 6);
    }

    // Хүргэлтийн ачааны машин: тэвш, бүхээг ба цонх, тунгалаг зайтай хоёр дугуй.
    private function delivery(): void
    {
        $this->rect(4, 22, 60, 70, $this->ink);
        $this->poly([[63, 36], [82, 36], [96, 54], [96, 70], [63, 70]], $this->ink);
        $this->poly([[69, 42], [80, 42], [89, 54], [69, 54]], $this->clear);
        foreach ([24, 78] as $x) {
            $this->circle($x, 76, 15, $this->clear);
            $this->circle($x, 76, 11, $this->ink);
            $this->circle($x, 76, 4, $this->clear);
        }
    }

    // Хувийн тэмдэг: хоёр цагираг ба ташуу зураас.
    private function percent(): void
    {
        $this->ring(27, 27, 16, 8);
        $this->ring(73, 73, 16, 8);
        $this->line([[80, 14], [20, 86]], 9);
    }

    /** Ерөнхий камер-дөрвөлжин дүрс (сошиал холбоос), брэндийн логоны зураг биш. */
    private function instagram(): void
    {
        $this->roundRect(8, 8, 92, 92, 24, $this->ink);
        $this->roundRect(17, 17, 83, 83, 16, $this->clear);
        $this->ring(50, 50, 19, 8);
        $this->circle(71, 29, 5, $this->ink);
    }

    /** Тойрог доторх жижиг "f" үсэг (сошиал холбоос). */
    private function facebook(): void
    {
        $this->circle(50, 50, 46, $this->ink);
        $this->rect(48, 30, 60, 92, $this->clear);
        $this->roundRect(48, 22, 72, 36, 7, $this->clear);
        $this->rect(48, 22, 60, 36, $this->clear);
        $this->rect(38, 46, 70, 57, $this->clear);
        $this->rect(60, 30, 72, 36, $this->ink);
    }

    /** Ярианы бөмбөлөг (чат, WhatsApp, Messenger). */
    private function chat(): void
    {
        $this->circle(50, 46, 40, $this->ink);
        $this->poly([[18, 66], [36, 78], [12, 92]], $this->ink);
        foreach ([33, 50, 67] as $x) {
            $this->circle($x, 46, 6, $this->clear);
        }
    }

    // Хэрэглэгч: толгой (тойрог) ба мөр (бөөрөнхий тэгш өнцөгт).
    private function user(): void
    {
        $this->circle(50, 30, 20, $this->ink);
        $this->roundRect(14, 56, 86, 96, 28, $this->ink);
    }

    // Баруун тийш сум: иш ба гурвалжин хошуу (анхдагч icon).
    private function arrow(): void
    {
        $this->rect(8, 42, 60, 58, $this->ink);
        $this->poly([[54, 18], [94, 50], [54, 82]], $this->ink);
    }

    // ------------------------------------------------------------ үндсэн дүрсүүд

    // Торны нэгжийг даавууны px руу хөрвүүлнэ.
    private function p(float $v): int
    {
        return (int) round($v * $this->u);
    }

    // Дүүргэсэн тойрог (төв, радиус торны нэгжээр).
    private function circle(float $cx, float $cy, float $r, int $color): void
    {
        imagefilledellipse($this->img, $this->p($cx), $this->p($cy), $this->p(2 * $r), $this->p(2 * $r), $color);
    }

    // Цагираг: бэхээр тойрог зураад дотор талыг тунгалаг болгож арчина (доор байсныг устгана).
    private function ring(float $cx, float $cy, float $r, float $width): void
    {
        $this->circle($cx, $cy, $r, $this->ink);
        $this->circle($cx, $cy, $r - $width, $this->clear);
    }

    /** Эллипсийн хүрээг 48 богино зузаан хэрчмээр зурна (дотор нь байгааг хадгална). */
    private function ellipseRing(float $cx, float $cy, float $rx, float $ry, float $width): void
    {
        $points = [];
        for ($i = 0; $i <= 48; $i++) {
            $a = 2 * M_PI * $i / 48;
            $points[] = [$cx + ($rx - $width / 2) * cos($a), $cy + ($ry - $width / 2) * sin($a)];
        }
        $this->line($points, $width);
    }

    // Дүүргэсэн тэгш өнцөгт (x1/y1 нь хамаарахгүй хил).
    private function rect(float $x0, float $y0, float $x1, float $y1, int $color): void
    {
        imagefilledrectangle($this->img, $this->p($x0), $this->p($y0), $this->p($x1) - 1, $this->p($y1) - 1, $color);
    }

    // Бөөрөнхий булантай тэгш өнцөгт: хоёр загалмай тэгш өнцөгт + дөрвөн булангийн тойрог.
    private function roundRect(float $x0, float $y0, float $x1, float $y1, float $r, int $color): void
    {
        $this->rect($x0 + $r, $y0, $x1 - $r, $y1, $color);
        $this->rect($x0, $y0 + $r, $x1, $y1 - $r, $color);
        foreach ([[$x0 + $r, $y0 + $r], [$x1 - $r, $y0 + $r], [$x0 + $r, $y1 - $r], [$x1 - $r, $y1 - $r]] as [$cx, $cy]) {
            $this->circle($cx, $cy, $r, $color);
        }
    }

    /**
     * Дүүргэсэн олон өнцөгт (оройнууд торны нэгжээр).
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private function poly(array $points, int $color): void
    {
        imagefilledpolygon($this->img, array_merge(...array_map(fn ($pt) => [$this->p($pt[0]), $this->p($pt[1])], $points)), $color);
    }

    /** Бөөрөнхий холбоос, үзүүртэй зузаан хугаралтай шугам. @param list<array{0: float, 1: float}> $points */
    private function line(array $points, float $width): void
    {
        // Хэрчим бүрийг нормаль векторын дагуу ($nx,$ny = хагас зузаан) өргөсгөсөн дөрвөлжин болгоно.
        for ($i = 1; $i < count($points); $i++) {
            [[$x0, $y0], [$x1, $y1]] = [$points[$i - 1], $points[$i]];
            $len = max(0.001, hypot($x1 - $x0, $y1 - $y0));
            [$nx, $ny] = [-($y1 - $y0) / $len * $width / 2, ($x1 - $x0) / $len * $width / 2];
            $this->poly([[$x0 + $nx, $y0 + $ny], [$x1 + $nx, $y1 + $ny], [$x1 - $nx, $y1 - $ny], [$x0 - $nx, $y0 - $ny]], $this->ink);
        }
        // Орой бүрт тойрог тавьж холбоос, үзүүрийг бөөрөнхийлнө.
        foreach ($points as [$x, $y]) {
            $this->circle($x, $y, $width / 2, $this->ink);
        }
    }
}
