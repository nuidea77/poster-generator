<?php

namespace Tests\Feature;

use App\Jobs\RunCreation;
use App\Models\Creation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Agent\CreativeAgent;
use App\Services\AI\AiManager;
use App\Services\AI\Exceptions\AiException;
use App\Services\Billing\BillingService;
use App\Services\Media\IconSet;
use App\Services\Media\LogoOverlay;
use App\Services\Media\ReelAssembler;
use App\Services\Media\TextOverlay;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
            'ai.providers.gemini_pro.key' => 'k-gemini',
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
        $poster = ['type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'Кофены постер'];

        // A new account gets exactly one free poster (12 credits, one size).
        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertJsonPath('data.credits', 12)
            ->assertJsonPath('data.plan', ['name' => 'Үнэгүй', 'free' => true])
            ->assertJsonPath('data.subscribed', false);

        // Reels are priced by length; 15 s is the default.
        $this->actingAs($user)->postJson('/api/v1/creations', ['type' => 'reel', 'prompt' => 'Кофены reels'])
            ->assertStatus(402)->assertJsonPath('needed', 80);
        $this->actingAs($user)->postJson('/api/v1/creations', ['type' => 'reel', 'duration' => 60, 'prompt' => 'Кофены reels'])
            ->assertStatus(402)->assertJsonPath('needed', 265);
        $this->actingAs($user)->postJson('/api/v1/creations', ['type' => 'reel', 'duration' => 20, 'prompt' => 'Кофены reels'])
            ->assertStatus(422)->assertJsonValidationErrors('duration');
        $this->actingAs($user)->postJson('/api/v1/creations', ['formats' => ['1:1', '9:16']] + $poster)
            ->assertStatus(402)->assertJsonPath('needed', 15);

        $first = $this->actingAs($user)->postJson('/api/v1/creations', $poster)->assertStatus(202)->json('data.id');
        $this->assertSame(12, Creation::where('public_id', $first)->value('credits'));
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.credits', 0);

        $this->actingAs($user)->postJson('/api/v1/creations', $poster)
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('needed', 12);
        $this->assertSame(1, Creation::count()); // nothing is kept for a refused job

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
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.credits', 72)->assertJsonPath('data.plan.name', 'Стандарт');

        $id = $this->actingAs($user)->postJson('/api/v1/creations', ['formats' => ['1:1', '9:16']] + $poster)->assertStatus(202)->json('data.id');
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
            'formats' => ['4:5', '9:16'],
            'prompt' => 'Шинэ кофе шопын нээлт',
            'product' => ['name' => 'Латте', 'price' => '8,500₮'],
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'remember_logo' => '1',
            'images' => [UploadedFile::fake()->image('p1.jpg', 400, 400)],
            'references' => [UploadedFile::fake()->image('look.jpg', 400, 400)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->json('data.id');

        Queue::assertPushed(RunCreation::class);
        $creation = Creation::where('public_id', $id)->first();
        $this->assertSame(['logo', 'product_1', 'reference_1'], array_column($creation->inputs, 'id'));
        $this->assertSame('reference', $creation->inputs[2]['role']);
        $this->assertNotNull($this->user->fresh()->brand_logo_path);
    }

    public function test_reel_orientation_defaults_to_vertical_and_reaches_the_video_model(): void
    {
        Queue::fake();
        $reel = ['type' => 'reel', 'prompt' => 'Кофены reels'];

        $this->actingAs($this->user)->postJson('/api/v1/creations', ['formats' => ['4:5']] + $reel)
            ->assertStatus(422)->assertJsonValidationErrors('formats.0');
        $this->actingAs($this->user)->postJson('/api/v1/creations', ['formats' => ['9:16', '16:9']] + $reel)
            ->assertStatus(422)->assertJsonValidationErrors('formats');

        $id = $this->actingAs($this->user)->postJson('/api/v1/creations', $reel)->assertStatus(202)->json('data.id');
        $this->assertSame(['9:16'], Creation::where('public_id', $id)->first()->formats);

        $id = $this->actingAs($this->user)->postJson('/api/v1/creations', ['formats' => ['16:9']] + $reel)->assertStatus(202)->json('data.id');
        $creation = Creation::where('public_id', $id)->first();
        $this->assertSame(['aspect' => '16:9', 'width' => 1920, 'height' => 1080], collect($creation->reelFormat())->only(['aspect', 'width', 'height'])->all());

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['generate_videos', ['clips' => [['provider' => 'seedance', 'prompt' => 'wide pan', 'duration' => 5, 'first_frame_image_id' => '', 'purpose' => 'x']]]]]))
                ->push($this->turn([['finish', ['summary' => 'stop']]])),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks' => Http::response(['id' => 't1']),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks/t1' => Http::response(['status' => 'failed']),
        ]);

        app()->call([new RunCreation($creation), 'handle']); // the queue is faked above

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/contents/generations/tasks') && str_contains($r['content'][0]['text'], '--ratio 16:9'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic.com') && str_contains(json_encode($r['messages'][0]['content'], JSON_UNESCAPED_UNICODE), 'horizontal 16:9 video (1920×1080)'));
    }

    public function test_fair_use_limits_active_jobs(): void
    {
        Queue::fake();
        config(['creations.max_active_per_user' => 1]);
        Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'running']);

        $this->actingAs($this->user)->postJson('/api/v1/creations', ['type' => 'reel', 'prompt' => 'Фитнес клубын reels'])
            ->assertStatus(429);
    }

    public function test_auto_format_takes_the_aspect_the_image_was_generated_at(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['generate_image', ['provider' => 'gemini', 'prompt' => 'wide banner', 'aspect' => '16:9', 'reference_image_ids' => [], 'purpose' => 'auto']]]))
                ->push($this->turn([['deliver_poster', ['format' => 'auto', 'image_id' => 'img_1']]]))
                ->push($this->turn([['finish', ['summary' => 'wide']]])),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::png(1600, 900))]]]]]]]),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['auto'], 'prompt' => 'x', 'status' => 'queued']);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertSame(['auto', '16:9'], [$creation->outputs[0]['format'], $creation->outputs[0]['label']]);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($creation->outputs[0]['path']));
        $this->assertSame([1920, 1080], [$w, $h]);
    }

    public function test_poster_pipeline_delivers_exact_sizes_and_hides_models(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([
                    ['load_skill', ['name' => 'poster-design']],
                    ['generate_image', ['provider' => 'gemini', 'prompt' => 'latte hero shot with logo', 'aspect' => '1:1', 'reference_image_ids' => ['logo'], 'purpose' => 'refused']], // logos never go to a model
                    ['generate_image', ['provider' => 'gemini', 'prompt' => 'latte hero shot', 'aspect' => '4:5', 'reference_image_ids' => [], 'purpose' => 'feed']],
                ]))
                ->push($this->turn([
                    ['generate_image', ['provider' => 'openai', 'prompt' => 'same, vertical', 'aspect' => '9:16', 'reference_image_ids' => ['img_1'], 'purpose' => '9:16']],
                ]))
                ->push($this->turn([
                    ['deliver_poster', ['format' => '4:5', 'image_id' => 'img_1']],
                    ['deliver_poster', ['format' => '9:16', 'image_id' => 'img_2']],
                    ['deliver_poster', ['format' => '1:1', 'image_id' => 'img_1']], // not requested → error back to Claude
                ]))
                ->push($this->turn([['finish', ['summary' => 'Gemini for the logo, GPT for the story.']]])),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::png(800, 1000))]]]]]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode(self::png(1024, 1536))]]]),
        ]);

        $creation = Creation::create([
            'user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5', '9:16'],
            'prompt' => 'Кофе шопын нээлт', 'status' => 'queued',
        ]);
        Storage::disk('public')->put('in/logo.png', self::png());
        $creation->update(['inputs' => [['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => 'in/logo.png']]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertSame(['4:5', '9:16'], array_column($creation->outputs, 'format'));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($creation->outputs[0]['path']));
        $this->assertSame([1080, 1350], [$w, $h]);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($creation->outputs[1]['path']));
        $this->assertSame([1080, 1920], [$w, $h]);
        $this->assertStringContainsString('was not requested', collect($creation->steps)->firstWhere('input.format', '1:1')['error']);
        $this->assertStringContainsString('Do not pass the logo', collect($creation->steps)->firstWhere('input.purpose', 'refused')['error']);
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
                && $tools === ['find_references', 'load_skill', 'generate_image', 'add_text', 'add_logo', 'deliver_poster', 'finish']
                && collect($r['tools'])->firstWhere('name', 'deliver_poster')['input_schema']['properties']['format']['enum'] === ['4:5', '9:16']
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
                ->push($this->turn([['deliver_poster', ['format' => '4:5', 'image_id' => 'img_3']]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode(self::png(800, 1000))]]]),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'queued']);
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

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'queued']);
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
                ->push($this->turn([['deliver_reel', ['clip_ids' => ['vid_4', 'vid_3']]]]))
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

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'duration' => 15, 'prompt' => 'Бүтээгдэхүүний reels', 'status' => 'queued']);
        Storage::disk('public')->put('in/p.png', self::png());
        $creation->update(['inputs' => [['id' => 'product_1', 'kind' => 'image', 'role' => 'product', 'source' => 'upload', 'path' => 'in/p.png']]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertSame(['vid_4', 'vid_3'], $creation->reel_clips);
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
            && $r['instances'][0]['image']['mimeType'] === 'image/png'
            && $r['parameters'] === ['aspectRatio' => '9:16', 'durationSeconds' => 6, 'resolution' => '720p']);

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

    public function test_images_asked_for_in_one_turn_are_generated_together_within_budget(): void
    {
        $image = ['data' => [['b64_json' => base64_encode(self::png(1024, 1536))]]];
        $ask = fn (string $purpose) => ['generate_image', ['provider' => 'openai', 'prompt' => "still {$purpose}", 'aspect' => '9:16', 'reference_image_ids' => [], 'purpose' => $purpose]];

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([$ask('a'), $ask('b'), $ask('c'), $ask('d')])) // 4 × $0.25 > the $0.75 poster budget
                ->push($this->turn([['deliver_poster', ['format' => '1:1', 'image_id' => 'img_2']]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
            'api.openai.com/v1/images/generations' => Http::response($image),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'queued']);
        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, (string) $creation->error_detail);
        $this->assertSame(['still a', 'still b', 'still c'], array_column($creation->assets, 'prompt'));
        $this->assertSame(['img_1', 'img_2', 'img_3'], array_column($creation->assets, 'id'));
        $this->assertEqualsWithDelta(0.75, array_sum(array_column($creation->assets, 'cost')), 0.0001);
        $this->assertCount(3, collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/images/generations')));

        // Fable gets one result per call, in order: three images and one budget refusal.
        $results = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'anthropic.com'))->values()[1]['messages'][2]['content'];
        $this->assertSame(['toolu_0_generate_image', 'toolu_1_generate_image', 'toolu_2_generate_image', 'toolu_3_generate_image'], array_column($results, 'tool_use_id'));
        $this->assertStringContainsString('`img_2`', $results[1]['content'][0]['text']);
        $this->assertTrue($results[3]['is_error']);
        $this->assertStringContainsString('Over the media budget', $results[3]['content']);
    }

    public function test_gemini_pro_uses_its_own_model_and_2k_size(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::png())]]]]]]])]);

        app(AiManager::class)->image('gemini_pro')->generateImage('poster with "ХЯМДРАЛ"', '4:5');
        app(AiManager::class)->image('gemini')->generateImage('plain photo', '4:5');

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0]);
        $this->assertStringContainsString('/models/gemini-3-pro-image-preview:generateContent', $sent[0]->url());
        $this->assertSame(['aspectRatio' => '4:5', 'imageSize' => '2K'], $sent[0]['generationConfig']['imageConfig']);
        $this->assertStringContainsString('/models/gemini-2.5-flash-image:generateContent', $sent[1]->url());
        $this->assertSame(['aspectRatio' => '4:5'], $sent[1]['generationConfig']['imageConfig']);
        $this->assertContains('gemini_pro', app(AiManager::class)->available('image'));
    }

    public function test_veo_request_uses_the_shape_the_api_accepts(): void
    {
        // The live API rejects `inlineData` images and string durations (both shown in Google's docs).
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['name' => 'models/veo/operations/op1'])]);
        $veo = app(AiManager::class)->video('veo');

        $this->assertSame('models/veo/operations/op1', $veo->submit('steam rises', '16:9', 6, ['data' => 'PNGDATA', 'mime' => 'image/png']));
        $veo->submit('city at night', '9:16', 8);

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0]->data());
        $this->assertSame(['prompt' => 'steam rises', 'image' => ['bytesBase64Encoded' => base64_encode('PNGDATA'), 'mimeType' => 'image/png']], $sent[0]['instances'][0]);
        $this->assertSame(['aspectRatio' => '16:9', 'durationSeconds' => 6, 'resolution' => '720p'], $sent[0]['parameters']);
        $this->assertSame(8, $sent[1]['parameters']['durationSeconds']);
        $this->assertSame('1080p', $sent[1]['parameters']['resolution']);
        $this->assertArrayNotHasKey('image', $sent[1]['instances'][0]);
    }

    public function test_reel_keeps_clip_sound_and_fills_silent_clips(): void
    {
        $ffmpeg = config('ai.ffmpeg');
        if (! Process::run([$ffmpeg, '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $loud = tempnam(sys_get_temp_dir(), 'loud').'.mp4';
        $mute = tempnam(sys_get_temp_dir(), 'mute').'.mp4';
        Process::run([$ffmpeg, '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x568:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '2', '-pix_fmt', 'yuv420p', '-shortest', $loud])->throw();
        Process::run([$ffmpeg, '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x568:rate=24', '-t', '1.5', '-pix_fmt', 'yuv420p', $mute])->throw();

        $assembler = app(ReelAssembler::class);
        $out = $assembler->assemble([$loud, $mute], 1080, 1920);

        $this->assertEqualsWithDelta(3.5, $assembler->duration($out), 0.15);
        $level = fn (string $from, string $to) => (float) (preg_match('/mean_volume: (-?[\d.]+) dB/', Process::run([$ffmpeg, '-ss', $from, '-to', $to, '-i', $out, '-af', 'volumedetect', '-f', 'null', '-'])->errorOutput(), $m) ? $m[1] : -99);
        $this->assertGreaterThan(-35, $level('0.3', '1.7'));   // the Veo-like clip keeps its sound
        $this->assertLessThan(-60, $level('2.3', '3.3'));      // the silent clip stays silent

        @unlink($loud);
        @unlink($mute);
        @unlink($out);
    }

    public function test_out_of_quota_providers_are_paused_and_reported(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['generate_image', ['provider' => 'openai', 'prompt' => 'x', 'aspect' => '1:1', 'reference_image_ids' => [], 'purpose' => 'x']]]))
                ->push($this->turn([['finish', ['summary' => 'x']]])),
            'api.openai.com/*' => Http::response(['error' => ['message' => 'You have no credits remaining.']], 429),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'queued']);
        RunCreation::dispatchSync($creation);

        $ai = app(AiManager::class);
        $this->assertTrue($ai->paused('openai'));
        $this->assertNotContains('openai', $ai->available('image'));
        $this->assertContains('gemini', $ai->available('image'));
        $this->assertStringContainsString('paused', $creation->fresh()->steps[0]['error']);
        $this->assertFalse($ai->noteFailure('gemini', 'Safety filter blocked the prompt')); // ordinary errors do not pause
        $this->assertFalse($ai->noteFailure('gemini', 'Gemini: Resource exhausted. Please try again later. See error-code-429')); // rate limits are transient

        // Refusing a paused provider does not extend its pause.
        Cache::forget('ai:paused:openai:direct');
        $this->assertTrue($ai->noteFailure('openai', 'Provider [openai] is paused: out of quota/credits. Use another model.'));
        $this->assertFalse($ai->paused('openai'));
    }

    public function test_job_fails_fast_without_calling_fable_when_no_image_model_is_left(): void
    {
        Http::fake();
        $ai = app(AiManager::class);
        foreach ($ai->available('image') as $name) {
            $ai->noteFailure($name, 'You exceeded your current quota.');
        }

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'queued']);
        RunCreation::dispatchSync($creation);

        $this->assertSame('failed', $creation->fresh()->status);
        $this->assertStringContainsString('No image model available', $creation->fresh()->error_detail);
        Http::assertNothingSent();
    }

    public function test_animate_still_makes_a_free_clip_that_counts_toward_the_reel(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([
                    ['animate_still', ['image_id' => 'card', 'duration' => 6, 'motion' => 'push_in', 'purpose' => 'end card']],
                    ['animate_still', ['image_id' => 'card', 'duration' => 8, 'motion' => 'still', 'purpose' => 'hold']],
                ]))
                ->push($this->turn([['deliver_reel', ['clip_ids' => ['vid_2', 'vid_3']]]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'duration' => 15, 'formats' => ['9:16'], 'prompt' => 'x', 'status' => 'queued']);
        Storage::disk('public')->put('in/card.png', self::png(540, 960));
        $creation->update(['assets' => [['id' => 'card', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini_pro', 'path' => 'in/card.png', 'cost' => 0.134]]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, json_encode($creation->steps));
        $this->assertSame([['still', 6, 0.0], ['still', 8, 0.0]], array_map(fn ($a) => [$a['provider'], $a['duration'], (float) $a['cost']], array_slice($creation->assets, 1)));
        $this->assertEqualsWithDelta(14.0, $creation->outputs[0]['duration'], 0.2);
        $this->assertSame([1080, 1920], [$creation->outputs[0]['width'], $creation->outputs[0]['height']]);
    }

    public function test_add_text_burns_exact_typography_onto_a_clip(): void
    {
        $ffmpeg = config('ai.ffmpeg');
        if (! Process::run([$ffmpeg, '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $clip = tempnam(sys_get_temp_dir(), 'clip').'.mp4';
        Process::run([$ffmpeg, '-y', '-f', 'lavfi', '-i', 'color=c=0x223344:s=540x960:r=24', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '3', '-pix_fmt', 'yuv420p', '-shortest', $clip])->throw();
        Storage::disk('public')->put('in/clip.mp4', file_get_contents($clip));

        $text = fn (string $t, string $role, string $pos, string $font) => ['text' => $t, 'role' => $role, 'position' => $pos, 'align' => 'center', 'font' => $font, 'color' => 'FFFFFF', 'box' => $role === 'price', 'box_color' => 'E85D04', 'start' => 0.2, 'end' => 3, 'animation' => 'slide_up'];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['add_text', ['asset_id' => 'vid_1', 'scrim' => 'top_bottom', 'scrim_color' => '000000', 'items' => [
                    $text('ӨВЛИЙН ХЯМДРАЛ', 'title', 'top', 'condensed'),
                    ['icon' => 'location'] + $text('Их Дэлгүүр', 'small', 'bottom', 'sans'), // icon + scrim images loop on the clip
                    $text('4,500₮', 'price', 'lower_third', 'serif'), // serif has no ₮: falls back to bold sans
                    $text("Захиалах\n9911-2233", 'small', 'bottom', 'sans'),
                ]]]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'duration' => 15, 'formats' => ['9:16'], 'prompt' => 'x', 'status' => 'running']);
        $creation->update(['assets' => [['id' => 'vid_1', 'kind' => 'video', 'source' => 'generated', 'provider' => 'veo', 'path' => 'in/clip.mp4', 'duration' => 3, 'cost' => 1.2]]]);

        app(CreativeAgent::class)->run($creation);
        $creation->refresh();

        $this->assertSame('add_text', $creation->steps[0]['name'], json_encode($creation->steps));
        $this->assertArrayNotHasKey('error', $creation->steps[0], json_encode($creation->steps));
        $made = $creation->asset('vid_2');
        $this->assertSame(['text', 3, 0.0], [$made['provider'], $made['duration'], (float) $made['cost']]);
        $this->assertStringContainsString('ӨВЛИЙН ХЯМДРАЛ', $made['prompt']);

        $probe = json_decode(Process::run([config('ai.ffprobe'), '-v', 'error', '-show_entries', 'stream=codec_type,width,height:format=duration', '-of', 'json', Storage::disk('public')->path($made['path'])])->output(), true);
        $this->assertSame(['video', 'audio'], array_column($probe['streams'], 'codec_type')); // sound kept
        $this->assertEqualsWithDelta(3.0, (float) $probe['format']['duration'], 0.1);
        @unlink($clip);
    }

    public function test_poster_text_is_set_in_real_fonts_and_delivered(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $item = fn (string $t, string $role, string $pos, string $align) => ['text' => $t, 'role' => $role, 'position' => $pos, 'align' => $align, 'font' => 'bold_sans', 'color' => 'FFFFFF', 'box' => $role === 'price', 'box_color' => 'C1121F', 'start' => 0, 'end' => 0, 'animation' => 'none'];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['add_text', ['asset_id' => 'img_1', 'items' => [
                    $item('ӨВЛИЙН ХЯМДРАЛ', 'title', 'top', 'left'),
                    $item('-30%', 'price', 'lower_third', 'center'),
                    $item('\u0423\u0442\u0430\u0441: 9911-2233', 'small', 'bottom', 'center'), // model sent escapes
                ]]]]))
                ->push($this->turn([['deliver_poster', ['format' => '4:5', 'image_id' => 'img_2']]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'queued']);
        Storage::disk('public')->put('in/visual.png', self::png(896, 1152)); // Gemini's "4:5" is not exactly 4:5
        $creation->update(['assets' => [['id' => 'img_1', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini', 'path' => 'in/visual.png', 'aspect' => '4:5', 'cost' => 0.039]]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, json_encode($creation->steps));
        $this->assertSame(['text', '4:5', 'image'], [$creation->asset('img_2')['provider'], $creation->asset('img_2')['aspect'], $creation->asset('img_2')['kind']]);
        $this->assertSame('img_2', $creation->outputs[0]['source_asset']);
        $this->assertStringEndsWith('| Утас: 9911-2233', $creation->asset('img_2')['prompt']);
        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring(Storage::disk('public')->get($creation->asset('img_2')['path'])), 0, 2), 'text is set on the exact frame');
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($creation->outputs[0]['path']));
        $this->assertSame([1080, 1350], [$w, $h]);
    }

    public function test_add_logo_places_the_original_file_without_reshaping_it(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        // A 200×100 pure red logo on a blue 1080×1350 visual (the exact 4:5 frame).
        $make = function (int $w, int $h, array $rgb) {
            $img = imagecreatetruecolor($w, $h);
            imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
            ob_start();
            imagepng($img);

            return ob_get_clean();
        };
        Storage::disk('public')->put('in/logo.png', $make(200, 100, [255, 0, 0]));
        Storage::disk('public')->put('in/visual.png', $make(1080, 1350, [0, 0, 255]));

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['add_logo', ['asset_id' => 'img_1', 'position' => 'top_left', 'width' => 0.2, 'remove_background' => true, 'plate' => 'none']]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'running',
            'inputs' => [['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => 'in/logo.png']],
            'assets' => [['id' => 'img_1', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini', 'path' => 'in/visual.png', 'aspect' => '4:5', 'cost' => 0.039]]]);

        app(CreativeAgent::class)->run($creation);
        $made = $creation->refresh()->asset('img_2');
        $this->assertSame('logo', $made['provider'], json_encode($creation->steps));

        // Logo 20% of 1080 px = 216 × 108 (aspect kept); side margin 7% of the width (text grid) = 76 px, top 5% of 1350 = 68 px.
        $img = imagecreatefromstring(Storage::disk('public')->get($made['path']));
        $red = fn (int $x, int $y) => imagecolorat($img, $x, $y) >> 16 & 0xFF;
        $this->assertGreaterThan(240, $red(90, 80));     // inside the logo
        $this->assertGreaterThan(240, $red(280, 170));   // still inside at 216×108
        $this->assertLessThan(20, $red(60, 100));        // left margin: background
        $this->assertLessThan(20, $red(300, 120));       // right of the logo: background
        $this->assertLessThan(20, $red(100, 190));       // below the logo: background
        $this->assertSame([1080, 1350], [imagesx($img), imagesy($img)]);
    }

    public function test_every_line_of_centred_text_is_centred(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 1920);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 0, 0, 0));
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);

        $out = app(TextOverlay::class)->burn($file, [
            ['text' => "ӨВЛИЙН ШИНЭ ЦУГЛУУЛГА\nЦАМЦ", 'role' => 'title', 'position' => 'top', 'align' => 'center', 'font' => 'serif', 'color' => 'FFFFFF', 'box' => false, 'box_color' => '', 'start' => 0, 'end' => 0, 'animation' => 'none'],
        ], 0, true);
        $img = imagecreatefrompng($out);
        @unlink($file);
        @unlink($out);

        // Horizontal extent of white pixels per text line; both lines must be centred on 540.
        $rows = [];
        for ($y = 0; $y < 1920; $y++) {
            for ($x = 0; $x < 1080; $x++) {
                if ((imagecolorat($img, $x, $y) >> 16 & 0xFF) > 128) {
                    $rows[$y] = [min($rows[$y][0] ?? 1080, $x), max($rows[$y][1] ?? 0, $x)];
                }
            }
        }
        $ys = array_keys($rows);
        $gap = collect($ys)->sliding(2)->first(fn ($p) => $p->last() - $p->first() > 3)->last(); // first row of line 2
        foreach ([array_filter($rows, fn ($y) => $y < $gap, ARRAY_FILTER_USE_KEY), array_filter($rows, fn ($y) => $y >= $gap, ARRAY_FILTER_USE_KEY)] as $line) {
            $mid = (min(array_column($line, 0)) + max(array_column($line, 1))) / 2;
            $this->assertEqualsWithDelta(540, $mid, 12);
        }
    }

    public function test_two_short_facts_share_one_row_at_the_two_edges(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 1350);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $item = fn (string $t, string $icon, bool $same) => ['text' => $t, 'role' => 'small', 'position' => 'bottom', 'align' => 'center', 'same_line' => $same, 'font' => 'sans', 'color' => 'FFFFFF', 'box' => false, 'box_color' => '', 'start' => 0, 'end' => 0, 'animation' => 'none', 'icon' => $icon];
        $overlay = app(TextOverlay::class);
        @unlink($overlay->burn($file, [['align' => 'left'] + $item('7711-5566', 'phone', false), ['align' => 'right'] + $item('Хүргэлттэй', 'delivery', true), $item('Олимпийн гудамж 8', 'location', false)], 0, true));
        @unlink($file);

        [$phone, $delivery, $address] = array_column($overlay->placed, 'rect');
        $this->assertSame($phone[1], $delivery[1]);                  // same row
        $this->assertSame(76, $phone[0]);                             // phone on the left text edge (7%)
        $this->assertEqualsWithDelta(1080 - 76, $delivery[2], 2);     // delivery ends on the right edge
        $this->assertGreaterThan($phone[3], $address[1]);             // the next fact starts below the row
        $this->assertLessThan(0.034 * 1080 * 1.25, $phone[3] - $phone[1]); // small facts stay small (line = 1.2 × size)

        // Both centred: the pair stays together as one group in the middle, not at the edges.
        $bg = imagecreatetruecolor(1080, 1350);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        @unlink($overlay->burn($file, [$item('7711-5566', 'phone', false), $item('Хүргэлттэй', 'delivery', true)], 0, true));
        @unlink($file);
        [$p, $d] = array_column($overlay->placed, 'rect');
        $this->assertGreaterThan(200, $p[0]);
        $this->assertLessThan(1080 - 200, $d[2]);
        $this->assertEqualsWithDelta(540, ($p[0] + $d[2]) / 2, 30);
        $this->assertLessThan(0.08 * 1080, $d[0] - $p[2]); // a small gap between them

        // Contacts: long small address on the left, bold subtitle phone on the right — the phone keeps its size.
        $bg = imagecreatetruecolor(1080, 1350);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        @unlink($overlay->burn($file, [$item('БЗД, 26-р хороо, Номин тауэр 3 давхар', 'location', false), ['role' => 'subtitle', 'align' => 'right'] + $item('7700-4455', 'phone', true)], 0, true));
        @unlink($file);
        [$address, $phoneBig] = array_column($overlay->placed, 'rect');
        $this->assertGreaterThan($address[3] - $address[1], $phoneBig[3] - $phoneBig[1]);
    }

    public function test_items_with_x_and_y_are_placed_freely_at_their_size(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 1350);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $base = ['role' => 'small', 'position' => 'top', 'same_line' => false, 'font' => 'sans', 'color' => 'FFFFFF', 'box' => false, 'box_color' => '', 'icon' => 'none'];
        $overlay = app(TextOverlay::class);
        @unlink($overlay->burn($file, [
            ['text' => '7000-4545', 'align' => 'right', 'x' => 0.9, 'y' => 0.5] + $base,
            ['text' => 'ШИНЭ', 'align' => 'left', 'x' => 0.2, 'y' => 0.1, 'size' => 0.1] + $base,
        ], 0, true));
        @unlink($file);

        [$phone, $word] = array_column($overlay->placed, 'rect');
        $this->assertSame(675, $phone[1]);                                // y = 50% of 1350
        $this->assertEqualsWithDelta(972, $phone[2], 15);                 // right edge at 90% of 1080
        $this->assertSame(135, $word[1]);
        $this->assertEqualsWithDelta(216, $word[0], 2);                   // left edge at 20%
        $this->assertGreaterThan(80, $word[3] - $word[1]);                // size 0.1 = 108 px, not the small role
    }

    public function test_row_items_sit_at_their_own_align_and_pills_are_drawn_under_the_text(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 1080);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 230, 230, 230));
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $item = fn (string $t, bool $same, string $shape = 'rect', bool $box = false) => ['text' => $t, 'role' => 'small', 'position' => 'center', 'align' => 'center', 'same_line' => $same, 'font' => 'bold_sans', 'color' => '111111', 'box' => $box, 'box_color' => '22C55E', 'box_shape' => $shape, 'start' => 0, 'end' => 0, 'animation' => 'none', 'icon' => 'none'];
        $overlay = app(TextOverlay::class);
        $out = $overlay->burn($file, [['align' => 'left'] + $item('ЭХО', false), $item('РЕНТГЕН ШИНЖИЛГЭЭ', true), ['align' => 'right'] + $item('ҮЗЛЭГ', true), $item('Бэлэг авах', false, 'pill', true)], 0, true);
        $img = imagecreatefrompng($out);
        @unlink($file);
        @unlink($out);

        [$a, $b, $c, $pill] = array_column($overlay->placed, 'rect');
        $this->assertSame([$a[1], $a[3]], [$b[1], $b[3]]);   // one row, one size
        $this->assertSame($a[1], $c[1]);
        // Each item sits at its own align: left edge, centre, right edge (margin 7% = 76 px).
        $this->assertEqualsWithDelta(76, $a[0], 15);  // rect = estimated width, the glyphs are measured
        $this->assertEqualsWithDelta(540, ($b[0] + $b[2]) / 2, 3);
        $this->assertEqualsWithDelta(1080 - 76, $c[2], 15);

        // The pill: green fill left of the text, rounded (the corner stays background).
        $green = fn (int $x, int $y) => (imagecolorat($img, $x, $y) >> 8 & 0xFF) > 150 && (imagecolorat($img, $x, $y) >> 16 & 0xFF) < 100;
        $midY = (int) (($pill[1] + $pill[3]) / 2);
        $this->assertTrue($green($pill[0] + 12, $midY));
        $this->assertFalse($green($pill[0] + 1, $pill[1] + 1));
    }

    public function test_text_sits_in_the_middle_of_its_pill(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 600);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $overlay = app(TextOverlay::class);
        $out = $overlay->burn($file, [['text' => 'ШИНЭ НАМРЫН УНДАА', 'role' => 'subtitle', 'position' => 'center', 'align' => 'center', 'font' => 'bold_sans', 'color' => 'FFFFFF', 'box' => true, 'box_color' => 'C2410C', 'box_shape' => 'pill', 'icon' => 'none']], 0, true);
        $img = imagecreatefrompng($out);
        @unlink($file);
        @unlink($out);

        // White glyph rows inside the orange pill: the gap above them equals the gap below.
        [$x0, $y0, $x1, $y1] = $overlay->placed[0]['rect'];
        $rows = [];
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0 + 20; $x < $x1 - 20; $x += 2) {
                $c = imagecolorat($img, $x, $y);
                if (($c >> 8 & 0xFF) > 200 && ($c & 0xFF) > 200) {
                    $rows[] = $y;
                    break;
                }
            }
        }
        $this->assertEqualsWithDelta(min($rows) - $y0, $y1 - max($rows), 4);
    }

    public function test_the_brief_lists_the_clients_last_designs_and_suggests_another_map(): void
    {
        Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'old', 'status' => 'done', 'steps' => [
            ['name' => 'add_text', 'input' => ['items' => [['text' => 'X', 'role' => 'title', 'font' => 'condensed', 'position' => 'top', 'align' => 'left']]]],
            ['name' => 'deliver_poster', 'input' => ['format' => '4:5', 'image_id' => 'img_1', 'design' => ['map' => 'split', 'style' => 'Swiss Industrial', 'palette' => '111111, FFD400', 'type' => 'condensed caps', 'idea' => 'hero number']]],
        ]]);
        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'new', 'status' => 'running']);

        $brief = collect((new \ReflectionMethod(CreativeAgent::class, 'brief'))->invoke(app(CreativeAgent::class), $creation))->last()['text'];

        $this->assertStringContainsString('map: split · style: Swiss Industrial', $brief);
        $this->assertDoesNotMatchRegularExpression('/suggested: `split`/', $brief); // the map just used is not suggested again
    }

    public function test_starred_words_are_drawn_in_the_accent_colour_in_place(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 400);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $item = fn (string $t, string $pos) => ['text' => $t, 'role' => 'title', 'position' => $pos, 'align' => 'left', 'font' => 'bold_sans', 'color' => 'FFFFFF', 'accent_color' => 'FFB703', 'box' => false, 'box_shape' => 'rect'];
        $out = app(TextOverlay::class)->burn($file, [$item('Бизнес харагдахгүй уу', 'top'), $item('Бизнес *харагдахгүй* уу', 'center')], 0, true);
        $img = imagecreatefrompng($out);
        @unlink($file);
        @unlink($out);

        // Ink extent and accent pixels of each line.
        $scan = function (int $y0, int $y1) use ($img) {
            [$min, $max, $amber] = [1080, 0, 0];
            for ($y = $y0; $y < $y1; $y += 2) {
                for ($x = 0; $x < 1080; $x++) {
                    $c = imagecolorat($img, $x, $y);
                    [$r, $g, $b] = [$c >> 16 & 0xFF, $c >> 8 & 0xFF, $c & 0xFF];
                    if ($r > 120) {
                        [$min, $max] = [min($min, $x), max($max, $x)];
                        $amber += $r > 200 && $b < 80 ? 1 : 0;
                    }
                }
            }

            return [$min, $max, $amber];
        };
        [$a0, $a1, $plainAmber] = $scan(40, 140);
        [$b0, $b1, $accentAmber] = $scan(160, 260);
        $this->assertSame(0, $plainAmber);
        $this->assertGreaterThan(200, $accentAmber);          // the starred word is amber
        $this->assertEqualsWithDelta($a0, $b0, 3);             // and the line sits where the plain one does
        $this->assertEqualsWithDelta($a1, $b1, 4);
    }

    public function test_a_circle_icon_is_a_disc_with_the_glyph_cut_in_another_colour(): void
    {
        $img = imagecreatefromstring(app(IconSet::class)->png('phone', 100, '4C1D95', 'FFFFFF'));
        $rgba = fn (int $x, int $y) => [imagecolorat($img, $x, $y) >> 16 & 0xFF, imagecolorat($img, $x, $y) >> 8 & 0xFF, imagecolorat($img, $x, $y) & 0xFF, imagecolorat($img, $x, $y) >> 24 & 0x7F];
        $this->assertSame(127, $rgba(2, 2)[3]);                       // outside the disc: transparent
        $this->assertSame([255, 255, 255, 0], $rgba(50, 8));          // the white disc
        $this->assertEqualsWithDelta(0x4C, $rgba(39, 50)[0], 30);     // the phone outline in violet inside it
    }

    public function test_a_partial_add_text_is_patched_onto_the_last_full_layout(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        Storage::disk('public')->put('in/visual.png', self::png(1080, 1350));
        $item = fn (string $t) => ['text' => $t, 'role' => 'small', 'position' => 'top', 'align' => 'center', 'same_line' => false, 'font' => 'sans', 'color' => 'FFFFFF', 'box' => false, 'box_color' => '', 'box_shape' => 'rect', 'start' => 0, 'end' => 0, 'animation' => 'none', 'icon' => 'none'];
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['add_text', ['asset_id' => 'img_1', 'items' => [$item('Нэг'), $item('Хоёр'), $item('Гурав'), $item('Дөрөв')]]]]))
                ->push($this->turn([['add_text', ['asset_id' => 'img_1', 'items' => [$item('Хоёр!'), $item('Тав')]]]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
        ]);
        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'running',
            'assets' => [['id' => 'img_1', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini', 'path' => 'in/visual.png', 'aspect' => '4:5', 'cost' => 0.039]]]);

        app(CreativeAgent::class)->run($creation);
        $steps = collect($creation->fresh()->steps)->where('name', 'add_text')->values();

        $this->assertArrayNotHasKey('error', $steps[0]);
        $this->assertArrayNotHasKey('error', $steps[1]);
        // 'Хоёр!' replaces an item of the same position/role, 'Тав' is added: 5 items drawn, the rest kept.
        $layout = collect($creation->fresh()->assets)->last()['layout'];
        $this->assertSame(['Нэг', 'Хоёр!', 'Гурав', 'Дөрөв', 'Тав'], array_column($layout, 'text'));
        $this->assertSame('img_1', collect($creation->fresh()->assets)->last()['from']);
    }

    public function test_posters_run_on_the_cheaper_model_and_are_costed_at_its_price(): void
    {
        config(['ai.agent.poster_model' => 'claude-sonnet-5-5', 'ai.agent.model' => 'claude-fable-5-1']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'finish', 'input' => ['summary' => 'ok']]], 'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 1000000, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]])]);
        $simple = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'running', 'inputs' => []]);

        try {
            app(CreativeAgent::class)->run($simple);
        } catch (\Throwable) {
            // finish without a delivery may fail the job; only the model and the cost matter here
        }

        Http::assertSent(fn ($r) => $r['model'] === 'claude-sonnet-5-5');
        $this->assertSame('claude-sonnet-5-5', $simple->fresh()->model);
        $this->assertEqualsWithDelta(10.0, (float) $simple->fresh()->cost_usd, 0.01); // 1M output tokens at $10, not Fable's $50

        // Two formats use it too.
        $full = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5', '1:1'], 'prompt' => 'x', 'status' => 'running', 'inputs' => []]);
        try {
            app(CreativeAgent::class)->run($full);
        } catch (\Throwable) {
        }
        $this->assertSame('claude-sonnet-5-5', $full->fresh()->model);
    }

    public function test_placeholder_text_calls_are_refused(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('placeholder');
        app(TextOverlay::class)->burn(__FILE__, [['text' => '	text', 'role' => 'small']], 0, true);
    }

    public function test_wide_serif_capitals_keep_clear_of_the_edges(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 1080);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $out = app(TextOverlay::class)->burn($file, [['text' => 'САР ШИНИЙН МЭНД ХҮРГЭЕ', 'role' => 'title', 'position' => 'top', 'align' => 'center', 'font' => 'serif', 'color' => 'FFFFFF', 'box' => false, 'box_color' => '', 'start' => 0, 'end' => 0, 'animation' => 'none']], 0, true);
        $img = imagecreatefrompng($out);
        @unlink($file);
        @unlink($out);

        [$min, $max] = [1080, 0];
        for ($y = 0; $y < 400; $y += 2) {
            for ($x = 0; $x < 1080; $x++) {
                if ((imagecolorat($img, $x, $y) >> 16 & 0xFF) > 128) {
                    [$min, $max] = [min($min, $x), max($max, $x)];
                }
            }
        }
        $this->assertGreaterThan(60, $min);       // ≥ ~6% margin on the left
        $this->assertLessThan(1080 - 60, $max);   // and on the right
        $this->assertGreaterThan(700, $max - $min); // still a big headline
    }

    public function test_a_display_number_is_huge_and_never_in_old_style_serif_digits(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        $bg = imagecreatetruecolor(1080, 1080);
        $file = sys_get_temp_dir().'/bg-'.uniqid().'.png';
        imagepng($bg, $file);
        $item = fn (string $role) => ['text' => '15', 'role' => $role, 'position' => 'top', 'align' => 'center', 'font' => 'serif', 'color' => 'FFFFFF', 'box' => false, 'box_color' => '', 'start' => 0, 'end' => 0, 'animation' => 'none'];

        // Height of the drawn digits: a display number is about twice a title; serif digits get lining (condensed) figures.
        $height = function (string $role) use ($file, $item) {
            $out = app(TextOverlay::class)->burn($file, [$item($role)], 0, true);
            $img = imagecreatefrompng($out);
            @unlink($out);
            $rows = 0;
            for ($y = 0; $y < 1080; $y += 2) {
                for ($x = 300; $x < 780; $x += 2) {
                    if ((imagecolorat($img, $x, $y) >> 16 & 0xFF) > 200) {
                        $rows++;
                        break;
                    }
                }
            }

            return $rows * 2;
        };
        $display = $height('display');
        $title = $height('title');
        @unlink($file);

        $this->assertGreaterThan(1.8 * $title, $display);
        $this->assertGreaterThan(0.18 * 1080, $display); // Oswald digits are cap height, not sunk old-style figures
    }

    public function test_a_reply_cut_off_at_max_tokens_does_not_end_the_job(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['add_text', ['asset_id' => 'img_1']]], 'max_tokens')) // half-written call
                ->push($this->turn([['deliver_poster', ['format' => '1:1', 'image_id' => 'img_1']]]))
                ->push($this->turn([['finish', ['summary' => 'ok']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'queued']);
        Storage::disk('public')->put('in/visual.png', self::png(1024, 1024));
        $creation->update(['assets' => [['id' => 'img_1', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini', 'path' => 'in/visual.png', 'aspect' => '1:1', 'cost' => 0.039]]]);

        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertSame('done', $creation->status, json_encode($creation->steps));
        $this->assertSame('cut off (max_tokens)', $creation->steps[0]['error']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && ($r['messages'][2]['content'][0]['is_error'] ?? false) === true
            && $r['messages'][2]['content'][0]['tool_use_id'] === 'toolu_0_add_text');
    }

    public function test_a_round_badge_gets_a_round_plate(): void
    {
        if (! Process::run([config('ai.ffmpeg'), '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg not installed');
        }

        // A red disc with transparent corners (a badge) on a blue 1000×1000 visual, light plate.
        $logo = imagecreatetruecolor(200, 200);
        imagesavealpha($logo, true);
        imagealphablending($logo, false);
        imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagefilledellipse($logo, 100, 100, 200, 200, imagecolorallocate($logo, 255, 0, 0));
        $bg = imagecreatetruecolor(1000, 1000);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 0, 0, 255));
        [$logoFile, $bgFile] = [sys_get_temp_dir().'/badge-'.uniqid().'.png', sys_get_temp_dir().'/bg-'.uniqid().'.png'];
        imagepng($logo, $logoFile);
        imagepng($bg, $bgFile);

        $placed = app(LogoOverlay::class)->place($bgFile, $logoFile, 'center', 0.2, 'light', true);
        $img = imagecreatefrompng($placed['path']);
        @unlink($logoFile);
        @unlink($bgFile);
        @unlink($placed['path']);

        // Logo 200 px + 24 px plate each side = a 248 px circle centred at 500,500.
        $rgb = fn (int $x, int $y) => [imagecolorat($img, $x, $y) >> 16 & 0xFF, imagecolorat($img, $x, $y) >> 8 & 0xFF, imagecolorat($img, $x, $y) & 0xFF];
        $this->assertSame([255, 0, 0], $rgb(500, 500));            // the logo
        $this->assertGreaterThan(240, min($rgb(500, 388)));          // white ring above the disc
        $this->assertSame([0, 0, 255], $rgb(385, 385));            // the plate's corner is open: a circle, not a square card
        $this->assertEqualsWithDelta([0.376, 0.376, 0.624, 0.624], $placed['box'], 0.002);
    }

    public function test_library_filters_by_outcome(): void
    {
        foreach (['done', 'done', 'failed', 'running'] as $status) {
            Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => $status, 'status' => $status]);
        }
        $count = fn (string $q) => count($this->actingAs($this->user)->getJson("/api/v1/creations{$q}")->assertOk()->json('data'));

        $this->assertSame(4, $count(''));
        $this->assertSame(2, $count('?status=done'));
        $this->assertSame(1, $count('?status=failed'));
        $this->assertSame(1, $count('?status=active'));
        $this->assertSame(1, $count('?status=failed&type=poster'));
        $this->assertSame(0, $count('?status=failed&type=reel'));
    }

    public function test_gemini_says_why_no_image_came_back(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'IMAGE_SAFETY', 'content' => ['parts' => []]]]])]);

        try {
            app(AiManager::class)->image('gemini')->generateImage('x', '1:1');
            $this->fail('expected an exception');
        } catch (AiException $e) {
            $this->assertStringContainsString('IMAGE_SAFETY', $e->getMessage());
        }
    }

    public function test_giving_up_after_a_media_failure_is_refused_once(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'IMAGE_SAFETY', 'content' => ['parts' => []]]]]),
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['generate_image', ['provider' => 'gemini', 'prompt' => 'x', 'aspect' => '1:1']]]))
                ->push($this->turn([['finish', ['summary' => 'gave up']]]))   // refused: nothing delivered yet
                ->push($this->turn([['finish', ['summary' => 'gave up']]])),  // accepted the second time
        ]);
        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'running']);

        app(CreativeAgent::class)->run($creation);
        $finishes = collect($creation->fresh()->steps)->where('name', 'finish')->values();

        $this->assertCount(2, $finishes);
        $this->assertStringContainsString('Nothing has been delivered yet', $finishes[0]['error']);
        $this->assertArrayNotHasKey('error', $finishes[1]);
    }

    public function test_reel_delivery_needs_clips_and_respects_the_cap(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->turn([['deliver_reel', ['clip_ids' => []]]]))
                ->push($this->turn([['deliver_reel', ['clip_ids' => ['vid_1', 'vid_2']]]]))
                ->push($this->turn([['finish', ['summary' => 'x']]])),
        ]);

        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'duration' => 15, 'prompt' => 'x', 'status' => 'queued', 'assets' => [
            ['id' => 'vid_1', 'kind' => 'video', 'duration' => 5],
            ['id' => 'vid_2', 'kind' => 'video', 'duration' => 5],
        ]]);
        RunCreation::dispatchSync($creation);
        $creation->refresh();

        $this->assertStringContainsString('at least one clip', $creation->steps[0]['error']);
        $this->assertStringContainsString('at least 12 s (ordered 15 s)', $creation->steps[1]['error']);
        $this->assertSame('failed', $creation->status);
    }

    public function test_owner_only_access_list_retry_and_delete(): void
    {
        Queue::fake();
        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'Хуучин бриф', 'status' => 'done']);

        $this->actingAs(User::factory()->create())->getJson("/api/v1/creations/{$creation->public_id}")->assertNotFound();
        $this->actingAs($this->user)->getJson('/api/v1/creations')->assertJsonPath('data.0.id', $creation->public_id);

        $newId = $this->actingAs($this->user)->postJson("/api/v1/creations/{$creation->public_id}/retry")->assertStatus(202)->json('data.id');
        $this->assertNotSame($creation->public_id, $newId);
        $this->assertSame('Хуучин бриф', Creation::where('public_id', $newId)->value('prompt'));

        $this->actingAs($this->user)->deleteJson("/api/v1/creations/{$creation->public_id}")->assertNoContent();
    }

    public function test_a_done_poster_can_be_revised_keeping_its_clean_visual_and_layout(): void
    {
        Queue::fake();
        Storage::disk('public')->put('c/clean.png', 'clean');
        Storage::disk('public')->put('c/text.png', 'text');
        Storage::disk('public')->put('c/final.jpg', 'final');
        $items = [['text' => '135,000₮', 'role' => 'price', 'x' => 0.1, 'y' => 0.7]];
        $source = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'Hydrafacial', 'status' => 'done',
            'assets' => [
                ['id' => 'img_1', 'kind' => 'image', 'source' => 'generated', 'provider' => 'gemini', 'path' => 'c/clean.png', 'aspect' => '4:5', 'cost' => 0.04],
                ['id' => 'img_2', 'kind' => 'image', 'source' => 'generated', 'provider' => 'text', 'path' => 'c/text.png', 'from' => 'img_1', 'layout' => $items],
                ['id' => 'img_3', 'kind' => 'image', 'source' => 'generated', 'provider' => 'logo', 'path' => 'c/text.png', 'prompt' => 'logo top_left on img_2'],
            ],
            'outputs' => [['kind' => 'image', 'format' => '4:5', 'label' => '4:5', 'path' => 'c/final.jpg', 'source_asset' => 'img_3']],
            'steps' => [['type' => 'tool', 'name' => 'add_logo', 'input' => ['asset_id' => 'img_2', 'position' => 'top_left', 'width' => 0.2]]],
        ]);

        // Only the owner, only with a request.
        $this->actingAs(User::factory()->create())->postJson("/api/v1/creations/{$source->public_id}/revise", ['request' => 'үнэ 129,000₮'])->assertNotFound();
        $this->actingAs($this->user)->postJson("/api/v1/creations/{$source->public_id}/revise", ['request' => ''])->assertUnprocessable();

        $id = $this->actingAs($this->user)->postJson("/api/v1/creations/{$source->public_id}/revise", ['request' => 'Үнийг 129,000₮ болго'])
            ->assertStatus(202)->assertJsonPath('data.parent_id', $source->public_id)->assertJsonPath('data.revision', 'Үнийг 129,000₮ болго')
            ->assertJsonCount(0, 'data.inputs') // the previous poster is internal
            ->json('data.id');
        $rev = Creation::where('public_id', $id)->first();
        $this->assertSame($source->id, $rev->parent_id);
        $this->assertSame('previous', $rev->inputs[0]['role']);
        $this->assertEquals(['img_1', '4:5', 0], [$rev->assets[0]['id'], $rev->assets[0]['revision_of'], $rev->assets[0]['cost']]);
        $this->assertSame('clean', Storage::disk('public')->get($rev->assets[0]['path']));
        Queue::assertPushed(RunCreation::class);

        // The brief carries the request, the clean visual, the previous items and the logo placement — and no new map.
        $brief = json_encode((fn () => $this->brief($rev))->call(app(CreativeAgent::class)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('## Revision request', $brief);
        $this->assertStringContainsString('Үнийг 129,000₮ болго', $brief);
        $this->assertStringContainsString('clean visual without text = `img_1`', $brief);
        $this->assertStringContainsString('135,000₮', $brief);
        $this->assertStringContainsString('top_left', $brief);
        $this->assertStringNotContainsString('Composition for this poster', $brief);

        // Reels and unfinished posters cannot be revised.
        $running = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'running']);
        $this->actingAs($this->user)->postJson("/api/v1/creations/{$running->public_id}/revise", ['request' => 'өөрчил'])->assertStatus(409);
    }

    public function test_reels_are_closed_until_enabled(): void
    {
        Queue::fake();
        config(['creations.reels_enabled' => false]);

        $this->actingAs($this->user)->postJson('/api/v1/creations', ['type' => 'reel', 'formats' => ['9:16'], 'duration' => 15, 'prompt' => 'Кофе шопын reels видео'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson('/api/v1/meta')->assertJsonPath('reels_enabled', false);

        $old = Creation::create(['user_id' => $this->user->id, 'type' => 'reel', 'formats' => ['9:16'], 'duration' => 15, 'prompt' => 'x', 'status' => 'failed']);
        $this->actingAs($this->user)->postJson("/api/v1/creations/{$old->public_id}/retry")->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_a_google_block_page_pauses_the_image_model_and_raw_uploads_are_not_delivered(): void
    {
        $ai = app(\App\Services\AI\AiManager::class);
        $this->assertTrue($ai->noteFailure('gemini', 'Gemini: <html><head><title>Sorry...</title></head><body>your computer or network may be sending automated queries</body></html>'));
        $this->assertTrue($ai->paused('gemini'));
        \Illuminate\Support\Facades\Cache::flush();

        Storage::disk('public')->put('in/chair.png', 'x');
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->turn([['deliver_poster', ['format' => '4:5', 'image_id' => 'product_1', 'design' => []]]]))
            ->push($this->turn([['finish', ['summary' => 'no visual']]]))]);
        $creation = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'running',
            'inputs' => [['id' => 'product_1', 'kind' => 'image', 'role' => 'product', 'source' => 'upload', 'path' => 'in/chair.png']]]);
        try {
            app(CreativeAgent::class)->run($creation);
        } catch (\Throwable) {
            // finishing without a poster fails the job (and refunds it)
        }
        $step = collect($creation->fresh()->steps)->firstWhere('name', 'deliver_poster');
        $this->assertStringContainsString('raw upload', $step['error']);
        $this->assertSame([], $creation->fresh()->outputs);
    }

    public function test_posters_running_together_get_different_composition_maps(): void
    {
        $maps = [];
        foreach (range(1, 6) as $k) {
            $c = Creation::create(['user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['4:5'], 'prompt' => 'x', 'status' => 'running']);
            $maps[] = (fn () => $this->mapFor($c))->call(app(CreativeAgent::class));
        }
        $this->assertCount(6, array_unique($maps)); // each running job reserves its map
    }

    public function test_admin_sees_internal_log(): void
    {
        $creation = Creation::create([
            'user_id' => $this->user->id, 'type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'x', 'status' => 'done',
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
