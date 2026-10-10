<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Creation extends Model
{
    use HasUlids;

    public const POSTER = 'poster';

    public const REEL = 'reel';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const ASSEMBLING = 'assembling';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const ACTIVE = [self::QUEUED, self::RUNNING, self::ASSEMBLING];

    protected $fillable = [
        'user_id', 'type', 'formats', 'prompt', 'product', 'status', 'stage', 'progress',
        'inputs', 'assets', 'steps', 'outputs', 'reel_clips', 'summary', 'error_detail',
        'model', 'input_tokens', 'output_tokens', 'started_at', 'finished_at',
    ];

    protected $attributes = [
        'inputs' => '[]',
        'assets' => '[]',
        'steps' => '[]',
        'outputs' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'formats' => 'array',
            'product' => 'array',
            'inputs' => 'array',
            'assets' => 'array',
            'steps' => 'array',
            'outputs' => 'array',
            'reel_clips' => 'array',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** ULID column used in URLs; the integer id never leaves the server. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function addStep(array $step): void
    {
        $this->steps = [...$this->steps, $step + ['at' => now()->toIso8601String()]];
        $this->save();
    }

    /**
     * Register a generated/uploaded media file. Returns its short id (img_1, vid_3…).
     */
    public function addAsset(array $asset): string
    {
        $assets = $this->assets;
        $prefix = $asset['kind'] === 'video' ? 'vid' : 'img';
        $id = $asset['id'] ?? $prefix.'_'.(count(array_filter($assets, fn ($a) => ($a['source'] ?? null) === 'generated')) + 1);
        $assets[] = ['id' => $id] + $asset;
        $this->assets = $assets;
        $this->save();

        return $id;
    }

    public function asset(string $id): ?array
    {
        return collect($this->assets)->firstWhere('id', $id)
            ?? collect($this->inputs)->firstWhere('id', $id);
    }

    public function addOutput(array $output): void
    {
        $this->outputs = [...$this->outputs, $output];
        $this->save();
    }

    public function setStage(string $stage, int $progress): void
    {
        $this->update(['stage' => $stage, 'progress' => max($this->progress, min(99, $progress))]);
    }
}
