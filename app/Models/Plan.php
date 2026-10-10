<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['slug', 'name', 'price', 'period_days', 'credits', 'features', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'period_days' => 'integer',
            'credits' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The free tier: the active plan priced 0. Its credits are granted once
     * per account and do not expire.
     */
    public static function free(): ?self
    {
        return static::where('is_active', true)->where('price', 0)->orderBy('sort')->first();
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }
}
