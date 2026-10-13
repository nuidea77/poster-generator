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

    /** The scrim of the last burn() [where, hex], to judge contrast as the viewer sees it. */
    private array $lastScrim = ['none', '000000'];

    public const SCRIMS = ['none', 'top', 'bottom', 'top_bottom'];

    /**
     * @param  list<array{text: string, role: string, position: string, align?: string, font: string, color: string, box: bool, box_color: string, start: float, end: float, animation: string, icon?: string}>  $items
     * @param  bool  $still  an image (poster): static text, PNG out; else a clip: timed text, MP4 out
     * @param  string  $scrim  soft gradient behind the text zones: none | top | bottom | top_bottom
     * @param  string  $footerColor  hex of the band behind `footer` items (default near-black)
     * @param  string  $footerStyle  band (full width at the foot) | pill (rounded bar inset from the edges)
     * @return string Absolute path of the new file (caller moves it).
     */
    public function burn(string $video, array $items, float $duration, bool $still = false, array $reserved = [], string $scrim = 'none', string $scrimColor = '000000', string $footerColor = '', string $footerStyle = 'band'): string
    {
        $this->placed = [];
        $this->lastScrim = [$scrim, $this->hex($scrimColor)];

        if (! $items) {
            throw new AiException('Give at least one text item.');
        }

        // The model sometimes fires a stray call whose only "text" is a placeholder; refuse it
        // instead of rendering the word "text" on the poster.
        $words = array_map(fn ($it) => mb_strtolower(preg_replace('/^[\s.…]+|[\s.…]+$/u', '', (string) ($it['text'] ?? ''))), $items);
        if (! array_diff($words, ['', 'text', 'placeholder', 'tbd', 'todo', 'x', 'lorem ipsum'])) {
            throw new AiException('That call held only placeholder text ("'.trim((string) ($items[0]['text'] ?? '')).'"). Send the real items — every string, in one call.');
        }

        [$w, $h] = $this->size($video);
        $dir = storage_path('app/private/text/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            // Pass 1: size every line; pass 2: lay the zones out top to bottom without overlaps.
            $blocks = [];

            $list = array_values($items);
            // Rows: an item with same_line joins the previous item's row. 2 items sit on the two
            // text edges; 3+ items share the width in equal columns (steps, gifts, features).
            [$rowOf, $rowSize, $rowLen, $r] = [[], [], [], -1];
            // Room an item needs in a row: its characters (+ icon) at its role's size.
            $len = fn ($it) => (max(4, mb_strlen((string) ($it['text'] ?? ''))) + (($it['icon'] ?? 'none') !== 'none' ? 2 : 0)
                + (! empty($it['box']) || ($it['box_shape'] ?? '') === 'glass' ? 2.5 : 0)) * (self::SIZES[$it['role'] ?? ''] ?? self::SIZES['subtitle']);
            foreach ($list as $j => $it) {
                $r += empty($it['same_line']) || $j === 0 ? 1 : 0;
                $rowSize[$r] = ($rowSize[$r] ?? 0) + 1;
                $rowLen[$r] = ($rowLen[$r] ?? 0) + $len($it);
                $rowOf[$j] = [$r, $rowSize[$r] - 1];
            }

            foreach ($list as $i => $item) {
                // *word* marks an accent run (two-tone headline); the stars are not drawn.
                $raw = $this->clean($item['text'] ?? '');
                $marked = substr_count($raw, '*') >= 2 && substr_count($raw, '*') % 2 === 0;
                $text = $marked ? str_replace('*', '', $raw) : $raw;
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
                [$rowNo, $column] = $rowOf[$i];
                $columns = $rowSize[$rowNo];
                $same = $column > 0;
                $paired = $columns > 1;
                // A pair splits the width by text length (a long address next to a short phone);
                // rows of 3+ get equal columns.
                $share = $columns === 2 ? $len($item) / $rowLen[$rowNo] : 1 / $columns;
                $align = in_array($item['align'] ?? '', ['left', 'right', 'center'], true) ? $item['align'] : 'center';
                if ($columns === 2 && $align === 'center') {
                    $align = $same ? 'right' : 'left';
                } elseif ($columns > 2) {
                    $align = 'column'; // centred in its own column
                }

                $longest = max(array_map('mb_strlen', explode("\n", $text)));
                $caps = mb_strtoupper($text) === $text ? 1.12 : 1.0;
                $size = (int) round(min($w, $h) * (self::SIZES[$item['role'] ?? ''] ?? self::SIZES['subtitle']));
                $size = (int) min($size, floor($w * (0.84 * $share - ($paired ? 0.02 : 0)) / max(1, $longest * self::WIDTH[$font] * $caps)));
                // Then measure the real glyphs (wide serif capitals beat the estimate): the widest
                // line plus its panel must stay inside 86% of the width.
                $fontFile = resource_path('fonts/'.self::FONTS[$font]);
                $widest = max(array_map(fn ($line) => ($box = imagettfbbox(100 * 0.75, 0, $fontFile, $line)) ? $box[2] - $box[0] : 0, explode("\n", $text)));
                // Panel shape: rect (drawtext box), pill (rounded, box colour) or glass (frosted light pill).
                $shape = in_array($item['box_shape'] ?? 'rect', ['pill', 'glass', 'card'], true) ? $item['box_shape'] : 'rect';
                if (in_array($shape, ['pill', 'card'], true) && empty($item['box'])) {
                    $shape = 'rect';
                }
                $panel = ! empty($item['box']) || $shape === 'glass';
                $boxed = $panel ? ($shape === 'rect' ? 0.7 : 1.4) : 0.0;
                $size = (int) max(8, min($size, floor($w * ($paired ? 0.86 * $share - 0.03 : 0.86) / max(0.01, $widest / 100 + $boxed + ($paired && ($item['icon'] ?? 'none') !== 'none' ? 1.3 : 0)))));
                $soft = ! $still && ! $panel && in_array($item['role'] ?? '', ['subtitle', 'small'], true); // soft dark panel on footage
                $pad = (int) round($size * ($shape === 'card' ? 0.7 : ($panel ? 0.35 : ($soft ? 0.4 : 0))));
                $lines = substr_count($text, "\n") + 1;
                $position = in_array($item['position'] ?? '', ['top', 'center', 'lower_third', 'bottom', 'footer'], true) ? $item['position'] : 'lower_third';
                // A footer row of 3+ is justified: first on the left edge, last on the right, the rest centred.
                if ($position === 'footer' && $columns > 2) {
                    $align = match ($column) {
                        0 => 'left',
                        $columns - 1 => 'right',
                        default => 'column',
                    };
                }

                $display = ($item['role'] ?? '') === 'display'; // big digits/caps: no descenders, sits tight on the next line
                $blocks[$position][] = compact('i', 'item', 'text', 'raw', 'marked', 'font', 'size', 'pad', 'soft', 'same', 'align', 'shape', 'column', 'columns', 'rowNo')
                    + ['ems' => ['pad' => $pad / max(1, $size), 'line' => ($display ? 0.9 : 1.2) * $lines + 0.2 * ($lines - 1), 'gap' => $display ? 0.12 : 0.3, 'width' => min($w * 0.9 / max(1, $size), $longest * self::WIDTH[$font] * $caps)]];
            }

            // Items in one row share one size (the smallest), then every block gets its box.
            foreach ($blocks as $position => $set) {
                $rowMin = [];
                foreach ($set as $b) {
                    $key = $b['rowNo'].':'.($b['item']['role'] ?? '').($b['columns'] === 2 && $b['shape'] !== 'rect' ? ':badge' : '');
                    $rowMin[$key] = min($rowMin[$key] ?? PHP_INT_MAX, $b['size']);
                }
                foreach ($set as $k => $b) {
                    // Rows of 3+: same role → one size. Pairs keep their own sizes, except two badges
                    // (pill/glass) of one role, which must match (a price pill beside an offer pill).
                    $badge = $b['shape'] !== 'rect';
                    $size = $b['columns'] > 2 || ($badge && $b['columns'] === 2) ? $rowMin[$b['rowNo'].':'.($b['item']['role'] ?? '').($b['columns'] === 2 ? ':badge' : '')] ?? $b['size'] : $b['size'];
                    $pad = (int) round($size * $b['ems']['pad']);
                    $blocks[$position][$k] = ['size' => $size, 'pad' => $pad,
                        'width' => (int) round($size * $b['ems']['width']) + 2 * $pad,
                        'height' => (int) round($size * $b['ems']['line']) + 2 * $pad,
                        'gap' => (int) round($size * $b['ems']['gap'])] + $b;
                }
            }

            $filters = [];
            $icons = []; // [file, x, y, enable]
            $panels = []; // rounded panels drawn under the text: [file, x, y, enable]
            $floor = 0;

            // Footer: a full-width band at the foot of the frame (above the app UI on Stories and
            // reels) holding the contact facts in rows — address left, phone right.
            $bottomLimit = (int) round($h * ($still ? 0.95 : 0.8));
            // Height of a zone: each row counts once, as tall as its tallest item.
            $zoneHeight = function (array $set): int {
                $rows = [];
                foreach ($set as $b) {
                    $rows[$b['rowNo']] = max($rows[$b['rowNo']] ?? 0, $b['height'] + $b['gap']);
                }

                return array_sum($rows);
            };
            $footerTop = null;
            if (isset($blocks['footer'])) {
                $group = $zoneHeight($blocks['footer']) - end($blocks['footer'])['gap'];
                $padY = (int) round(min($w, $h) * 0.035);
                $bandBottom = $still && $h <= $w * 1.5 ? $h : (int) round($h * ($still ? 0.87 : 0.8));
                $bandTop = $bandBottom - $group - 2 * $padY;
                $footerTop = $bandTop + $padY;
                $bottomLimit = min($bottomLimit, $bandTop - (int) round(min($w, $h) * 0.025));
                if ($footerStyle === 'pill') {
                    // A rounded bar floating above the foot, inset from the edges (contact strip).
                    $inset = (int) round($w * 0.04);
                    $lift = $still && $h <= $w * 1.5 ? (int) round($h * 0.03) : 0;
                    $bandTop -= $lift;
                    $footerTop -= $lift;
                    $bottomLimit -= $lift;
                    File::put("{$dir}/band.png", $this->panel($w - 2 * $inset, $bandBottom - $lift - $bandTop, 'pill', $this->hex($footerColor ?: 'FFFFFF'), (int) round(($bandBottom - $bandTop) * 0.3)));
                    $panels[] = ['band.png', $inset, $bandTop, null];
                } else {
                    File::put("{$dir}/band.png", $this->band($w, $bandBottom - $bandTop, $this->hex($footerColor ?: '141414')));
                    $panels[] = ['band.png', 0, $bandTop, null];
                }
            }

            foreach (['top', 'center', 'lower_third', 'bottom', 'footer'] as $position) {
                $y = max($this->baseY($position, $w, $h, $still), $floor);

                if ($position === 'bottom' && isset($blocks['bottom'])) {
                    $group = $zoneHeight($blocks['bottom']);
                    $y = max($floor, min($y, $bottomLimit - $group));
                }
                if ($position === 'footer' && $footerTop !== null) {
                    $y = $footerTop;
                }

                $row = null; // the row the last block started on: ['y' => top, 'gap' => …, 'end' => next free y]
                $rowH = []; // each row is as tall as its tallest item; smaller items centre in it
                foreach ($blocks[$position] ?? [] as $bb) {
                    $rowH[$bb['rowNo']] = max($rowH[$bb['rowNo']] ?? 0, $bb['height']);
                }
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
                    $rowStart = $same ? $row['y'] : $y;
                    $y = $rowStart + intdiv($rowH[$b['rowNo']] - $b['height'], 2);
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
                    // Centre of this item: the frame, or its column in a row of 3+.
                    $cx = $b['align'] === 'column' ? (int) round($margin + ($w - 2 * $margin) * ($b['column'] + 0.5) / $b['columns']) : (int) round($w / 2);
                    $x = match (true) {
                        $left => (string) ($margin + $pad),
                        $right => 'w-text_w-'.($margin + $pad),
                        default => "{$cx}-text_w/2",
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
                            default => (int) round($cx - ($lead + $textW + 2 * $pad) / 2),
                        };
                        $x = (string) ($iconX + $lead + $pad);
                        if ($icon === 'arrow') { // trails the text: "Захиалах →"
                            $x = (string) ($iconX + $pad);
                            $iconX += $pad + $textW + (int) round($size * 0.35);
                        }
                        $width = $lead + $textW + 2 * $pad;
                        File::put("{$dir}/i{$i}.png", app(IconSet::class)->png($icon, $iconPx, $this->hex($item['color'] ?? '')));
                        $icons[] = ["i{$i}.png", $iconX, $top + (int) round($size * 0.37 - $iconPx / 2), $still ? null : "between(t,{$start},{$end})"];
                    }
                    if ($b['shape'] !== 'rect') {
                        // Rounded pill / frosted glass panel, measured from the real glyphs; an icon sits inside it.
                        $fontFile = resource_path('fonts/'.self::FONTS[$b['font']]);
                        $textW = max(array_map(fn ($line) => ($bb = imagettfbbox($size * 0.75, 0, $fontFile, $line)) ? $bb[2] - $bb[0] : 0, explode("\n", $b['text'])));
                        $padX = (int) round($size * ($b['shape'] === 'card' ? 0.9 : 0.7));
                        $iconPx = (int) round($size * 0.95);
                        $lead = $icon ? $iconPx + (int) round($size * 0.35) : 0;
                        $width = $lead + $textW + 2 * $padX;
                        $panelX = match (true) {
                            $left => $margin,
                            $right => $w - $margin - $width,
                            default => (int) round($cx - $width / 2),
                        };
                        $multi = str_contains($b['text'], "\n");
                        $trail = $icon === 'arrow' ? $lead : 0; // the arrow sits after the text
                        $x = match (true) {
                            ! $multi || $left => (string) ($panelX + $padX + $lead - $trail),
                            $right => ($panelX + $width - $padX).'-text_w',
                            default => "{$cx}-text_w/2",
                        };
                        if (! $multi) {
                            // Centre the glyphs themselves in the pill: the line box keeps room for
                            // descenders, so capitals placed at its top sat high with a gap below.
                            $yExpr = "{$y}+({$b['height']}-text_h)/2".(! $still && ($item['animation'] ?? '') === 'slide_up' ? "+{$size}*0.6*(1-min(1,max(0,(t-{$start})/{$in})))" : '');
                        }
                        if ($icon) {
                            $icons[array_key_last($icons)][1] = $trail ? $panelX + $padX + $textW + (int) round($size * 0.35) : $panelX + $padX;
                            $icons[array_key_last($icons)][2] = $y + (int) round(($b['height'] - $iconPx) / 2);
                        }
                        File::put("{$dir}/p{$i}.png", $this->panel($width, $b['height'], $b['shape'] === 'card' ? 'pill' : $b['shape'], $this->hex($item['box_color'] ?? 'FFFFFF'), $b['shape'] === 'card' ? (int) round($size * 0.5) : ($multi ? (int) round($size * 0.6) : null)));
                        $panels[] = ["p{$i}.png", $panelX, $y, $still ? null : "between(t,{$start},{$end})"];
                    }

                    $style = match (true) {
                        $b['shape'] !== 'rect' => '',
                        // Small lines get a soft dark panel so they stay readable over busy footage.
                        $soft => ":box=1:boxcolor=black@0.38:boxborderw={$pad}:shadowcolor=black@0.4:shadowx=0:shadowy=1",
                        $pad > 0 => ":box=1:boxcolor={$this->color($item['box_color'] ?? '', '000000')}@0.82:boxborderw={$pad}",
                        $this->light($item['color'] ?? '') => ':shadowcolor=black@0.55:shadowx=0:shadowy='.max(2, round($size * 0.05)),
                        default => '',
                    };
                    $common = "expansion=none:fontsize={$size}:fontcolor={$this->color($item['color'] ?? '', 'FFFFFF')}:x={$x}:alpha='{$alpha}'{$style}";

                    // Every line is drawn on its own on a shared baseline grid: drawtext would centre
                    // a block with its lines flush left, and some fonts (Oswald) space lines far apart.
                    $lines = explode("\n", $b['text']);
                    $rawLines = explode("\n", $b['raw']);
                    foreach ($lines as $k => $line) {
                        $lineY = count($lines) === 1 ? $yExpr : "({$yExpr})+".($k * (int) round($size * 1.4) + (int) round($size * 0.92)).'-ascent';
                        $runs = $b['marked'] ? explode('*', $rawLines[$k] ?? $line) : [$line];
                        if (count($runs) === 1) {
                            File::put("{$dir}/t{$i}_{$k}.txt", $line);
                            $filters[] = "drawtext=fontfile=f{$i}.ttf:textfile=t{$i}_{$k}.txt:line_spacing=".round($size * 0.2).":y='{$lineY}':{$common}";

                            continue;
                        }

                        // Two-tone line: each run drawn on one baseline, the odd runs in the accent colour.
                        $fontFile = resource_path('fonts/'.self::FONTS[$b['font']]);
                        $advance = function (string $s) use ($size, $fontFile): int {
                            if ($s === '') {
                                return 0;
                            }
                            $with = imagettfbbox($size * 0.75, 0, $fontFile, $s.'H');
                            $mark = imagettfbbox($size * 0.75, 0, $fontFile, 'H');

                            return ($with[2] - $with[0]) - ($mark[2] - $mark[0]);
                        };
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
                                // Leading spaces are skipped and replaced by the measured offset (drawtext
                                // places a run that starts with a space a little too far right).
                                $lead = mb_strlen($run) - mb_strlen(ltrim($run));
                                File::put("{$dir}/t{$i}_{$k}_{$r}.txt", ltrim($run));
                                $runCommon = str_replace(["x={$x}:", "fontcolor={$this->color($item['color'] ?? '', 'FFFFFF')}"], ['x='.($startX + $advance($done.mb_substr($run, 0, $lead))).':', 'fontcolor='.($r % 2 ? $accent : $this->color($item['color'] ?? '', 'FFFFFF'))], $common);
                                $filters[] = "drawtext=fontfile=f{$i}.ttf:textfile=t{$i}_{$k}_{$r}.txt:y='{$baseY}':{$runCommon}";
                            }
                            $done .= $run;
                        }
                    }
                    $x0 = $b['shape'] !== 'rect' ? $panelX : match (true) {
                        $left => $margin,
                        $right => $w - $margin - $width,
                        default => (int) round($cx - $width / 2),
                    };
                    $this->placed[] = ['text' => $b['text'], 'panel' => $pad > 0 || $position === 'footer', 'rect' => [$x0, $y, $x0 + $width, $y + $b['height']], 'color' => $this->hex($item['color'] ?? ''), 'position' => $position, 'shrunk' => $size < (int) round(min($w, $h) * (self::SIZES[$item['role'] ?? ''] ?? self::SIZES['subtitle']) * 0.75)];
                    $rowGap = max($same ? $row['gap'] : 0, $b['gap']);
                    $row = ['y' => $rowStart, 'gap' => $rowGap, 'end' => $rowStart + $rowH[$b['rowNo']] + $rowGap];
                    $y = $row['end'];
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

            foreach ($panels as $k => [$file, $px, $py, $enable]) {
                $inputs = [...$inputs, ...$image($file)];
                $graph[] = "{$label}[{$n}:v]overlay={$px}:{$py}".($enable ? ":enable='{$enable}'" : '')."{$end}[p{$k}]";
                [$label, $n] = ["[p{$k}]", $n + 1];
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
     * product, a face, hair, busy texture), panel or not — most likely overlapping
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
            // Panels and scrims make text readable but do not stop it covering the product, so
            // every line is measured; the agent judges whether it is only busy background.
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
            $a = $this->scrimAlpha($y, $h, $where, $peak);
            imageline($img, 0, $y, $w - 1, $y, imagecolorallocatealpha($img, $r, $g, $b, 127 - (int) round($a * 127)));
        }

        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /**
     * A rounded panel: pill (box colour, 92% opaque, fully round ends) or glass (frosted
     * white with a light rim). Drawn 3x and scaled down for smooth edges.
     */
    private function panel(int $w, int $h, string $shape, string $hex, ?int $radius = null): string
    {
        $k = 3;
        $big = imagecreatetruecolor($w * $k, $h * $k);
        imagesavealpha($big, true);
        imagealphablending($big, false);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        [$r, $g, $b] = $shape === 'glass' ? [255, 255, 255] : sscanf($hex, '%02x%02x%02x');
        $r0 = min((int) floor($h / 2), $radius ?? (int) floor($h / 2)) * $k;
        $shapeAt = function (int $inset, int $color) use ($big, $w, $h, $k, $r0) {
            [$W, $H, $rr] = [$w * $k - 2 * $inset, $h * $k - 2 * $inset, max(1, $r0 - $inset)];
            imagefilledrectangle($big, $inset + $rr, $inset, $inset + $W - $rr - 1, $inset + $H - 1, $color);
            imagefilledrectangle($big, $inset, $inset + $rr, $inset + $W - 1, $inset + $H - $rr - 1, $color);
            foreach ([[$rr, $rr], [$W - $rr - 1, $rr], [$rr, $H - $rr - 1], [$W - $rr - 1, $H - $rr - 1]] as [$cx, $cy]) {
                imagefilledellipse($big, $inset + $cx, $inset + $cy, 2 * $rr, 2 * $rr, $color);
            }
        };

        if ($shape === 'glass') {
            $shapeAt(0, imagecolorallocatealpha($big, 255, 255, 255, 30));           // light rim
            $shapeAt(2 * $k, imagecolorallocatealpha($big, 255, 255, 255, 64));      // frosted fill ~50%
        } else {
            $shapeAt(0, imagecolorallocatealpha($big, $r, $g, $b, 10));
        }

        $out = imagecreatetruecolor($w, $h);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $big, 0, 0, 0, 0, $w, $h, $w * $k, $h * $k);
        ob_start();
        imagepng($out);

        return (string) ob_get_clean();
    }

    /** The footer band: a solid full-width strip, slightly translucent so the photo still shows. */
    private function band(int $w, int $h, string $hex): string
    {
        $img = imagecreatetruecolor($w, max(1, $h));
        imagesavealpha($img, true);
        imagealphablending($img, false);
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');
        imagefill($img, 0, 0, imagecolorallocatealpha($img, $r, $g, $b, 18)); // ~86% opaque
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** Scrim opacity at row $y: strongest at the edge, gone by mid-frame. */
    private function scrimAlpha(int $y, int $h, string $where, float $peak): float
    {
        $fromTop = in_array($where, ['top', 'top_bottom'], true) ? max(0, 1 - $y / ($h * 0.5)) : 0;
        $fromBottom = in_array($where, ['bottom', 'top_bottom'], true) ? max(0, 1 - ($h - 1 - $y) / ($h * 0.55)) : 0;

        return $peak * max($fromTop, $fromBottom) ** 1.6;
    }

    /**
     * Lines of the last burn() whose colour is too close to the picture behind them
     * (WCAG contrast ratio under 2.5 for headlines, 3 for small lines, scrim included; panels are skipped — they bring
     * their own background). Light text on a light area is the usual case.
     *
     * @return list<string>
     */
    public function lowContrastLines(string $cleanImage): array
    {
        $img = @imagecreatefromstring((string) file_get_contents($cleanImage));
        if (! $img) {
            return [];
        }
        [$W, $H] = [imagesx($img), imagesy($img)];
        $lum = function (array $rgb): float {
            $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$where, $scrimHex] = $this->lastScrim;
        $scrimRgb = sscanf($scrimHex, '%02x%02x%02x');
        $peak = $lum($scrimRgb) > 0.3 ? 0.62 : 0.72;

        $low = [];
        foreach ($this->placed as $p) {
            if ($p['panel']) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = [max(0, $p['rect'][0]), max(0, $p['rect'][1]), min($W - 1, $p['rect'][2]), min($H - 1, $p['rect'][3])];
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
            $text = $lum(sscanf($p['color'] ?? 'FFFFFF', '%02x%02x%02x'));
            // Small lines need more contrast than big headlines (WCAG: 3 for large text).
            $needed = ($y1 - $y0) < $H * 0.05 ? 3.0 : 2.5;
            if ((max($bg, $text) + 0.05) / (min($bg, $text) + 0.05) < $needed) {
                $low[] = str_replace("\n", ' / ', $p['text']);
            }
        }

        return $low;
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
