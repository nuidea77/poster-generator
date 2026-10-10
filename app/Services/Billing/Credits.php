<?php

namespace App\Services\Billing;

use App\Models\Creation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Credit wallet. Credits live on subscription periods (paid plans) and on a
 * one-off free-tier grant. Spending draws from the period that expires
 * first; unused credits expire with their period.
 */
class Credits
{
    /** Never expires. */
    private const FREE_YEARS = 100;

    public function price(string $type, int $formats = 1): int
    {
        $c = config('pricing.credits');

        return $type === Creation::REEL
            ? $c['reel']
            : $c['poster'] + max(0, $formats - 1) * $c['poster_extra_format'];
    }

    /**
     * Hard cap on image + video spend (USD) for a job.
     */
    public function mediaBudget(Creation $creation): float
    {
        $b = config('pricing.media_budget');

        return $creation->type === Creation::REEL
            ? $b['reel']
            : $b['poster'] + max(0, count($creation->formats ?? []) - 1) * $b['poster_extra_format'];
    }

    public function balance(User $user): int
    {
        $this->grantFree($user);

        return (int) $this->wallet($user)->sum(fn (Subscription $s) => $s->remaining());
    }

    /**
     * Give a new account the free tier's credits, once.
     */
    public function grantFree(User $user): void
    {
        $free = Plan::free();

        if (! $free || $user->subscriptions()->where('plan_id', $free->id)->exists()) {
            return;
        }

        $user->subscriptions()->create([
            'plan_id' => $free->id,
            'starts_at' => now(),
            'ends_at' => now()->addYears(self::FREE_YEARS),
            'credits' => $free->credits,
        ]);
    }

    /**
     * Take the job's price from the wallet, or refuse with 402.
     */
    public function charge(User $user, Creation $creation, int $credits): void
    {
        if ($user->is_admin || $credits === 0) {
            return;
        }

        $this->grantFree($user);

        DB::transaction(function () use ($user, $creation, $credits) {
            $wallet = $this->wallet($user, lock: true);

            if ($wallet->sum(fn (Subscription $s) => $s->remaining()) < $credits) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Кредит хүрэлцэхгүй байна. Багц авах эсвэл сунгана уу.',
                    'code' => 'subscription_required',
                    'needed' => $credits,
                ], 402));
            }

            $charges = [];
            $left = $credits;

            foreach ($wallet as $subscription) {
                $take = min($left, $subscription->remaining());

                if ($take > 0) {
                    $subscription->increment('credits_used', $take);
                    $charges[] = ['subscription_id' => $subscription->id, 'credits' => $take];
                    $left -= $take;
                }

                if ($left === 0) {
                    break;
                }
            }

            $creation->update(['credits' => $credits, 'charges' => $charges]);
        });
    }

    /**
     * Give a failed job's credits back to the periods they came from. Idempotent.
     */
    public function refund(Creation $creation): void
    {
        DB::transaction(function () use ($creation) {
            $creation = Creation::withTrashed()->whereKey($creation->id)->lockForUpdate()->first();

            foreach ($creation->charges ?? [] as $charge) {
                Subscription::whereKey($charge['subscription_id'])
                    ->where('credits_used', '>=', $charge['credits'])
                    ->decrement('credits_used', $charge['credits']);
            }

            $creation->update(['credits' => 0, 'charges' => []]);
        });
    }

    /**
     * Periods with credits that can be spent now, soonest-expiring first.
     * Includes renewals that start later, so credits are usable right after paying.
     */
    private function wallet(User $user, bool $lock = false)
    {
        $query = $user->subscriptions()
            ->where('ends_at', '>', now())
            ->whereColumn('credits_used', '<', 'credits')
            ->orderBy('ends_at');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }
}
