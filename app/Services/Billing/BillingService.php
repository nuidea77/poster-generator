<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(private QPayClient $qpay) {}

    /**
     * Create a pending payment and its QPay invoice. Reuses an unexpired
     * pending invoice for the same plan so double-clicks don't create duplicates.
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
     * Ask QPay whether the invoice is paid and activate the subscription if so.
     * Safe to call repeatedly and concurrently.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        if ($payment->created_at->lt(now()->subHours(config('qpay.invoice_ttl_hours')))) {
            $payment->update(['status' => Payment::EXPIRED]);

            return $payment;
        }

        if (! $force && $payment->checked_at && $payment->checked_at->gt(now()->subSeconds(config('qpay.check_interval')))) {
            return $payment;
        }

        $payment->update(['checked_at' => now()]);

        if (config('qpay.fake')) {
            return $payment;
        }

        $result = $this->qpay->paidAmount($payment->invoice_id);

        if ($result['paid'] >= $payment->amount) {
            $this->markPaid($payment, ['check' => $result['rows']]);
        }

        return $payment->refresh();
    }

    /**
     * Mark paid and extend the user's subscription exactly once.
     */
    public function markPaid(Payment $payment, array $raw = []): Subscription
    {
        return DB::transaction(function () use ($payment, $raw) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($existing = Subscription::where('payment_id', $payment->id)->first()) {
                return $existing;
            }

            $plan = $payment->plan;
            $currentEnd = Subscription::where('user_id', $payment->user_id)->paid()->max('ends_at');
            $startsAt = $currentEnd && now()->lt($currentEnd) ? Carbon::parse($currentEnd) : now();

            $payment->update([
                'status' => Payment::PAID,
                'paid_at' => now(),
                'raw' => array_merge($payment->raw ?? [], $raw),
            ]);

            return Subscription::create([
                'user_id' => $payment->user_id,
                'plan_id' => $plan->id,
                'payment_id' => $payment->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addDays($plan->period_days),
                'credits' => $plan->credits,
            ]);
        });
    }

    public function callbackUrl(Payment $payment): string
    {
        $base = rtrim(config('qpay.callback_base') ?: config('app.url'), '/');

        return $base.'/api/v1/payments/qpay/callback/'.$payment->callback_token;
    }
}
