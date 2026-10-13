<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Студийн skill-үүдийг (bundled, админы засвар, custom) Claude Agent Skills
 * хэлбэрээр бичнэ: skill бүрт SKILL.md (name + description frontmatter) болон
 * LICENSE бүхий нэг хавтас, мөн claude.ai-д оруулах skill тус бүрийн zip.
 * Нэрэнд угтвар залгадаг тул зарим нь эх болсон Claude skill-үүдтэй
 * хэзээ ч давхцахгүй.
 */
class ClaudeSkillExporter
{
    // Экспортолсон skill-ийн нэрийн угтвар.
    public const PREFIX = 'studio-';

    public function __construct(private SkillLibrary $skills) {}

    /**
     * Идэвхтэй skill бүрийг $to хавтас руу хавтас + zip болгон бичнэ.
     *
     * @return list<array{name: string, dir: string, zip: string}>
     */
    public function export(string $to): array
    {
        File::ensureDirectoryExists($to);
        $skills = $this->skills->index(enabledOnly: true);
        $names = array_column($skills, 'name');
        $written = [];

        foreach ($skills as $skill) {
            // coreyhaines31/marketingskills-ээс өөрчлөлтгүй авсан skill-үүдийг тэр repo-оос
            // шууд суулгадаг; studio- хуулбар нь зөвхөн давхардал үүсгэнэ.
            if (str_contains((string) $skill['origin'], 'marketingskills')) {
                continue;
            }

            $name = self::PREFIX.$skill['name'];
            $dir = "{$to}/{$name}";
            File::deleteDirectory($dir);
            File::ensureDirectoryExists($dir);

            $license = resource_path("ai/skills/{$skill['name']}/LICENSE.txt");
            $hasLicense = $skill['source'] === 'bundled' && File::exists($license);

            // LICENSE нь зөвхөн bundled skill-д байдаг; references/-ийг ч хамт хуулна.
            File::put("{$dir}/SKILL.md", $this->skillMd($name, $skill, $this->skills->source($skill['name'])['content'], $names, $hasLicense));

            if ($hasLicense) {
                File::copy($license, "{$dir}/LICENSE.txt");
            }
            if ($skill['source'] === 'bundled' && File::isDirectory($refs = resource_path("ai/skills/{$skill['name']}/references"))) {
                File::copyDirectory($refs, "{$dir}/references");
            }

            $written[] = ['name' => $name, 'dir' => $dir, 'zip' => $this->zip($dir, "{$to}/{$name}.zip", $name)];
        }

        return $written;
    }

    /**
     * Frontmatter, Poster Studio-гийн тайлбар болон бие бүхий SKILL.md-ийн текстийг угсарна.
     */
    private function skillMd(string $name, array $skill, string $body, array $names, bool $hasLicense): string
    {
        // Бусад skill рүү хийсэн `нэр` холбоосыг экспортолсон (угтвартай) нэр рүү чиглүүлнэ.
        $body = preg_replace_callback('/`('.implode('|', array_map('preg_quote', $names)).')`/', fn ($m) => '`'.self::PREFIX.$m[1].'`', $body);

        $front = ['---', "name: {$name}", 'description: '.$this->yaml($skill['description'])];
        if ($hasLicense) {
            // LICENSE файл "MIT"-ээр эхэлбэл MIT, бусад нь Apache-2.0.
            $spdx = str_starts_with(trim(File::get(resource_path("ai/skills/{$skill['name']}/LICENSE.txt"))), 'MIT') ? 'MIT' : 'Apache-2.0';
            $front[] = "license: {$spdx} (see LICENSE.txt)";
        }
        $front[] = '---';

        $note = '> Written for the Poster Studio creative agent. Tool names (`generate_image`, `generate_videos`, `deliver_poster`, `deliver_reel`, `finish`) and model names (`gemini`, `openai`, `seedance`, `veo`) refer to that pipeline; elsewhere apply the same guidance with whatever image or video tools are available.';
        if ($skill['origin']) {
            $note .= "\n>\n> Source: {$skill['origin']}.";
        }

        return implode("\n", $front)."\n\n{$note}\n\n".trim($body)."\n";
    }

    /** Текстийг нэг мөрөнд шахаж, YAML-ийн давхар хашилттай string болгоно. */
    private function yaml(string $text): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim(preg_replace('/\s+/', ' ', $text))).'"';
    }

    /** Skill-ийн хавтсыг claude.ai-д оруулах zip болгоно; zip-ийн замыг буцаана. */
    private function zip(string $dir, string $path, string $name): string
    {
        File::delete($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        // Файл бүрийг "<нэр>/..." дотор, Windows-ийн \ -ийг / болгон нэмнэ.
        foreach (File::allFiles($dir) as $file) {
            $zip->addFile($file->getPathname(), "{$name}/".str_replace('\\', '/', $file->getRelativePathname()));
        }

        $zip->close();

        return $path;
    }
}
