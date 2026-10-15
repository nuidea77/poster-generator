<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\Billing\Credits;
use App\Services\Media\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Одоогийн хэрэглэгчийн профайл, брэнд, credit, багцын мэдээлэл.
class MeResource extends JsonResource
{
    // Идэвхтэй захиалга байхгүй бол үнэгүй багцыг харуулна.
    public function toArray(Request $request): array
    {
        $subscription = $this->activeSubscription();

        return [
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => (bool) $this->is_admin,
            'brand_name' => $this->brand_name,
            'brand_colors' => $this->brand_colors ?? [],
            'brand_fonts' => $this->brand_fonts,
            'brand_tone' => $this->brand_tone,
            'brand_notes' => $this->brand_notes,
            'brand_logo_url' => $logo = $this->brand_logo_path ? MediaStore::url($this->brand_logo_path) : null,
            'logo_url' => $logo, // хуучин клиентэд (Create.vue) зориулсан ижил утга
            'subscribed' => $this->isSubscribed(),
            'credits' => $this->is_admin ? null : app(Credits::class)->balance($this->resource), // null = хязгааргүй
            'plan' => $subscription ? ['name' => $subscription->plan->name, 'free' => false] : (($free = Plan::free()) ? ['name' => $free->name, 'free' => true] : null),
            'subscription' => $subscription ? [
                'plan' => $subscription->plan->name,
                'ends_at' => $this->subscriptions()->max('ends_at'),
            ] : null,
        ];
    }
}
