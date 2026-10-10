<?php

namespace App\Http\Resources;

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
}
