<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['slug', 'name', 'price', 'period_days', 'poster_limit', 'reel_limit', 'features', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'period_days' => 'integer',
            'poster_limit' => 'integer',
            'reel_limit' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The free tier: the active plan priced 0. Every user without a paid
     * subscription is on it; its limits are for the lifetime of the account.
     */
    public static function free(): ?self
    {
        return static::where('is_active', true)->where('price', 0)->orderBy('sort')->first();
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    /** null = unlimited */
    public function limit(string $type): ?int
    {
        return $type === Creation::REEL ? $this->reel_limit : $this->poster_limit;
    }
}
