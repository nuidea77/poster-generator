<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config([
            'qpay.fake' => false,
            'qpay.base_url' => 'https://merchant-sandbox.qpay.mn',
            'qpay.client_id' => 'cid',
            'qpay.client_secret' => 'secret',
            'qpay.invoice_code' => 'TEST_INVOICE',
            'app.url' => 'https://poster.test',
        ]);
    }

    private function fakeQpay(array $checkRows = []): void
    {
        Http::fake([
            '*/v2/auth/token' => Http::response(['token_type' => 'bearer', 'access_token' => 'tok', 'expires_in' => time() + 3600, 'refresh_token' => 'r', 'refresh_expires_in' => time() + 7200]),
            '*/v2/invoice' => Http::response([
                'invoice_id' => 'inv-123',
                'qr_text' => '000201...',
                'qr_image' => base64_encode('PNG'),
                'qPay_shortUrl' => 'https://s.qpay.mn/x',
                'urls' => [['name' => 'Khan bank', 'description' => 'Хаан банк', 'logo' => 'https://l/khan.png', 'link' => 'khanbank://q?qPay_QRcode=000201']],
            ]),
            '*/v2/payment/check' => Http::response(['count' => count($checkRows), 'paid_amount' => array_sum(array_column($checkRows, 'payment_amount')), 'rows' => $checkRows]),
        ]);
    }

    public function test_meta_lists_plans_and_formats_without_models(): void
    {
        $this->getJson('/api/v1/meta')
            ->assertOk()
            ->assertJsonCount(3, 'plans')
            ->assertJsonCount(4, 'poster_formats')
            ->assertJsonPath('reel.max_seconds', 180)
            ->assertJsonMissingPath('providers');
    }

    public function test_checkout_creates_qpay_invoice_and_reuses_pending(): void
    {
        $this->fakeQpay();
        $user = User::factory()->create();
        $plan = Plan::where('slug', 'monthly')->first();

        $id = $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 49000)
            ->assertJsonPath('data.urls.0.name', 'Khan bank')
            ->json('data.id');

        $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => $plan->id])->assertJsonPath('data.id', $id);

        $payment = Payment::find($id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/auth/token') && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:secret')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/invoice')
            && $r['invoice_code'] === 'TEST_INVOICE'
            && $r['amount'] === 49000
            && $r['sender_invoice_no'] === $payment->sender_invoice_no
            && $r['callback_url'] === 'https://poster.test/api/v1/payments/qpay/callback/'.$payment->callback_token);
        Http::assertSentCount(2); // token cached, second checkout reused the invoice
    }

    public function test_callback_verifies_with_qpay_and_activates_once(): void
    {
        $this->fakeQpay([['payment_id' => 'p1', 'payment_status' => 'PAID', 'payment_amount' => 49000]]);
        $user = User::factory()->create();
        $payment = app(BillingService::class)->startCheckout($user, Plan::where('slug', 'monthly')->first());

        $this->postJson('/api/v1/payments/qpay/callback/'.$payment->callback_token)->assertOk();
        $this->getJson('/api/v1/payments/qpay/callback/'.$payment->callback_token)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(1, Subscription::count());
        $this->assertTrue($user->fresh()->isSubscribed());
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, Subscription::first()->ends_at->timestamp, 5);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/payment/check') && $r['object_type'] === 'INVOICE' && $r['object_id'] === 'inv-123');
    }

    public function test_underpaid_or_unknown_callback_does_not_activate(): void
    {
        $this->fakeQpay([['payment_id' => 'p1', 'payment_status' => 'PAID', 'payment_amount' => 1000]]);
        $user = User::factory()->create();
        $payment = app(BillingService::class)->startCheckout($user, Plan::where('slug', 'monthly')->first());

        $this->postJson('/api/v1/payments/qpay/callback/'.$payment->callback_token)->assertOk();
        $this->postJson('/api/v1/payments/qpay/callback/not-a-token')->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->isSubscribed());
    }

    public function test_renewal_extends_from_current_end(): void
    {
        $user = User::factory()->create();
        $plan = Plan::where('slug', 'monthly')->first();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(20)]);

        $payment = Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'sender_invoice_no' => 'X1', 'callback_token' => 't', 'amount' => $plan->price, 'status' => 'pending']);
        app(BillingService::class)->markPaid($payment);

        $this->assertEqualsWithDelta(now()->addDays(50)->timestamp, $user->subscriptions()->max('ends_at') ? strtotime($user->subscriptions()->max('ends_at')) : 0, 5);
    }

    public function test_client_polling_is_throttled_and_owner_only(): void
    {
        $this->fakeQpay();
        $user = User::factory()->create();
        $payment = app(BillingService::class)->startCheckout($user, Plan::first());

        $this->actingAs($user)->getJson("/api/v1/payments/{$payment->id}")->assertJsonPath('data.status', 'pending');
        $this->actingAs($user)->getJson("/api/v1/payments/{$payment->id}")->assertOk();
        Http::assertSentCount(3); // token + invoice + one check (second poll within interval)

        $this->actingAs(User::factory()->create())->getJson("/api/v1/payments/{$payment->id}")->assertNotFound();
    }

    public function test_fake_mode_can_simulate_payment_locally(): void
    {
        config(['qpay.fake' => true]);
        Http::fake();
        $user = User::factory()->create();

        $id = $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => Plan::first()->id])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/payments/{$id}/simulate")->assertJsonPath('data.status', 'paid');

        $this->assertTrue($user->fresh()->isSubscribed());
        Http::assertNothingSent();
    }
}
