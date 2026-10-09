<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Generation;
use App\Services\AI\AiManager;
use App\Services\AI\Exceptions\AiException;
use App\Services\ContentGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Claude Fable drives an agentic loop: it reads the brief and attached
 * images, chooses which image / video model to call for each asset, checks
 * the results, and assembles posters and reels through tools.
 */
class CreativeAgent
{
    public const ASPECTS = ['1:1', '4:5', '9:16', '16:9'];

    public function __construct(
        private AiManager $ai,
        private ContentGenerator $generator,
        private SkillLibrary $skills,
    ) {}

    public function run(AgentRun $run): void
    {
        $run->update(['status' => 'running', 'error' => null, 'model' => config('ai.agent.model')]);

        try {
            $this->loop($run);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function loop(AgentRun $run): void
    {
        $tools = $this->tools();
        $messages = [['role' => 'user', 'content' => $this->userContent($run)]];

        for ($step = 0; $step < config('ai.agent.max_steps'); $step++) {
            $response = $this->claude($tools, $messages);

            $run->increment('input_tokens', (int) ($response['usage']['input_tokens'] ?? 0));
            $run->increment('output_tokens', (int) ($response['usage']['output_tokens'] ?? 0));

            if (($response['stop_reason'] ?? null) === 'refusal') {
                $why = $response['stop_details']['explanation'] ?? 'The request was declined by safety filters.';
                throw new AiException('Claude declined this brief: '.$why);
            }

            // Pass the full content (incl. thinking blocks) back unchanged.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            $results = [];
            $finished = false;

            foreach ($response['content'] as $block) {
                if ($block['type'] === 'text' && trim($block['text']) !== '') {
                    $run->addStep(['type' => 'note', 'text' => $block['text']]);
                }

                if ($block['type'] !== 'tool_use') {
                    continue;
                }

                $input = is_array($block['input']) ? $block['input'] : [];

                try {
                    $result = $this->call($run, $block['name'], $input);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $result['content']];
                    $run->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'result' => $result['summary']]);
                } catch (AiException|ConnectionException $e) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => $e->getMessage()];
                    $run->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'error' => $e->getMessage()]);
                }

                if ($block['name'] === 'finish') {
                    $finished = true;
                }
            }

            if ($finished) {
                $run->update(['status' => 'done']);

                return;
            }

            if (($response['stop_reason'] ?? null) !== 'tool_use') {
                // Model ended its turn without calling finish; treat as done.
                $run->update(['status' => 'done', 'summary' => $run->summary ?: $this->lastText($response)]);

                return;
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new AiException('Agent stopped: too many steps without finishing.');
    }

    // ---------------------------------------------------------------- Claude

    private function claude(array $tools, array $messages): array
    {
        $cfg = config('ai.providers.anthropic');
        $agent = config('ai.agent');

        if (blank($cfg['key'])) {
            throw new AiException('ANTHROPIC_API_KEY is not set — the agent needs Claude.');
        }

        $headers = ['x-api-key' => $cfg['key'], 'anthropic-version' => '2023-06-01'];
        $body = [
            'model' => $agent['model'],
            'max_tokens' => 16000,
            'system' => [[
                'type' => 'text',
                'text' => $this->skill(),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools' => $tools,
            'output_config' => ['effort' => $agent['effort']],
            'messages' => $messages,
        ];

        // Fable's thinking is always on; depth is controlled with effort above.
        // Server-side fallback re-runs a declined request on another model.
        if ($agent['fallbacks']) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        $response = Http::timeout($agent['timeout'])
            ->withHeaders($headers)
            ->post(rtrim($cfg['base_url'], '/').'/v1/messages', $body);

        if ($response->failed()) {
            throw new AiException('Claude: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json();
    }

    private function skill(): string
    {
        return str_replace('{{SKILLS}}', $this->skills->prompt(), file_get_contents(resource_path('ai/creative-director.md')));
    }

    private function userContent(AgentRun $run): array
    {
        $content = [];

        foreach ($run->assets as $asset) {
            if ($asset['kind'] !== 'image') {
                continue;
            }
            $content[] = ['type' => 'text', 'text' => "Attached reference image `{$asset['id']}`".(filled($asset['note'] ?? null) ? " ({$asset['note']})" : '').':'];
            $content[] = $this->imageBlock($asset);
        }

        $language = ContentGenerator::LANGUAGES[$run->language] ?? 'Mongolian (Cyrillic script)';
        $content[] = ['type' => 'text', 'text' => "Brief:\n{$run->prompt}\n\nClient-facing language: {$language}."];

        return $content;
    }

    // ----------------------------------------------------------------- tools

    private function tools(): array
    {
        $imageProviders = array_values(array_filter(['gemini', 'openai'], fn ($p) => $this->ai->isConfigured($p)));
        $videoProviders = array_values(array_filter(AiManager::VIDEO_PROVIDERS, fn ($p) => $this->ai->isConfigured($p)));

        $palette = [
            'type' => 'object',
            'properties' => array_fill_keys(['background', 'primary', 'accent', 'text'], ['type' => 'string', 'description' => 'Hex colour, e.g. #0f172a']),
            'required' => ['background', 'primary', 'accent', 'text'],
            'additionalProperties' => false,
        ];

        $tools = [];

        if ($imageProviders) {
            $tools[] = $this->tool('generate_image',
                'Generate one image with the chosen image model. Returns the image (look at it) and its asset id. Use reference_image_ids to keep real products/people/logos faithful.',
                [
                    'provider' => ['type' => 'string', 'enum' => $imageProviders],
                    'prompt' => ['type' => 'string', 'description' => 'English visual prompt. No text/letters/logos in the image.'],
                    'aspect' => ['type' => 'string', 'enum' => self::ASPECTS],
                    'reference_image_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Asset ids of images to use as references (may be empty).'],
                    'purpose' => ['type' => 'string', 'description' => 'Short note on what this image is for (shown to the client).'],
                ]);
        }

        if ($videoProviders) {
            $tools[] = $this->tool('generate_video',
                'Generate a short video clip (5 or 10 seconds). Pass first_frame_image_id to animate an existing image; empty string for text-to-video. Takes a few minutes.',
                [
                    'provider' => ['type' => 'string', 'enum' => $videoProviders],
                    'prompt' => ['type' => 'string', 'description' => 'English prompt describing subject and camera motion.'],
                    'aspect' => ['type' => 'string', 'enum' => ['9:16', '16:9', '1:1']],
                    'duration' => ['type' => 'integer', 'enum' => [5, 10]],
                    'first_frame_image_id' => ['type' => 'string', 'description' => 'Asset id of the image to animate, or "".'],
                    'purpose' => ['type' => 'string'],
                ]);
        }

        $tools[] = $this->tool('create_poster',
            'Create a finished poster from an image asset plus copy. Opens in the client\'s poster editor.',
            [
                'image_id' => ['type' => 'string', 'description' => 'Asset id of the background image.'],
                'format' => ['type' => 'string', 'enum' => self::ASPECTS],
                'layout' => ['type' => 'string', 'enum' => ContentGenerator::LAYOUTS],
                'font' => ['type' => 'string', 'enum' => ContentGenerator::FONTS],
                'tagline' => ['type' => 'string', 'description' => 'Max 3 words, may be empty.'],
                'headline' => ['type' => 'string', 'description' => 'Max 7 words.'],
                'subheadline' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'One or two short sentences, may be empty.'],
                'cta' => ['type' => 'string'],
                'palette' => $palette,
                'caption' => ['type' => 'string', 'description' => 'Social media caption.'],
                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]);

        $tools[] = $this->tool('create_reel',
            'Create a finished 9:16 reel storyboard. Each scene references an image or video asset id.',
            [
                'title' => ['type' => 'string'],
                'hook' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'music_mood' => ['type' => 'string'],
                'font' => ['type' => 'string', 'enum' => ContentGenerator::FONTS],
                'palette' => $palette,
                'scenes' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'asset_id' => ['type' => 'string', 'description' => 'Image or video asset id for this scene.'],
                            'duration' => ['type' => 'integer', 'description' => 'Seconds, 2-6.'],
                            'text' => ['type' => 'string', 'description' => 'Overlay text, max 8 words.'],
                            'subtext' => ['type' => 'string'],
                            'voiceover' => ['type' => 'string'],
                            'motion' => ['type' => 'string', 'enum' => ContentGenerator::MOTIONS],
                        ],
                        'required' => ['asset_id', 'duration', 'text', 'subtext', 'voiceover', 'motion'],
                        'additionalProperties' => false,
                    ],
                ],
            ]);

        $tools[] = $this->tool('load_skill',
            'Read one skill from the library in full. Call before the work that skill covers.',
            [
                'name' => ['type' => 'string', 'enum' => array_column($this->skills->index(enabledOnly: true), 'name') ?: ['none']],
            ]);

        $tools[] = $this->tool('finish',
            'Call once when all deliverables are created. Ends the job.',
            [
                'summary' => ['type' => 'string', 'description' => 'What was made, which models were used and why, assumptions. In the client\'s language.'],
            ]);

        return $tools;
    }

    private function tool(string $name, string $description, array $properties): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array{content: string|array, summary: string}
     */
    private function call(AgentRun $run, string $name, array $input): array
    {
        return match ($name) {
            'generate_image' => $this->generateImage($run, $input),
            'generate_video' => $this->generateVideo($run, $input),
            'create_poster' => $this->createPoster($run, $input),
            'create_reel' => $this->createReel($run, $input),
            'load_skill' => $this->loadSkill($input),
            'finish' => $this->finish($run, $input),
            default => throw new AiException("Unknown tool [{$name}]."),
        };
    }

    private function generateImage(AgentRun $run, array $in): array
    {
        $references = [];
        foreach ($in['reference_image_ids'] ?? [] as $id) {
            $references[] = $this->readAsset($run, $id, 'image');
        }

        $url = $this->generator->image($in['prompt'], $in['aspect'], $in['provider'], $references);

        if ($url === null) {
            throw new AiException('Provider returned no image.');
        }

        $id = $run->addAsset([
            'kind' => 'image', 'url' => $url, 'provider' => $in['provider'], 'source' => 'generated',
            'prompt' => $in['prompt'], 'aspect' => $in['aspect'], 'note' => $in['purpose'] ?? '',
        ]);

        return [
            'summary' => "{$id} ← {$in['provider']}",
            'content' => [
                ['type' => 'text', 'text' => "Generated image asset id: {$id} (aspect {$in['aspect']}, provider {$in['provider']}). Review it:"],
                $this->imageBlock($run->asset($id)),
            ],
        ];
    }

    private function generateVideo(AgentRun $run, array $in): array
    {
        $frame = filled($in['first_frame_image_id'] ?? null) ? $this->readAsset($run, $in['first_frame_image_id'], 'image') : null;

        $url = $this->generator->video($in['prompt'], $in['aspect'], (int) $in['duration'], $in['provider'], $frame);

        $id = $run->addAsset([
            'kind' => 'video', 'url' => $url, 'provider' => $in['provider'], 'source' => 'generated',
            'prompt' => $in['prompt'], 'aspect' => $in['aspect'], 'duration' => (int) $in['duration'], 'note' => $in['purpose'] ?? '',
        ]);

        return [
            'summary' => "{$id} ← {$in['provider']}",
            'content' => "Generated video asset id: {$id} ({$in['duration']}s, aspect {$in['aspect']}). It cannot be previewed here; assume the prompt was followed.",
        ];
    }

    private function createPoster(AgentRun $run, array $in): array
    {
        $asset = $run->asset($in['image_id']) ?? throw new AiException("Unknown asset [{$in['image_id']}].");

        $content = $this->generator->normalizePoster($in);
        $content['image_url'] = $asset['url'];
        $content['format'] = in_array($in['format'] ?? null, self::ASPECTS, true) ? $in['format'] : '4:5';
        $content['image_prompt'] = $asset['prompt'] ?? '';

        $generation = Generation::create([
            'type' => 'poster',
            'prompt' => $run->prompt,
            'text_provider' => 'agent',
            'image_provider' => $asset['provider'] ?? 'upload',
            'options' => ['language' => $run->language, 'format' => $content['format'], 'agent_run_id' => $run->id],
            'content' => $content,
        ]);

        $run->update(['outputs' => [...$run->outputs, ['type' => 'poster', 'id' => $generation->id, 'title' => $content['headline']]]]);

        return ['summary' => "poster #{$generation->id}", 'content' => "Poster created (id {$generation->id})."];
    }

    private function createReel(AgentRun $run, array $in): array
    {
        $scenes = [];
        foreach ($in['scenes'] ?? [] as $scene) {
            $asset = $run->asset($scene['asset_id'] ?? '') ?? throw new AiException("Unknown asset [{$scene['asset_id']}].");
            $scenes[] = $scene + [
                'image_url' => $asset['kind'] === 'image' ? $asset['url'] : null,
                'video_url' => $asset['kind'] === 'video' ? $asset['url'] : null,
                'image_prompt' => $asset['prompt'] ?? '',
            ];
        }

        $content = $this->generator->normalizeReel(['scenes' => $scenes] + $in);

        // normalizeReel drops unknown keys; re-attach the media per scene.
        foreach ($content['scenes'] as $i => &$s) {
            $s['image_url'] = $scenes[$i]['image_url'];
            $s['video_url'] = $scenes[$i]['video_url'];
        }
        unset($s);

        $generation = Generation::create([
            'type' => 'reel',
            'prompt' => $run->prompt,
            'text_provider' => 'agent',
            'image_provider' => 'agent',
            'options' => ['language' => $run->language, 'duration' => array_sum(array_column($content['scenes'], 'duration')), 'scenes' => count($content['scenes']), 'agent_run_id' => $run->id],
            'content' => $content,
        ]);

        $run->update(['outputs' => [...$run->outputs, ['type' => 'reel', 'id' => $generation->id, 'title' => $content['title'] ?: $content['hook']]]]);

        return ['summary' => "reel #{$generation->id}", 'content' => "Reel created (id {$generation->id}) with ".count($content['scenes']).' scenes.'];
    }

    private function loadSkill(array $in): array
    {
        $name = (string) ($in['name'] ?? '');
        $content = $this->skills->get($name) ?? throw new AiException("Unknown skill [{$name}].");

        return ['summary' => $name, 'content' => "<skill name=\"{$name}\">\n{$content}\n</skill>"];
    }

    private function finish(AgentRun $run, array $in): array
    {
        $run->update(['summary' => (string) ($in['summary'] ?? '')]);

        return ['summary' => 'done', 'content' => 'OK'];
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array{data: string, mime: string}
     */
    private function readAsset(AgentRun $run, string $id, string $kind): array
    {
        $asset = $run->asset($id) ?? throw new AiException("Unknown asset [{$id}].");

        if ($asset['kind'] !== $kind) {
            throw new AiException("Asset [{$id}] is a {$asset['kind']}, not a {$kind}.");
        }

        $path = preg_replace('#^/storage/#', '', $asset['url']);
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            throw new AiException("Asset file for [{$id}] is missing.");
        }

        return ['data' => $disk->get($path), 'mime' => $disk->mimeType($path) ?: 'image/png'];
    }

    /**
     * Claude image block, downscaled to keep requests small.
     */
    private function imageBlock(array $asset): array
    {
        $path = preg_replace('#^/storage/#', '', $asset['url']);
        $data = Storage::disk('public')->get($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($data))) {
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, 1024 / max($w, $h));
            if ($scale < 1) {
                $img = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
            }
            ob_start();
            imagejpeg($img, null, 85);
            $data = ob_get_clean();
            $mime = 'image/jpeg';
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)]];
    }

    private function lastText(array $response): string
    {
        return collect($response['content'])->where('type', 'text')->pluck('text')->implode("\n");
    }
}
