<?php

namespace App\Services\Agent;

use App\Models\Skill;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Skills in the Anthropic Agent Skills layout: a folder per skill with a
 * SKILL.md whose YAML frontmatter carries `name` and `description`.
 * Bundled skills live in resources/ai/skills; users add their own in the DB.
 */
class SkillLibrary
{
    /**
     * @return array<int, array{name: string, description: string, source: string, enabled: bool, id?: int}>
     */
    public function index(bool $enabledOnly = false): array
    {
        $skills = [];

        foreach (File::glob(resource_path('ai/skills/*/SKILL.md')) as $path) {
            $parsed = self::parse(File::get($path));
            $skills[] = ['name' => $parsed['name'] ?: basename(dirname($path)), 'description' => $parsed['description'], 'source' => 'bundled', 'enabled' => true];
        }

        if (Schema::hasTable('skills')) {
            foreach (Skill::orderBy('name')->get() as $skill) {
                $skills[] = ['id' => $skill->id, 'name' => $skill->name, 'description' => $skill->description, 'source' => 'custom', 'enabled' => $skill->enabled];
            }
        }

        return array_values(array_filter($skills, fn ($s) => ! $enabledOnly || $s['enabled']));
    }

    public function get(string $name): ?string
    {
        $custom = Schema::hasTable('skills') ? Skill::where('name', $name)->where('enabled', true)->first() : null;

        if ($custom) {
            return $custom->content;
        }

        $path = resource_path('ai/skills/'.basename($name).'/SKILL.md');

        return File::exists($path) ? self::parse(File::get($path))['body'] : null;
    }

    /**
     * Markdown list for the system prompt: name — description.
     */
    public function prompt(): string
    {
        $lines = array_map(
            fn ($s) => "- `{$s['name']}`".($s['source'] === 'custom' ? ' *(custom)*' : '')." — {$s['description']}",
            $this->index(enabledOnly: true),
        );

        return $lines ? implode("\n", $lines) : '(no skills available)';
    }

    /**
     * Split YAML-ish frontmatter (name, description) from the markdown body.
     *
     * @return array{name: string, description: string, body: string}
     */
    public static function parse(string $markdown): array
    {
        $name = $description = '';
        $body = $markdown;

        if (preg_match('/\A---\s*\n(.*?)\n---\s*\n(.*)\z/s', $markdown, $m)) {
            $body = $m[2];
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(name|description):\s*(.*)$/', trim($line), $kv)) {
                    ${$kv[1]} = trim($kv[2], " \"'");
                }
            }
        }

        return ['name' => $name, 'description' => $description, 'body' => trim($body)];
    }
}
