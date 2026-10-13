<?php

namespace App\Services\Billing;

use App\Models\Creation;
use App\Models\Payment;
use App\Models\Subscription;

/**
 * API list prices (config/pricing.php) turned into USD per call. Used to
 * enforce each job's media budget, to set credit prices and to report real
 * cost and margin.
 */
class CostMeter
{
    /**
     * @param  array{input_tokens?: int, output_tokens?: int, cache_creation_input_tokens?: int, cache_read_input_tokens?: int}  $usage
     */
    public function claude(array $usage): float
    {
        $p = config('pricing.costs.claude');

        return (($usage['input_tokens'] ?? 0) * $p['input']
            + ($usage['output_tokens'] ?? 0) * $p['output']
            + ($usage['cache_creation_input_tokens'] ?? 0) * $p['cache_write']
            + ($usage['cache_read_input_tokens'] ?? 0) * $p['cache_read']) / 1_000_000;
    }

    public function image(string $provider): float
    {
        return (float) config("pricing.costs.image.{$provider}", 0.25);
    }

    public function video(string $provider, int $seconds): float
    {
        return $seconds * (float) config("pricing.costs.video_second.{$provider}", 0.4);
    }

    public function mnt(float $usd): int
    {
        return (int) round($usd * config('pricing.usd_mnt'));
    }

    // ---------------------------------------------------------------- price book

    /**
     * Estimated API cost of one job part (USD), split into Fable and media,
     * priced with the most expensive image and video models.
     *
     * @param  'poster'|'poster_extra_format'|'reel'  $part
     * @param  'typical'|'worst'  $case
     * @return array{claude: float, media: float, total: float}
     */
    public function estimate(string $part, string $case, int $seconds = 0): array
    {
        $e = config("pricing.estimates.{$part}.{$case}");
        $tokens = $e['claude'];

        foreach ($e['claude_per_second'] ?? [] as $key => $perSecond) {
            $tokens[$key] = ($tokens[$key] ?? 0) + $perSecond * $seconds;
        }

        $claude = $this->claude($tokens);
        $media = $e['images'] * $this->priciestImage() + $seconds * ($e['video_factor'] ?? 0) * $this->priciestVideoSecond();

        return ['claude' => round($claude, 4), 'media' => round($media, 4), 'total' => round($claude + $media, 4)];
    }

    /**
     * Credits for one job part: enough that revenue covers the worst case ×
     * margin.worst and the typical case × margin.typical. Prices of 50+ credits
     * are rounded up to a multiple of 5.
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
     * Hard cap on image + video spend (USD): the worst case's media.
     */
    public function budget(string $part, int $seconds = 0): float
    {
        return $this->estimate($part, 'worst', $seconds)['media'];
    }

