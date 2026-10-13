<?php

namespace App\Services\Billing;

use App\Models\Creation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Credit хэтэвч. Credit нь захиалгын хугацаанууд (төлбөртэй багц) болон
 * нэг удаагийн үнэгүй олголт дээр хадгалагдана. Зарцуулахдаа хамгийн түрүүнд
 * дуусах хугацаанаас авна; ашиглаагүй credit хугацаатайгаа хамт дуусна.
 */
class Credits
{
    /** Үнэгүй олголт бараг хэзээ ч дуусахгүй. */
    private const FREE_YEARS = 100;

    // Үнэ, төсвийг CostMeter-ээс тооцоолно.
    public function __construct(private CostMeter $meter) {}

    /**
     * Ажлын credit үнэ.
     */
    public function price(Creation $creation): int
    {
        return $this->meter->listCredits($creation);
    }

    /**
     * Нэг ажлын зураг + видеоны зардлын (USD) хатуу дээд хязгаар.
     */
    public function mediaBudget(Creation $creation): float
    {
        // Эхний форматаас бусад нэмэлт формат бүрт нэмэлт төсөв өгнө.
        $extra = max(0, count($creation->formats ?? []) - 1);

        return $creation->type === Creation::REEL
            ? $this->meter->budget('reel', $creation->reelDuration())
            : $this->meter->budget('poster') + $extra * $this->meter->budget('poster_extra_format');
    }

    /**
     * Хэрэглэгчийн одоо зарцуулж болох нийт credit.
     */
    public function balance(User $user): int
    {
        $this->grantFree($user);

        return (int) $this->wallet($user)->sum(fn (Subscription $s) => $s->remaining());
    }

    /**
     * Шинэ бүртгэлд үнэгүй багцын credit-ийг нэг удаа олгоно.
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
     * Ажлын үнийг хэтэвчнээс хасна, хүрэлцэхгүй бол 402-оор татгалзана.
     */
    public function charge(User $user, Creation $creation, int $credits): void
    {
        // Админ болон үнэгүй ажлаас credit хасахгүй.
        if ($user->is_admin || $credits === 0) {
            return;
        }

        $this->grantFree($user);

        // Мөрүүдийг түгжиж, зэрэг хүсэлтүүд нэг credit-ийг давхар зарцуулахаас сэргийлнэ.
        DB::transaction(function () use ($user, $creation, $credits) {
            $wallet = $this->wallet($user, lock: true);

            if ($wallet->sum(fn (Subscription $s) => $s->remaining()) < $credits) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Кредит хүрэлцэхгүй байна. Багц авах эсвэл сунгана уу.',
                    'code' => 'subscription_required',
                    'needed' => $credits,
                ], 402));
            }

            // Хамгийн түрүүнд дуусах хугацаанаас эхлэн хасаж, буцаан олголтод зориулж хаанаас хэд авснаа тэмдэглэнэ.
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
     * Амжилтгүй болсон ажлын credit-ийг авсан хугацаануудад нь буцаана. Давтан дуудахад аюулгүй.
     */
    public function refund(Creation $creation): void
    {
        DB::transaction(function () use ($creation) {
            $creation = Creation::withTrashed()->whereKey($creation->id)->lockForUpdate()->first();

            // credits_used сөрөг болохоос хамгаалж нөхцөлтэйгээр хасна.
            foreach ($creation->charges ?? [] as $charge) {
                Subscription::whereKey($charge['subscription_id'])
                    ->where('credits_used', '>=', $charge['credits'])
                    ->decrement('credits_used', $charge['credits']);
            }

            $creation->update(['credits' => 0, 'charges' => []]);
        });
    }

    /**
     * Одоо зарцуулж болох credit-тэй хугацаанууд, хамгийн түрүүнд дуусахаас нь эхлэн.
     * Хожим эхлэх сунгалтуудыг ч оруулдаг тул төлсний дараа credit шууд ашиглагдана.
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
