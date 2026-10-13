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

// QPay төлбөр: эхлүүлэх, төлөв шалгах, callback, локал симуляци.
class PaymentController extends Controller
{
    // Төлбөрийн логикийг BillingService-д даатгана.
    public function __construct(private BillingService $billing) {}

    // Идэвхтэй, төлбөртэй багцад QPay нэхэмжлэл үүсгэнэ.
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)->where(fn ($q) => $q->where('price', '>', 0))],
        ]);

        try {
            $payment = $this->billing->startCheckout($request->user(), Plan::findOrFail($data['plan_id']));
        } catch (PaymentException $e) {
            Log::error($e->getMessage());

            return response()->json(['message' => 'Төлбөрийн систем түр ажиллахгүй байна. Дахин оролдоно уу.'], 502);
        }

        return (new PaymentResource($payment->load('plan')))->response()->setStatusCode(201);
    }

    // Өөрийн төлбөрийн төлөвийг QPay-ээс шалгаж буцаана.
    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        // QPay алдаа гарвал хадгалсан төлөвөөр нь буцаана.
        try {
            $payment = $this->billing->refresh($payment);
        } catch (PaymentException $e) {
            Log::warning($e->getMessage());
        }

        return new PaymentResource($payment->load('plan'));
    }

    /**
     * Төлбөр хийгдсэний дараа QPay үүнийг дуудна. Token нь манай төлбөрийг таньдаг;
     * бодит төлөвийг үргэлж payment/check-ээс авна, хүсэлтээс хэзээ ч биш.
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
     * Зөвхөн локал хөгжүүлэлтэд (QPAY_FAKE=true): амжилттай төлбөрийг дуурайна.
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
