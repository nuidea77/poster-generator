<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// QPay-ийн нэг нэхэмжлэл/төлбөр ба түүний төлөв.
class Payment extends Model
{
    // Төлбөрийн төлөвүүд.
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'plan_id', 'provider', 'sender_invoice_no', 'invoice_id', 'callback_token',
        'amount', 'status', 'qr_image', 'qr_text', 'short_url', 'urls', 'paid_at', 'checked_at', 'raw',
    ];

    // Callback token болон QPay-ийн түүхий хариуг гадагш гаргахгүй.
    protected $hidden = ['callback_token', 'raw'];

    // Баганын төрөл хувиргалт.
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

    // Төлбөр хийсэн хэрэглэгч.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Худалдан авч буй багц.
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    // Төлбөр хүлээгдэж байгаа эсэх.
    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
