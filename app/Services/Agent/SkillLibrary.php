<?php

namespace App\Services\Agent;

use App\Models\Skill;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Skills in the Anthropic Agent Skills layout: a folder per skill with a
 * SKILL.md whose YAML frontmatter carries `name` and `description`.
 * Bundled skills live in resources/ai/skills; users add their own in the DB.
 * A DB row with a bundled skill's name is an admin edit of it (an override):
 * it replaces the file's text until it is reset.
 */
class SkillLibrary
{
    /**
     * @return array<int, array{name: string, description: string, origin: ?string, source: string, enabled: bool, overridden: bool, id?: int}>
     */
    public function index(bool $enabledOnly = false): array
    {
        $rows = Schema::hasTable('skills') ? Skill::orderBy('name')->get()->keyBy('name') : collect();
        $skills = [];

        foreach ($this->bundled() as $name => $parsed) {
            $edit = $rows->pull($name);
            $skills[] = [
                ...($edit ? ['id' => $edit->id] : []),
                'name' => $name,
                'description' => $edit->description ?? $parsed['description'],
                'origin' => $parsed['origin'] ?: null,
                'source' => 'bundled',
                'enabled' => $edit->enabled ?? true,
                'overridden' => (bool) $edit,
            ];
        }

        foreach ($rows as $skill) {
            $skills[] = ['id' => $skill->id, 'name' => $skill->name, 'description' => $skill->description, 'origin' => $skill->origin, 'source' => 'custom', 'enabled' => $skill->enabled, 'overridden' => false];
        }

        return array_values(array_filter($skills, fn ($s) => ! $enabledOnly || $s['enabled']));
    }

    /**
     * Text the agent loads; null when the skill is unknown or switched off.
     */
    public function get(string $name): ?string
    {
        $row = Schema::hasTable('skills') ? Skill::where('name', $name)->first() : null;

        if ($row) {
            return $row->enabled ? $row->content : null;
        }

        return $this->bundled()[$name]['body'] ?? null;
    }

    /**
     * Text for the admin editor, also for skills that are switched off.
     *
     * @return array{content: string, original: ?string}|null original = the bundled file's text
     */
    public function source(string $name): ?array
    {
        $row = Schema::hasTable('skills') ? Skill::where('name', $name)->first() : null;
        $original = $this->bundled()[$name]['body'] ?? null;

        if (! $row && $original === null) {
            return null;
        }

        return ['content' => $row->content ?? $original, 'original' => $original];
    }

    /**
     * @return array<string, array{name: string, description: string, origin: string, body: string}> by name
     */
    public function bundled(): array
    {
        $skills = [];

        foreach (File::glob(resource_path('ai/skills/*/SKILL.md')) as $path) {
            $parsed = self::parse(File::get($path));
            $skills[$parsed['name'] ?: basename(dirname($path))] = $parsed;
        }

        return $skills;
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
     * Split YAML-ish frontmatter (name, description, origin) from the markdown body.
     *
     * @return array{name: string, description: string, origin: string, body: string}
     */
    public static function parse(string $markdown): array
    {
        $name = $description = $origin = '';
        $markdown = str_replace("\r\n", "\n", $markdown);
        $body = $markdown;

        if (preg_match('/\A---\s*\n(.*?)\n---\s*\n(.*)\z/s', $markdown, $m)) {
            $body = $m[2];
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(name|description|origin):\s*(.*)$/', trim($line), $kv)) {
                    ${$kv[1]} = stripcslashes(trim($kv[2], " \"'"));
                }
            }
        }

        return ['name' => $name, 'description' => $description, 'origin' => $origin, 'body' => trim($body)];
    }
}
