<?php

namespace App\Services\Agent;

use App\Models\Creation;
use App\Services\AI\AiManager;
use App\Services\AI\Exceptions\AiException;
use App\Services\Media\MediaStore;
use App\Services\Media\PosterFormatter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Claude Fable runs the creative job: it reads the brief and the client's
 * logo / product photos, loads skills, decides per asset which image or
 * video model to call, reviews each image, and delivers the finished
 * posters or the ordered clips for the 90-second reel.
 */
class CreativeAgent
{
    public const ASPECTS = ['1:1', '4:5', '9:16', '16:9'];

    public function __construct(
        private AiManager $ai,
        private SkillLibrary $skills,
        private PosterFormatter $formatter,
    ) {}

    public function run(Creation $creation): void
    {
        $creation->update(['model' => config('ai.agent.model')]);
        $creation->setStage('planning', 5);

        $tools = $this->tools($creation);
        $messages = [['role' => 'user', 'content' => $this->brief($creation)]];

        for ($step = 0; $step < config('ai.agent.max_steps'); $step++) {
            $response = $this->claude($tools, $messages);

            $creation->increment('input_tokens', (int) ($response['usage']['input_tokens'] ?? 0));
            $creation->increment('output_tokens', (int) ($response['usage']['output_tokens'] ?? 0));

            if (($response['stop_reason'] ?? null) === 'refusal') {
                throw new AiException('Claude declined: '.($response['stop_details']['explanation'] ?? 'safety filter'));
            }

            // Thinking blocks must be passed back unchanged.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            $results = [];
            $finished = false;

            foreach ($response['content'] as $block) {
                if ($block['type'] === 'text' && trim($block['text']) !== '') {
                    $creation->addStep(['type' => 'note', 'text' => $block['text']]);
                }

                if ($block['type'] !== 'tool_use') {
                    continue;
                }

                $input = is_array($block['input'] ?? null) ? $block['input'] : [];

                try {
                    $result = $this->call($creation, $block['name'], $input);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $result['content']];
                    $creation->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'result' => $result['summary']]);
                } catch (AiException|ConnectionException $e) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => $e->getMessage()];
                    $creation->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'error' => $e->getMessage()]);
                }

                $finished = $finished || $block['name'] === 'finish';
            }

            if ($finished || ($response['stop_reason'] ?? null) !== 'tool_use') {
                return;
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new AiException('Agent stopped: too many steps.');
    }

    // ---------------------------------------------------------------- Claude

    private function claude(array $tools, array $messages): array
    {
        $cfg = config('ai.providers.anthropic');
        $agent = config('ai.agent');

        $headers = ['x-api-key' => $cfg['key'], 'anthropic-version' => '2023-06-01'];
        $body = [
            'model' => $agent['model'],
            'max_tokens' => 16000,
            'system' => [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools' => $tools,
            // Fable thinks adaptively by default; depth is set with effort.
            'output_config' => ['effort' => $agent['effort']],
            'messages' => $messages,
        ];

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

    private function systemPrompt(): string
    {
        return str_replace('{{SKILLS}}', $this->skills->prompt(), file_get_contents(resource_path('ai/creative-director.md')));
    }

    private function brief(Creation $creation): array
    {
        $content = [];

        foreach ($creation->inputs as $input) {
            $label = $input['role'] === 'logo' ? 'Brand logo' : 'Product photo';
            $content[] = ['type' => 'text', 'text' => "{$label} — asset id `{$input['id']}`:"];
            $content[] = $this->imageBlock($input['path']);
        }

        $lines = ['# Job', ''];

        if ($creation->type === Creation::POSTER) {
            $lines[] = 'Deliverable: POSTER — one finished image per format below.';
            foreach ($creation->formats as $key) {
                $f = config("creations.poster_formats.{$key}");
                $lines[] = "- `{$key}`: {$f['label']} ({$f['platforms']}), final size {$f['width']}×{$f['height']}, generate at aspect {$f['aspect']}";
            }
        } else {
            $reel = config('creations.reel');
            $lines[] = "Deliverable: REEL — vertical 9:16 video, exactly {$reel['duration']} seconds, assembled from AI video clips (5 or 10 s each) in the order you give.";
        }

        if (array_filter($creation->product ?? [])) {
            $lines[] = '';
            $lines[] = '## Product';
            foreach (['name' => 'Name', 'price' => 'Price', 'description' => 'Description'] as $key => $label) {
                if (filled($creation->product[$key] ?? null)) {
                    $lines[] = "- {$label}: {$creation->product[$key]}";
                }
            }
        }

        if ($brand = $creation->user?->brand_name) {
            $lines[] = "- Brand: {$brand}";
        }

        $lines[] = '';
        $lines[] = '## Client brief';
        $lines[] = $creation->prompt;

        $content[] = ['type' => 'text', 'text' => implode("\n", $lines)];

        return $content;
    }

    // ----------------------------------------------------------------- tools

    private function tools(Creation $creation): array
    {
        $images = $this->ai->available('image');
        $videos = $this->ai->available('video');
        $tools = [];

        $tools[] = $this->tool('load_skill',
            'Read one skill from the library in full. Call before the work that skill covers.',
            ['name' => ['type' => 'string', 'enum' => array_column($this->skills->index(enabledOnly: true), 'name') ?: ['none']]]);

        if ($images) {
            $tools[] = $this->tool('generate_image',
                'Generate one image with the chosen image model. Returns the image so you can review it, plus its asset id. Pass reference_image_ids to keep the real logo/product faithful.',
                [
                    'provider' => ['type' => 'string', 'enum' => $images],
                    'prompt' => ['type' => 'string', 'description' => 'Detailed English visual prompt.'],
                    'aspect' => ['type' => 'string', 'enum' => self::ASPECTS],
                    'reference_image_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Asset ids used as references (may be empty).'],
                    'purpose' => ['type' => 'string', 'description' => 'Short internal note: what this image is for.'],
                ]);
        }

        if ($creation->type === Creation::REEL && $videos) {
            $tools[] = $this->tool('generate_videos',
                'Generate several 9:16 video clips in parallel (one call for the whole storyboard). Each clip is 5 or 10 s. first_frame_image_id animates an existing image ("" for text-to-video). Takes several minutes. Returns clip asset ids and any failures.',
                [
                    'clips' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'provider' => ['type' => 'string', 'enum' => $videos],
                                'prompt' => ['type' => 'string', 'description' => 'English prompt: subject motion + camera motion.'],
                                'duration' => ['type' => 'integer', 'enum' => config('creations.reel.clip_seconds')],
                                'first_frame_image_id' => ['type' => 'string'],
                                'purpose' => ['type' => 'string'],
                            ],
                            'required' => ['provider', 'prompt', 'duration', 'first_frame_image_id', 'purpose'],
                            'additionalProperties' => false,
                        ],
                    ],
                ]);
        }

        if ($creation->type === Creation::POSTER) {
            $tools[] = $this->tool('deliver_poster',
                'Deliver the final image for one requested format. It is cropped (center) to the exact pixel size. Call once per format.',
                [
                    'format' => ['type' => 'string', 'enum' => array_values($creation->formats)],
                    'image_id' => ['type' => 'string'],
                ]);
        } else {
            $tools[] = $this->tool('deliver_reel',
                'Deliver the reel: clip asset ids in playback order. Their durations must add up to at least '.config('creations.reel.duration').' s; the result is trimmed to exactly that length.',
                ['clip_ids' => ['type' => 'array', 'items' => ['type' => 'string']]]);
        }

        $tools[] = $this->tool('finish',
            'Call once after delivering everything.',
            ['summary' => ['type' => 'string', 'description' => 'Internal note: what you made, which models and why.']]);

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
    private function call(Creation $creation, string $name, array $input): array
    {
        return match ($name) {
            'load_skill' => $this->loadSkill($input),
            'generate_image' => $this->generateImage($creation, $input),
            'generate_videos' => $this->generateVideos($creation, $input),
            'deliver_poster' => $this->deliverPoster($creation, $input),
            'deliver_reel' => $this->deliverReel($creation, $input),
            'finish' => $this->finish($creation, $input),
            default => throw new AiException("Unknown tool [{$name}]."),
        };
    }

    private function loadSkill(array $in): array
    {
        $name = (string) ($in['name'] ?? '');
        $content = $this->skills->get($name) ?? throw new AiException("Unknown skill [{$name}].");

        return ['summary' => $name, 'content' => "<skill name=\"{$name}\">\n{$content}\n</skill>"];
    }

    private function generateImage(Creation $creation, array $in): array
    {
        $aspect = in_array($in['aspect'] ?? null, self::ASPECTS, true) ? $in['aspect'] : '1:1';
        $references = array_map(fn ($id) => $this->readAsset($creation, $id, 'image'), $in['reference_image_ids'] ?? []);

        $image = $this->ai->image($in['provider'])->generateImage(trim($in['prompt']).' High quality, professional, no watermark.', $aspect, $references);
        $file = MediaStore::put("creations/{$creation->public_id}/work", $image['data'], $image['mime']);

        $id = $creation->addAsset([
            'kind' => 'image', 'source' => 'generated', 'provider' => $in['provider'],
            'path' => $file['path'], 'prompt' => $in['prompt'], 'aspect' => $aspect, 'note' => $in['purpose'] ?? '',
        ]);

        $this->bumpProgress($creation);

        return [
            'summary' => "{$id} ← {$in['provider']}",
            'content' => [
                ['type' => 'text', 'text' => "Generated image `{$id}` (aspect {$aspect}). Review it:"],
                $this->imageBlock($file['path']),
            ],
        ];
    }

    private function generateVideos(Creation $creation, array $in): array
    {
        $clips = array_values($in['clips'] ?? []);

        if (! $clips) {
            throw new AiException('No clips requested.');
        }

        $creation->setStage('filming', 25);

        // Submit everything first so the provider renders clips in parallel.
        $tasks = [];
        $report = [];

        foreach ($clips as $i => $clip) {
            try {
                $frame = filled($clip['first_frame_image_id'] ?? null) ? $this->readAsset($creation, $clip['first_frame_image_id'], 'image') : null;
                $provider = $this->ai->video($clip['provider']);
                $tasks[$i] = ['provider' => $provider, 'name' => $clip['provider'], 'id' => $provider->submit($clip['prompt'], '9:16', (int) $clip['duration'], $frame), 'clip' => $clip];
            } catch (AiException|ConnectionException $e) {
                $report[$i] = 'clip #'.($i + 1).": FAILED to start — {$e->getMessage()}";
            }
        }

        $pending = $tasks;
        $done = 0;
        $interval = max(0, (int) config('ai.providers.seedance.poll_interval'));
        $deadline = time() + (int) config('ai.providers.seedance.poll_timeout');

        while ($pending && time() <= $deadline) {
            if ($interval) {
                sleep($interval);
            }

            foreach ($pending as $i => $task) {
                $status = $task['provider']->status($task['id']);

                if ($status['state'] === 'pending') {
                    continue;
                }

                unset($pending[$i]);

                if ($status['state'] === 'failed') {
                    $report[$i] = 'clip #'.($i + 1).": FAILED — {$status['error']}";

                    continue;
                }

                try {
                    $video = $task['provider']->download($status['url']);
                    $file = MediaStore::put("creations/{$creation->public_id}/work", $video['data'], $video['mime']);
                    $id = $creation->addAsset([
                        'kind' => 'video', 'source' => 'generated', 'provider' => $task['name'],
                        'path' => $file['path'], 'prompt' => $task['clip']['prompt'],
                        'duration' => (int) $task['clip']['duration'], 'note' => $task['clip']['purpose'] ?? '',
                    ]);
                    $report[$i] = 'clip #'.($i + 1).": `{$id}` ({$task['clip']['duration']} s) — {$task['clip']['purpose']}";
                    $done++;
                } catch (AiException|ConnectionException $e) {
                    $report[$i] = 'clip #'.($i + 1).": FAILED to download — {$e->getMessage()}";
                }

                $creation->setStage('filming', 25 + (int) (55 * $done / count($clips)));
            }
        }

        foreach ($pending as $i => $task) {
            $report[$i] = 'clip #'.($i + 1).': FAILED — timed out';
        }

        ksort($report);

        return [
            'summary' => "{$done}/".count($clips).' clips',
            'content' => "Clips (cannot be previewed here; assume each followed its prompt):\n".implode("\n", $report),
        ];
    }

    private function deliverPoster(Creation $creation, array $in): array
    {
        $key = $in['format'] ?? '';

        if (! in_array($key, $creation->formats, true)) {
            throw new AiException("Format [{$key}] was not requested.");
        }

        $asset = $creation->asset($in['image_id'] ?? '') ?? throw new AiException("Unknown asset [{$in['image_id']}].");

        if ($asset['kind'] !== 'image') {
            throw new AiException('Only images can be delivered as posters.');
        }

        $format = config("creations.poster_formats.{$key}");
        $poster = $this->formatter->fit(MediaStore::read($asset['path'])['data'], $format['width'], $format['height']);
        $file = MediaStore::put("creations/{$creation->public_id}", $poster['data'], $poster['mime']);

        // Re-delivering a format replaces the earlier output.
        $creation->outputs = array_values(array_filter($creation->outputs, fn ($o) => ($o['format'] ?? null) !== $key));
        $creation->addOutput([
            'kind' => 'image', 'format' => $key, 'label' => $format['label'],
            'width' => $format['width'], 'height' => $format['height'],
            'path' => $file['path'], 'url' => $file['url'], 'source_asset' => $asset['id'],
        ]);

        $this->bumpProgress($creation);

        return ['summary' => "{$key} ← {$asset['id']}", 'content' => "Delivered {$key} ({$format['width']}×{$format['height']})."];
    }

    private function deliverReel(Creation $creation, array $in): array
    {
        $ids = array_values($in['clip_ids'] ?? []);
        $total = 0;

        foreach ($ids as $id) {
            $asset = $creation->asset($id) ?? throw new AiException("Unknown asset [{$id}].");

            if ($asset['kind'] !== 'video') {
                throw new AiException("Asset [{$id}] is not a video clip.");
            }

            $total += (int) ($asset['duration'] ?? 0);
        }

        $need = config('creations.reel.duration');

        if ($total < $need) {
            throw new AiException("Clips add up to {$total} s; at least {$need} s are needed. Generate more clips and deliver again.");
        }

        $creation->update(['reel_clips' => $ids]);

        return ['summary' => count($ids)." clips, {$total} s", 'content' => "Reel accepted ({$total} s of clips, trimmed to {$need} s). It will be assembled after you finish."];
    }

    private function finish(Creation $creation, array $in): array
    {
        $creation->update(['summary' => (string) ($in['summary'] ?? '')]);

        return ['summary' => 'done', 'content' => 'OK'];
    }

    // --------------------------------------------------------------- helpers

    private function bumpProgress(Creation $creation): void
    {
        if ($creation->type === Creation::POSTER) {
            $need = max(1, count($creation->formats));
            $generated = count(array_filter($creation->assets, fn ($a) => ($a['source'] ?? '') === 'generated'));
            $delivered = count($creation->outputs);
            $creation->setStage('generating', 10 + (int) (50 * min(1, $generated / $need)) + (int) (35 * $delivered / $need));
        } else {
            $creation->setStage('storyboard', 15);
        }
    }

    /**
     * @return array{data: string, mime: string}
     */
    private function readAsset(Creation $creation, string $id, string $kind): array
    {
        $asset = $creation->asset($id) ?? throw new AiException("Unknown asset [{$id}].");

        if ($asset['kind'] !== $kind) {
            throw new AiException("Asset [{$id}] is a {$asset['kind']}, not a {$kind}.");
        }

        return MediaStore::read($asset['path']);
    }

    /**
     * Claude image block, downscaled to ≤1024 px to keep requests small.
     */
    private function imageBlock(string $path): array
    {
        ['data' => $data, 'mime' => $mime] = MediaStore::read($path);

        if ($img = @imagecreatefromstring($data)) {
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
}
