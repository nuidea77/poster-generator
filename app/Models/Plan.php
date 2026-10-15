<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Захиалгын багц: үнэ (₮), хугацаа (өдөр), олгох credit.
class Plan extends Model
{
    protected $fillable = ['slug', 'kind', 'name', 'price', 'period_days', 'credits', 'features', 'is_active', 'sort'];

    // Баганын төрөл хувиргалт.
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
     * Үнэгүй түвшин: үнэ нь 0 идэвхтэй багц. Түүний credit-ийг бүртгэл бүрт
     * нэг удаа олгох ба хугацаа нь дуусахгүй.
     */
    public const SUBSCRIPTION = 'subscription';

    public const PACK = 'pack';

    // Нэг удаагийн нэмэлт кредит эсэх (сар/жилийн багц биш).
    public function isPack(): bool
    {
        return $this->kind === self::PACK;
    }

    public static function free(): ?self
    {
        return static::where('is_active', true)->where('kind', self::SUBSCRIPTION)->where('price', 0)->orderBy('sort')->first();
    }

    // Энэ багц үнэгүй эсэх.
    public function isFree(): bool
    {
        return $this->price === 0;
    }
}
