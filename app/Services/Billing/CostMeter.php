<?php

namespace App\Services\Billing;

use App\Models\Creation;
use App\Models\Payment;
use App\Models\Subscription;

/**
 * API-ын жагсаалтын үнийг (config/pricing.php) дуудлага бүрийн USD болгоно.
 * Ажил бүрийн media төсвийг хязгаарлах, credit үнийг тогтоох, бодит зардал
 * болон ашгийн хувийг тайлагнахад ашиглана.
 */
class CostMeter
{
    /**
     * Claude (Fable)-ийн token хэрэглээний USD зардал (үнэ нь 1M token тутамд).
     *
     * @param  array{input_tokens?: int, output_tokens?: int, cache_creation_input_tokens?: int, cache_read_input_tokens?: int}  $usage
     */
    public function claude(array $usage, ?string $model = null): float
    {
        $p = config("pricing.costs.claude_models.{$model}") ?? config('pricing.costs.claude');

        return (($usage['input_tokens'] ?? 0) * $p['input']
            + ($usage['output_tokens'] ?? 0) * $p['output']
            + ($usage['cache_creation_input_tokens'] ?? 0) * $p['cache_write']
            + ($usage['cache_read_input_tokens'] ?? 0) * $p['cache_read']) / 1_000_000;
    }

    /**
     * Нэг зураг үүсгэх USD зардал (тохиргоонд байхгүй бол 0.25).
     */
    public function image(string $provider): float
    {
        return (float) config("pricing.costs.image.{$provider}", 0.25);
    }

    /**
     * Видеоны USD зардал = секунд × секундын үнэ (тохиргоонд байхгүй бол 0.4).
     */
    public function video(string $provider, int $seconds): float
    {
        return $seconds * (float) config("pricing.costs.video_second.{$provider}", 0.4);
    }

    /**
     * USD-г ханшаар төгрөг (₮) болгоно.
     */
    public function mnt(float $usd): int
    {
        return (int) round($usd * config('pricing.usd_mnt'));
    }

    // ---------------------------------------------------------------- үнийн жагсаалт

    /**
     * Ажлын нэг хэсгийн тооцоолсон API зардал (USD), Fable болон media-д хуваасан,
     * хамгийн үнэтэй зураг, видео загвараар тооцсон.
     *
     * @param  'poster'|'poster_extra_format'|'reel'  $part
     * @param  'typical'|'worst'  $case
     * @return array{claude: float, media: float, total: float}
     */
    public function estimate(string $part, string $case, int $seconds = 0): array
    {
        $e = config("pricing.estimates.{$part}.{$case}");
        $tokens = $e['claude'];

        // Reel-ийн урттай хамт өсөх token-уудыг нэмнэ.
        foreach ($e['claude_per_second'] ?? [] as $key => $perSecond) {
            $tokens[$key] = ($tokens[$key] ?? 0) + $perSecond * $seconds;
        }

        $claude = $this->claude($tokens);
        $media = $e['images'] * $this->priciestImage() + $seconds * ($e['video_factor'] ?? 0) * $this->priciestVideoSecond();

        return ['claude' => round($claude, 4), 'media' => round($media, 4), 'total' => round($claude + $media, 4)];
    }

    /**
     * Ажлын нэг хэсгийн credit үнэ: орлого нь хамгийн муу тохиолдол × margin.worst
     * болон ердийн тохиолдол × margin.typical хоёуланг нөхөхүйц байна. 50 ба түүнээс
     * дээш credit-ийн үнийг 5-ын үржвэр хүртэл дээш бүхэлчилнэ.
     */
    public function credits(string $part, int $seconds = 0): int
    {
        $margin = config('pricing.margin');
        $usd = max(
            $this->estimate($part, 'worst', $seconds)['total'] * $margin['worst'],
            $this->estimate($part, 'typical', $seconds)['total'] * $margin['typical'],
        );
        $credits = (int) ceil($this->mnt($usd) / config('pricing.credit_mnt'));

        return $credits >= 50 ? (int) ceil($credits / 5) * 5 : $credits;
    }

    /**
     * Зураг + видеоны зардлын (USD) хатуу дээд хязгаар: хамгийн муу тохиолдлын media.
     */
    public function budget(string $part, int $seconds = 0): float
    {
        return $this->estimate($part, 'worst', $seconds)['media'];
    }

