<?php

namespace Tests\Feature;

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.anthropic.key' => 'test-anthropic',
            'ai.providers.openai.key' => 'test-openai',
            'ai.providers.gemini.key' => 'test-gemini',
        ]);
    }

    private function poster(array $overrides = []): array
    {
        return array_merge([
            'prompt' => 'Кофе шопын нээлт',
            'language' => 'mn',
            'style' => 'Минимал',
            'format' => '4:5',
            'text_provider' => 'anthropic',
            'image_provider' => 'openai',
        ], $overrides);
    }

    public function test_config_lists_providers_without_keys(): void
    {
        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('default_text', 'anthropic')
            ->assertJsonMissing(['key' => 'test-anthropic']);
    }

    public function test_poster_with_claude(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => "```json\n".json_encode([
                    'headline' => 'Нээлт!',
                    'cta' => 'Ирээрэй',
                    'layout' => 'nonsense',
                    'palette' => ['primary' => '#ff0000', 'text' => 'red'],
                    'hashtags' => ['#coffee'],
                    'image_prompt' => 'coffee shop',
                ])."\n```"]],
            ]),
        ]);

        $this->postJson('/api/generate/poster', $this->poster())
            ->assertCreated()
            ->assertJsonPath('content.headline', 'Нээлт!')
            ->assertJsonPath('content.layout', 'center')
            ->assertJsonPath('content.palette.primary', '#ff0000')
            ->assertJsonPath('content.palette.text', '#ffffff');

        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-fable-5-1'
            && $r->hasHeader('x-api-key', 'test-anthropic')
            && str_contains($r['messages'][0]['content'], 'Mongolian'));

        $this->assertSame(1, Generation::count());
    }

    public function test_reel_with_gemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'title' => 'Fitness',
                    'scenes' => [
                        ['duration' => 3, 'text' => 'Hook', 'motion' => 'zoom-in', 'image_prompt' => 'gym'],
                        ['duration' => 99, 'text' => 'CTA', 'motion' => 'spin'],
                    ],
                ])]]]]],
            ]),
        ]);

        $this->postJson('/api/generate/reel', $this->poster([
            'text_provider' => 'gemini',
            'duration' => 15,
            'scenes' => 2,
        ]))
            ->assertCreated()
            ->assertJsonCount(2, 'content.scenes')
            ->assertJsonPath('content.scenes.1.duration', 10)
            ->assertJsonPath('content.scenes.1.motion', 'zoom-in');
    }

    public function test_poster_with_openai_text(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"headline":"Hello"}']]],
            ]),
        ]);

        $this->postJson('/api/generate/poster', $this->poster(['text_provider' => 'openai', 'language' => 'en']))
            ->assertCreated()
            ->assertJsonPath('content.headline', 'Hello');
    }

    public function test_image_generation_is_stored(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('PNGDATA')]]]),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('JPG')]]]]]],
            ]),
        ]);

        $url = $this->postJson('/api/images', ['prompt' => 'coffee', 'aspect' => '9:16', 'provider' => 'openai'])
            ->assertOk()
            ->json('url');
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $url));

        $url = $this->postJson('/api/images', ['prompt' => 'coffee', 'aspect' => '9:16', 'provider' => 'gemini'])
            ->assertOk()
            ->json('url');
        $this->assertStringEndsWith('.jpg', $url);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'images/generations') && $r['size'] === '1024x1536');
    }

    public function test_provider_errors_are_returned_as_json(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $this->postJson('/api/generate/poster', $this->poster())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Claude: invalid x-api-key');
    }

    public function test_missing_key_is_reported(): void
    {
        config(['ai.providers.gemini.key' => null]);

        $this->postJson('/api/generate/poster', $this->poster(['text_provider' => 'gemini']))
            ->assertStatus(502);
    }

    public function test_demo_mode_and_crud(): void
    {
        $id = $this->postJson('/api/generate/poster', $this->poster(['text_provider' => 'demo', 'image_provider' => 'demo']))
            ->assertCreated()
            ->json('id');

        $this->postJson('/api/images', ['prompt' => 'x', 'aspect' => '1:1', 'provider' => 'demo'])
            ->assertOk()
            ->assertJsonPath('url', null);

        $this->putJson("/api/generations/{$id}", ['content' => ['headline' => 'Edited']])->assertOk();
        $this->getJson('/api/generations')->assertJsonPath('0.content.headline', 'Edited');
        $this->deleteJson("/api/generations/{$id}")->assertNoContent();
    }

    public function test_spa_is_served(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()->assertSee('id="app"', false);
    }
}
