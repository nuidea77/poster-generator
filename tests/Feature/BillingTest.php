<?php

namespace Tests\Feature;

use App\Models\Creation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\CostMeter;
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
            ->assertJsonCount(5, 'plans')
            ->assertJsonCount(11, 'poster_formats')
            ->assertJsonCount(4, 'reel.durations')
            ->assertJsonPath('credit_prices.reel.30', 140)
            ->assertJsonMissingPath('providers');
    }

    public function test_credit_prices_follow_unit_costs_through_cost_meter(): void
    {
        $meter = app(CostMeter::class);
        $this->assertSame(['poster' => 12, 'poster_extra_format' => 3, 'reel' => [15 => 80, 30 => 140, 45 => 205, 60 => 265]], $meter->creditPrices());

        foreach ($meter->priceBook() as $row) {
            $this->assertGreaterThanOrEqual(2.0, $row['margin_worst'], $row['label']);
            $this->assertGreaterThanOrEqual(3.0, $row['margin_typical'], $row['label']);
        }

        // Media budget = worst-case media: 4 stills + 1.3 × length of the priciest video model.
        $this->assertEqualsWithDelta(4 * 0.25 + 30 * 1.3 * 0.40, $meter->budget('reel', 30), 0.0001);

        // A cheaper video model as the priciest one makes reels cheaper; posters do not change.
        config(['pricing.costs.video_second.veo' => 0.12]);
        $this->assertSame(12, $meter->credits('poster'));
        $this->assertLessThan(80, $meter->credits('reel', 15));
    }

    public function test_admin_cost_report_compares_price_book_with_real_costs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Creation::create(['user_id' => $admin->id, 'type' => 'reel', 'duration' => 30, 'credits' => 140, 'prompt' => 'x', 'status' => 'done', 'cost_usd' => 9.5,
            'assets' => [['id' => 'vid_1', 'kind' => 'video', 'cost' => 8.0]]]);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/costs')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/admin/costs')
            ->assertOk()
            ->assertJsonCount(6, 'price_book')
            ->assertJsonPath('price_book.3.label', 'Reels 30 сек')
            ->assertJsonPath('price_book.3.credits', 140)
            ->assertJsonPath('actuals.0.label', 'Reels 30 сек')
            ->assertJsonPath('actuals.0.avg_claude_usd', 1.5)
            ->assertJsonPath('actuals.0.avg_media_usd', 8);
    }

    public function test_checkout_creates_qpay_invoice_and_reuses_pending(): void
    {
        $this->fakeQpay();
        $user = User::factory()->create();
        $plan = Plan::where('slug', 'standard')->first();

        $id = $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 72000)
            ->assertJsonPath('data.urls.0.name', 'Khan bank')
            ->json('data.id');

        $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => $plan->id])->assertJsonPath('data.id', $id);

        $payment = Payment::find($id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/auth/token') && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:secret')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/invoice')
            && $r['invoice_code'] === 'TEST_INVOICE'
            && $r['amount'] === 72000
            && $r['sender_invoice_no'] === $payment->sender_invoice_no
            && $r['callback_url'] === 'https://poster.test/api/v1/payments/qpay/callback/'.$payment->callback_token);
        Http::assertSentCount(2); // token cached, second checkout reused the invoice
    }

    public function test_callback_verifies_with_qpay_and_activates_once(): void
    {
        $this->fakeQpay([['payment_id' => 'p1', 'payment_status' => 'PAID', 'payment_amount' => 72000]]);
        $user = User::factory()->create();
        $payment = app(BillingService::class)->startCheckout($user, Plan::where('slug', 'standard')->first());

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
        $payment = app(BillingService::class)->startCheckout($user, Plan::where('slug', 'standard')->first());

        $this->postJson('/api/v1/payments/qpay/callback/'.$payment->callback_token)->assertOk();
        $this->postJson('/api/v1/payments/qpay/callback/not-a-token')->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->isSubscribed());
    }

    public function test_renewal_extends_from_current_end(): void
    {
        $user = User::factory()->create();
        $plan = Plan::where('slug', 'standard')->first();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(20)]);

        $payment = Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'sender_invoice_no' => 'X1', 'callback_token' => 't', 'amount' => $plan->price, 'status' => 'pending']);
        app(BillingService::class)->markPaid($payment);

        $this->assertEqualsWithDelta(now()->addDays(50)->timestamp, $user->subscriptions()->max('ends_at') ? strtotime($user->subscriptions()->max('ends_at')) : 0, 5);
    }

    public function test_client_polling_is_throttled_and_owner_only(): void
    {
        $this->fakeQpay();
        $user = User::factory()->create();
        $payment = app(BillingService::class)->startCheckout($user, Plan::where('slug', 'standard')->first());

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

        $id = $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => Plan::where('slug', 'standard')->first()->id])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/payments/{$id}/simulate")->assertJsonPath('data.status', 'paid');

        $this->assertTrue($user->fresh()->isSubscribed());
        Http::assertNothingSent();
    }
}
