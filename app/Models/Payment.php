<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'plan_id', 'provider', 'sender_invoice_no', 'invoice_id', 'callback_token',
        'amount', 'status', 'qr_image', 'qr_text', 'short_url', 'urls', 'paid_at', 'checked_at', 'raw',
    ];

    protected $hidden = ['callback_token', 'raw'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'urls' => 'array',
            'raw' => 'array',
            'paid_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
