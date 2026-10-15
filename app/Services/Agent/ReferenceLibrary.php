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

    /** Баярын жишээ ↔ брифийн үг: [жишээнд байх үгс, брифэд байх үгс]. */
    private const SEASONS = [
        [['christmas', 'new year', 'santa', 'bauble', 'snow', '2025', 'winter'], ['шинэ жил', 'зул сар', 'өвөл', 'new year', 'christmas']],
        [['halloween'], ['halloween', 'хэллоуин']],
        [['valentine'], ['валентин', 'valentine', 'хайр']],
        [['black friday'], ['black friday', 'хар баасан']],
        [['tsagaan sar', 'lunar', 'horse year'], ['цагаан сар', 'сар шин']],
        [['naadam'], ['наадам']],
        [["women's day", 'soldier', 'army', 'independence', 'national day', "children's day", 'chinggis'], ['эмэгтэйчүүд', 'цэргийн', 'тусгаар', 'хүүхдийн баяр', 'чингис']],
    ];

    /** Брифийн өнгө аяс → [түлхүүр үгс, тохирох стилиуд]. */
    private const MOODS = [
        'premium' => [['тансаг', 'premium', 'luxury', 'дээд зэрэг'], ['Gold luxury', 'Minimal Editorial', 'Editorial photo']],
        'energetic' => [['залуу', 'эрч хүч', 'шуурхай', 'хүчтэй', 'хөгжилтэй', 'energetic'], ['Commercial', 'Maximal retail', 'Tactical Dark', 'Neo-Brutalism', 'Bold Typographic / Swiss', 'Playful']],
        'clean' => [['цэвэрхэн', 'цайвар', 'минимал', 'энгийн', 'clean'], ['Clean light product', 'Minimal Editorial']],
        'trust' => [['найдвартай', 'мэргэжлийн', 'trust'], ['Corporate & Editorial', 'Commercial', 'Clean light product']],
        'warm' => [['дулаан', 'уламжлалт', 'гэр бүл'], ['Editorial photo', 'Minimal Editorial', 'Gold luxury']],
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
     * Брифэд хамгийн тохирох $n жишээ: таамагласан салбарынхаас брифтэй хамгийн олон үг
     * (гарчиг, тайлбар, стиль) давхцсаныг нь; тэнцвэл $seed-ээр санамсаргүй. Салбар танигдаагүй бол хоосон.
     *
     * @return list<array{id: string, title: string, category: string, style: ?string, notes: ?string, path: string}>
     */
    public function pick(string $text, int $seed = 0, int $n = 2): array
    {
        $category = $this->forBrief($text, $seed, 0, 0)['category'];
        if (! $category) {
            return [];
        }

        $words = fn (string $s) => array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)), fn ($w) => mb_strlen($w) >= 4));
        $brief = $words($text);
        $lower = mb_strtolower($text);
        $has = fn (array $list, string $hay) => (bool) array_filter($list, fn ($w) => str_contains($hay, $w));
        // Брифийн өнгө аяс → тохирох стилиуд.
        $moods = array_keys(array_filter(self::MOODS, fn ($m) => $has($m[0], $lower)));
        $score = function (StyleReference $r) use ($brief, $words, $lower, $has, $moods) {
            $hay = mb_strtolower("{$r->title} {$r->notes} {$r->style}");
            $points = count(array_intersect($brief, $words($hay)));
            // Баярын жишээ зөвхөн тэр баярын брифэд.
            foreach (self::SEASONS as [$ref, $brf]) {
                if ($has($ref, $hay) && ! $has($brf, $lower)) {
                    $points -= 5;
                } elseif ($has($ref, $hay)) {
                    $points += 3;
                }
            }
            foreach ($moods as $mood) {
                $points += in_array($r->style, self::MOODS[$mood][1], true) ? 2 : 0;
            }

            return $points;
        };

        return StyleReference::where('enabled', true)->where('category', $category)->get()
            ->shuffle($seed ?: null)
            ->sortByDesc($score)
            ->take($n)
            ->map(fn (StyleReference $r) => $this->present($r))
            ->values()
            ->all();
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
