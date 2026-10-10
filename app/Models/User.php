<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'brand_name', 'logo_path'])]
#[Hidden(['password', 'remember_token', 'is_admin'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creations(): HasMany
    {
        return $this->hasMany(Creation::class);
    }

    /**
     * The subscription period covering "now" (or the latest future one).
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->with('plan')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    public function isSubscribed(): bool
    {
        return $this->is_admin || $this->subscriptions()->where('ends_at', '>', now())->exists();
    }

    /**
     * What the user may still create of each type under their current plan:
     * the paid subscription covering now, otherwise the free tier.
     *
     * @return array{plan: ?Plan, subscription_id: ?int, used: int, limit: ?int, remaining: ?int}
     */
    public function allowance(string $type): array
    {
        $subscription = $this->subscriptions()->with('plan')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->orderByDesc('ends_at')->first();
        $plan = $subscription?->plan ?? Plan::free();

        if ($this->is_admin || ! $plan) {
            return ['plan' => $plan, 'subscription_id' => $subscription?->id, 'used' => 0, 'limit' => $this->is_admin ? null : 0, 'remaining' => $this->is_admin ? null : 0];
        }

        $limit = $plan->limit($type);
        $used = $limit === null ? 0 : $this->creations()->withTrashed()
            ->where('type', $type)
            ->where('status', '!=', Creation::FAILED) // failed runs give the credit back
            ->where('subscription_id', $subscription?->id)
            ->count();

        return [
            'plan' => $plan,
            'subscription_id' => $subscription?->id,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }
}
