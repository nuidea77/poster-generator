<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Багц худалдан авалт: QPay нэхэмжлэх үүсгэх, төлөлтийг шалгах, захиалгыг идэвхжүүлэх.
 */
class BillingService
{
    // QPay API клиент.
    public function __construct(private QPayClient $qpay) {}

    /**
     * Хүлээгдэж буй төлбөр болон түүний QPay нэхэмжлэхийг үүсгэнэ. Давхар
     * дарахад давхардал үүсэхгүйн тулд ижил багцын хугацаа нь дуусаагүй хүлээгдэж буй нэхэмжлэхийг дахин ашиглана.
     */
    public function startCheckout(User $user, Plan $plan): Payment
    {
        $existing = $user->payments()
            ->where('plan_id', $plan->id)
            ->where('status', Payment::PENDING)
            ->where('amount', $plan->price)
            ->where('created_at', '>', now()->subHours(config('qpay.invoice_ttl_hours') - 1))
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'sender_invoice_no' => 'PS'.now()->format('ymd').strtoupper(Str::random(10)),
            'callback_token' => Str::random(48),
            'amount' => $plan->price,
            'status' => Payment::PENDING,
        ]);

        // Зөвхөн локал орчинд: QPay руу хандахгүй, хуурамч нэхэмжлэх үүсгэнэ.
        if (config('qpay.fake')) {
            $payment->update([
                'invoice_id' => 'FAKE-'.$payment->sender_invoice_no,
                'qr_text' => 'FAKE-QR',
                'urls' => [],
            ]);

            return $payment;
        }

        $invoice = $this->qpay->createInvoice(
            $payment->sender_invoice_no,
            $plan->price,
            "Poster Studio — {$plan->name}",
            (string) $user->id,
            $this->callbackUrl($payment),
        );

        $payment->update([
            'invoice_id' => $invoice['invoice_id'],
            'qr_text' => $invoice['qr_text'],
            'qr_image' => $invoice['qr_image'],
            'short_url' => $invoice['short_url'],
            'urls' => $invoice['urls'],
            'raw' => $invoice['raw'],
        ]);

        return $payment;
    }

    /**
     * Нэхэмжлэх төлөгдсөн эсэхийг QPay-ээс асууж, төлөгдсөн бол захиалгыг идэвхжүүлнэ.
     * Давтан болон зэрэг дуудахад аюулгүй.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        // Нэхэмжлэхийн хүчинтэй хугацаа өнгөрсөн бол хугацаа дууссан гэж тэмдэглэнэ.
        if ($payment->created_at->lt(now()->subHours(config('qpay.invoice_ttl_hours')))) {
            $payment->update(['status' => Payment::EXPIRED]);

            return $payment;
        }

        // QPay руу хэт олон удаа хандахгүйн тулд саяхан шалгасан бол алгасна.
        if (! $force && $payment->checked_at && $payment->checked_at->gt(now()->subSeconds(config('qpay.check_interval')))) {
            return $payment;
        }

        $payment->update(['checked_at' => now()]);

        if (config('qpay.fake')) {
            return $payment;
        }

        // Callback-д итгэхгүй, төлсөн дүнг заавал payment/check-ээр баталгаажуулна.
        $result = $this->qpay->paidAmount($payment->invoice_id);

        if ($result['paid'] >= $payment->amount) {
            $this->markPaid($payment, ['check' => $result['rows']]);
        }

        return $payment->refresh();
    }

    /**
     * Төлсөн гэж тэмдэглэж, хэрэглэгчийн захиалгыг яг нэг удаа сунгана.
     */
    public function markPaid(Payment $payment, array $raw = []): Subscription
    {
        return DB::transaction(function () use ($payment, $raw) {
            // Төлбөрийн мөрийг түгжиж, зэрэг дуудалтаас давхар захиалга үүсэхээс сэргийлнэ.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Энэ төлбөрөөр захиалга аль хэдийн үүссэн бол түүнийг буцаана (idempotent).
            if ($existing = Subscription::where('payment_id', $payment->id)->orderBy('installment')->first()) {
                return $existing;
            }

            // Идэвхтэй төлбөртэй захиалга байвал түүний дуусах өдрөөс, үгүй бол одооноос эхлүүлнэ.
            $plan = $payment->plan;
            $currentEnd = Subscription::where('user_id', $payment->user_id)->paid()->max('ends_at');
            // Нэмэлт кредит (pack) захиалгыг сунгахгүй: төлсөн өдрөөс period_days хоног хүчинтэй.
            $startsAt = ! $plan->isPack() && $currentEnd && now()->lt($currentEnd) ? Carbon::parse($currentEnd) : now();

            $payment->update([
                'status' => Payment::PAID,
                'paid_at' => now(),
                'raw' => array_merge($payment->raw ?? [], $raw),
            ]);

            // Сарын багц: нэг үе. Жилийн багц: 12 сарын үе, кредит нь сар бүр тэнцүү хуваагдаж орно
            // (үлдэгдэл нь эхний сард); сар бүрийн ашиглаагүй кредит тэр сарын эцэст дуусна.
            $months = ! $plan->isPack() && $plan->period_days >= 360 ? 12 : 1;
            $each = intdiv($plan->credits, $months);
            $first = null;
            for ($i = 0; $i < $months; $i++) {
                $from = $months === 1 ? $startsAt->copy() : $startsAt->copy()->addMonthsNoOverflow($i);
                $subscription = Subscription::create([
                    'user_id' => $payment->user_id,
                    'plan_id' => $plan->id,
                    'payment_id' => $payment->id,
                    'installment' => $i,
                    'starts_at' => $from,
                    'ends_at' => $months === 1 ? $from->copy()->addDays($plan->period_days) : $startsAt->copy()->addMonthsNoOverflow($i + 1),
                    'credits' => $each + ($i === 0 ? $plan->credits - $each * $months : 0),
                ]);
                $first ??= $subscription;
            }

            return $first;
        });
    }

    /**
     * Төлбөрийн нууц token агуулсан QPay callback URL.
     */
    public function callbackUrl(Payment $payment): string
    {
        $base = rtrim(config('qpay.callback_base') ?: config('app.url'), '/');

        return $base.'/api/v1/payments/qpay/callback/'.$payment->callback_token;
    }
}