    /**
     * Хэрэглэгчид харагдах credit үнэ: постер, нэмэлт хэмжээ, reel-ийн урт тус бүр.
     *
     * @return array{poster: int, poster_extra_format: int, reel: array<int, int>}
     */
    public function creditPrices(): array
    {
        return [
            'poster' => $this->credits('poster'),
            'poster_extra_format' => $this->credits('poster_extra_format'),
            'reel' => collect(config('creations.reel.durations'))->keys()->mapWithKeys(fn ($s) => [$s => $this->credits('reel', $s)])->all(),
        ];
    }

    /**
     * Админы тайлан болон `pricing:report`-д зориулсан үнийн жагсаалтын мөрүүд.
     *
     * @return list<array<string, mixed>>
     */
    public function priceBook(): array
    {
        $rows = [['part' => 'poster', 'label' => 'Постер (1 хэмжээ)', 'seconds' => 0], ['part' => 'poster_extra_format', 'label' => '+ нэмэлт хэмжээ', 'seconds' => 0]];

        foreach (array_keys(config('creations.reel.durations')) as $s) {
            $rows[] = ['part' => 'reel', 'label' => "Reels {$s} сек", 'seconds' => $s];
        }

        // Мөр бүрт тооцоолсон зардал, credit үнэ, орлого, ашгийн харьцааг нэмнэ.
        return array_map(function (array $row) {
            $typical = $this->estimate($row['part'], 'typical', $row['seconds']);
            $worst = $this->estimate($row['part'], 'worst', $row['seconds']);
            $credits = $this->credits($row['part'], $row['seconds']);
            $revenue = $credits * config('pricing.credit_mnt');

            return $row + [
                'typical' => $typical,
                'worst' => $worst,
                'budget_usd' => $this->budget($row['part'], $row['seconds']),
                'credits' => $credits,
                'revenue_mnt' => $revenue,
                'margin_typical' => round($revenue / max(1, $this->mnt($typical['total'])), 2),
                'margin_worst' => round($revenue / max(1, $this->mnt($worst['total'])), 2),
            ];
        }, $rows);
    }

