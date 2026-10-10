<?php

namespace App\Http\Resources;

use App\Services\Billing\CostMeter;
use App\Services\Billing\Credits;
use App\Services\Media\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal view for admins: includes the agent log, model choices and cost signals.
 */
class AdminCreationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $generated = collect($this->assets)->where('source', 'generated');

        return (new CreationResource($this->resource))->toArray($request) + [
            'user' => ['id' => $this->user_id, 'email' => $this->user?->email],
            'model' => $this->model,
            'summary' => $this->summary,
            'error_detail' => $this->error_detail,
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'cost' => $this->costReport(),
            'usage' => [
                'images' => $generated->where('kind', 'image')->countBy('provider'),
                'videos' => $generated->where('kind', 'video')->countBy('provider'),
                'video_seconds' => $generated->where('kind', 'video')->sum('duration'),
            ],
            'assets' => $generated->map(fn ($a) => collect($a)->except('path')->all() + ['url' => MediaStore::url($a['path'])])->values(),
            'steps' => $this->steps,
            'started_at' => $this->started_at,
        ];
    }

    /**
     * Real API cost vs what the credits earned (at the cheapest paid plan's credit value).
     */
    private function costReport(): array
    {
        $meter = app(CostMeter::class);
        $media = round(array_sum(array_column($this->assets, 'cost')), 4);
        $revenue = $this->credits * config('pricing.credit_mnt');
        $cost = $meter->mnt($this->cost_usd);

        return [
            'usd' => round($this->cost_usd, 4),
            'mnt' => $cost,
            'claude_usd' => round($this->cost_usd - $media, 4),
            'media_usd' => $media,
            'budget_usd' => app(Credits::class)->mediaBudget($this->resource),
            'credits' => $this->credits,
            'revenue_mnt' => $revenue,
            'margin' => $cost > 0 && $revenue > 0 ? round($revenue / $cost, 1) : null, // × cost
            'cache_read_tokens' => $this->cache_read_tokens,
            'cache_write_tokens' => $this->cache_write_tokens,
        ];
    }
}
