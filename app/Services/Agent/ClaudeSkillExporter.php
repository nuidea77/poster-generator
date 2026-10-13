<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Writes the studio's skills (bundled, admin edits and custom ones) as
 * Claude Agent Skills: one folder per skill with a SKILL.md (name +
 * description frontmatter) and its LICENSE, plus a zip per skill for
 * uploading to claude.ai. Names get a prefix so they never clash with the
 * Claude skills some of them were adapted from.
 */
class ClaudeSkillExporter
{
    public const PREFIX = 'studio-';

    public function __construct(private SkillLibrary $skills) {}

    /**
     * @return list<array{name: string, dir: string, zip: string}>
     */
    public function export(string $to): array
    {
        File::ensureDirectoryExists($to);
        $skills = $this->skills->index(enabledOnly: true);
        $names = array_column($skills, 'name');
        $written = [];

        foreach ($skills as $skill) {
            // Skills taken unchanged from coreyhaines31/marketingskills are installed from
            // that repo itself; a studio- copy would only duplicate them.
            if (str_contains((string) $skill['origin'], 'marketingskills')) {
                continue;
            }

            $name = self::PREFIX.$skill['name'];
            $dir = "{$to}/{$name}";
            File::deleteDirectory($dir);
            File::ensureDirectoryExists($dir);

            $license = resource_path("ai/skills/{$skill['name']}/LICENSE.txt");
            $hasLicense = $skill['source'] === 'bundled' && File::exists($license);

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

    private function skillMd(string $name, array $skill, string $body, array $names, bool $hasLicense): string
    {
        // Point cross-references at the exported names.
        $body = preg_replace_callback('/`('.implode('|', array_map('preg_quote', $names)).')`/', fn ($m) => '`'.self::PREFIX.$m[1].'`', $body);

        $front = ['---', "name: {$name}", 'description: '.$this->yaml($skill['description'])];
        if ($hasLicense) {
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

    private function yaml(string $text): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim(preg_replace('/\s+/', ' ', $text))).'"';
    }

    private function zip(string $dir, string $path, string $name): string
    {
        File::delete($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach (File::allFiles($dir) as $file) {
            $zip->addFile($file->getPathname(), "{$name}/".str_replace('\\', '/', $file->getRelativePathname()));
        }

        $zip->close();

        return $path;
    }
}
