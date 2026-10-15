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
            ->assertJsonCount(7, 'plans')
            ->assertJsonPath('plans.2.slug', 'business')
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

    public function test_loss_report_counts_failed_unpaid_and_below_cost_jobs(): void
    {
        config(['pricing.usd_mnt' => 3600]);
        $customer = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $standard = Plan::where('slug', 'standard')->first(); // 72,000₮ for 60 credits = 1,200₮ a credit
        $payment = Payment::create(['user_id' => $customer->id, 'plan_id' => $standard->id, 'amount' => 72000, 'status' => Payment::PAID, 'sender_invoice_no' => 'L1', 'callback_token' => 'tok-l1']);
        $paid = Subscription::create(['user_id' => $customer->id, 'plan_id' => $standard->id, 'payment_id' => $payment->id, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'credits' => 60]);
        $job = fn (array $a) => Creation::create($a + ['user_id' => $customer->id, 'type' => 'poster', 'prompt' => 'x', 'credits' => 0, 'charges' => []]);

        $job(['status' => 'done', 'credits' => 12, 'charges' => [['subscription_id' => $paid->id, 'credits' => 12]], 'cost_usd' => 1.0]); // earns 14,400₮, costs 3,600₮
        $job(['status' => 'done', 'credits' => 1, 'charges' => [['subscription_id' => $paid->id, 'credits' => 1]], 'cost_usd' => 1.0]); // earns 1,200₮, costs 3,600₮
        $job(['status' => 'failed', 'cost_usd' => 2.0, 'error_detail' => 'Veo: bad request', 'assets' => [['id' => 'img_1', 'kind' => 'image', 'cost' => 0.5]]]);
        $job(['status' => 'done', 'user_id' => $admin->id, 'cost_usd' => 0.5]); // admin: nobody paid
        $job(['status' => 'failed', 'cost_usd' => 0.4])->forceFill(['created_at' => now()->subDays(40)])->save();

        $l = app(CostMeter::class)->losses(30);

        $this->assertSame(15600, $l['revenue_mnt']);
        $this->assertSame(16200, $l['cost_mnt']);
        $this->assertSame(-600, $l['net_mnt']);
        $this->assertSame(['jobs' => 1, 'cost_mnt' => 7200, 'claude_usd' => 1.5, 'media_usd' => 0.5], collect($l['failed'])->only(['jobs', 'cost_mnt', 'claude_usd', 'media_usd'])->all());
        $this->assertSame('Veo: bad request', $l['failed']['reasons'][0]['reason']);
        $this->assertSame([['label' => 'Админ / тест', 'jobs' => 1, 'cost_mnt' => 1800, 'claude_usd' => 0.5, 'media_usd' => 0.0]], $l['unpaid']['by_who']);
        $this->assertSame(1, $l['underwater']['jobs']);
        $this->assertSame(2400, $l['underwater']['lost_mnt']);
        $this->assertSame(2, app(CostMeter::class)->losses()['failed']['jobs']); // all time includes the old one

        $this->actingAs($admin)->getJson('/api/v1/admin/costs?days=30')->assertOk()->assertJsonPath('losses.net_mnt', -600);

        // Overview: what was made and what it cost; a QPAY_FAKE invoice is not money.
        Payment::create(['user_id' => $admin->id, 'plan_id' => $standard->id, 'amount' => 999000, 'status' => Payment::PAID, 'invoice_id' => 'FAKE-X', 'paid_at' => now(), 'sender_invoice_no' => 'L2', 'callback_token' => 'tok-l2']);
        $payment->forceFill(['paid_at' => now()])->save();
        $sum = app(CostMeter::class)->summary(30);
        $this->assertSame(['done' => 3, 'failed' => 1], collect($sum['posters'])->only(['done', 'failed'])->all());
        $this->assertSame(16200, $sum['spent_mnt']);
        $this->assertSame(72000, $sum['cash_mnt']);
        $this->assertSame(['count' => 1, 'mnt' => 999000], $sum['test_payments']);
        $this->assertSame(72000 - 16200, $sum['profit_mnt']);
        // As if everything (admin tests too) had been paid at list price; the failed job only costs.
        $list = 3 * app(CostMeter::class)->credits('poster') * config('pricing.credit_mnt');
        $this->assertSame(['revenue_mnt' => $list, 'cost_mnt' => 16200, 'profit_mnt' => $list - 16200], collect($sum['as_customer'])->only(['revenue_mnt', 'cost_mnt', 'profit_mnt'])->all());
        $this->assertSame(7200, $sum['as_customer']['failed_cost_mnt']);
        $this->assertSame([1800, 1, 0], [collect($sum['parts'])->firstWhere('label', 'Зураг')['mnt'], collect($sum['parts'])->firstWhere('label', 'Зураг')['count'], collect($sum['parts'])->firstWhere('label', 'Видео')['mnt']]);

        // Job by job, admin tests included: tokens, real cost and the list price it is measured against.
        $recent = collect(app(CostMeter::class)->recent(30));
        $mine = $recent->firstWhere('admin', true);
        $this->assertSame([1800, 0], [$mine['cost_mnt'], $mine['charged_mnt']]);
        $this->assertSame(app(CostMeter::class)->credits('poster') * config('pricing.credit_mnt'), $mine['list_mnt']);
        $this->assertSame(4, $recent->count()); // the 40-day-old job is outside the window
        $this->assertArrayHasKey('cache_read', $mine['tokens']);
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

    public function test_a_yearly_plan_releases_its_credits_month_by_month(): void
    {
        $user = User::factory()->create();
        $plan = Plan::where('slug', 'business-yearly')->first();
        $payment = Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'sender_invoice_no' => 'Y1', 'callback_token' => 'y', 'amount' => $plan->price, 'status' => 'pending']);

        $first = app(BillingService::class)->markPaid($payment);
        $this->assertSame(0, $first->installment);
        $this->assertSame($first->id, app(BillingService::class)->markPaid($payment)->id); // idempotent

        $months = $user->subscriptions()->orderBy('installment')->get();
        $this->assertCount(12, $months);
        $this->assertSame(1800, $months->sum('credits'));
        $this->assertSame(150, $months[0]->credits);
        $this->assertEqualsWithDelta(now()->addMonths(12)->timestamp, $months[11]->ends_at->timestamp, 5);

        // Only this month's 150 can be spent now; next month opens on its start date.
        $credits = app(\App\Services\Billing\Credits::class);
        $this->assertSame(150, $credits->balance($user) - $this->freeCredits($user));
        $this->travelTo(now()->addMonth()->addMinute());
        $this->assertSame(150, $credits->balance($user) - $this->freeCredits($user));
    }

    public function test_credit_packs_are_sold_apart_from_plans(): void
    {
        $this->getJson('/api/v1/meta')->assertJsonCount(3, 'packs')->assertJsonPath('packs.0.slug', 'credits-12')->assertJsonCount(7, 'plans');

        $user = User::factory()->create();
        $standard = Plan::where('slug', 'standard')->first();
        $pack = Plan::where('slug', 'credits-36')->first();
        app(BillingService::class)->markPaid(Payment::create(['user_id' => $user->id, 'plan_id' => $standard->id, 'sender_invoice_no' => 'S1', 'callback_token' => 's', 'amount' => $standard->price, 'status' => 'pending']));
        $end = $user->subscriptions()->paid()->max('ends_at');

        // A pack starts today (does not extend the plan), lasts 90 days, adds its credits, and is not a subscription.
        $sub = app(BillingService::class)->markPaid(Payment::create(['user_id' => $user->id, 'plan_id' => $pack->id, 'sender_invoice_no' => 'P1', 'callback_token' => 'p', 'amount' => $pack->price, 'status' => 'pending']));
        $this->assertEqualsWithDelta(now()->timestamp, $sub->starts_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $sub->ends_at->timestamp, 5);
        $this->assertSame($end, $user->subscriptions()->paid()->max('ends_at'));
        $this->assertSame(60 + 36, app(\App\Services\Billing\Credits::class)->balance($user) - $this->freeCredits($user));

        $packOnly = User::factory()->create();
        app(BillingService::class)->markPaid(Payment::create(['user_id' => $packOnly->id, 'plan_id' => $pack->id, 'sender_invoice_no' => 'P2', 'callback_token' => 'q', 'amount' => $pack->price, 'status' => 'pending']));
        $this->assertFalse($packOnly->fresh()->isSubscribed());
        $this->assertSame(36, app(\App\Services\Billing\Credits::class)->balance($packOnly) - $this->freeCredits($packOnly));
    }

    private function freeCredits(User $user): int
    {
        return (int) $user->subscriptions()->whereHas('plan', fn ($q) => $q->where('price', 0))->sum('credits');
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
