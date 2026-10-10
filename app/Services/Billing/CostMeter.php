<?php

namespace App\Services\Billing;

/**
 * API list prices (config/pricing.php) turned into USD per call. Used to
 * enforce each job's media budget and to report real cost and margin.
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
}
