<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Skill;
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
        $this->postJson('/api/skills', ['name' => 'poster-design', 'description' => 'x', 'content' => 'y'])
            ->assertStatus(422); // bundled name is reserved

        $id = $this->postJson('/api/skills', ['name' => 'my-brand', 'description' => 'Our brand rules', 'content' => "# Brand\nUse navy."])
            ->assertCreated()
            ->json('id');

        $this->getJson('/api/skills')->assertJsonFragment(['name' => 'my-brand', 'source' => 'custom']);
        $this->getJson('/api/skills/my-brand')->assertJsonPath('content', "# Brand\nUse navy.");

        $this->putJson("/api/skills/{$id}", ['name' => 'my-brand', 'description' => 'Our brand rules', 'content' => 'z', 'enabled' => false])->assertOk();
        $this->assertStringNotContainsString('my-brand', app(SkillLibrary::class)->prompt());
        $this->getJson('/api/skills/my-brand')->assertNotFound();

        $this->deleteJson("/api/skills/{$id}")->assertNoContent();
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

        $run = AgentRun::create(['prompt' => 'x', 'language' => 'en']);
        app(CreativeAgent::class)->run($run);
        $run->refresh();

        $this->assertSame('done', $run->status);
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
