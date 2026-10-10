<?php

namespace Tests\Feature;

use App\Models\Creation;
use App\Models\Skill;
use App\Models\User;
use App\Services\Agent\CreativeAgent;
use App\Services\Agent\SkillLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkillTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundled_skills_are_parsed_from_frontmatter(): void
    {
        $library = app(SkillLibrary::class);
        $names = array_column($library->index(), 'name');

        $this->assertContains('poster-design', $names);
        $this->assertContains('video-prompting', $names);
        $this->assertStringStartsWith('# Poster design', $library->get('poster-design'));
        $this->assertStringNotContainsString('---', substr($library->get('poster-design'), 0, 5));
        $this->assertNull($library->get('../creative-director'));
    }

    public function test_custom_skills_crud_and_precedence(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/skills')->assertForbidden();
        $this->actingAs($admin);

        $this->postJson('/api/v1/admin/skills', ['name' => 'poster-design', 'description' => 'x', 'content' => 'y'])
            ->assertStatus(422); // bundled name is reserved

        $id = $this->postJson('/api/v1/admin/skills', ['name' => 'my-brand', 'description' => 'Our brand rules', 'content' => "# Brand\nUse navy."])
            ->assertCreated()
            ->json('id');

        $this->getJson('/api/v1/admin/skills')->assertJsonFragment(['name' => 'my-brand', 'source' => 'custom']);
        $this->getJson('/api/v1/admin/skills/my-brand')->assertJsonPath('content', "# Brand\nUse navy.");

        $this->putJson("/api/v1/admin/skills/{$id}", ['name' => 'my-brand', 'description' => 'Our brand rules', 'content' => 'z', 'enabled' => false])->assertOk();
        $this->assertStringNotContainsString('my-brand', app(SkillLibrary::class)->prompt());
        $this->getJson('/api/v1/admin/skills/my-brand')->assertNotFound();

        $this->deleteJson("/api/v1/admin/skills/{$id}")->assertNoContent();
        $this->assertSame(0, Skill::count());
    }

    public function test_agent_lists_skills_and_loads_them_on_demand(): void
    {
        config(['ai.providers.anthropic.key' => 'k', 'ai.providers.openai.key' => 'k']);
        Skill::create(['name' => 'my-brand', 'description' => 'Brand rules', 'content' => 'Always use navy #001f3f.']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['stop_reason' => 'tool_use', 'usage' => [], 'content' => [
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'load_skill', 'input' => ['name' => 'my-brand']],
                    ['type' => 'tool_use', 'id' => 't2', 'name' => 'load_skill', 'input' => ['name' => 'nope']],
                ]])
                ->push(['stop_reason' => 'tool_use', 'usage' => [], 'content' => [
                    ['type' => 'tool_use', 'id' => 't3', 'name' => 'finish', 'input' => ['summary' => 'ok']],
                ]]),
        ]);

        $user = User::factory()->create();
        $run = Creation::create(['user_id' => $user->id, 'type' => 'poster', 'formats' => ['feed_square'], 'prompt' => 'x', 'status' => 'running']);
        app(CreativeAgent::class)->run($run);
        $run->refresh();

        $this->assertSame('my-brand', $run->steps[0]['result']);
        $this->assertSame('Unknown skill [nope].', $run->steps[1]['error']);

        Http::assertSent(function (Request $r) {
            $system = $r['system'][0]['text'];
            $tool = collect($r['tools'])->firstWhere('name', 'load_skill');

            return str_contains($system, '`my-brand` *(custom)* — Brand rules')
                && str_contains($system, '`poster-design`')
                && in_array('my-brand', $tool['input_schema']['properties']['name']['enum'], true);
        });

        Http::assertSent(fn (Request $r) => count($r['messages']) === 3
            && str_contains($r['messages'][2]['content'][0]['content'], 'Always use navy #001f3f.'));
    }
}
