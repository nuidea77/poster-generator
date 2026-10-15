<?php

namespace App\Services\Agent;

use App\Models\StyleReference;

/**
 * Агентын хэв маягийн жишээ сан: админы сонгосон идэвхтэй жишээнүүдийг хайж,
 * зургийн моделд style reference болгон өгөх (гэрэл, өнгө, найруулга — текст/лого биш).
 */
class ReferenceLibrary
{
    /**
     * Идэвхтэй жишээнүүдийг ангиллаар шүүж, хэв маяг таарсныг нь эхэнд, дараа нь шинэ нь эхэндээ байхаар буцаана.
     *
     * @return list<array{id: string, title: string, category: string, style: ?string, notes: ?string, path: string}>
     */
    public function search(?string $category = null, ?string $style = null, int $limit = 6): array
    {
        $style = mb_strtolower(trim((string) $style));
        $query = StyleReference::query()->where('enabled', true);

        if ($category) {
            $query->where('category', $category);
        }
        if ($style !== '') {
            // Яг таарсан → хэсэгчлэн таарсан → бусад (том/жижиг үсэг ялгахгүй).
            $query->orderByRaw(
                'CASE WHEN LOWER(style) = ? THEN 0 WHEN LOWER(style) LIKE ? THEN 1 ELSE 2 END',
                [$style, '%'.$style.'%'],
            );
        }

        return $query->latest('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (StyleReference $r) => $this->present($r))
            ->all();
    }

    /** Брифийн түлхүүр үгээр салбарыг таамаглах (Монгол + англи). */
    private const KEYWORDS = [
        'food' => ['хоол', 'ресторан', 'кафе', 'бургер', 'пицца', 'кофе', 'ундаа', 'амттан', 'меню', 'тахиа', 'нүдл', 'food', 'cafe', 'restaurant', 'menu', 'burger', 'pizza', 'coffee'],
        'beauty' => ['арьс', 'гоо сайхан', 'тарилга', 'шампунь', 'үсч', 'сормуус', 'нүүр', 'салон', 'beauty', 'skin', 'cosmetic', 'facial', 'spa'],
        'health' => ['эмнэлэг', 'шүд', 'эмчилгээ', 'витамин', 'эрүүл', 'эм ', 'health', 'dental', 'clinic', 'medical', 'pharmacy'],
        'fashion' => ['хувцас', 'ноолуур', 'гутал', 'загвар', 'fashion', 'cashmere', 'apparel', 'shoes'],
        'education' => ['сургалт', 'сургууль', 'курс', 'хэлний', 'их сургууль', 'тэтгэлэг', 'education', 'ielts', 'course', 'academy', 'school'],
        'event' => ['тоглолт', 'концерт', 'баяр', 'наадам', 'тэмцээн', 'шинэ жил', 'цагаан сар', 'мэнд хүргэ', 'event', 'concert', 'festival', 'party'],
        'retail' => ['хямдрал', 'дэлгүүр', 'худалдаа', 'бараа', 'sale', 'store', 'mall', 'shop'],
        'tech' => ['апп', 'аппликейшн', 'утас', 'банк', 'санхүү', 'зээл', 'компьютер', 'дрон', 'app', 'bank', 'fintech', 'software', 'phone', 'laptop'],
        'real_estate' => ['орон сууц', 'байр', 'хотхон', 'түрээс', 'барилга', 'м²', 'real estate', 'apartment', 'residence'],
        'sport' => ['спорт', 'фитнесс', 'фитнес', 'марафон', 'хөлбөмбөг', 'сагсан', 'gym', 'fitness', 'sport', 'marathon'],
    ];

    /**
     * Брифэд зориулсан жагсаалт: таамагласан салбарын жишээнүүд эхэнд (≤ $same), дараа нь
     * бусад салбаруудаас тэнцүү тархсан цөөн жишээ. $seed нь ажил бүрт өөр дараалал өгнө.
     *
     * @return array{category: ?string, refs: list<array{id: string, title: string, category: string, style: ?string, notes: ?string, path: string}>}
     */
    public function forBrief(string $text, int $seed = 0, int $same = 14, int $other = 10): array
    {
        $text = mb_strtolower($text);
        $scores = array_map(fn ($words) => array_sum(array_map(fn ($w) => substr_count($text, $w), $words)), self::KEYWORDS);
        arsort($scores);
        $category = reset($scores) > 0 ? array_key_first($scores) : null;

        $all = StyleReference::where('enabled', true)->get()->shuffle($seed ?: null);
        [$match, $rest] = $all->partition(fn (StyleReference $r) => $r->category === $category);
        // Бусад салбараас ээлжлэн нэг нэгээр авна (нэг салбар давамгайлахгүй).
        $spread = collect();
        $groups = $rest->groupBy('category')->values();
        for ($i = 0; $spread->count() < $other && $groups->contains(fn ($g) => $g->count() > $i); $i++) {
            foreach ($groups as $g) {
                if (isset($g[$i]) && $spread->count() < $other) {
                    $spread->push($g[$i]);
                }
            }
        }

        return ['category' => $category, 'refs' => $match->take($same)->concat($spread)->map(fn (StyleReference $r) => $this->present($r))->values()->all()];
    }

    /**
     * 'ref_<id>' танигчаар нэг идэвхтэй жишээг буцаана.
     *
     * @return array{id: string, title: string, category: string, style: ?string, notes: ?string, path: string}|null
     */
    public function get(string $id): ?array
    {
        if (! preg_match('/^ref_(\d+)$/', $id, $m)) {
            return null;
        }
        $ref = StyleReference::where('enabled', true)->find((int) $m[1]);

        return $ref ? $this->present($ref) : null;
    }

    // Агентад өгөх хэлбэр (path нь public диск дээрх зам).
    private function present(StyleReference $r): array
    {
        return [
            'id' => 'ref_'.$r->id,
            'title' => $r->title,
            'category' => $r->category,
            'style' => $r->style,
            'notes' => $r->notes,
            'path' => $r->path,
        ];
    }
}
