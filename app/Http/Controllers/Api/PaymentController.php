<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Services\Billing\Exceptions\PaymentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private BillingService $billing) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        try {
            $payment = $this->billing->startCheckout($request->user(), Plan::findOrFail($data['plan_id']));
        } catch (PaymentException $e) {
            Log::error($e->getMessage());

            return response()->json(['message' => 'Төлбөрийн систем түр ажиллахгүй байна. Дахин оролдоно уу.'], 502);
        }

        return (new PaymentResource($payment->load('plan')))->response()->setStatusCode(201);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        try {
            $payment = $this->billing->refresh($payment);
        } catch (PaymentException $e) {
            Log::warning($e->getMessage());
        }

        return new PaymentResource($payment->load('plan'));
    }

    /**
     * QPay calls this after a payment. The token identifies our payment; the
     * real status always comes from payment/check, never from the request.
     */
    public function callback(string $token): JsonResponse
    {
        $payment = Payment::where('callback_token', $token)->first();

        if ($payment) {
            try {
                $this->billing->refresh($payment, force: true);
            } catch (PaymentException $e) {
                Log::error('QPay callback: '.$e->getMessage(), ['payment' => $payment->id]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Local development only (QPAY_FAKE=true): simulate a successful payment.
     */
    public function simulate(Request $request, Payment $payment): PaymentResource
    {
        abort_unless(config('qpay.fake') && ! app()->isProduction(), 404);
        abort_unless($payment->user_id === $request->user()->id, 404);

        if ($payment->isPending()) {
            $this->billing->markPaid($payment, ['simulated' => true]);
        }

        return new PaymentResource($payment->refresh()->load('plan'));
    }
}
