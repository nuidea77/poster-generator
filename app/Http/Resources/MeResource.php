<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->activeSubscription();

        return [
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => (bool) $this->is_admin,
            'brand_name' => $this->brand_name,
            'logo_url' => $this->logo_path ? '/storage/'.$this->logo_path : null,
            'subscribed' => $this->isSubscribed(),
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'ends_at' => $this->subscriptions()->max('ends_at'),
            ] : null,
        ];
    }
}
