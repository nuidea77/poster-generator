<?php

namespace App\Services\Billing;

use App\Services\Billing\Exceptions\PaymentException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * QPay merchant API v2.
 *
 * POST /v2/auth/token      Basic client_id:client_secret → access_token (expires_in is an epoch timestamp)
 * POST /v2/invoice         simple invoice → invoice_id, qr_text, qr_image, qPay_shortUrl, urls[]
 * POST /v2/payment/check   {object_type: INVOICE, object_id, offset} → count, paid_amount, rows[]
 */
class QPayClient
{
    private const TOKEN_CACHE_KEY = 'qpay.access_token';

    /**
     * @return array{invoice_id: string, qr_text: string, qr_image: string, short_url: ?string, urls: array, raw: array}
     */
    public function createInvoice(string $senderInvoiceNo, int $amount, string $description, string $receiverCode, string $callbackUrl): array
    {
        $response = $this->api()->post('/v2/invoice', [
            'invoice_code' => config('qpay.invoice_code'),
            'sender_invoice_no' => $senderInvoiceNo,
            'invoice_receiver_code' => $receiverCode,
            'invoice_description' => mb_substr($description, 0, 255),
            'amount' => $amount,
            'callback_url' => $callbackUrl,
        ]);

        if ($response->failed() || ! $response->json('invoice_id')) {
            throw new PaymentException('QPay invoice: '.($response->json('message') ?? $response->body()));
        }

        return [
            'invoice_id' => (string) $response->json('invoice_id'),
            'qr_text' => (string) $response->json('qr_text'),
            'qr_image' => (string) $response->json('qr_image'),
            'short_url' => $response->json('qPay_shortUrl'),
            'urls' => collect($response->json('urls', []))
                ->map(fn ($u) => ['name' => $u['name'] ?? '', 'description' => $u['description'] ?? '', 'logo' => $u['logo'] ?? '', 'link' => $u['link'] ?? ''])
                ->values()
                ->all(),
            'raw' => $response->json(),
        ];
    }

    /**
     * Total PAID amount for an invoice (0 when nothing is paid yet).
     *
     * @return array{paid: int, rows: array}
     */
    public function paidAmount(string $invoiceId): array
    {
        $response = $this->api()->post('/v2/payment/check', [
            'object_type' => 'INVOICE',
            'object_id' => $invoiceId,
            'offset' => ['page_number' => 1, 'page_limit' => 100],
        ]);

        if ($response->failed()) {
            throw new PaymentException('QPay check: '.($response->json('message') ?? $response->body()));
        }

        $rows = $response->json('rows', []);
        $paid = collect($rows)->where('payment_status', 'PAID')->sum(fn ($r) => (float) ($r['payment_amount'] ?? 0));

        return ['paid' => (int) round($paid), 'rows' => $rows];
    }

    private function api(): PendingRequest
    {
        return Http::timeout(20)
            ->baseUrl(rtrim(config('qpay.base_url'), '/'))
            ->withToken($this->token())
            ->acceptJson();
    }

    private function token(): string
    {
        if ($token = Cache::get(self::TOKEN_CACHE_KEY)) {
            return $token;
        }

        $response = Http::timeout(20)
            ->withBasicAuth((string) config('qpay.client_id'), (string) config('qpay.client_secret'))
            ->post(rtrim(config('qpay.base_url'), '/').'/v2/auth/token');

        if ($response->failed() || ! $response->json('access_token')) {
            throw new PaymentException('QPay auth failed: '.$response->status());
        }

        // expires_in is a Unix timestamp in QPay v2, not a duration.
        $expiresAt = (int) $response->json('expires_in');
        $ttl = $expiresAt > time() ? $expiresAt - time() - 60 : 3000;

        Cache::put(self::TOKEN_CACHE_KEY, $response->json('access_token'), max(60, $ttl));

        return $response->json('access_token');
    }
}
