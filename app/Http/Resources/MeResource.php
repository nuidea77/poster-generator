<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->activeSubscription();
        $poster = $this->allowance('poster');
        $reel = $this->allowance('reel');

        return [
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => (bool) $this->is_admin,
            'brand_name' => $this->brand_name,
            'logo_url' => $this->logo_path ? '/storage/'.$this->logo_path : null,
            'subscribed' => $this->isSubscribed(),
            'plan' => $poster['plan'] ? ['name' => $poster['plan']->name, 'free' => $poster['plan']->isFree()] : null,
            // limit/remaining null = unlimited
            'allowance' => [
                'poster' => ['used' => $poster['used'], 'limit' => $poster['limit'], 'remaining' => $poster['remaining']],
                'reel' => ['used' => $reel['used'], 'limit' => $reel['limit'], 'remaining' => $reel['remaining']],
            ],
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'ends_at' => $this->subscriptions()->max('ends_at'),
            ] : null,
        ];
    }
}