    /**
     * Credit prices the customer sees: poster, extra size, and reels per length.
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
     * Price book rows for the admin report and `pricing:report`.
     *
     * @return list<array<string, mixed>>
     */
    public function priceBook(): array
    {
        $rows = [['part' => 'poster', 'label' => 'Постер (1 хэмжээ)', 'seconds' => 0], ['part' => 'poster_extra_format', 'label' => '+ нэмэлт хэмжээ', 'seconds' => 0]];

        foreach (array_keys(config('creations.reel.durations')) as $s) {
            $rows[] = ['part' => 'reel', 'label' => "Reels {$s} сек", 'seconds' => $s];
        }

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
     * What finished jobs really cost (recorded per call while they ran), grouped
     * by kind; compared with the price book.
     *
     * @return list<array<string, mixed>>
     */
    public function actuals(?int $days = null): array
    {
        // Every finished job counts, admin tests and free credits too: the margin is
        // the job's list price (what a customer pays for it) over its real cost.
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
                    // What the price book assumes for a typical job of this kind.
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

    /** Credits a customer is charged for this job (its list price), whoever ran it. */
    public function listCredits(Creation $creation): int
    {
        $extra = max(0, count($creation->formats ?? []) - 1);

        return $creation->type === Creation::REEL
            ? $this->credits('reel', $creation->reelDuration())
            : $this->credits('poster') + $extra * $this->credits('poster_extra_format');
    }

    /**
     * The latest jobs one by one: tokens, Fable and media cost, list price and
     * margin, admin tests included, so the real cost of a job is visible.
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
     * The whole picture in one place: what was made, what it cost (Fable tokens vs
     * images vs video), the money that came in (paid invoices) and the profit.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $days = null): array
    {
        $jobs = $this->since($days)->whereIn('status', [Creation::DONE, Creation::FAILED])
            ->get(['id', 'type', 'status', 'cost_usd', 'assets', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens']);
        $assets = $jobs->flatMap(fn (Creation $c) => $c->assets ?? []);
        $images = $assets->filter(fn ($a) => ($a['kind'] ?? '') === 'image' && ($a['cost'] ?? 0) > 0);
        $videos = $assets->filter(fn ($a) => ($a['kind'] ?? '') === 'video' && ($a['source'] ?? '') === 'generated' && ($a['cost'] ?? 0) > 0);
        $total = (float) $jobs->sum('cost_usd');
        $imageUsd = (float) $images->sum('cost');
        $videoUsd = (float) $videos->sum('cost');
        $claudeUsd = max(0, $total - $imageUsd - $videoUsd);

        $made = fn (string $type) => [
            'done' => $jobs->where('type', $type)->where('status', Creation::DONE)->count(),
            'failed' => $jobs->where('type', $type)->where('status', Creation::FAILED)->count(),
            'cost_mnt' => $this->mnt((float) $jobs->where('type', $type)->sum('cost_usd')),
            'avg_mnt' => $this->mnt((float) $jobs->where('type', $type)->sum('cost_usd') / max(1, $jobs->where('type', $type)->where('status', Creation::DONE)->count())),
        ];

        // QPAY_FAKE invoices (FAKE-…) are local tests, not money: counted apart.
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
        ];
    }

    /**
     * Money in vs API money out, and where it was lost: failed jobs (credits
     * refunded, API cost kept), jobs nobody paid for (admin / free credits) and
     * finished jobs that cost more than they earned. Revenue is what the
     * customer actually paid for the credits spent (payment amount ÷ the
     * period's credits); free-tier credits earn nothing.
     *
     * @return array<string, mixed>
     */
    public function losses(?int $days = null): array
    {
        $jobs = $this->since($days)
            ->whereIn('status', [Creation::DONE, Creation::FAILED])
            ->with('user:id,is_admin')
            ->get(['id', 'user_id', 'type', 'duration', 'status', 'credits', 'charges', 'cost_usd', 'assets', 'error_detail']);

        $perCredit = $this->pricePerCredit($jobs);
        $revenue = fn (Creation $c) => (int) round(collect($c->charges ?? [])->sum(fn ($ch) => $ch['credits'] * ($perCredit[$ch['subscription_id']] ?? 0)));

        $rows = $jobs->map(fn (Creation $c) => [
            'job' => $c,
            'cost' => $this->mnt($c->cost_usd),
            'media_usd' => array_sum(array_column($c->assets, 'cost')),
            'revenue' => $c->status === Creation::DONE ? $revenue($c) : 0,
        ]);

        $failed = $rows->filter(fn ($r) => $r['job']->status === Creation::FAILED && $r['cost'] > 0);
        $unpaid = $rows->filter(fn ($r) => $r['job']->status === Creation::DONE && $r['revenue'] === 0 && $r['cost'] > 0);
        $underwater = $rows->filter(fn ($r) => $r['job']->status === Creation::DONE && $r['revenue'] > 0 && $r['revenue'] < $r['cost']);

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
     * ₮ the customer paid per credit, by subscription id (0 for free grants).
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

    private function since(?int $days)
    {
        return Creation::query()->when($days, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)));
    }

    private function kind(Creation $c): string
    {
        return $c->type === Creation::REEL ? "Reels {$c->reelDuration()} сек" : 'Постер';
    }

    private function priciestImage(): float
    {
        return (float) max(config('pricing.costs.image'));
    }

    private function priciestVideoSecond(): float
    {
        return (float) max(config('pricing.costs.video_second'));
    }
}
