<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount' => $this->amount,
            'plan' => ['name' => $this->plan->name, 'period_days' => $this->plan->period_days],
            'qr_image' => $this->qr_image,
            'qr_text' => $this->when($this->isPending(), $this->qr_text),
            'short_url' => $this->short_url,
            'urls' => $this->urls ?? [],
            'paid_at' => $this->paid_at,
            'fake' => (bool) config('qpay.fake'),
        ];
    }
}
