<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['user_id', 'plan_id', 'payment_id', 'starts_at', 'ends_at', 'credits', 'credits_used'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'credits' => 'integer',
            'credits_used' => 'integer',
        ];
    }

    public function remaining(): int
    {
        return max(0, $this->credits - $this->credits_used);
    }

    /** Subscriptions bought with money (not the free-tier grant). */
    public function scopePaid($query)
    {
        return $query->whereHas('plan', fn ($q) => $q->where('price', '>', 0));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
