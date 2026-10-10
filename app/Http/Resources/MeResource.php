<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\Billing\Credits;
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
            'credits' => $this->is_admin ? null : app(Credits::class)->balance($this->resource), // null = unlimited
            'plan' => $subscription ? ['name' => $subscription->plan->name, 'free' => false] : (($free = Plan::free()) ? ['name' => $free->name, 'free' => true] : null),
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'ends_at' => $this->subscriptions()->max('ends_at'),
            ] : null,
        ];
    }
}
