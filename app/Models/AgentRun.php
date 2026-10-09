<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $fillable = [
        'prompt', 'language', 'status', 'assets', 'steps', 'outputs',
        'summary', 'error', 'model', 'input_tokens', 'output_tokens',
    ];

    protected $attributes = [
        'assets' => '[]',
        'steps' => '[]',
        'outputs' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'assets' => 'array',
            'steps' => 'array',
            'outputs' => 'array',
        ];
    }

    public function addStep(array $step): void
    {
        $steps = $this->steps;
        $steps[] = $step + ['at' => now()->toIso8601String()];
        $this->steps = $steps;
        $this->save();
    }

    public function addAsset(array $asset): string
    {
        $assets = $this->assets;
        // Uploads are named by the caller (img_u1…); generated assets count up on their own.
        $generated = count(array_filter($assets, fn ($a) => ($a['source'] ?? null) !== 'upload'));
        $id = $asset['id'] ?? ($asset['kind'] === 'video' ? 'vid' : 'img').'_'.($generated + 1);
        $assets[] = ['id' => $id] + $asset;
        $this->assets = $assets;
        $this->save();

        return $id;
    }

    public function asset(string $id): ?array
    {
        return collect($this->assets)->firstWhere('id', $id);
    }
}
