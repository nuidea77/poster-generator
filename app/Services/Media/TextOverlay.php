<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Жинхэнэ үсгийг ffmpeg drawtext-ээр зурж шингээнэ: багцад орсон OFL фонтоор
 * яг зөв үсэг (Монгол кирилл, ₮). Reel клип дээр цаг тохируулсан fade / slide-up-тай;
 * постер зураг дээр хөдөлгөөнгүй байрлалаар. Зураг, видео модельүүд Монгол үгийг
 * буруу бичдэг тул харилцагчийн унших ёстой бүх үгийг ийм аргаар тавьж болно.
 */
class TextOverlay
{
    // Фонтын түлхүүр → resources/fonts доторх файл.
    public const FONTS = [
        'bold_sans' => 'Montserrat-ExtraBold.ttf',
        'sans' => 'Montserrat-Medium.ttf',
        'condensed' => 'Oswald-Bold.ttf',
        'serif' => 'PlayfairDisplay-Bold.ttf',   // ₮ glyph байхгүй (bold_sans руу шилжинэ); old-style цифр (condensed руу шилжинэ)
        'rounded' => 'Nunito-ExtraBold.ttf',
        // Монгол кирилл (Ө, Ү, ₮) бүрэн дэмждэг display фонтууд — зөвхөн гарчиг, акцентад.
        'heavy' => 'Rubik-Black.ttf',            // маш бүдүүн, орчин үеийн
        'wide' => 'RubikMonoOne-Regular.ttf',    // өргөн, техно / brutalist том үсэг
        'display_serif' => 'YesevaOne-Regular.ttf', // өндөр контрасттай гоёмсог serif
        'elegant' => 'Alice-Regular.ttf',        // нимгэн сонгодог serif (тансаг, гоо сайхан)
        'script' => 'Lobster-Regular.ttf',       // бичгийн (кафе, хоол, ретро)
        'hand' => 'Caveat-Bold.ttf',             // гар бичмэл (найрсаг тэмдэглэл, акцент үг)
    ];

    /** Фонтын хэмжээ, кадрын богино талд эзлэх хувиар (role бүрээр). */
    private const SIZES = ['display' => 0.24, 'title' => 0.105, 'price' => 0.095, 'subtitle' => 0.058, 'small' => 0.034];

    /** Glyph-ийн дундаж өргөн фонтын хэмжээтэй харьцуулахад — хальж гарах мөрийг багасгахад хэрэглэнэ. */
    private const WIDTH = ['bold_sans' => 0.74, 'sans' => 0.6, 'condensed' => 0.5, 'serif' => 0.6, 'rounded' => 0.68,
        'heavy' => 0.71, 'wide' => 0.89, 'display_serif' => 0.68, 'elegant' => 0.66, 'script' => 0.59, 'hand' => 0.52];

    /**
     * Сүүлийн burn() текстийн блок бүрийг хаана тавьсан (px) — дуудагч доор нь юу байгааг шалгаж чадахын тулд.
     *
     * @var list<array{text: string, panel: bool, rect: array{0: int, 1: int, 2: int, 3: int}}>
     */
    public array $placed = [];

    /** Сүүлийн burn()-ийн scrim [хаана, hex] — үзэгчийн харах байдлаар contrast-ыг үнэлэхэд. */
    private array $lastScrim = ['none', '000000'];

    // Зөвшөөрөгдсөн scrim-ийн байрлалууд.
    public const SCRIMS = ['none', 'top', 'bottom', 'top_bottom'];

