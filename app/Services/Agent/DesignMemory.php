<?php

namespace App\Services\Agent;

use App\Models\Creation;
use Illuminate\Support\Collection;

/**
 * Хэрэглэгчдийн үнэлгээнээс (👍/👎) суралцах санах ой: үнэлэгдсэн постеруудын
 * deliver_poster-ийн design (map, style, palette, type, idea)-ийг нэгтгэж
 * agent-д зориулсан богино англи блок болгоно.
 */
class DesignMemory
{
    // Нийтээр хамгийн багадаа хэдэн үнэлгээтэй постер байх ёстой (түүнээс бага бол чимээгүй).
    public const MIN_RATED = 3;

    // Тухайн хэрэглэгчийн хэдэн сүүлийн үнэлгээг харах.
    public const USER_LIMIT = 20;

    // Нийт хэрэглэгчдийн хэдэн сүүлийн үнэлгээг харах.
    public const GLOBAL_LIMIT = 200;

    /**
     * Agent-ийн брифэд нэмэх блок; өгөгдөл хангалтгүй бол null.
     */
    public function brief(?int $userId): ?string
    {
        $global = $this->rated(null, self::GLOBAL_LIMIT);
        if ($global->count() < self::MIN_RATED) {
            return null;
        }

        $lines = ['## What clients liked (learned from their ratings)'];

        // Тухайн хэрэглэгчийн өөрийн сонголт хамгийн чухал.
        if ($userId) {
            $own = $this->rated($userId, self::USER_LIMIT);
            $liked = $this->summarize($own->where('rating', 1));
            $disliked = $this->summarize($own->where('rating', -1));
            if ($liked) {
                $lines[] = "- Liked by this client: {$liked}.";
            }
            if ($disliked) {
                $lines[] = "- Disliked by this client: {$disliked}.";
            }
            $feedback = $own->where('rating', -1)->pluck('feedback')->filter()->take(3)
                ->map(fn ($f) => "'".str_replace(["\r", "\n"], ' ', mb_strimwidth($f, 0, 160, '…'))."'");
            if ($feedback->isNotEmpty()) {
                $lines[] = '- This client\'s feedback on disliked posters: '.$feedback->implode('; ').'.';
            }
        }

        // Нийт хэрэглэгчдийн дунд хамгийн өндөр/бага үнэлгээтэй map, style.
        $best = collect(['map', 'style'])->map(fn ($key) => $this->ranked($global, $key))->filter();
        if ($best->isNotEmpty()) {
            $lines[] = '- Across all clients: '.$best->implode('; ').'.';
        }

        if (count($lines) === 1) {
            return null;
        }
        $lines[] = 'Lean towards what this client liked and away from what they disliked, but still vary the composition — this is a preference, not a template.';

        return implode("\n", $lines);
    }

    /**
     * Үнэлэгдсэн дууссан постерууд (шинээс нь), design-тай нь.
     *
     * @return Collection<int, array{rating: int, feedback: ?string, design: array}>
     */
    private function rated(?int $userId, int $limit): Collection
    {
        return Creation::query()
            ->where('type', Creation::POSTER)->where('status', Creation::DONE)->whereNotNull('rating')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->latest('rated_at')->latest('id')->limit($limit)
            ->get(['id', 'rating', 'feedback', 'steps'])
            ->map(fn (Creation $c) => ['rating' => (int) $c->rating, 'feedback' => $c->feedback, 'design' => $this->design($c)]);
    }

    /**
     * Эхний амжилттай deliver_poster алхмын design (map, style, palette, type, idea).
     */
    private function design(Creation $creation): array
    {
        $step = collect($creation->steps)->first(fn ($s) => ($s['name'] ?? '') === 'deliver_poster' && ! isset($s['error']) && is_array($s['input']['design'] ?? null));

        return collect($step['input']['design'] ?? [])
            ->only(['map', 'style', 'palette', 'type', 'idea'])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim($v))
            ->all();
    }

    /**
     * Олон удаа давтагдсан map + style хослол, palette, type-ийг "split + Editorial Warmth (3×)" хэлбэрээр.
     */
    private function summarize(Collection $items): ?string
    {
        $designs = $items->pluck('design')->filter();
        if ($designs->isEmpty()) {
            return null;
        }

        $combos = $designs->map(fn ($d) => implode(' + ', array_filter([$d['map'] ?? null, $d['style'] ?? null])))->filter();
        $parts = $this->counted($combos, 4);
        if ($palettes = $this->counted($designs->pluck('palette')->filter(), 2)) {
            $parts .= ($parts ? '; ' : '').'palettes: '.$palettes;
        }
        if ($types = $this->counted($designs->pluck('type')->filter(), 2)) {
            $parts .= ($parts ? '; ' : '').'type: '.$types;
        }
        if ($parts === '' && ($ideas = $designs->pluck('idea')->filter()->take(2))->isNotEmpty()) {
            $parts = 'ideas: '.$ideas->map(fn ($i) => mb_strimwidth($i, 0, 80, '…'))->implode(' / ');
        }

        return $parts ?: null;
    }

    // Давтамжаар эрэмбэлж "x (n×), y" гэж нийлүүлнэ.
    private function counted(Collection $values, int $top): string
    {
        return $values->map(fn ($v) => mb_strimwidth($v, 0, 60, '…'))->countBy()->sortDesc()->take($top)
            ->map(fn ($n, $v) => $n > 1 ? "{$v} ({$n}×)" : $v)->implode(', ');
    }

    /**
     * Нэг түлхүүрээр (map/style) дундаж үнэлгээ: хамгийн сайн ба муу (≥2 үнэлгээтэй).
     */
    private function ranked(Collection $rated, string $key): ?string
    {
        $scores = $rated->filter(fn ($r) => isset($r['design'][$key]))
            ->groupBy(fn ($r) => $r['design'][$key])
            ->filter(fn ($g) => $g->count() >= 2)
            ->map(fn ($g) => ['avg' => $g->avg('rating'), 'n' => $g->count()]);
        if ($scores->isEmpty()) {
            return null;
        }

        $fmt = fn ($s, $v) => sprintf('%s (%+d of %d)', $v, (int) round($s['avg'] * $s['n']), $s['n']);
        $best = $scores->filter(fn ($s) => $s['avg'] > 0)->sortByDesc(fn ($s) => [$s['avg'], $s['n']])->take(3)->map($fmt);
        $worst = $scores->filter(fn ($s) => $s['avg'] < 0)->sortBy(fn ($s) => [$s['avg'], -$s['n']])->take(2)->map($fmt);

        $parts = [];
        if ($best->isNotEmpty()) {
            $parts[] = "best-rated {$key}s ".$best->implode(', ');
        }
        if ($worst->isNotEmpty()) {
            $parts[] = "worst-rated {$key}s ".$worst->implode(', ');
        }

        return $parts ? implode(', ', $parts) : null;
    }
}
