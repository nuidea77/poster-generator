<?php

namespace App\Services\Agent;

use App\Models\Skill;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Anthropic Agent Skills бүтэцтэй skill-үүд: skill бүрт нэг хавтас, YAML
 * frontmatter нь `name`, `description` агуулсан SKILL.md.
 * Bundled skill-үүд resources/ai/skills-д байна; хэрэглэгчид өөрсдийнхөө skill-ийг DB-д нэмнэ.
 * Bundled skill-тэй ижил нэртэй DB мөр нь админы засвар (override):
 * reset хийтэл файлын текстийг орлоно.
 */
class SkillLibrary
{
    /**
     * Bundled ба custom skill-үүдийн жагсаалт (админы засварыг тусгасан).
     *
     * @return array<int, array{name: string, description: string, origin: ?string, source: string, enabled: bool, overridden: bool, id?: int}>
     */
    public function index(bool $enabledOnly = false): array
    {
        $rows = Schema::hasTable('skills') ? Skill::orderBy('name')->get()->keyBy('name') : collect();
        $skills = [];

        // Bundled skill-ийн DB мөрийг (override) $rows-оос гаргаж авна; үлдсэн нь custom.
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
     * Agent-ийн ачаалах текст; skill үл мэдэгдэх эсвэл унтраастай бол null.
     */
    public function get(string $name): ?string
    {
        $row = Schema::hasTable('skills') ? Skill::where('name', $name)->first() : null;

        // DB мөр (override эсвэл custom) файлаас давуу эрхтэй.
        if ($row) {
            return $row->enabled ? $row->content : null;
        }

        return $this->bundled()[$name]['body'] ?? null;
    }

    /**
     * Bundled skill-ийн references/ дотор хадгалдаг лавлах файлууд (хэрэгтэй үед ачаална).
     *
     * @return list<string>
     */
    public function references(string $name): array
    {
        return isset($this->bundled()[$name])
            ? array_map('basename', File::glob(resource_path("ai/skills/{$name}/references/*.md")))
            : [];
    }

    /** Идэвхтэй skill-ийн нэг лавлах файл, эсвэл null. */
    public function reference(string $name, string $file): ?string
    {
        $file = basename(str_replace('\\', '/', $file)); // skill-ийн хавтсаас хэзээ ч гарахгүй
        if ($this->get($name) === null || ! in_array($file, $this->references($name), true)) {
            return null;
        }

        return File::get(resource_path("ai/skills/{$name}/references/{$file}"));
    }

    /**
     * Админы засварлагчид зориулсан текст, унтраастай skill-д ч бас.
     *
     * @return array{content: string, original: ?string}|null original = bundled файлын текст
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
     * resources/ai/skills доторх бүх SKILL.md-ийг задлан уншина.
     *
     * @return array<string, array{name: string, description: string, origin: string, body: string}> нэрээр
     */
    public function bundled(): array
    {
        $skills = [];

        // Frontmatter-т name байхгүй бол хавтасны нэрийг ашиглана.
        foreach (File::glob(resource_path('ai/skills/*/SKILL.md')) as $path) {
            $parsed = self::parse(File::get($path));
            $skills[$parsed['name'] ?: basename(dirname($path))] = $parsed;
        }

        return $skills;
    }

    /**
     * System prompt-д зориулсан markdown жагсаалт: нэр — тайлбар.
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
     * YAML маягийн frontmatter-ийг (name, description, origin) markdown биеэс салгана.
     *
     * @return array{name: string, description: string, origin: string, body: string}
     */
    public static function parse(string $markdown): array
    {
        $name = $description = $origin = '';
        $markdown = str_replace("\r\n", "\n", $markdown);
        $body = $markdown;

        // Файлын эхэнд --- ... --- хүрээтэй frontmatter байвал задлана.
        if (preg_match('/\A---\s*\n(.*?)\n---\s*\n(.*)\z/s', $markdown, $m)) {
            $body = $m[2];
            // Зөвхөн name/description/origin мөрийг ижил нэртэй хувьсагчид онооно (хашилтыг авна).
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(name|description|origin):\s*(.*)$/', trim($line), $kv)) {
                    ${$kv[1]} = stripcslashes(trim($kv[2], " \"'"));
                }
            }
        }

        return ['name' => $name, 'description' => $description, 'origin' => $origin, 'body' => trim($body)];
    }
}