    /**
     * Текстийн item-уудыг зураг/клип дээр байрлуулж зурна: мөр, бүсийг тооцоолж,
     * scrim / panel / icon-уудтай нэг ffmpeg filter graph үүсгэнэ.
     *
     * @param  list<array{text: string, role: string, position: string, align?: string, font: string, color: string, box: bool, box_color: string, start: float, end: float, animation: string, icon?: string}>  $items
     * @param  bool  $still  зураг (постер): хөдөлгөөнгүй текст, PNG гаралт; үгүй бол клип: цагтай текст, MP4 гаралт
     * @param  string  $scrim  текстийн бүсийн ард зөөлөн градиент: none | top | bottom | top_bottom
     * @return string Шинэ файлын бүтэн зам (дуудагч нь зөөнө).
     */
    public function burn(string $video, array $items, float $duration, bool $still = false, string $scrim = 'none', string $scrimColor = '000000'): string
    {
        $this->placed = [];
        $this->lastScrim = [$scrim, $this->hex($scrimColor)];

        if (! $items) {
            throw new AiException('Give at least one text item.');
        }

        // Модель заримдаа зөвхөн placeholder "text" агуулсан санамсаргүй дуудлага хийдэг; постер
        // дээр "text" гэдэг үг зурахын оронд татгалзана.
        $words = array_map(fn ($it) => mb_strtolower(preg_replace('/^[\s.…]+|[\s.…]+$/u', '', (string) ($it['text'] ?? ''))), $items);
        $junk = ['', 'text', 'placeholder', 'tbd', 'todo', 'x', 'lorem ipsum', 'dummy', 'sample', 'test', 'example', 'foo', 'abc'];
        if (! array_diff($words, $junk) || max(array_map(fn ($w) => preg_match('/\d/', $w) ? 9 : mb_strlen(preg_replace('/[\p{C}\s]/u', '', $w)), $words)) <= 1) { // ганц санамсаргүй үсэг; "15" гэх мэт тоо бол жинхэнэ
            throw new AiException('That call held only placeholder text ("'.trim((string) ($items[0]['text'] ?? '')).'"). Send the real items — every string, in one call.');
        }

        // Кадрын хэмжээ ба ffmpeg ажиллах түр хавтас.
        [$w, $h] = $this->size($video);
        $dir = storage_path('app/private/text/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            // 1-р алхам: мөр бүрийн хэмжээг тооцно; 2-р алхам: бүсүүдийг дээрээс доош давхцалгүй байрлуулна.
            $blocks = [];

            $list = array_values($items);
            // Мөрүүд: same_line-тай item өмнөх item-ийн мөрөнд нэгдэнэ. Код мөрийг өөрөө зохицуулахгүй:
            // item бүр agent-ийн өгсөн align-аар байрлана, ижил align-тай item-ууд хажуу хажуудаа бүлэг болно.
            [$rowOf, $rowSize, $r] = [[], [], -1];
            // Role-ийн хэмжээний хувь.
            $roleSize = fn ($it) => self::SIZES[$it['role'] ?? ''] ?? self::SIZES['subtitle'];
            // Item бүрт мөрийн дугаар ба мөр доторх дарааллыг онооно.
            foreach ($list as $j => $it) {
                $r += empty($it['same_line']) || $j === 0 ? 1 : 0;
                $rowSize[$r] = ($rowSize[$r] ?? 0) + 1;
                $rowOf[$j] = [$r, $rowSize[$r] - 1];
            }

            // 1-р алхам: item бүрийн фонт, хэмжээ, тэгшлэл, panel-ийг тодорхойлж бүсээр нь бүлэглэнэ.
            foreach ($list as $i => $item) {
                // *үг* нь accent хэсгийг тэмдэглэнэ (хоёр өнгөт гарчиг); одууд зурагдахгүй.
                $raw = $this->clean($item['text'] ?? '');
                $marked = substr_count($raw, '*') >= 2 && substr_count($raw, '*') % 2 === 0;
                $text = $marked ? str_replace('*', '', $raw) : $raw;
                if ($text === '') {
                    continue;
                }

                // Playfair-д ₮ байхгүй, цифр нь old-style тул шаардлагатай үед өөр фонт руу шилжинэ.
                $font = array_key_exists($item['font'] ?? '', self::FONTS) ? $item['font'] : 'bold_sans';
                if ($font === 'serif' && str_contains($text, '₮')) {
                    $font = 'bold_sans';
                } elseif ($font === 'serif' && preg_match('/\d/', $text)) {
                    $font = 'condensed'; // Playfair-ийн old-style цифр том үсгээс доош унжина ("15 ЖИЛ" сул харагдсан)
                }

                // same_line: энэ item өмнөхтэйгөө нэг мөрөнд. Нэг мөрөнд хэд хэдэн item байвал
                // align бүр өөрийн бүлэг (зүүн ирмэг / төв / баруун ирмэг) болно — 'cluster'.
                [$rowNo, $column] = $rowOf[$i];
                $columns = $rowSize[$rowNo];
                $same = $column > 0;
                $align = in_array($item['align'] ?? '', ['left', 'right', 'center'], true) ? $item['align'] : 'center';
                $clusterAlign = $columns > 1 ? $align : null;
                if ($clusterAlign) {
                    $align = 'cluster';
                }

                // Хэмжээ = role-ийн хэмжээ. Цорын ганц хамгаалалт: хамгийн өргөн мөр кадраас хальж
                // тасрахгүйн тулд кадрын өргөний 86%-д багтана (100pt-ээр хэмжээд, ×0.75 = px→pt).
                $longest = max(array_map('mb_strlen', explode("\n", $text)));
                $caps = mb_strtoupper($text) === $text ? 1.12 : 1.0;
                $size = (int) round(min($w, $h) * $roleSize($item));
                $fontFile = resource_path('fonts/'.self::FONTS[$font]);
                $widest = max(array_map(fn ($line) => ($box = imagettfbbox(100 * 0.75, 0, $fontFile, $line)) ? $box[2] - $box[0] : 0, explode("\n", $text)));
                // Panel-ийн хэлбэр: rect (drawtext box), pill (бөөрөнхий, box өнгөтэй), card эсвэл glass (царцсан цайвар pill).
                // pill/card нь box-гүй бол rect болно.
                $shape = in_array($item['box_shape'] ?? 'rect', ['pill', 'glass', 'card', 'outline'], true) ? $item['box_shape'] : 'rect';
                if (in_array($shape, ['pill', 'card'], true) && empty($item['box'])) {
                    $shape = 'rect';
                }
                // $boxed = panel-ийн хэвтээ дотор зай em-ээр (rect 0.7, бөөрөнхий 1.4).
                $panel = ! empty($item['box']) || in_array($shape, ['glass', 'outline'], true);
                $boxed = $panel ? ($shape === 'rect' ? 0.7 : 1.4) : 0.0;
                $size = (int) max(8, min($size, floor($w * 0.86 / max(0.01, $widest / 100 + $boxed + (($item['icon'] ?? 'none') !== 'none' ? 1.3 : 0)))));
                $soft = ! $still && ! $panel && in_array($item['role'] ?? '', ['subtitle', 'small'], true); // видео дээр зөөлөн бараан panel
                $pad = (int) round($size * ($shape === 'card' ? 0.7 : ($panel ? 0.35 : ($soft ? 0.4 : 0))));
                $lines = substr_count($text, "\n") + 1;
                $position = in_array($item['position'] ?? '', ['top', 'center', 'lower_third', 'bottom'], true) ? $item['position'] : 'lower_third';

                $display = ($item['role'] ?? '') === 'display'; // том цифр/үсэг: доош унжих хэсэггүй, дараагийн мөртэй ойр суудаг
                // Мөр хоорондын алхам: давхарласан гарчиг (title/display/price, том үсэг эсвэл condensed)
                // хэвлэмэл постер шиг нягт; энгийн мөрүүд хэвийн зайтай.
                $headline = in_array($item['role'] ?? '', ['display', 'title', 'price'], true);
                $pitch = $headline ? ($caps > 1 || $font === 'condensed' ? 1.02 : 1.12) : 1.4;
                // Блокийг бүсээр нь хадгална; хэмжээсийг em-ээр (хэмжээнд үл хамаарах) тэмдэглэнэ,
                // учир нь мөрийн хэмжээ доор дахин тэнцүүлэгдэж болно.
                $blocks[$position][] = compact('i', 'item', 'text', 'raw', 'marked', 'font', 'size', 'pad', 'soft', 'same', 'align', 'shape', 'column', 'columns', 'rowNo', 'pitch', 'clusterAlign')
                    + ['ems' => ['pad' => $pad / max(1, $size), 'line' => ($display ? 0.9 : 1.2) + $pitch * ($lines - 1), 'gap' => $display ? 0.12 : (empty($item['rule']) ? 0.3 : 0.9), 'width' => min($w * 0.9 / max(1, $size), $longest * self::WIDTH[$font] * $caps)]];
            }

            // Блок бүр өөрийн хэмжээгээр хайрцгаа авна (мөрийн item-уудын хэмжээг тэнцүүлэхгүй).
            foreach ($blocks as $position => $set) {
                foreach ($set as $k => $b) {
                    $size = $b['size'];
                    $pad = (int) round($size * $b['ems']['pad']);
                    $blocks[$position][$k] = ['size' => $size, 'pad' => $pad,
                        'width' => (int) round($size * $b['ems']['width']) + 2 * $pad,
                        'height' => (int) round($size * $b['ems']['line']) + 2 * $pad,
                        'gap' => (int) round($size * $b['ems']['gap'])] + $b;
                }
            }

            // drawtext/drawbox filter-ууд; $floor = дараагийн бүс эхэлж болох хамгийн дээд y.
            $filters = [];
            $icons = []; // [файл, x, y, enable]
            $panels = []; // текстийн доор зурагдах бөөрөнхий panel-ууд: [файл, x, y, enable]
            $floor = 0;

            // Доод бүсийн хамгийн доод хил (Stories ба reels дээр аппын UI-аас дээр).
            $bottomLimit = (int) round($h * ($still ? 0.95 : 0.8));
            // Бүсийн өндөр: мөр бүр нэг удаа, хамгийн өндөр item-ийнхээ өндрөөр тоологдоно.
            $zoneHeight = function (array $set): int {
                $rows = [];
                foreach ($set as $b) {
                    $rows[$b['rowNo']] = max($rows[$b['rowNo']] ?? 0, $b['height'] + $b['gap']);
                }

                return array_sum($rows);
            };

            // 2-р алхам: бүсүүдийг дээрээс доош байрлуулна; бүс бүр өмнөхийнхөө доор ($floor) эхэлнэ.
            foreach (['top', 'center', 'lower_third', 'bottom'] as $position) {
                $y = max($this->baseY($position, $w, $h, $still), $floor);

                // Доод бүс $bottomLimit-ээс хэтрэхгүйн тулд дээш татагдана.
                if ($position === 'bottom' && isset($blocks['bottom'])) {
                    $group = $zoneHeight($blocks['bottom']);
                    $y = max($floor, min($y, $bottomLimit - $group));
                }

                $row = null; // сүүлийн блок эхэлсэн мөр: ['y' => дээд, 'gap' => …, 'end' => дараагийн чөлөөт y]
                $rowH = []; // мөр бүр хамгийн өндөр item-ийнхээ өндөртэй; жижиг item-ууд дотор нь төвлөрнө
                foreach ($blocks[$position] ?? [] as $bb) {
                    $rowH[$bb['rowNo']] = max($rowH[$bb['rowNo']] ?? 0, $bb['height']);
                }
                // Блок бүрийн байрлал, хөдөлгөөн, icon, panel, drawtext filter-ийг бүтээнэ.
                foreach ($blocks[$position] ?? [] as $b) {
                    ['i' => $i, 'item' => $item, 'size' => $size, 'pad' => $pad, 'soft' => $soft] = $b;
                    // Өмнөх блоктой нэг мөрөнд бол тэр мөрийн дээд y-г дахин ашиглана.
                    $same = $b['same'] && $row;
                    if ($same) {
                        $y = $row['y'];
                    }

                    // Мөрийн дотор босоо төвлөрүүлж, фонтыг түр хавтсанд f{i}.ttf нэрээр хуулна.
                    $rowStart = $same ? $row['y'] : $y;
                    $y = $rowStart + intdiv($rowH[$b['rowNo']] - $b['height'], 2);
                    File::copy(resource_path('fonts/'.self::FONTS[$b['font']]), "{$dir}/f{$i}.ttf");

                    // Хугацаа: start–end хооронд харагдана (хамгийн багадаа 0.5 s); fade-in 0.35 s, fade-out 0.3 s.
                    $top = $y + $pad;
                    $start = max(0.0, (float) ($item['start'] ?? 0));
                    $end = min($duration, max($start + 0.5, (float) ($item['end'] ?? $duration)));
                    $in = 0.35;
                    $alpha = match (true) {
                        $still => '1',
                        ($item['animation'] ?? 'fade') === 'none' => "if(between(t,{$start},{$end}),1,0)",
                        default => "if(lt(t,{$start}),0,if(lt(t,{$start}+{$in}),(t-{$start})/{$in},if(lt(t,{$end}-0.3),1,if(lt(t,{$end}),({$end}-t)/0.3,0))))",
                    };
                    // slide_up: 0.6em доороос fade-in-ий хугацаанд байрандаа гулсаж ирнэ.
                    $yExpr = ! $still && ($item['animation'] ?? '') === 'slide_up'
                        ? "{$top}+".round($size * 0.6)."*(1-min(1,max(0,(t-{$start})/{$in})))"
                        : (string) $top;
                    $left = $b['align'] === 'left';
                    $right = $b['align'] === 'right';
                    // Хажуугийн зай = өргөний 7% (LogoOverlay-ийн зайтай ижил).
                    $margin = (int) round($w * 0.07);
                    $cx = (int) round($w / 2);
                    // Бүлэг: мөрийн ижил align-тай item-уудын бодит өргөнийг (icon + текст + panel) хэмжиж,
                    // тогтмол зайтайгаар зэрэгцүүлээд бүлгийг тэр align-ийн дагуу зүүн / төв / баруун тийш тавина.
                    if ($b['align'] === 'cluster') {
                        $itemWidth = function (array $o): int {
                            $bb = imagettfbbox($o['size'] * 0.75, 0, resource_path('fonts/'.self::FONTS[$o['font']]), $o['text']);
                            $hasIcon = in_array($o['item']['icon'] ?? 'none', IconSet::NAMES, true) && ! str_contains($o['text'], "\n");
                            $circle = ($o['item']['icon_style'] ?? 'plain') === 'circle';
                            $iconPx = (int) round($o['size'] * ($circle ? 1.3 : 0.95));
                            $padX = $o['shape'] !== 'rect' ? (int) round($o['size'] * ($o['shape'] === 'card' ? 0.9 : 0.7)) : $o['pad'];

                            return ($bb[2] - $bb[0]) + ($hasIcon ? $iconPx + (int) round($o['size'] * 0.35) : 0) + 2 * $padX;
                        };
                        $mates = array_values(array_filter($blocks[$position], fn ($o) => $o['rowNo'] === $b['rowNo'] && $o['clusterAlign'] === $b['clusterAlign']));
                        $widths = array_map($itemWidth, $mates);
                        $gapPx = (int) round($w * 0.05);
                        $total = array_sum($widths) + $gapPx * (count($mates) - 1);
                        $start = max($margin, match ($b['clusterAlign']) {
                            'left' => $margin,
                            'right' => $w - $margin - $total,
                            default => (int) round(($w - $total) / 2),
                        });
                        $k = (int) array_search($b['i'], array_column($mates, 'i'), true);
                        $cx = $start + array_sum(array_slice($widths, 0, $k)) + $gapPx * $k + intdiv($widths[$k], 2);
                    }
                    $x = match (true) {
                        $left => (string) ($margin + $pad),
                        $right => 'w-text_w-'.($margin + $pad),
                        default => "{$cx}-text_w/2",
                    };
                    $width = $b['width'];

                    // Icon нь нэг мөрт item-ийн өмнө байрлана, хоёулаа хамт төвд (эсвэл зүүн ирмэгт).
                    $icon = in_array($item['icon'] ?? 'none', IconSet::NAMES, true) && ! str_contains($b['text'], "\n") ? $item['icon'] : null;
                    if ($icon) {
                        $box = imagettfbbox($size * 0.75, 0, resource_path('fonts/'.self::FONTS[$b['font']]), $b['text']);
                        $textW = $box[2] - $box[0];
                        $iconPx = (int) round($size * (($item['icon_style'] ?? 'plain') === 'circle' ? 1.3 : 0.95));
                        $lead = $iconPx + (int) round($size * 0.35);
                        $iconX = match (true) {
                            $left => $margin,
                            $right => $w - $margin - ($lead + $textW + 2 * $pad),
                            default => (int) round($cx - ($lead + $textW + 2 * $pad) / 2),
                        };
                        $x = (string) ($iconX + $lead + $pad);
                        if ($icon === 'arrow') { // текстийн ард орно: "Захиалах →"
                            $x = (string) ($iconX + $pad);
                            $iconX += $pad + $textW + (int) round($size * 0.35);
                        }
                        $width = $lead + $textW + 2 * $pad;
                        // icon_style circle: icon нь текстийн өнгөтэй тойрог дотор, дүрс нь ардах өнгөтэй
                        // (эс бөгөөс contrast-д зориулж бараан эсвэл цагаан).
                        $disc = ($item['icon_style'] ?? 'plain') === 'circle';
                        $glyph = $disc ? $this->hex($item['icon_color'] ?? ($this->light($item['color'] ?? '') ? '1F2937' : 'FFFFFF')) : $this->hex($item['color'] ?? '');
                        File::put("{$dir}/i{$i}.png", app(IconSet::class)->png($icon, $iconPx, $glyph, $disc ? $this->hex($item['color'] ?? '') : null));
                        $icons[] = ["i{$i}.png", $iconX, $top + (int) round($size * 0.37 - $iconPx / 2), $still ? null : "between(t,{$start},{$end})"];
                    }
                    if ($b['shape'] !== 'rect') {
                        // Бөөрөнхий pill / царцсан glass panel, жинхэнэ glyph-ээс хэмжигдэнэ; icon дотор нь орно.
                        $fontFile = resource_path('fonts/'.self::FONTS[$b['font']]);
                        $textW = max(array_map(fn ($line) => ($bb = imagettfbbox($size * 0.75, 0, $fontFile, $line)) ? $bb[2] - $bb[0] : 0, explode("\n", $b['text'])));
                        $padX = (int) round($size * ($b['shape'] === 'card' ? 0.9 : 0.7));
                        $iconPx = (int) round($size * (($item['icon_style'] ?? 'plain') === 'circle' ? 1.3 : 0.95));
                        $lead = $icon ? $iconPx + (int) round($size * 0.35) : 0;
                        $width = $lead + $textW + 2 * $padX;
                        $panelX = match (true) {
                            $left => $margin,
                            $right => $w - $margin - $width,
                            default => (int) round($cx - $width / 2),
                        };
                        $multi = str_contains($b['text'], "\n");
                        $trail = $icon === 'arrow' ? $lead : 0; // сум текстийн ард байрлана
                        $x = match (true) {
                            ! $multi || $left => (string) ($panelX + $padX + $lead - $trail),
                            $right => ($panelX + $width - $padX).'-text_w',
                            default => "{$cx}-text_w/2",
                        };
                        if (! $multi) {
                            // Glyph-уудыг өөрсдийг нь pill-ийн төвд тавина: мөрийн хайрцаг доош унжих
                            // үсэгт зай үлдээдэг тул дээд талд нь тавьсан том үсэг өндөр, доороо хоосон харагдсан.
                            $yExpr = "{$y}+({$b['height']}-text_h)/2".(! $still && ($item['animation'] ?? '') === 'slide_up' ? "+{$size}*0.6*(1-min(1,max(0,(t-{$start})/{$in})))" : '');
                        }
                        // Дээр нэмсэн icon-ийн байрлалыг pill-ийн дотор талд дахин тохируулна.
                        if ($icon) {
                            $icons[array_key_last($icons)][1] = $trail ? $panelX + $padX + $textW + (int) round($size * 0.35) : $panelX + $padX;
                            $icons[array_key_last($icons)][2] = $y + (int) round(($b['height'] - $iconPx) / 2);
                        }
                        File::put("{$dir}/p{$i}.png", $this->panel($width, $b['height'], $b['shape'] === 'card' ? 'pill' : $b['shape'], $this->hex(($b['shape'] === 'outline' ? ($item['box_color'] ?? '') ?: ($item['color'] ?? '') : ($item['box_color'] ?? '')) ?: 'FFFFFF'), $b['shape'] === 'card' ? (int) round($size * 0.5) : ($multi ? (int) round($size * 0.6) : null)));
                        $panels[] = ["p{$i}.png", $panelX, $y, $still ? null : "between(t,{$start},{$end})"];
                    }

                    // drawtext-ийн нэмэлт загвар: box (rect panel), сүүдэр эсвэл юу ч үгүй.
                    $style = match (true) {
                        $b['shape'] !== 'rect' => '', // өөрийн panel дээр: сүүдэргүй
                        // Жижиг мөрүүд ээдрээтэй видео дээр уншигдахуйц байхаар зөөлөн бараан panel авна.
                        $soft => ":box=1:boxcolor=black@0.38:boxborderw={$pad}:shadowcolor=black@0.4:shadowx=0:shadowy=1",
                        $pad > 0 => ":box=1:boxcolor={$this->color($item['box_color'] ?? '', '000000')}@0.82:boxborderw={$pad}",
                        ($item['effect'] ?? 'auto') === 'none' => '',
                        $this->light($item['color'] ?? '') || ($item['effect'] ?? 'auto') === 'shadow' => ':shadowcolor=black@0.55:shadowx=0:shadowy='.max(2, round($size * 0.05)),
                        default => '',
                    };
                    // Хүрээ (outline): өнгөт зураг дээр үсгийг тодруулах эсвэл стикер маягийн эффект.
                    if (($item['effect'] ?? 'auto') === 'outline' && $b['shape'] === 'rect' && $pad === 0) {
                        $style .= ':borderw='.max(2, (int) round($size * 0.06)).':bordercolor='.$this->color($item['effect_color'] ?? '', '000000');
                    }
                    // Бүх мөрт нийтлэг drawtext параметрүүд (expansion=none: % тэмдгийг шууд зурна).
                    $common = "expansion=none:fontsize={$size}:fontcolor={$this->color($item['color'] ?? '', 'FFFFFF')}:x={$x}:alpha='{$alpha}'{$style}";

                    // Мөр бүрийг нийтлэг baseline тор дээр тусад нь зурна: drawtext блокийг төвлөрүүлэхдээ
                    // мөрүүдийг зүүн тийш тэгшилдэг, зарим фонт (Oswald) мөр хооронд хэт их зай авдаг.
                    // Текстийг textfile-аар өгнө — escape хийх асуудлаас зайлсхийнэ.
                    $lines = explode("\n", $b['text']);
                    $rawLines = explode("\n", $b['raw']);
                    foreach ($lines as $k => $line) {
                        $lineY = count($lines) === 1 ? $yExpr : "({$yExpr})+".($k * (int) round($size * $b['pitch']) + (int) round($size * 0.92)).'-ascent';
                        $runs = $b['marked'] ? explode('*', $rawLines[$k] ?? $line) : [$line];
                        if (count($runs) === 1) {
                            File::put("{$dir}/t{$i}_{$k}.txt", $line);
                            $filters[] = "drawtext=fontfile=f{$i}.ttf:textfile=t{$i}_{$k}.txt:line_spacing=".round($size * 0.2).":y='{$lineY}':{$common}";

                            continue;
                        }

                        // Хоёр өнгөт мөр: хэсэг бүр нэг baseline дээр, сондгой хэсгүүд accent өнгөөр.
                        // $advance: "H" нэмж хэмжсэнээр төгсгөлийн зайг ч тооцсон шилжилтийн өргөн.
                        $fontFile = resource_path('fonts/'.self::FONTS[$b['font']]);
                        $advance = function (string $s) use ($size, $fontFile): int {
                            if ($s === '') {
                                return 0;
                            }
                            $with = imagettfbbox($size * 0.75, 0, $fontFile, $s.'H');
                            $mark = imagettfbbox($size * 0.75, 0, $fontFile, 'H');

                            return ($with[2] - $with[0]) - ($mark[2] - $mark[0]);
                        };
                        // Мөрийн эхлэх x ба baseline-ийг тэгшлэлээс хамааран тооцно.
                        $lineW = $advance($line);
                        $startX = match (true) {
                            is_numeric($x) => (int) $x,
                            $right && $b['shape'] !== 'rect' => $panelX + $width - $padX - $lineW,
                            $right => $w - $margin - $pad - $lineW,
                            default => (int) round($cx - $lineW / 2),
                        };
                        $baseY = count($lines) === 1
                            ? ($b['shape'] !== 'rect' ? "{$y}+".(int) round(($b['height'] + 0.7 * $size) / 2).'-ascent' : "({$yExpr})+".(int) round($size * 0.92).'-ascent')
                            : $lineY;
                        $accent = $this->color($item['accent_color'] ?? '', 'FFB703');
                        $done = '';
                        foreach ($runs as $r => $run) {
                            if (trim($run) !== '') {
                                // Эхний хоосон зайг алгасаж, хэмжсэн шилжилтээр орлуулна (зайгаар эхэлсэн
                                // хэсгийг drawtext бага зэрэг хэт баруун тийш тавьдаг).
                                $lead = mb_strlen($run) - mb_strlen(ltrim($run));
                                File::put("{$dir}/t{$i}_{$k}_{$r}.txt", ltrim($run));
                                $runCommon = str_replace(["x={$x}:", "fontcolor={$this->color($item['color'] ?? '', 'FFFFFF')}"], ['x='.($startX + $advance($done.mb_substr($run, 0, $lead))).':', 'fontcolor='.($r % 2 ? $accent : $this->color($item['color'] ?? '', 'FFFFFF'))], $common);
                                $filters[] = "drawtext=fontfile=f{$i}.ttf:textfile=t{$i}_{$k}_{$r}.txt:y='{$baseY}':{$runCommon}";
                            }
                            $done .= $run;
                        }
                    }
                    // Блокийн тэгш өнцөгтийг $placed-д бүртгэнэ (busyLines / lowContrastLines шалгалтад);
                    // 'shrunk' = role-ийн хэмжээнээс мэдэгдэхүйц багассан эсэх.
                    $x0 = $b['shape'] !== 'rect' ? $panelX : match (true) {
                        $left => $margin,
                        $right => $w - $margin - $width,
                        default => (int) round($cx - $width / 2),
                    };
                    // Доод нимгэн шугам (онцлогийн тайлбар, жагсаалтын мөр): текстийн өргөнөөс бага зэрэг урт.
                    if (! empty($item['rule']) && $b['shape'] === 'rect') {
                        $ext = (int) round($size * 0.6);
                        $filters[] = 'drawbox=x='.max(0, $x0 - $ext).':y='.((int) round($y + $b['height'] + $size * 0.25)).':w='.($width + 2 * $ext).':h='.max(1, (int) round($size * 0.04)).':color=0x'.$this->hex(($item['strike_color'] ?? '') ?: ($item['color'] ?? 'FFFFFF')).'@0.45:t=fill';
                    }
                    // Зураастай текст (хуучин үнэ): текстийн дундуур хөндлөн шугам.
                    if (! empty($item['strike']) && $b['shape'] === 'rect') {
                        $thick = max(2, (int) round($size * 0.07));
                        $filters[] = 'drawbox=x='.($x0 + $pad).':y='.((int) round($y + $b['height'] * 0.44) - intdiv($thick, 2)).':w='.max(1, $width - 2 * $pad).":h={$thick}:color=0x".$this->hex(($item['strike_color'] ?? '') ?: ($item['color'] ?? 'FFFFFF')).':t=fill';
                    }
                    $this->placed[] = ['text' => $b['text'], 'panel' => $pad > 0, 'rect' => [$x0, $y, $x0 + $width, $y + $b['height']], 'color' => $this->hex($item['color'] ?? ''), 'accent' => $b['marked'] ? $this->hex($item['accent_color'] ?? 'FFB703') : null, 'position' => $position, 'row' => $b['rowNo'], 'shrunk' => $size < (int) round(min($w, $h) * $roleSize($item) * 0.75)];
                    // Дараагийн мөрийн y = энэ мөрийн дээд + мөрийн өндөр + хамгийн том gap.
                    $rowGap = max($same ? $row['gap'] : 0, $b['gap']);
                    $row = ['y' => $rowStart, 'gap' => $rowGap, 'end' => $rowStart + $rowH[$b['rowNo']] + $rowGap];
                    $y = $row['end'];
                }

                // Дараагийн бүс энэ бүсээс 2%-ийн зайтай доор эхэлнэ.
                $floor = $y + (int) round(min($w, $h) * 0.02);
            }

            if (! $filters) {
                throw new AiException('All text items were empty (only blank lines). Put the real words in "text" as plain UTF-8 letters — no blank-line padding, no \u escapes.');
            }

            // Оролтыг түр хавтсанд энгийн нэрээр хуулна.
            $ext = $still ? 'png' : 'mp4';
            File::copy($video, "{$dir}/in.".pathinfo($video, PATHINFO_EXTENSION));

            // Graph: [бүхний доор scrim] → panel → текст → icon. Клип дээр зургууд давтагдана; overlay клиптэй хамт дуусна.
            // $label = одоогийн гаралтын шошго, $n = дараагийн оролтын дугаар.
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

            // Panel бүрийг дараалан overlay хийнэ (клип дээр enable-ээр цаг хязгаарлана).
            foreach ($panels as $k => [$file, $px, $py, $enable]) {
                $inputs = [...$inputs, ...$image($file)];
                $graph[] = "{$label}[{$n}:v]overlay={$px}:{$py}".($enable ? ":enable='{$enable}'" : '')."{$end}[p{$k}]";
                [$label, $n] = ["[p{$k}]", $n + 1];
            }

            // Бүх drawtext/drawbox-ийг нэг гинжээр, дараа нь icon-уудыг дээр нь давхарлана.
            $graph[] = $label.implode(',', $filters).'[t0]';
            $label = '[t0]';
            foreach ($icons as $k => [$file, $ix, $iy, $enable]) {
                $inputs = [...$inputs, ...$image($file)];
                $graph[] = "{$label}[{$n}:v]overlay={$ix}:{$iy}".($enable ? ":enable='{$enable}'" : '')."{$end}[t".($k + 1).']';
                [$label, $n] = ['[t'.($k + 1).']', $n + 1];
            }

            // Зураг: нэг кадр PNG. Клип: видеог дахин кодлож, аудиог (байвал) хуулна.
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

            // Түр хавтас устахаас өмнө үр дүнг гадна нь зөөнө.
            $keep = storage_path('app/private/text/'.Str::uuid().".{$ext}");
            File::move("{$dir}/out.{$ext}", $keep);

            return $keep;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Сүүлийн burn()-ийн цэвэр зургийн нарийн хэсэг (бүтээгдэхүүн, нүүр, үс, ээдрээтэй
     * бүтэц) дээр суусан мөрүүд, panel-тай эсэхээс үл хамааран — гол объекттой давхцсан
     * байх магадлалтай. 32 px хавтанцар бүрээр пикселүүдийн дундаж гэрэлтлийн алхмаар хэмжинэ.
     *
     * @return list<string>
     */
    public function busyLines(string $cleanImage): array
    {
        $img = @imagecreatefromstring((string) file_get_contents($cleanImage));
        if (! $img) {
            return [];
        }

        // Пикселийн саарал (luma) утга.
        $W = imagesx($img);
        $H = imagesy($img);
        $grey = function (int $x, int $y) use ($img): float {
            $c = imagecolorat($img, $x, $y);

            return ($c >> 16 & 255) * 0.3 + ($c >> 8 & 255) * 0.59 + ($c & 255) * 0.11;
        };

        $busy = [];
        foreach ($this->placed as $p) {
            // Panel ба scrim текстийг уншигдахуйц болгодог ч бүтээгдэхүүнийг халхлахаас сэргийлэхгүй тул
            // мөр бүрийг хэмжинэ; зөвхөн ээдрээтэй дэвсгэр эсэхийг agent өөрөө шийднэ.
            [$x0, $y0, $x1, $y1] = [max(0, $p['rect'][0]), max(0, $p['rect'][1]), min($W - 3, $p['rect'][2]), min($H - 3, $p['rect'][3])];
            // Хавтанцар бүрт 2px алхмаар хэвтээ ба босоо хөршийн гэрэлтлийн зөрүүний дунджийг авна.
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
            // Ээдрээтэй гэж үзэх: хавтанцрын 30%+ нь > 6, эсвэл аль нэг нь маш хурц (≥ 22) ба 8%+ нь ээдрээтэй.
            $share = count(array_filter($tiles, fn ($t) => $t > 6)) / max(1, count($tiles));
            if ($share >= 0.3 || (max($tiles ?: [0]) >= 22 && $share >= 0.08)) {
                $busy[] = str_replace("\n", ' / ', $p['text']);
            }
        }

        return $busy;
    }

    /** Текстийн блокийн дээд y; reels ба Stories (9:16) дээр аппын UI-аас зай авна. */
    private function baseY(string $position, int $w, int $h, bool $still = false): int
    {
        // Клип эсвэл Story хэмжээтэй постер дээр аппын UI-д зай үлдээнэ; хувиуд нь кадрын өндрөөс.
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
     * Кадрын ирмэгээс зураг руу орох зөөлөн градиент (scrim) — текст хатуу panel-гүйгээр
     * зураг дээр уншигдана: ирмэгтээ хамгийн хүчтэй, дунд хүрэхэд алга болно.
     */
    private function scrim(int $w, int $h, string $where, string $hex): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');
        // Цайвар scrim-ийн дээд тунгалаг бус байдал бага (62%), бараан нь 72%.
        $light = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.55;
        $peak = $light ? 0.62 : 0.72;

        // Мөр бүрийг өөрийн alpha-тай хэвтээ шугамаар зурна (GD alpha: 127 = бүрэн тунгалаг).
        for ($y = 0; $y < $h; $y++) {
            $a = $this->scrimAlpha($y, $h, $where, $peak);
            imageline($img, 0, $y, $w - 1, $y, imagecolorallocatealpha($img, $r, $g, $b, 127 - (int) round($a * 127)));
        }

        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /**
     * Бөөрөнхий panel: pill (box өнгө, 92% тунгалаг бус, үзүүр нь бүрэн бөөрөнхий) эсвэл glass
     * (цайвар хүрээтэй царцсан цагаан). Ирмэг гөлгөр байхын тулд 3x томоор зураад багасгана.
     */
    private function panel(int $w, int $h, string $shape, string $hex, ?int $radius = null): string
    {
        $k = 3;
        $big = imagecreatetruecolor($w * $k, $h * $k);
        imagesavealpha($big, true);
        imagealphablending($big, false);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        [$r, $g, $b] = $shape === 'glass' ? [255, 255, 255] : sscanf($hex, '%02x%02x%02x');
        // Радиус өндрийн хагасаас хэтрэхгүй (анхдагч нь бүрэн бөөрөнхий үзүүр).
        $r0 = min((int) floor($h / 2), $radius ?? (int) floor($h / 2)) * $k;
        // $inset px дотогш оруулсан бөөрөнхий тэгш өнцөгт: хоёр загалмай тэгш өнцөгт + 4 булангийн тойрог.
        $shapeAt = function (int $inset, int $color) use ($big, $w, $h, $k, $r0) {
            [$W, $H, $rr] = [$w * $k - 2 * $inset, $h * $k - 2 * $inset, max(1, $r0 - $inset)];
            imagefilledrectangle($big, $inset + $rr, $inset, $inset + $W - $rr - 1, $inset + $H - 1, $color);
            imagefilledrectangle($big, $inset, $inset + $rr, $inset + $W - 1, $inset + $H - $rr - 1, $color);
            foreach ([[$rr, $rr], [$W - $rr - 1, $rr], [$rr, $H - $rr - 1], [$W - $rr - 1, $H - $rr - 1]] as [$cx, $cy]) {
                imagefilledellipse($big, $inset + $cx, $inset + $cy, 2 * $rr, 2 * $rr, $color);
            }
        };

        if ($shape === 'glass') {
            $shapeAt(0, imagecolorallocatealpha($big, 255, 255, 255, 30));           // цайвар хүрээ
            $shapeAt(2 * $k, imagecolorallocatealpha($big, 255, 255, 255, 64));      // царцсан дүүргэлт ~50%
        } elseif ($shape === 'outline') {
            // Зөвхөн хүрээ: өнгөт цагираг, дотор нь тунгалаг (утасны дугаар, "Join now" зэрэг товч).
            $stroke = max(2, (int) round($h * 0.045)) * $k;
            $shapeAt(0, imagecolorallocatealpha($big, $r, $g, $b, 0));
            $shapeAt($stroke, imagecolorallocatealpha($big, 0, 0, 0, 127));
        } else {
            // pill: alpha 10/127 ≈ 92% тунгалаг бус.
            $shapeAt(0, imagecolorallocatealpha($big, $r, $g, $b, 10));
        }

        // Жинхэнэ хэмжээ рүү resample хийнэ.
        $out = imagecreatetruecolor($w, $h);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $big, 0, 0, 0, 0, $w, $h, $w * $k, $h * $k);
        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    /** $y мөр дээрх scrim-ийн тунгалаг бус байдал: ирмэгтээ хамгийн хүчтэй, кадрын дунд алга болно. */
    private function scrimAlpha(int $y, int $h, string $where, float $peak): float
    {
        // Дээрээс өндрийн 50%, доороос 55% хүртэл шугаман бууралт; ^1.6 нь зөөлөн уналт өгнө.
        $fromTop = in_array($where, ['top', 'top_bottom'], true) ? max(0, 1 - $y / ($h * 0.5)) : 0;
        $fromBottom = in_array($where, ['bottom', 'top_bottom'], true) ? max(0, 1 - ($h - 1 - $y) / ($h * 0.55)) : 0;

        return $peak * max($fromTop, $fromBottom) ** 1.6;
    }

    /**
     * Сүүлийн burn()-ийн өнгө нь ардах зурагтайгаа хэт ойр мөрүүд
     * (WCAG contrast харьцаа гарчигт 2.5, жижиг мөрөнд 3-аас бага, scrim-ийг оруулж тооцно; panel-уудыг алгасна —
     * тэд өөрийн дэвсгэртэй). Цайвар талбай дээрх цайвар текст хамгийн түгээмэл тохиолдол.
     *
     * @return list<string>
     */
    public function lowContrastLines(string $cleanImage): array
    {
        $img = @imagecreatefromstring((string) file_get_contents($cleanImage));
        if (! $img) {
            return [];
        }
        // WCAG-ийн харьцангуй гэрэлтэлт (sRGB → шугаман).
        [$W, $H] = [imagesx($img), imagesy($img)];
        $lum = function (array $rgb): float {
            $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        // scrim()-тэй ижил дээд утгыг ашиглаж, үзэгчийн харах дэвсгэрийг сэргээнэ.
        [$where, $scrimHex] = $this->lastScrim;
        $scrimRgb = sscanf($scrimHex, '%02x%02x%02x');
        $peak = $lum($scrimRgb) > 0.3 ? 0.62 : 0.72;

        $low = [];
        foreach ($this->placed as $p) {
            if ($p['panel']) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = [max(0, $p['rect'][0]), max(0, $p['rect'][1]), min($W - 1, $p['rect'][2]), min($H - 1, $p['rect'][3])];
            // Мөрийн тэгш өнцөгт доторх дундаж өнгийг 4px алхмаар, scrim-тэй хольж тооцно.
            [$sum, $n] = [[0, 0, 0], 0];
            for ($y = $y0; $y < $y1; $y += 4) {
                $a = $this->scrimAlpha($y, $H, $where, $peak);
                for ($x = $x0; $x < $x1; $x += 4) {
                    $c = imagecolorat($img, $x, $y);
                    foreach ([16, 8, 0] as $k => $shift) {
                        $sum[$k] += (($c >> $shift) & 0xFF) * (1 - $a) + $scrimRgb[$k] * $a;
                    }
                    $n++;
                }
            }
            if (! $n) {
                continue;
            }
            $bg = $lum(array_map(fn ($v) => $v / $n, $sum));
            // Жижиг мөрөнд том гарчгаас илүү contrast хэрэгтэй (WCAG: том текстэд 3); одоор тэмдэглэсэн
            // accent үгийг мөн өөрийн өнгөөр нь үнэлнэ (цөцгий өнгөн дээр шар алга болдог).
            $needed = ($y1 - $y0) < $H * 0.05 ? 3.0 : 2.5;
            foreach (array_values(array_filter([$p['color'] ?? 'FFFFFF', $p['accent'] ?? null])) as $k => $hex) {
                $text = $lum(sscanf($hex, '%02x%02x%02x'));
                if ((max($bg, $text) + 0.05) / (min($bg, $text) + 0.05) < ($k ? max($needed, 3.0) : $needed)) { // accent үг тод ялгарах ёстой
                    $low[] = str_replace("\n", ' / ', $p['text']).($k ? ' (its accent word)' : '');
                }
            }
        }

        return $low;
    }

    // Хэрэглэгчийн өнгийг "0x"-гүй 6 оронтой hex болгоно (буруу бол цагаан).
    private function hex(string $hex): string
    {
        return substr($this->color($hex, 'FFFFFF'), 2);
    }

    /** Цайвар текстэд зурагнаас ялгарах сүүдэр хэрэгтэй; бараан текстэд хэрэггүй (luma > 0.55 = цайвар). */
    private function light(string $hex): bool
    {
        $hex = substr($this->color($hex, 'FFFFFF'), 2);
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.55;
    }

    // ffmpeg-ийн "0xRRGGBB" өнгө; '#'-ийг хасаж, буруу утгад $fallback өгнө.
    private function color(string $hex, string $fallback): string
    {
        $hex = ltrim(trim($hex), '#');

        return '0x'.(preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : $fallback);
    }

    // Текстийг цэвэрлэнэ: escape хийсэн "\n"-ийг жинхэнэ мөр шилжилт болгож, \r ба бусад
    // удирдах тэмдэгтийг хасаад 90 тэмдэгтээр хязгаарлана.
    private function clean(string $text): string
    {
        $text = str_replace(['\\n', "\r"], ["\n", ''], $text);
        $text = preg_replace('/[^\P{C}\n]/u', '', $text);

        return Str::limit(trim($text), 90, '');
    }

    /**
     * Зураг/клипийн өргөн, өндрийг ffprobe-оор уншина.
     *
     * @return array{0: int, 1: int}
     */
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
