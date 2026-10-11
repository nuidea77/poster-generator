<?php

namespace App\Services\Billing;

use App\Models\Creation;

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
    public function actuals(): array
    {
        return Creation::where('status', Creation::DONE)->where('cost_usd', '>', 0)
            ->get(['type', 'duration', 'formats', 'credits', 'cost_usd', 'assets'])
            ->groupBy(fn (Creation $c) => $c->type === Creation::REEL ? "Reels {$c->reelDuration()} сек" : 'Постер')
            ->map(function ($jobs, $label) {
                $media = $jobs->map(fn (Creation $c) => array_sum(array_column($c->assets, 'cost')));
                $cost = $jobs->sum('cost_usd');
                $revenue = $jobs->sum('credits') * config('pricing.credit_mnt');

                return [
                    'label' => $label,
                    'jobs' => $jobs->count(),
                    'avg_usd' => round($cost / $jobs->count(), 4),
                    'avg_claude_usd' => round(($cost - $media->sum()) / $jobs->count(), 4),
                    'avg_media_usd' => round($media->avg(), 4),
                    'max_usd' => round($jobs->max('cost_usd'), 4),
                    'margin' => $cost > 0 ? round($revenue / $this->mnt($cost), 2) : null,
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
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
