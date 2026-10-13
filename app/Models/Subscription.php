<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Захиалгын нэг үе: хугацаа болон түүнд хамаарах credit-ийн үлдэгдэл.
class Subscription extends Model
{
    protected $fillable = ['user_id', 'plan_id', 'payment_id', 'starts_at', 'ends_at', 'credits', 'credits_used'];

    // Баганын төрөл хувиргалт.
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'credits' => 'integer',
            'credits_used' => 'integer',
        ];
    }

    // Үлдсэн credit (сөрөг болохгүй).
    public function remaining(): int
    {
        return max(0, $this->credits - $this->credits_used);
    }

    /** Мөнгөөр худалдан авсан захиалгууд (үнэгүй түвшний олголт биш). */
    public function scopePaid($query)
    {
        return $query->whereHas('plan', fn ($q) => $q->where('price', '>', 0));
    }

    // Энэ захиалгыг үүсгэсэн төлбөр (үнэгүй олголтод байхгүй).
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // Захиалгын багц.
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    // Захиалгын эзэн.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
