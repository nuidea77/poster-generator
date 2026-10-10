<?php

namespace App\Http\Resources;

use App\Models\Creation;
use App\Services\Media\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the customer sees. Deliberately excludes providers, prompts sent to
 * models, agent steps and technical errors.
 */
class CreationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'formats' => $this->formats ?? [],
            'prompt' => $this->prompt,
            'product' => $this->product,
            'status' => $this->status,
            'stage' => $this->status === Creation::FAILED ? 'failed' : $this->stage,
            'progress' => $this->status === Creation::DONE ? 100 : $this->progress,
            'inputs' => collect($this->inputs)->map(fn ($i) => ['role' => $i['role'], 'url' => MediaStore::url($i['path'])])->values(),
            'outputs' => collect($this->outputs)->map(fn ($o) => collect($o)->only(['kind', 'format', 'label', 'width', 'height', 'duration', 'url', 'thumb_url'])->all())->values(),
            'error' => $this->status === Creation::FAILED ? 'Бүтээх явцад алдаа гарлаа. Дахин оролдоно уу.' : null,
            'created_at' => $this->created_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