    /**
     * Дууссан ажлуудын бодит зардал (ажиллах үед дуудлага бүрээр бүртгэсэн),
     * төрлөөр нь бүлэглэж үнийн жагсаалттай харьцуулна.
     *
     * @return list<array<string, mixed>>
     */
    public function actuals(?int $days = null): array
    {
        // Админы тест, үнэгүй credit-ээр хийсэн ч бүх дууссан ажлыг тооцно: ашгийн харьцаа нь
        // ажлын жагсаалтын үнийг (хэрэглэгч төлөх дүн) бодит зардалд нь хуваасан утга.
        return $this->since($days)->where('status', Creation::DONE)->where('cost_usd', '>', 0)
            ->get(['type', 'duration', 'formats', 'credits', 'cost_usd', 'assets', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'])
            ->groupBy(fn (Creation $c) => $this->kind($c))
            ->map(function ($jobs, $label) {
                $media = $jobs->map(fn (Creation $c) => array_sum(array_column($c->assets, 'cost')));
                $cost = $jobs->sum('cost_usd');
                $list = $jobs->sum(fn (Creation $c) => $this->listCredits($c)) * config('pricing.credit_mnt');
                $first = $jobs->first();

                return [
                    'label' => $label,
                    'jobs' => $jobs->count(),
                    'avg_usd' => round($cost / $jobs->count(), 4),
                    'avg_claude_usd' => round(($cost - $media->sum()) / $jobs->count(), 4),
                    'avg_media_usd' => round($media->avg(), 4),
                    'max_usd' => round($jobs->max('cost_usd'), 4),
                    // Энэ төрлийн ердийн ажилд үнийн жагсаалтын таамагласан зардал.
                    'estimate_usd' => $first->type === Creation::REEL
                        ? $this->estimate('reel', 'typical', $first->reelDuration())['total']
                        : $this->estimate('poster', 'typical')['total'],
                    'avg_tokens' => [
                        'input' => (int) $jobs->avg('input_tokens'),
                        'output' => (int) $jobs->avg('output_tokens'),
                        'cache_read' => (int) $jobs->avg('cache_read_tokens'),
                        'cache_write' => (int) $jobs->avg('cache_write_tokens'),
                    ],
                    'list_mnt' => (int) round($list / $jobs->count()),
                    'margin' => $cost > 0 ? round($list / $this->mnt($cost), 2) : null,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /** Хэн ажиллуулсанаас үл хамааран энэ ажилд хэрэглэгчээс авах credit (жагсаалтын үнэ). */
    public function listCredits(Creation $creation): int
    {
        $extra = max(0, count($creation->formats ?? []) - 1);

        return $creation->type === Creation::REEL
            ? $this->credits('reel', $creation->reelDuration())
            : $this->credits('poster') + $extra * $this->credits('poster_extra_format');
    }

    /**
     * Сүүлийн ажлууд нэг бүрчлэн: token, Fable болон media зардал, жагсаалтын үнэ,
     * ашгийн харьцаа (админы тест орно), ингэснээр ажил бүрийн бодит зардал харагдана.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?int $days = null, int $limit = 40): array
    {
        return $this->since($days)->whereIn('status', [Creation::DONE, Creation::FAILED])->where('cost_usd', '>', 0)
            ->with('user:id,email,is_admin')->latest('id')->limit($limit)
            ->get(['id', 'user_id', 'type', 'duration', 'formats', 'status', 'credits', 'cost_usd', 'assets', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'created_at'])
            ->map(function (Creation $c) {
                $media = array_sum(array_column($c->assets, 'cost'));
                $list = $this->listCredits($c) * config('pricing.credit_mnt');

                return [
                    'id' => $c->id,
                    'at' => $c->created_at?->toIso8601String(),
                    'who' => $c->user?->email,
                    'admin' => (bool) $c->user?->is_admin,
                    'kind' => $this->kind($c),
                    'formats' => $c->formats,
                    'status' => $c->status,
                    'images' => count(array_filter($c->assets, fn ($a) => ($a['kind'] ?? '') === 'image' && ($a['cost'] ?? 0) > 0)),
                    'tokens' => ['input' => $c->input_tokens, 'output' => $c->output_tokens, 'cache_read' => $c->cache_read_tokens, 'cache_write' => $c->cache_write_tokens],
                    'claude_usd' => round($c->cost_usd - $media, 4),
                    'media_usd' => round($media, 4),
                    'usd' => round($c->cost_usd, 4),
                    'cost_mnt' => $this->mnt($c->cost_usd),
                    'charged_mnt' => $c->credits * config('pricing.credit_mnt'),
                    'list_mnt' => $list,
                    'margin' => round($list / max(1, $this->mnt($c->cost_usd)), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Бүх зураглал нэг дор: юу хийсэн, хэд зарцуулсан (Fable token / зураг / видео),
     * орж ирсэн мөнгө (төлөгдсөн нэхэмжлэх) болон ашиг.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $days = null): array
    {
        $jobs = $this->since($days)->whereIn('status', [Creation::DONE, Creation::FAILED])
            ->get(['id', 'type', 'duration', 'formats', 'reel_clips', 'status', 'cost_usd', 'assets', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens']);
        // Зардлыг Fable, зураг, видео гэж задлана; Fable нь нийтээс media-г хассан үлдэгдэл.
        $assets = $jobs->flatMap(fn (Creation $c) => $c->assets ?? []);
        $images = $assets->filter(fn ($a) => ($a['kind'] ?? '') === 'image' && ($a['cost'] ?? 0) > 0);
        $videos = $assets->filter(fn ($a) => ($a['kind'] ?? '') === 'video' && ($a['source'] ?? '') === 'generated' && ($a['cost'] ?? 0) > 0);
        $total = (float) $jobs->sum('cost_usd');
        $imageUsd = (float) $images->sum('cost');
        $videoUsd = (float) $videos->sum('cost');
        $claudeUsd = max(0, $total - $imageUsd - $videoUsd);

        // Төрөл тус бүрийн амжилттай / амжилтгүй тоо болон зардал.
        $made = fn (string $type) => [
            'done' => $jobs->where('type', $type)->where('status', Creation::DONE)->count(),
            'failed' => $jobs->where('type', $type)->where('status', Creation::FAILED)->count(),
            'cost_mnt' => $this->mnt((float) $jobs->where('type', $type)->sum('cost_usd')),
            'avg_mnt' => $this->mnt((float) $jobs->where('type', $type)->sum('cost_usd') / max(1, $jobs->where('type', $type)->where('status', Creation::DONE)->count())),
        ];

        // QPAY_FAKE нэхэмжлэхүүд (FAKE-…) нь локал тест, бодит мөнгө биш тул тусад нь тоолно.
        $paid = Payment::where('status', Payment::PAID)
            ->when($days, fn ($q) => $q->where('paid_at', '>=', now()->subDays($days)))
            ->get(['amount', 'invoice_id']);
        [$fake, $real] = $paid->partition(fn (Payment $p) => str_starts_with((string) $p->invoice_id, 'FAKE-'));
        $cash = (int) $real->sum('amount');
        $spent = $this->mnt($total);

        return [
            'posters' => $made(Creation::POSTER),
            'reels' => $made(Creation::REEL),
            'spent_usd' => round($total, 2),
            'spent_mnt' => $spent,
            'parts' => [
                ['label' => 'Fable (token)', 'usd' => round($claudeUsd, 2), 'mnt' => $this->mnt($claudeUsd), 'count' => null],
                ['label' => 'Зураг', 'usd' => round($imageUsd, 2), 'mnt' => $this->mnt($imageUsd), 'count' => $images->count()],
                ['label' => 'Видео', 'usd' => round($videoUsd, 2), 'mnt' => $this->mnt($videoUsd), 'count' => $videos->count()],
            ],
            'tokens' => [
                'input' => (int) $jobs->sum('input_tokens'),
                'output' => (int) $jobs->sum('output_tokens'),
                'cache_read' => (int) $jobs->sum('cache_read_tokens'),
                'cache_write' => (int) $jobs->sum('cache_write_tokens'),
            ],
            'payments' => $real->count(),
            'cash_mnt' => $cash,
            'test_payments' => ['count' => $fake->count(), 'mnt' => (int) $fake->sum('amount')],
            'profit_mnt' => $cash - $spent,
            // Бүх ажлыг (админы тест орно) жагсаалтын үнээр төлсөн гэж үзвэл;
            // амжилтгүй ажлын credit буцаагддаг тул орлого өгөхгүй ч зардал гарсаар байна.
            'as_customer' => (function () use ($jobs, $spent) {
                $done = $jobs->where('status', Creation::DONE);
                $revenue = (int) $done->sum(fn (Creation $c) => $this->listCredits($c) * config('pricing.credit_mnt'));
                $doneCost = $this->mnt((float) $done->sum('cost_usd'));

                return [
                    'revenue_mnt' => $revenue,
                    'cost_mnt' => $spent,
                    'profit_mnt' => $revenue - $spent,
                    'margin' => $spent ? round($revenue / $spent, 2) : null,
                    'failed_cost_mnt' => $spent - $doneCost,
                    'losing_jobs' => $done->filter(fn (Creation $c) => $this->listCredits($c) * config('pricing.credit_mnt') < $this->mnt($c->cost_usd))->count(),
                ];
            })(),
        ];
    }

    /**
     * Орж ирсэн мөнгө ба API-д гарсан мөнгө, мөн хаана алдагдсан нь: амжилтгүй ажил
     * (credit буцаасан ч API зардал гарсан), хэн ч төлөөгүй ажил (админ / үнэгүй credit),
     * олсноосоо их зардал гарсан дууссан ажил. Орлого гэдэг нь зарцуулсан credit-д
     * хэрэглэгчийн бодитоор төлсөн дүн (төлбөрийн дүн ÷ тухайн хугацааны credit);
     * үнэгүй олголтын credit орлого өгөхгүй.
     *
     * @return array<string, mixed>
     */
    public function losses(?int $days = null): array
    {
        $jobs = $this->since($days)
            ->whereIn('status', [Creation::DONE, Creation::FAILED])
            ->with('user:id,is_admin')
            ->get(['id', 'user_id', 'type', 'duration', 'status', 'credits', 'charges', 'cost_usd', 'assets', 'error_detail']);

        // Ажлын орлого = хасагдсан credit бүрийг тухайн захиалгын нэг credit-ийн үнээр үржүүлсэн нийлбэр.
        $perCredit = $this->pricePerCredit($jobs);
        $revenue = fn (Creation $c) => (int) round(collect($c->charges ?? [])->sum(fn ($ch) => $ch['credits'] * ($perCredit[$ch['subscription_id']] ?? 0)));

        $rows = $jobs->map(fn (Creation $c) => [
            'job' => $c,
            'cost' => $this->mnt($c->cost_usd),
            'media_usd' => array_sum(array_column($c->assets, 'cost')),
            'revenue' => $c->status === Creation::DONE ? $revenue($c) : 0,
        ]);

        // Алдагдлын гурван ангилал: амжилтгүй, төлөөгүй, алдагдалтай.
        $failed = $rows->filter(fn ($r) => $r['job']->status === Creation::FAILED && $r['cost'] > 0);
        $unpaid = $rows->filter(fn ($r) => $r['job']->status === Creation::DONE && $r['revenue'] === 0 && $r['cost'] > 0);
        $underwater = $rows->filter(fn ($r) => $r['job']->status === Creation::DONE && $r['revenue'] > 0 && $r['revenue'] < $r['cost']);

        // Ангилал бүрийн нийт тоо, зардал (₮), Fable болон media USD.
        $sum = fn ($set) => [
            'jobs' => $set->count(),
            'cost_mnt' => $set->sum('cost'),
            'claude_usd' => round($set->sum(fn ($r) => $r['job']->cost_usd - $r['media_usd']), 4),
            'media_usd' => round($set->sum('media_usd'), 4),
        ];

        $revenueTotal = $rows->sum('revenue');
        $costTotal = $rows->sum('cost');

        return [
            'days' => $days,
            'revenue_mnt' => $revenueTotal,
            'cost_mnt' => $costTotal,
            'net_mnt' => $revenueTotal - $costTotal,
            'failed' => $sum($failed) + [
                'by_kind' => $failed->groupBy(fn ($r) => $this->kind($r['job']))->map(fn ($set, $label) => ['label' => $label] + $sum($set))->sortKeys()->values()->all(),
                'reasons' => $failed->groupBy(fn ($r) => mb_strimwidth(trim((string) $r['job']->error_detail) ?: '—', 0, 140, '…'))
                    ->map(fn ($set, $reason) => ['reason' => $reason] + $sum($set))
                    ->sortByDesc('cost_mnt')->take(8)->values()->all(),
            ],
            'unpaid' => $sum($unpaid) + [
                'by_who' => $unpaid->groupBy(fn ($r) => $r['job']->user?->is_admin ? 'Админ / тест' : 'Үнэгүй кредит')
                    ->map(fn ($set, $label) => ['label' => $label] + $sum($set))->values()->all(),
            ],
            'underwater' => $sum($underwater) + ['lost_mnt' => $underwater->sum(fn ($r) => $r['cost'] - $r['revenue'])],
        ];
    }

    /**
     * Хэрэглэгчийн нэг credit-д төлсөн ₮, захиалгын id-аар (үнэгүй олголт болон FAKE төлбөрт 0).
     *
     * @return array<int, float>
     */
    private function pricePerCredit($jobs): array
    {
        $ids = $jobs->flatMap(fn (Creation $c) => array_column($c->charges ?? [], 'subscription_id'))->unique()->values();

        return Subscription::with('payment:id,amount,invoice_id')->whereIn('id', $ids)->get(['id', 'payment_id', 'credits'])
            ->mapWithKeys(fn (Subscription $s) => [$s->id => $s->payment && $s->credits > 0 && ! str_starts_with((string) $s->payment->invoice_id, 'FAKE-') ? $s->payment->amount / $s->credits : 0.0])
            ->all();
    }

    /**
     * Сүүлийн $days өдрийн ажлуудын query ($days нь null бол бүгд).
     */
    private function since(?int $days)
    {
        return Creation::query()->when($days, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)));
    }

    /**
     * Тайланд харагдах ажлын төрлийн нэр (постер эсвэл reel-ийн урт).
     */
    private function kind(Creation $c): string
    {
        return $c->type === Creation::REEL ? "Reels {$c->reelDuration()} сек" : 'Постер';
    }

    /**
     * Хамгийн үнэтэй зургийн загварын нэг зургийн үнэ (USD).
     */
    private function priciestImage(): float
    {
        return (float) max(config('pricing.costs.image'));
    }

    /**
     * Хамгийн үнэтэй видео загварын нэг секундын үнэ (USD).
     */
    private function priciestVideoSecond(): float
    {
        return (float) max(config('pricing.costs.video_second'));
    }
}
