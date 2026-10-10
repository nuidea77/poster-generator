<?php

namespace Tests\Feature;

use App\Jobs\RunCreation;
use App\Models\Creation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(PlanSeeder::class);
        config([
            'ai.providers.anthropic.key' => 'k-anthropic',
            'ai.providers.anthropic.base_url' => 'https://api.anthropic.com',
            'ai.providers.openai.key' => 'k-openai',
            'ai.providers.gemini.key' => 'k-gemini',
            'ai.providers.seedance.key' => 'k-seedance',
            'ai.providers.seedance.poll_interval' => 0,
            'ai.providers.veo.key' => 'k-gemini',
            'ai.providers.veo.poll_interval' => 0,
        ]);

        $this->user = User::factory()->create();
        Subscription::create(['user_id' => $this->user->id, 'plan_id' => Plan::where('slug', 'standard')->first()->id, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'credits' => 1000]);
    }

    private static function png(int $w = 64, int $h = 64): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 80, 20));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    private function turn(array $calls, string $stop = 'tool_use'): array
    {
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']];
        foreach ($calls as $i => [$name, $input]) {
            $content[] = ['type' => 'tool_use', 'id' => "toolu_{$i}_{$name}", 'name' => $name, 'input' => $input];
        }

        return ['model' => 'claude-fable-5-1', 'stop_reason' => $stop, 'content' => $content, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    public function test_credits_are_charged_up_front_and_refunded_on_failure(): void
    {
        Queue::fake();
        config(['creations.max_active_per_user' => 10]); // queued jobs never finish here
        $user = User::factory()->create();
        $poster = ['type' => 'poster', 'formats' => ['feed_square'], 'prompt' => 'Кофены постер'];

        // A new account starts with the free plan's credits: one poster (12) + one reel (120).
        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertJsonPath('data.credits', 132)
            ->assertJsonPath('data.plan', ['name' => 'Үнэгүй', 'free' => true])
            ->assertJsonPath('data.subscribed', false);

        $first = $this->actingAs($user)->postJson('/api/v1/creations', $poster)->assertStatus(202)->json('data.id');
        $this->assertSame(12, Creation::where('public_id', $first)->value('credits'));
        $this->actingAs($user)->postJson('/api/v1/creations', ['type' => 'reel', 'prompt' => 'Кофены reels'])->assertStatus(202);
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.credits', 0);

        $this->actingAs($user)->postJson('/api/v1/creations', $poster)
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('needed', 12);
        $this->assertSame(2, Creation::count()); // nothing is kept for a refused job

        // Deleting a finished creation does not give credits back; a failed run does.
        $creation = Creation::where('public_id', $first)->first();
        $creation->update(['status' => Creation::DONE]);
        $this->actingAs($user)->deleteJson("/api/v1/creations/{$first}")->assertNoContent();
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.credits', 0);

        (new RunCreation($creation))->failed(new \RuntimeException('boom'));
        (new RunCreation($creation))->failed(new \RuntimeException('again')); // idempotent
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.credits', 12);

        // A paid period adds its credits, which are spent before the never-expiring free ones.
        $standard = Plan::where('slug', 'standard')->first();
        app(BillingService::class)->markPaid(Payment::create(['user_id' => $user->id, 'plan_id' => $standard->id, 'amount' => $standard->price, 'status' => Payment::PENDING, 'sender_invoice_no' => 'T1', 'callback_token' => 'tok-t1']));
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.credits', 172)->assertJsonPath('data.plan.name', 'Стандарт');

        $id = $this->actingAs($user)->postJson('/api/v1/creations', ['formats' => ['feed_square', 'story']] + $poster)->assertStatus(202)->json('data.id');
        $paid = Subscription::where('user_id', $user->id)->paid()->first();
        $this->assertSame([['subscription_id' => $paid->id, 'credits' => 15]], Creation::where('public_id', $id)->value('charges'));

        // The free plan cannot be bought.
        $this->actingAs($user)->postJson('/api/v1/payments', ['plan_id' => Plan::where('slug', 'free')->first()->id])->assertStatus(422);
    }

    public function test_validation_and_queueing_with_uploads(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->postJson('/api/v1/creations', ['type' => 'poster', 'prompt' => 'Кофены постер'])
            ->assertStatus(422)->assertJsonValidationErrors('formats');

        $id = $this->actingAs($this->user)->post('/api/v1/creations', [
            'type' => 'poster',
            'formats' => ['feed_portrait', 'story'],
            'prompt' => 'Шинэ кофе шопын нээлт',
            'product' => ['name' => 'Латте', 'price' => '8,500₮'],
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'remember_logo' => '1',
            'images' => [UploadedFile::fake()->image('p1.jpg', 400, 400)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->json('data.id');

        Queue::assertPushed(RunCreation::class);
        $creation = Creation::where('public_id', $id)->first();
        $this->assertSame(['logo', 'product_1'], array_column($creation->inputs, 'id'));
        $this->assertNotNull($this->user->fresh()->logo_path);
    }

    public function test_fair_use_limits_active_jobs(): void
    {
        Queue::fake();
        config(['creations.max_active_per_user' => 1]);
        Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['feed_square'], 'prompt' => 'x', 'status' => 'running']);

        $this->actingAs($this->user)->postJson('/api/v1/creations', ['type' => 'reel', 'prompt' => 'Фитнес клубын reels'])
            ->assertStatus(429);
    }

    public function test_poster_pipeline_delivers_exact_sizes_and_hides_models(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([
                    ['load_skill', ['name' => 'poster-design']],
                    ['generate_image', ['provider' => 'gemini', 'prompt' => 'latte hero shot', 'aspect' => '4:5', 'reference_image_ids' => ['logo'], 'purpose' => 'feed']],
                ]))
                ->push($this->turn([
                    ['generate_image', ['provider' => 'openai', 'prompt' => 'same, vertical', 'aspect' => '9:16', 'reference_image_ids' => ['img_1'], 'purpose' => 'story']],
                ]))
                ->push($this->turn([
                    ['deliver_poster', ['format' => 'feed_portrait', 'image_id' => 'img_1']],
                    ['deliver_poster', ['format' => 'story', 'image_id' => 'img_2']],
                    ['deliver_poster', ['format' => 'feed_square', 'image_id' => 'img_1']], // not requested → error back to Claude
                ]))
                ->push($this->turn([['finish', ['summary' => 'Gemini for the logo, GPT for the story.']]])),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::png(800, 1000))]]]]]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode(self::png(1024, 1536))]]]),
        ]);

        $creation = Creation::create([
            'user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['feed_portrait', 'story'],
            'prompt' => 'Кофе шопын нээлт', 'status' => 'queued',
        ]);
        Storage::disk('public')->put('in/logo.png', self::png());
        $creation->update(['inputs' => [['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => 'in/logo.png']]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertSame(['feed_portrait', 'story'], array_column($creation->outputs, 'format'));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($creation->outputs[0]['path']));
        $this->assertSame([1080, 1350], [$w, $h]);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($creation->outputs[1]['path']));
        $this->assertSame([1080, 1920], [$w, $h]);
        $this->assertStringContainsString('was not requested', collect($creation->steps)->firstWhere('input.format', 'feed_square')['error']);
        $this->assertSame(400, $creation->input_tokens);
        // 4 Claude turns (100 in / 20 out each) + one Gemini and one GPT image.
        $this->assertEqualsWithDelta(4 * 0.002 + 0.039 + 0.25, $creation->cost_usd, 0.0001);
        $this->assertSame(0.25, collect($creation->assets)->firstWhere('provider', 'openai')['cost']);

        // Customer API never mentions models or agent internals.
        $json = $this->actingAs($this->user)->getJson("/api/v1/creations/{$creation->public_id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 100)
            ->assertJsonCount(2, 'data.outputs')
            ->getContent();
        foreach (['gemini', 'openai', 'seedance', 'veo', 'claude', 'fable', 'provider', 'steps', 'summary'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $json);
        }

        // Claude request shape.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'anthropic.com')) {
                return false;
            }
            $tools = collect($r['tools'])->pluck('name')->all();

            return $r['model'] === 'claude-fable-5-1'
                && ! array_key_exists('thinking', $r->data())
                && ! array_key_exists('tool_choice', $r->data())
                && $r['fallbacks'] === 'default'
                && $r->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
                && $tools === ['load_skill', 'generate_image', 'deliver_poster', 'finish']
                && collect($r['tools'])->firstWhere('name', 'deliver_poster')['input_schema']['properties']['format']['enum'] === ['feed_portrait', 'story']
                && str_contains($r['system'][0]['text'], '`poster-design`')
                && last(last($r['messages'])['content'])['cache_control'] === ['type' => 'ephemeral']
                && str_contains(last($r['messages'][0]['content'])['text'], 'Media budget for this job: $1.00')
                && collect($r['messages'][0]['content'])->contains(fn ($b) => $b['type'] === 'image');
        });
    }

    public function test_media_budget_caps_generation_cost(): void
    {
        $image = ['provider' => 'openai', 'prompt' => 'hero', 'aspect' => '4:5', 'reference_image_ids' => [], 'purpose' => 'try'];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['generate_image', $image], ['generate_image', $image], ['generate_image', $image], ['generate_image', $image]]))
                ->push($this->turn([['deliver_poster', ['format' => 'feed_portrait', 'image_id' => 'img_3']]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode(self::png(800, 1000))]]]),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['feed_portrait'], 'prompt' => 'x', 'status' => 'queued']);
        RunCreation::dispatchSync($creation);
        $creation->refresh();

        // $0.75 budget for one format = three high-quality GPT images (1 + 2 retries); the fourth is refused.
        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertCount(3, $creation->assets);
        $this->assertStringContainsString('Over the media budget', $creation->steps[3]['error']);
        Http::assertSentCount(6);

        $this->user->forceFill(['is_admin' => true])->save();
        $this->actingAs($this->user)->getJson("/api/v1/admin/creations/{$creation->public_id}")
            ->assertJsonPath('data.cost.media_usd', 0.75)
            ->assertJsonPath('data.cost.budget_usd', 0.75);
    }

    public function test_agent_without_deliverables_fails_with_generic_message(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->turn([['finish', ['summary' => 'oops']]]))]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['feed_square'], 'prompt' => 'x', 'status' => 'queued']);
        RunCreation::dispatchSync($creation);

        $this->assertSame('failed', $creation->fresh()->status);
        $this->actingAs($this->user)->getJson("/api/v1/creations/{$creation->public_id}")
            ->assertJsonPath('data.stage', 'failed')
            ->assertJsonPath('data.error', 'Бүтээх явцад алдаа гарлаа. Дахин оролдоно уу.');
    }

    public function test_reel_pipeline_mixes_video_models_and_keeps_the_real_length(): void
    {
        if (! Process::run(['ffmpeg', '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $clip = tempnam(sys_get_temp_dir(), 'clip').'.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x568:rate=24', '-t', '1.5', '-pix_fmt', 'yuv420p', $clip])->throw();
        $clipBytes = file_get_contents($clip);
        $veo = 'https://generativelanguage.googleapis.com/v1beta';

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['generate_image', ['provider' => 'gemini', 'prompt' => 'product still 9:16', 'aspect' => '9:16', 'reference_image_ids' => ['product_1'], 'purpose' => 'hero frame']]]))
                ->push($this->turn([['generate_videos', ['clips' => [
                    ['provider' => 'seedance', 'prompt' => 'slow orbit', 'duration' => 10, 'first_frame_image_id' => 'img_1', 'purpose' => 'hero'],
                    ['provider' => 'seedance', 'prompt' => 'city at night', 'duration' => 5, 'first_frame_image_id' => '', 'purpose' => 'world'],
                    ['provider' => 'veo', 'prompt' => 'barista pours milk', 'duration' => 5, 'first_frame_image_id' => 'img_1', 'purpose' => 'in use'],
                ]]]]))
                ->push($this->turn([['deliver_reel', ['clip_ids' => ['vid_4', 'vid_2']]]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
            "{$veo}/models/veo-3.1-generate-preview:predictLongRunning" => Http::response(['name' => 'models/veo-3.1-generate-preview/operations/op1']),
            "{$veo}/models/veo-3.1-generate-preview/operations/op1" => Http::sequence()
                ->push(['name' => 'op1', 'done' => false])
                ->push(['name' => 'op1', 'done' => true, 'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => "{$veo}/files/v1:download?alt=media"]]]]]]),
            "{$veo}/files/*" => Http::response($clipBytes),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::png(90, 160))]]]]]]]),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks' => Http::sequence()->push(['id' => 't1'])->push(['id' => 't2']),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks/t1' => Http::sequence()
                ->push(['status' => 'running'])
                ->push(['status' => 'succeeded', 'content' => ['video_url' => 'https://cdn.test/t1.mp4']]),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks/t2' => Http::response(['status' => 'succeeded', 'content' => ['video_url' => 'https://cdn.test/t2.mp4']]),
            'cdn.test/*' => Http::response($clipBytes),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'prompt' => 'Бүтээгдэхүүний reels', 'status' => 'queued']);
        Storage::disk('public')->put('in/p.png', self::png());
        $creation->update(['inputs' => [['id' => 'product_1', 'kind' => 'image', 'role' => 'product', 'source' => 'upload', 'path' => 'in/p.png']]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertSame(['vid_4', 'vid_2'], $creation->reel_clips);
        // Ids follow completion order: the 5 s Seedance clip finishes first.
        $this->assertSame([['seedance', 5], ['seedance', 10], ['veo', 6]], array_map(fn ($a) => [$a['provider'], $a['duration']], array_slice($creation->assets, 1)));
        $this->assertSame('veo', $creation->asset('vid_4')['provider']);

        // No padding or trimming: the reel is as long as its clips (2 × 1.5 s here).
        $out = Storage::disk('public')->path($creation->outputs[0]['path']);
        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,codec_name,width,height:format=duration', '-of', 'json', $out])->throw();
        $info = json_decode($probe->output(), true);
        $video = collect($info['streams'])->firstWhere('codec_type', 'video');
        $this->assertSame(['h264', 1080, 1920], [$video['codec_name'], $video['width'], $video['height']]);
        $this->assertNotNull(collect($info['streams'])->firstWhere('codec_type', 'audio'));
        $this->assertEqualsWithDelta(3.0, (float) $info['format']['duration'], 0.15);
        $this->assertEqualsWithDelta(3.0, $creation->outputs[0]['duration'], 0.15);

        // All clips were submitted before polling.
        $posts = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'POST' && str_ends_with($p[0]->url(), '/contents/generations/tasks'));
        $this->assertCount(2, $posts);
        $this->assertSame('first_frame', $posts->first()[0]['content'][1]['role']);
        $this->assertStringContainsString('--duration 10', $posts->first()[0]['content'][0]['text']);
        $this->assertStringContainsString('--ratio 9:16', $posts->last()[0]['content'][0]['text']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), ':predictLongRunning')
            && $r->hasHeader('x-goog-api-key', 'k-gemini')
            && $r['instances'][0]['prompt'] === 'barista pours milk'
            && $r['instances'][0]['image']['inlineData']['mimeType'] === 'image/png'
            && $r['parameters'] === ['aspectRatio' => '9:16', 'durationSeconds' => '6', 'resolution' => '720p']);

        // Fable sees both video models with their clip lengths.
        Http::assertSent(function (Request $r) {
            $tool = str_contains($r->url(), 'anthropic.com') ? collect($r['tools'])->firstWhere('name', 'generate_videos') : null;
            $clip = $tool['input_schema']['properties']['clips']['items']['properties'] ?? null;

            return $clip
                && $clip['provider']['enum'] === ['seedance', 'veo']
                && $clip['duration']['enum'] === [4, 5, 6, 8, 10]
                && str_contains($tool['description'], 'veo 4/6/8 s');
        });

        // Customers never see either video model.
        $json = $this->actingAs($this->user)->getJson("/api/v1/creations/{$creation->public_id}")->getContent();
        foreach (['seedance', 'veo', 'gemini'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $json);
        }

        @unlink($clip);
    }

    public function test_reel_delivery_needs_clips_and_respects_the_cap(): void
    {
        config(['creations.reel.max_seconds' => 8]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['deliver_reel', ['clip_ids' => []]]]))
                ->push($this->turn([['deliver_reel', ['clip_ids' => ['vid_1', 'vid_2']]]]))
                ->push($this->turn([['finish', ['summary' => 'x']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'prompt' => 'x', 'status' => 'queued', 'assets' => [
            ['id' => 'vid_1', 'kind' => 'video', 'duration' => 5],
            ['id' => 'vid_2', 'kind' => 'video', 'duration' => 5],
        ]]);
        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertStringContainsString('at least one clip', $creation->steps[0]['error']);
        $this->assertStringContainsString('at most 8 s', $creation->steps[1]['error']);
        $this->assertSame('failed', $creation->status);
    }

    public function test_owner_only_access_list_retry_and_delete(): void
    {
        Queue::fake();
        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['feed_square'], 'prompt' => 'Хуучин бриф', 'status' => 'done']);

        $this->actingAs(User::factory()->create())->getJson("/api/v1/creations/{$creation->public_id}")->assertNotFound();
        $this->actingAs($this->user)->getJson('/api/v1/creations')->assertJsonPath('data.0.id', $creation->public_id);

        $newId = $this->actingAs($this->user)->postJson("/api/v1/creations/{$creation->public_id}/retry")->assertStatus(202)->json('data.id');
        $this->assertNotSame($creation->public_id, $newId);
        $this->assertSame('Хуучин бриф', Creation::where('public_id', $newId)->value('prompt'));

        $this->actingAs($this->user)->deleteJson("/api/v1/creations/{$creation->public_id}")->assertNoContent();
    }

    public function test_admin_sees_internal_log(): void
    {
        $creation = Creation::create([
            'user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['feed_square'], 'prompt' => 'x', 'status' => 'done',
            'assets' => [['id' => 'img_1', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini', 'path' => 'a.png', 'prompt' => 'p']],
            'steps' => [['type' => 'tool', 'name' => 'generate_image']],
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/admin/creations')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->getJson('/api/v1/admin/creations')
            ->assertOk()
            ->assertJsonPath('data.0.usage.images.gemini', 1)
            ->assertJsonPath('data.0.steps.0.name', 'generate_image');
    }
}
