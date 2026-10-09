<?php

namespace Tests\Feature;

use App\Jobs\RunCreativeAgent;
use App\Models\AgentRun;
use App\Models\Generation;
use App\Services\Agent\CreativeAgent;
use App\Services\AI\AiManager;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'ai.providers.anthropic.key' => 'test-anthropic',
            'ai.providers.openai.key' => 'test-openai',
            'ai.providers.gemini.key' => 'test-gemini',
            'ai.providers.seedance.key' => 'test-seedance',
            'ai.providers.seedance.poll_interval' => 0,
        ]);
    }

    private function claudeTurn(array $toolCalls, string $stop = 'tool_use', string $text = ''): array
    {
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']];
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($toolCalls as $i => [$name, $input]) {
            $content[] = ['type' => 'tool_use', 'id' => "toolu_{$name}_{$i}", 'name' => $name, 'input' => $input];
        }

        return ['model' => 'claude-fable-5-1', 'stop_reason' => $stop, 'content' => $content, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    public function test_store_uploads_images_and_queues_the_job(): void
    {
        Queue::fake();

        $this->post('/api/agent-runs', [
            'prompt' => 'Постер хий',
            'language' => 'mn',
            'images' => [UploadedFile::fake()->image('product.jpg', 400, 400)],
            'notes' => ['бүтээгдэхүүн'],
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('assets.0.id', 'img_u1')
            ->assertJsonPath('assets.0.note', 'бүтээгдэхүүн');

        Queue::assertPushed(RunCreativeAgent::class);
    }

    public function test_store_requires_claude_key(): void
    {
        config(['ai.providers.anthropic.key' => null]);

        $this->postJson('/api/agent-runs', ['prompt' => 'x', 'language' => 'mn'])->assertStatus(422);
    }

    public function test_agent_loop_picks_models_and_builds_outputs(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->claudeTurn([
                    ['generate_image', ['provider' => 'gemini', 'prompt' => 'product on marble', 'aspect' => '4:5', 'reference_image_ids' => ['img_u1'], 'purpose' => 'poster bg']],
                    ['generate_video', ['provider' => 'seedance', 'prompt' => 'slow dolly', 'aspect' => '9:16', 'duration' => 5, 'first_frame_image_id' => '', 'purpose' => 'reel clip']],
                ], text: 'Using Gemini to keep the product faithful.'))
                ->push($this->claudeTurn([
                    ['create_poster', [
                        'image_id' => 'img_1', 'format' => '4:5', 'layout' => 'bottom', 'font' => 'bold',
                        'tagline' => 'ШИНЭ', 'headline' => 'Нээлт', 'subheadline' => 'x', 'body' => '', 'cta' => 'Ирээрэй',
                        'palette' => ['background' => '#000', 'primary' => '#f00', 'accent' => '#ff0', 'text' => '#fff'],
                        'caption' => 'cap', 'hashtags' => ['#a'],
                    ]],
                    ['create_reel', [
                        'title' => 'Reel', 'hook' => 'Hook!', 'caption' => 'c', 'hashtags' => [], 'music_mood' => 'upbeat', 'font' => 'bold',
                        'palette' => ['background' => '#000', 'primary' => '#f00', 'accent' => '#ff0', 'text' => '#fff'],
                        'scenes' => [
                            ['asset_id' => 'vid_2', 'duration' => 5, 'text' => 'Hook!', 'subtext' => '', 'voiceover' => 'v', 'motion' => 'zoom-in'],
                            ['asset_id' => 'img_1', 'duration' => 4, 'text' => 'CTA', 'subtext' => '', 'voiceover' => 'v', 'motion' => 'pan-left'],
                        ],
                    ]],
                ]))
                ->push($this->claudeTurn([['finish', ['summary' => 'Бэлэн боллоо.']]])),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(self::png())]]]]]],
            ]),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks' => Http::response(['id' => 'cgt-1']),
            'ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks/cgt-1' => Http::sequence()
                ->push(['id' => 'cgt-1', 'status' => 'running'])
                ->push(['id' => 'cgt-1', 'status' => 'succeeded', 'content' => ['video_url' => 'https://cdn.example/clip.mp4']]),
            'cdn.example/clip.mp4' => Http::response('MP4DATA'),
        ]);

        $run = AgentRun::create(['prompt' => 'Постер ба reels хий', 'language' => 'mn']);
        Storage::disk('public')->put('uploads/p.png', self::png());
        $run->addAsset(['id' => 'img_u1', 'kind' => 'image', 'url' => '/storage/uploads/p.png', 'source' => 'upload', 'note' => 'product']);

        app(CreativeAgent::class)->run($run);
        $run->refresh();

        $this->assertSame('done', $run->status);
        $this->assertSame('Бэлэн боллоо.', $run->summary);
        $this->assertCount(3, $run->assets);
        $this->assertSame('gemini', $run->asset('img_1')['provider']);
        $this->assertSame('video', $run->asset('vid_2')['kind']);
        $this->assertStringEndsWith('.mp4', $run->asset('vid_2')['url']);
        $this->assertSame(['poster', 'reel'], array_column($run->outputs, 'type'));
        $this->assertSame(30, $run->input_tokens);

        $poster = Generation::where('type', 'poster')->first();
        $this->assertSame('Нээлт', $poster->content['headline']);
        $this->assertSame($run->asset('img_1')['url'], $poster->content['image_url']);

        $reel = Generation::where('type', 'reel')->first();
        $this->assertSame($run->asset('vid_2')['url'], $reel->content['scenes'][0]['video_url']);
        $this->assertSame($run->asset('img_1')['url'], $reel->content['scenes'][1]['image_url']);

        $types = array_column($run->steps, 'type');
        $this->assertSame('note', $types[0]);
        $this->assertSame(['generate_image', 'generate_video', 'create_poster', 'create_reel', 'finish'], array_column(array_filter($run->steps, fn ($s) => $s['type'] === 'tool'), 'name'));

        // Claude request shape: Fable with effort, strict tools, fallbacks, cached skill, reference image sent as vision input.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'anthropic.com')) {
                return false;
            }

            return $r['model'] === 'claude-fable-5-1'
                && ! array_key_exists('thinking', $r->data())
                && $r['output_config']['effort'] === 'high'
                && $r['fallbacks'] === 'default'
                && $r->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
                && $r['system'][0]['cache_control']['type'] === 'ephemeral'
                && collect($r['tools'])->every(fn ($t) => $t['strict'] === true)
                && collect($r['messages'][0]['content'])->contains(fn ($b) => $b['type'] === 'image');
        });

        // Gemini got the reference image; Seedance got the duration flag and ratio.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis') && isset($r['contents'][0]['parts'][0]['inlineData']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generations/tasks') && $r->method() === 'POST'
            && str_contains($r['content'][0]['text'], '--duration 5 --ratio 9:16'));
    }

    public function test_tool_errors_are_returned_to_claude_and_refusals_fail_the_run(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->claudeTurn([['create_poster', ['image_id' => 'nope', 'format' => '1:1', 'layout' => 'center', 'font' => 'modern', 'tagline' => '', 'headline' => 'h', 'subheadline' => '', 'body' => '', 'cta' => 'c', 'palette' => [], 'caption' => '', 'hashtags' => []]]]))
                ->push(['model' => 'claude-fable-5-1', 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => 'nope'], 'content' => [], 'usage' => []]),
        ]);

        $run = AgentRun::create(['prompt' => 'x', 'language' => 'en']);

        try {
            app(CreativeAgent::class)->run($run);
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertStringContainsString('declined', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Unknown asset [nope].', $run->steps[0]['error']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic.com')
            && count($r['messages']) === 3
            && ($r['messages'][2]['content'][0]['is_error'] ?? false) === true);
    }

    public function test_video_tool_is_hidden_without_seedance_key(): void
    {
        config(['ai.providers.seedance.key' => null]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeTurn([['finish', ['summary' => 'ok']]]))]);

        app(CreativeAgent::class)->run(AgentRun::create(['prompt' => 'x', 'language' => 'en']));

        Http::assertSent(fn (Request $r) => ! collect($r['tools'])->contains('name', 'generate_video')
            && collect($r['tools'])->contains('name', 'generate_image'));
    }

    public function test_openai_uses_edits_endpoint_with_references(): void
    {
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode('PNG')]]])]);

        app(AiManager::class)->image('openai')->generateImage('x', '1:1', [['data' => self::png(), 'mime' => 'image/png']]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'images/edits') && $r->isMultipart());
    }

    private static function png(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }
}
