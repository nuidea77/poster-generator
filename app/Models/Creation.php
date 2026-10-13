<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Нэг бүтээл (poster эсвэл reel): оролт, agent-ийн алхам, asset, үр дүн, өртөг.
 */
class Creation extends Model
{
    use HasUlids, SoftDeletes;

    // Бүтээлийн төрлүүд.
    public const POSTER = 'poster';

    public const REEL = 'reel';

    // Бүтээлийн төлөвүүд.
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const ASSEMBLING = 'assembling';

    public const DONE = 'done';

    public const FAILED = 'failed';

    // Дуусаагүй (явж буй) төлөвүүд.
    public const ACTIVE = [self::QUEUED, self::RUNNING, self::ASSEMBLING];

    protected $fillable = [
        'user_id', 'type', 'credits', 'charges', 'formats', 'duration', 'prompt', 'product', 'status', 'stage', 'progress',
        'inputs', 'assets', 'steps', 'outputs', 'reel_clips', 'summary', 'error_detail',
        'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_usd', 'started_at', 'finished_at',
    ];

    // JSON жагсаалтууд хоосон массиваар эхэлнэ.
    protected $attributes = [
        'inputs' => '[]',
        'assets' => '[]',
        'steps' => '[]',
        'outputs' => '[]',
    ];

    // Баганын төрөл хувиргалт.
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
            'charges' => 'array',
            'credits' => 'integer',
            'cost_usd' => 'float',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** URL-д хэрэглэгдэх ULID багана; бүхэл тоон id серверээс гадагш гарахгүй. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    // Route binding-д public_id ашиглана.
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    // Бүтээлийн эзэн.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Бүтээл ажиллаж байгаа эсэх (дараалалд, ажиллаж буй, угсарч буй).
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * Reel-ийн чиглэл (`formats`-д хадгалагдана; хуучин reel-д байхгүй тул 9:16).
     *
     * @return array{aspect: string, label: string, hint: string, width: int, height: int}
     */
    public function reelFormat(): array
    {
        $formats = config('creations.reel.formats');
        $aspect = isset($formats[$this->formats[0] ?? '']) ? $this->formats[0] : array_key_first($formats);

        return ['aspect' => $aspect] + $formats[$aspect];
    }

    /**
     * Хэрэглэгчийн сонгосон reel-ийн урт, секундээр (хуучин reel: хамгийн богино).
     */
    public function reelDuration(): int
    {
        $durations = config('creations.reel.durations');

        return isset($durations[$this->duration]) ? (int) $this->duration : array_key_first($durations);
    }

    // Agent-ийн алхмыг цагийн тэмдэгтэй нь логд нэмж хадгална.
    public function addStep(array $step): void
    {
        $this->steps = [...$this->steps, $step + ['at' => now()->toIso8601String()]];
        $this->save();
    }

    /**
     * Үүсгэсэн/upload хийсэн медиа файлыг бүртгэнэ. Богино id-г нь буцаана (img_1, vid_3…).
     */
    public function addAsset(array $asset): string
    {
        $assets = $this->assets;
        // Id өгөгдөөгүй бол үүсгэсэн asset-уудын тоогоор дугаарлана.
        $prefix = $asset['kind'] === 'video' ? 'vid' : 'img';
        $id = $asset['id'] ?? $prefix.'_'.(count(array_filter($assets, fn ($a) => ($a['source'] ?? null) === 'generated')) + 1);
        $assets[] = ['id' => $id] + $asset;
        $this->assets = $assets;
        $this->save();

        return $id;
    }

    // Id-аар asset-ийг, олдохгүй бол хэрэглэгчийн оролтоос хайна.
    public function asset(string $id): ?array
    {
        return collect($this->assets)->firstWhere('id', $id)
            ?? collect($this->inputs)->firstWhere('id', $id);
    }

    // Бэлэн үр дүн (poster/reel) нэмж хадгална.
    public function addOutput(array $output): void
    {
        $this->outputs = [...$this->outputs, $output];
        $this->save();
    }

    // Үе шатыг шинэчилнэ; явц буурахгүй, дуусахаас өмнө 99-өөс хэтрэхгүй.
    public function setStage(string $stage, int $progress): void
    {
        $this->update(['stage' => $stage, 'progress' => max($this->progress, min(99, $progress))]);
    }
}
