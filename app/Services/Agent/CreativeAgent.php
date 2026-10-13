<?php

namespace App\Services\Agent;

use App\Models\Creation;
use App\Services\AI\AiManager;
use App\Services\AI\Exceptions\AiException;
use App\Services\Billing\CostMeter;
use App\Services\Billing\Credits;
use App\Services\Media\IconSet;
use App\Services\Media\LogoCutout;
use App\Services\Media\LogoOverlay;
use App\Services\Media\MediaStore;
use App\Services\Media\PosterFormatter;
use App\Services\Media\ReelAssembler;
use App\Services\Media\TextOverlay;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Claude Fable runs the creative job: it reads the brief and the client's
 * logo / product photos, loads skills, decides per asset which image or
 * video model to call, reviews each image, and delivers the finished
 * posters or the ordered clips for the reel.
 */
class CreativeAgent
{
    public const ASPECTS = ['1:1', '3:4', '4:3', '2:3', '3:2', '9:16', '16:9', '5:4', '4:5', '21:9'];

    public function __construct(
        private AiManager $ai,
        private SkillLibrary $skills,
        private PosterFormatter $formatter,
        private Credits $credits,
        private CostMeter $cost,
        private ReelAssembler $reels,
        private TextOverlay $text,
        private LogoOverlay $logos,
        private LogoCutout $cutout,
    ) {}

    public function run(Creation $creation): void
    {
        // Every job needs at least one image model; do not spend Fable tokens when all are paused.
        if (! $this->ai->available('image')) {
            throw new AiException('No image model available (all out of quota/credits).');
        }

        $creation->update(['model' => config('ai.agent.model')]);
        $creation->setStage('planning', 5);

        // Fixed for the whole run: Fable's thinking blocks are bound to the exact system
        // prompt, so editing a skill or the director prompt mid-job must not change it.
        $system = $this->systemPrompt();
        $tools = $this->tools($creation);
        $messages = [['role' => 'user', 'content' => $this->brief($creation)]];

        for ($step = 0; $step < config('ai.agent.max_steps'); $step++) {
            $response = $this->claude($system, $tools, $messages);

            $usage = $response['usage'] ?? [];
            $creation->incrementEach([
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                'cost_usd' => $this->cost->claude($usage),
            ]);

            if (($response['stop_reason'] ?? null) === 'refusal') {
                throw new AiException('Claude declined: '.($response['stop_details']['explanation'] ?? 'safety filter'));
            }

            // Thinking blocks must be passed back unchanged.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            $results = [];
            $finished = false;
            // A reply cut off at max_tokens ends in a half-written tool call (empty or garbled
            // input): refuse that one call and let the agent go on instead of stopping the job.
            $cutOff = ($response['stop_reason'] ?? null) === 'max_tokens';
            $truncated = $cutOff ? collect($response['content'])->last(fn ($b) => $b['type'] === 'tool_use')['id'] ?? null : null;
            $images = $this->generateImagesAtOnce($creation, array_values(array_filter($response['content'], fn ($b) => ($b['id'] ?? null) !== $truncated)));

            foreach ($response['content'] as $block) {
                if ($block['type'] === 'text' && trim($block['text']) !== '') {
                    $creation->addStep(['type' => 'note', 'text' => $block['text']]);
                }

                if ($block['type'] !== 'tool_use') {
                    continue;
                }

                $input = is_array($block['input'] ?? null) ? $block['input'] : [];

                if ($block['id'] === $truncated) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => 'Your reply hit the length limit and this call was cut off, so it was not run. Think more briefly and send the call again.'];
                    $creation->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'error' => 'cut off (max_tokens)']);

                    continue;
                }

                $failed = false;
                try {
                    $result = array_key_exists($block['id'], $images) ? $this->settled($images[$block['id']]) : $this->call($creation, $block['name'], $input);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $result['content']];
                    $creation->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'result' => $result['summary']]);
                } catch (AiException|ConnectionException $e) {
                    if ($block['name'] === 'generate_image' && $this->ai->noteFailure((string) ($input['provider'] ?? ''), $e->getMessage())) {
                        $e = new AiException($e->getMessage().' — this model is out of quota/credits and is paused; use another image model.');
                    }
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => $e->getMessage()];
                    $creation->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'error' => $e->getMessage()]);
                    $failed = true;
                }

                $finished = $finished || ($block['name'] === 'finish' && ! $failed);
            }

            if ($finished || (! $cutOff && ($response['stop_reason'] ?? null) !== 'tool_use')) {
                return;
            }

            $messages[] = ['role' => 'user', 'content' => $results ?: 'Your reply hit the length limit before any tool call. Think more briefly and continue with the next tool call.'];
        }

        throw new AiException('Agent stopped: too many steps.');
    }

    // ---------------------------------------------------------------- Claude

    private function claude(string $system, array $tools, array $messages): array
    {
        $cfg = config('ai.providers.anthropic');
        $agent = config('ai.agent');

        $headers = ['x-api-key' => $cfg['key'], 'anthropic-version' => '2023-06-01'];
        $body = [
            'model' => $agent['model'],
            'max_tokens' => $agent['max_tokens'] ?? 12000,
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools' => $tools,
            // Fable thinks adaptively by default; depth is set with effort.
            'output_config' => ['effort' => $agent['effort']],
            'messages' => $this->withCacheBreakpoint($messages),
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

    /**
     * Cache the conversation up to the newest message so each turn re-reads
     * the history at the cache price. The stored history is left untouched.
     */
    private function withCacheBreakpoint(array $messages): array
    {
        $last = count($messages) - 1;

        if (is_array($messages[$last]['content'] ?? null) && $messages[$last]['content']) {
            $block = array_key_last($messages[$last]['content']);
            $messages[$last]['content'][$block]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $messages;
    }

    private function systemPrompt(): string
    {
        return str_replace('{{SKILLS}}', $this->skills->prompt(), file_get_contents(resource_path('ai/creative-director.md')));
    }

    private function brief(Creation $creation): array
    {
        $content = [];

        foreach ($creation->inputs as $input) {
            $label = match ($input['role']) {
                'logo' => 'Brand logo (never give it to an image/video model; place it with add_logo, pixel-exact)',
                'reference' => 'Style reference from the client (match its look, mood, composition and pacing; do not copy its content, text or logos)',
                default => 'Product photo',
            };
            $content[] = ['type' => 'text', 'text' => "{$label} — asset id `{$input['id']}`:"];
            $content[] = $this->imageBlock($input['path']);
        }

        $lines = ['# Job', ''];

        if ($creation->type === Creation::POSTER) {
            $lines[] = 'Deliverable: POSTER — one finished image per format below.';
            foreach ($creation->formats as $key) {
                $f = config("creations.poster_formats.{$key}");
                $lines[] = $key === 'auto'
                    ? '- `auto`: no fixed ratio. Pick the aspect that suits the brief best and generate at it; the poster keeps the aspect of the image you deliver.'
                    : "- `{$key}`: final size {$f['width']}×{$f['height']}, generate at aspect {$key}";
            }
        } else {
            $reel = $creation->reelFormat();
            $orientation = $reel['width'] > $reel['height'] ? 'horizontal' : 'vertical';
            $lines[] = "Deliverable: REEL — {$orientation} {$reel['aspect']} video ({$reel['width']}×{$reel['height']}) assembled from AI video clips in the order you give. First-frame images must be generated at {$reel['aspect']} too.";
            $lines[] = sprintf('Length: the client ordered a %d s reel. The delivered clips must add up to %d–%d s; plan the storyboard and clip lengths for that.', ...$this->reelLength($creation));
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

        $lines[] = '';
        $lines[] = '## Budget';
        $lines[] = sprintf('Media budget for this job: $%.2f in total for images and video. %s Generation tools refuse requests beyond it, so plan within it.', $this->credits->mediaBudget($creation), $this->priceList($creation));

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
            'Read one skill from the library in full. Call before the work that skill covers. Some skills point to files under references/: pass `reference` (the file name) to read one of those instead.',
            [
                'name' => ['type' => 'string', 'enum' => array_column($this->skills->index(enabledOnly: true), 'name') ?: ['none']],
                'reference' => ['type' => 'string', 'description' => 'Optional: a file from the skill\'s references/ folder, e.g. hook-system.md'],
            ]);

        if ($images) {
            $tools[] = $this->tool('generate_image',
                'Generate one image with the chosen image model. Returns the image so you can review it, plus its asset id. Pass reference_image_ids to keep the real logo/product faithful. Several generate_image calls in the same turn run in parallel — request independent images together.',
                [
                    'provider' => ['type' => 'string', 'enum' => $images],
                    'prompt' => ['type' => 'string', 'description' => 'Detailed English visual prompt.'],
                    'aspect' => ['type' => 'string', 'enum' => self::ASPECTS],
                    'reference_image_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Asset ids used as references (may be empty).'],
                    'purpose' => ['type' => 'string', 'description' => 'Short internal note: what this image is for.'],
                ]);
        }

        if ($creation->type === Creation::REEL && $videos) {
            $lengths = collect($videos)->mapWithKeys(fn ($v) => [$v => $this->ai->video($v)->durations()]);
            $tools[] = $this->tool('generate_videos',
                'Generate several '.$creation->reelFormat()['aspect'].' video clips in parallel (one call for the whole storyboard). Clip lengths per model: '
                .$lengths->map(fn ($d, $v) => "{$v} ".implode('/', $d).' s')->implode(', ')
                .' (other values snap to the nearest). first_frame_image_id animates an existing image ("" for text-to-video). Takes several minutes. Returns clip asset ids, real lengths and any failures.',
                [
                    'clips' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'provider' => ['type' => 'string', 'enum' => $videos],
                                'prompt' => ['type' => 'string', 'description' => 'English prompt: subject motion + camera motion.'],
                                'duration' => ['type' => 'integer', 'enum' => $lengths->flatten()->unique()->sort()->values()->all()],
                                'first_frame_image_id' => ['type' => 'string'],
                                'purpose' => ['type' => 'string'],
                            ],
                            'required' => ['provider', 'prompt', 'duration', 'first_frame_image_id', 'purpose'],
                            'additionalProperties' => false,
                        ],
                    ],
                ]);
        }

        if ($creation->type === Creation::REEL) {
            $tools[] = $this->tool('animate_still',
                'Turn one of your images into a silent clip without a video model: free, instant, no quota. The picture does not change — only a locked shot or a slow camera move over it — so text and logos stay perfectly sharp. Use it for title / info / end cards, and to finish the reel when video clips fail.',
                [
                    'image_id' => ['type' => 'string'],
                    'duration' => ['type' => 'integer', 'enum' => [2, 3, 4, 5, 6, 8, 10]],
                    'motion' => ['type' => 'string', 'enum' => ['still', 'push_in', 'pull_out', 'pan_left', 'pan_right']],
                    'purpose' => ['type' => 'string'],
                ]);
        }

        $tools[] = $this->tool('add_text',
            $creation->type === Creation::REEL
                ? 'Burn real typography onto one of your clips (exact letters incl. Mongolian Cyrillic and ₮, bundled fonts, timed fade or slide-up). Returns a new clip id with the same length and sound — deliver that one instead. Use it for the hook title, prices, dates, places and the CTA, over live-action clips or on cards. Times are seconds from the clip start; use \\n for a second line.'
                : 'Set the poster\'s words in real fonts on one of your images (exact letters incl. Mongolian Cyrillic and ₮ — no misspellings). Generate the visual WITHOUT text but with calm zones for it, then call this and deliver the returned image. start/end/animation are ignored on images; use \\n for a second line.',
            [
                'asset_id' => ['type' => 'string', 'description' => $creation->type === Creation::REEL ? 'Clip id' : 'Image id'],
                'scrim' => ['type' => 'string', 'enum' => TextOverlay::SCRIMS, 'description' => 'Soft gradient from the frame edge behind the text zones, when the photo is too busy or bright for the text; none if the zone is already calm'],
                'scrim_color' => ['type' => 'string', 'description' => 'Hex of the scrim: 000000 (dark, for white text) or a light/brand tone for dark text'],
                'footer_color' => ['type' => 'string', 'description' => 'Hex of the footer strip. House style: the brand\'s main colour (or its dark tone) with white text and circle icons; white (FFFFFF) only when the poster itself is that brand colour all over'],
                'footer_style' => ['type' => 'string', 'enum' => ['band', 'pill', 'open'], 'description' => 'band = full-width strip at the foot; pill = rounded contact bar inset from the edges (white with dark text, or the brand colour); open = no strip, the facts sit on a calm or dark foot of the picture (add scrim bottom if it is busy)'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => 'Exact words; wrap a key word in *stars* to draw it in accent_color'],
                            'role' => ['type' => 'string', 'enum' => ['display', 'title', 'price', 'subtitle', 'small'], 'description' => 'display = a huge hero number or 1–2 short words (15, -30%, 2026), about twice a title'],
                            'position' => ['type' => 'string', 'enum' => ['top', 'center', 'lower_third', 'bottom', 'footer'], 'description' => 'footer = the contact band at the very bottom (address, phone, hours, web, delivery), drawn on a full-width colour band'],
                            'align' => ['type' => 'string', 'enum' => ['center', 'left', 'right']],
                            'same_line' => ['type' => 'boolean', 'description' => 'true = on the same row as the previous item. 2 items: left and right edge (phone | delivery, date | time); 3–4 items: equal centred columns (steps, gifts, features). Usually false'],
                            'font' => ['type' => 'string', 'enum' => array_keys(TextOverlay::FONTS)],
                            'color' => ['type' => 'string', 'description' => 'Hex, e.g. FFFFFF'],
                            'box' => ['type' => 'boolean', 'description' => 'Solid panel behind the text'],
                            'box_color' => ['type' => 'string', 'description' => 'Hex of the panel'],
                            'box_shape' => ['type' => 'string', 'enum' => ['rect', 'pill', 'glass', 'card'], 'description' => 'rect = square panel; pill = rounded pill in box_color (badges, chips, CTA bars — needs box true); glass = frosted light pill (calm, premium; dark text); card = rounded content card in box_color (a short paragraph or headline on a photo — needs box true)'],
                            'accent_color' => ['type' => 'string', 'description' => 'Hex for *starred* words in text (two-tone headline: "Is your business *invisible?*")'],
                            'start' => ['type' => 'number'],
                            'end' => ['type' => 'number'],
                            'animation' => ['type' => 'string', 'enum' => ['fade', 'slide_up', 'none']],
                            'icon' => ['type' => 'string', 'enum' => ['none', ...IconSet::NAMES], 'description' => 'Small icon before a one-line fact (phone, location, clock, calendar, web, mail, delivery, gift, instagram, facebook, chat …) in the text colour; none for headlines. One fact per icon item: hours and phone are two items, not one line joined with ·'],
                            'icon_style' => ['type' => 'string', 'enum' => ['plain', 'circle'], 'description' => 'circle = the icon inside a disc of the text colour (footer contact style); plain = the bare icon'],
                            'icon_color' => ['type' => 'string', 'description' => 'Hex of the glyph inside a circle icon: the band/panel colour behind it (default dark or white for contrast)'],
                        ],
                        'required' => ['text', 'role', 'position', 'align', 'same_line', 'font', 'color', 'box', 'box_color', 'box_shape', 'start', 'end', 'animation', 'icon'],
                        'additionalProperties' => false,
                    ],
                ],
            ]);

        if (collect($creation->inputs)->contains(fn ($i) => ($i['role'] ?? null) === 'logo')) {
            $tools[] = $this->tool('add_logo',
                "Place the client's logo file on one of your images or clips, pixel-exact: shape, proportions, letters and colours never change (it is only scaled evenly). Always use this for the logo — never ask an image or video model to draw it. Returns a new asset id; deliver that one.",
                [
                    'asset_id' => ['type' => 'string'],
                    'position' => ['type' => 'string', 'enum' => LogoOverlay::POSITIONS],
                    'width' => ['type' => 'number', 'description' => 'Logo width as a share of the frame width (0.08–0.4); sizes by logo type and format in the logo-placement skill'],
                    'remove_background' => ['type' => 'boolean', 'description' => 'true (normal): a plain background around the logo (e.g. white on a JPG) is removed; the logo itself, including its white inner parts, stays exact. false: keep the file as is.'],
                    'plate' => ['type' => 'string', 'enum' => ['none', 'light', 'dark'], 'description' => 'none (normal) = the logo straight on the poster; light = white plate behind it; dark = translucent dark plate — shaped like the logo (circle for a round badge, rounded card otherwise); only when the logo would not stand out from the area behind it'],
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
                vsprintf('Deliver the reel: clip asset ids in playback order. The reel is exactly as long as the clips together, which must be %2$d–%3$d s (the client ordered %1$d s).', $this->reelLength($creation)),
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
            'animate_still' => $this->animateStill($creation, $input),
            'add_text' => $this->addText($creation, $input),
            'add_logo' => $this->addLogo($creation, $input),
            'deliver_poster' => $this->deliverPoster($creation, $input),
            'deliver_reel' => $this->deliverReel($creation, $input),
            'finish' => $this->finish($creation, $input),
            default => throw new AiException("Unknown tool [{$name}]."),
        };
    }

    private function loadSkill(array $in): array
    {
        $name = (string) ($in['name'] ?? '');

        if (filled($in['reference'] ?? null)) {
            $content = $this->skills->reference($name, (string) $in['reference'])
                ?? throw new AiException("No reference [{$in['reference']}] in skill [{$name}]. Available: ".(implode(', ', $this->skills->references($name)) ?: 'none').'.');

            return ['summary' => "{$name}/{$in['reference']}", 'content' => "<skill-reference skill=\"{$name}\" file=\"{$in['reference']}\">\n{$content}\n</skill-reference>"];
        }

        $content = $this->skills->get($name) ?? throw new AiException("Unknown skill [{$name}].");

        return ['summary' => $name, 'content' => "<skill name=\"{$name}\">\n{$content}\n</skill>"];
    }

    private function generateImage(Creation $creation, array $in): array
    {
        $job = $this->prepareImage($creation, $in);

        return $this->storeImage($creation, $job, $this->retryRateLimit(fn () => $job['provider']->generateImage($job['prompt'], $job['aspect'], $job['references'])));
    }

    /**
     * A "try again later" rate limit (429) is transient: wait and retry a couple
     * of times instead of failing the call (quota/billing errors are not retried).
     */
    private function retryRateLimit(callable $call): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $call();
            } catch (AiException $e) {
                if ($attempt >= 3 || ! preg_match('/try again later|rate limit|Resource exhausted\\./i', $e->getMessage())) {
                    throw $e;
                }
                sleep(config('ai.rate_limit_wait', 10) * ($attempt + 1)); // 10 + 20 + 30 s: per-minute quotas refill
            }
        }
    }

    /**
     * When Fable asks for several images in one turn, request them all at once
     * (Http::pool) instead of one after another. Results are keyed by
     * tool_use id: the tool result, or the exception to report for that call.
     *
     * @return array<string, array|Throwable>
     */
    private function generateImagesAtOnce(Creation $creation, array $content): array
    {
        $blocks = array_filter($content, fn ($b) => ($b['type'] ?? null) === 'tool_use' && $b['name'] === 'generate_image');

        if (count($blocks) < 2) {
            return [];
        }

        $jobs = $out = [];
        $reserved = 0.0;

        foreach ($blocks as $block) {
            try {
                $jobs[$block['id']] = $this->prepareImage($creation, is_array($block['input'] ?? null) ? $block['input'] : [], $reserved);
                $reserved += $jobs[$block['id']]['cost'];
            } catch (AiException $e) {
                $out[$block['id']] = $e;
            }
        }

        $responses = $jobs ? Http::pool(fn (Pool $pool) => array_map(
            fn ($id) => $jobs[$id]['provider']->imageRequest($pool->as($id), $jobs[$id]['prompt'], $jobs[$id]['aspect'], $jobs[$id]['references']),
            array_keys($jobs),
        )) : [];

        foreach ($jobs as $id => $job) {
            try {
                $response = $responses[$id] ?? null;

                if (! $response instanceof Response) {
                    throw new AiException('Image request failed: '.($response instanceof Throwable ? $response->getMessage() : 'no response'));
                }

                $out[$id] = $this->storeImage($creation, $job, $this->retryRateLimit(function () use ($job, $response) {
                    static $first = true;
                    if ($first) {
                        $first = false;

                        return $job['provider']->parseImage($response);
                    }

                    return $job['provider']->generateImage($job['prompt'], $job['aspect'], $job['references']);
                }));
            } catch (AiException|ConnectionException $e) {
                $out[$id] = $e;
            }
        }

        return $out;
    }

    /**
     * The logo must keep its exact shape and size, so models never get it: it is
     * placed only with add_logo.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function withoutLogo(Creation $creation, array $ids): array
    {
        $logos = collect($creation->inputs)->where('role', 'logo')->pluck('id')->all();

        if (array_intersect($ids, $logos)) {
            throw new AiException('Do not pass the logo to an image model — it redraws it. Generate without it and place the real logo with add_logo.');
        }

        return array_values($ids);
    }

    private function settled(array|Throwable $result): array
    {
        return $result instanceof Throwable ? throw $result : $result;
    }

    /**
     * Validate one generate_image call and reserve its cost ($reserved = cost of
     * images already accepted in the same turn).
     */
    private function prepareImage(Creation $creation, array $in, float $reserved = 0.0): array
    {
        $provider = $this->ai->image((string) ($in['provider'] ?? ''));
        $cost = $this->cost->image($in['provider']);
        $this->guardBudget($creation, $cost + $reserved);

        return [
            'in' => $in,
            'provider' => $provider,
            'cost' => $cost,
            'aspect' => in_array($in['aspect'] ?? null, self::ASPECTS, true) ? $in['aspect'] : '1:1',
            'references' => array_map(fn ($id) => $this->readAsset($creation, $id, 'image'), $this->withoutLogo($creation, $in['reference_image_ids'] ?? [])),
            'prompt' => trim((string) ($in['prompt'] ?? '')).' High quality, professional, no watermark.',
        ];
    }

    private function storeImage(Creation $creation, array $job, array $image): array
    {
        $in = $job['in'];
        $aspect = $job['aspect'];
        $cost = $job['cost'];
        $file = MediaStore::put("creations/{$creation->public_id}/work", $image['data'], $image['mime']);

        $id = $creation->addAsset([
            'kind' => 'image', 'source' => 'generated', 'provider' => $in['provider'],
            'path' => $file['path'], 'prompt' => $in['prompt'], 'aspect' => $aspect, 'note' => $in['purpose'] ?? '', 'cost' => $cost,
        ]);
        $creation->increment('cost_usd', $cost);

        $this->bumpProgress($creation);

        return [
            'summary' => "{$id} ← {$in['provider']}",
            'content' => [
                ['type' => 'text', 'text' => "Generated image `{$id}` (aspect {$aspect}). Review it:"],
                $this->imageBlock($file['path']),
            ],
        ];
    }

    private function animateStill(Creation $creation, array $in): array
    {
        $asset = $creation->asset((string) ($in['image_id'] ?? '')) ?? throw new AiException("Unknown asset [{$in['image_id']}].");

        if ($asset['kind'] !== 'image') {
            throw new AiException('animate_still needs an image.');
        }

        $seconds = (int) ($in['duration'] ?? 4);
        $motion = (string) ($in['motion'] ?? 'push_in');
        $reel = $creation->reelFormat();
        $tmp = $this->reels->stillClip(MediaStore::absolute($asset['path']), $seconds, $motion, $reel['width'], $reel['height']);
        $file = MediaStore::put("creations/{$creation->public_id}/work", file_get_contents($tmp), 'video/mp4');
        @unlink($tmp);

        $id = $creation->addAsset([
            'kind' => 'video', 'source' => 'generated', 'provider' => 'still',
            'path' => $file['path'], 'prompt' => "{$motion} over {$asset['id']}", 'duration' => $seconds,
            'note' => $in['purpose'] ?? '', 'cost' => 0.0,
        ]);
        $this->bumpProgress($creation);

        return ['summary' => "{$id} ← {$asset['id']} ({$motion}, {$seconds} s)", 'content' => "Clip `{$id}` ({$seconds} s, {$motion}) made from `{$asset['id']}`."];
    }

    private function addText(Creation $creation, array $in): array
    {
        $id = (string) ($in['asset_id'] ?? $in['clip_id'] ?? '');
        $asset = $creation->asset($id) ?? throw new AiException("Unknown asset [{$id}].");
        $still = $asset['kind'] === 'image';

        if ($creation->type === Creation::REEL && $still) {
            throw new AiException('In a reel, add_text needs a clip (use animate_still first for a card).');
        }

        // Cyrillic sometimes arrives as a JSON control escape: "\b" + "a" for "Ба", "\f" for "Ф",
        // "\t" for "Т" — the string is then cut short. Say exactly that so the next call is clean.
        $broken = collect($in['items'] ?? [])->first(fn ($it) => is_string($it['text'] ?? null) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\t/', $it['text']));
        if ($broken) {
            throw new AiException('The text arrived broken: '.json_encode($broken['text'], JSON_UNESCAPED_UNICODE).' contains a control character where a Cyrillic letter belongs (the JSON escapes \b \f \t \r instead of Б Ф Т Р). Nothing was drawn. Send the complete call again and type every Cyrillic letter directly (Б, Ф, Т, Р, Н), never as an escape.');
        }

        // A stray partial call (1–3 items after a full layout on the same visual) wastes a render;
        // a poster's add_text must carry the complete set every time.
        $before = collect($creation->steps)
            ->filter(fn ($s) => ($s['name'] ?? '') === 'add_text' && ! isset($s['error']) && (($s['input']['asset_id'] ?? '') === $id))
            ->map(fn ($s) => count($s['input']['items'] ?? []))->max() ?? 0;
        $count = count($in['items'] ?? []);
        if ($still && $before >= 4 && $count < $before / 2) {
            throw new AiException("This call has only {$count} of the {$before} items you set before on `{$id}` — it would replace the poster with a fragment. Send the complete set of items in one call.");
        }

        // The model sometimes sends Cyrillic as "\u0410" escapes (or a stray CR before "u0410"); set the real letters.
        $in['items'] = array_map(fn ($item) => is_array($item) && is_string($item['text'] ?? null)
            ? [...$item, 'text' => preg_replace_callback('/(?:\\\\+|\r)u([0-9a-fA-F]{4})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $item['text'])]
            : $item, array_values($in['items'] ?? []));

        $base = $this->exactFrame($asset);
        $tmp = $this->text->burn($base ?? MediaStore::absolute($asset['path']), $in['items'], (float) ($asset['duration'] ?? 0), $still, $asset['reserved'] ?? [], (string) ($in['scrim'] ?? 'none'), (string) ($in['scrim_color'] ?? '000000'), (string) ($in['footer_color'] ?? ''), (string) ($in['footer_style'] ?? 'band'));
        $busy = $still ? $this->text->busyLines($base ?? MediaStore::absolute($asset['path'])) : [];
        $faint = $still ? $this->text->lowContrastLines($base ?? MediaStore::absolute($asset['path'])) : [];
        $stripOverSubject = $still ? $this->text->footerOverDetail($base ?? MediaStore::absolute($asset['path'])) : 0.0;
        $file = MediaStore::put("creations/{$creation->public_id}/work", file_get_contents($tmp), $still ? 'image/png' : 'video/mp4');
        @unlink($tmp);
        $base && @unlink($base);

        $lines = array_column($in['items'] ?? [], 'text');
        $new = $creation->addAsset([
            'kind' => $asset['kind'], 'source' => 'generated', 'provider' => 'text',
            'path' => $file['path'], 'prompt' => 'text on '.$asset['id'].': '.implode(' | ', $lines),
            'aspect' => $asset['aspect'] ?? null, 'duration' => $asset['duration'] ?? null, 'reserved' => $asset['reserved'] ?? [],
            'note' => 'typography', 'cost' => 0.0,
        ]);

        $content = "`{$new}` = `{$asset['id']}` with the text set in real fonts (letters exactly as given). Deliver `{$new}` instead of `{$asset['id']}`.";
        if ($busy) {
            // Measured, not guessed: these lines cover a detailed part of the picture (product, face, hair, texture).
            $content .= ' WARNING — measured on the clean visual, these lines sit on a detailed area (likely the product, a face or busy texture): "'.implode('", "', $busy).'". '
                .'Look closely; unless that is intended, call add_text again on `'.$asset['id'].'` with them in a calmer zone, fewer or shorter lines in that zone (a panel or scrim does not stop it covering the product). If you can see the lines sit only on busy background (not the product, a face or the main subject) and a scrim or panel keeps them readable, that is fine — otherwise move them or regenerate the visual with an empty band for the facts.';
        }

        // Counted, not judged: layout rules the agent tends to skip.
        $footer = array_values(array_filter($in['items'], fn ($it) => ($it['position'] ?? '') === 'footer' && trim((string) ($it['text'] ?? '')) !== ''));
        // Rows as drawn (the overlay joins facts that fit one strip), not as requested.
        $footerRows = count(array_unique(array_column(array_filter($this->text->placed, fn ($p) => $p['position'] === 'footer'), 'row')));
        $cramped = array_column(array_filter($this->text->placed, fn ($p) => $p['position'] === 'footer' && $p['shrunk']), 'text');
        if ($footerRows > 2 || count($footer) > 6) {
            $content .= ' LAYOUT — the footer holds '.count($footer).' facts in '.$footerRows.' rows; keep it to 2 rows (up to 3 short facts per row with same_line; the phone bold on the right). Drop the weakest fact or move it to a pill.';
        } elseif ($cramped) {
            $content .= ' LAYOUT — footer facts had to shrink to fit their row ("'.implode('", "', $cramped).'"): shorten them (address without the district, "07:30–22:00" not "Цагийн хуваарь 07:30–22:00") so the one row reads at full size, or drop the weakest fact.';
        }

        if ($stripOverSubject >= 0.35) {
            $content .= ' LAYOUT — the footer strip covers a detailed part of the picture ('.round($stripOverSubject * 100).'% of it) — likely the bottom of the product. If the product is cut, make the visual again with the product ending above the bottom 15% (a plain surface there); a cropped table or background under the strip is fine.';
        }
        // A poster without an action: every ad except a pure greeting gets a CTA pill.
        if ($still && $creation->type === Creation::POSTER && ! collect($in['items'])->contains(fn ($it) => ($it['icon'] ?? '') === 'arrow')) {
            $content .= ' CTA — there is no call to action. Unless this is a pure greeting, add a short accent pill with icon arrow (`Захиалах →`, `Бүртгүүлэх →`, `Залгах →`) in the lower third, above the footer.';
        }

        if ($faint) {
            // Measured: text colour against the picture (and scrim) behind it.
            $content .= ' WARNING — low contrast, measured: "'.implode('", "', $faint).'" is too close in brightness to the area behind it and will be hard to read on a phone. '
                .'Call add_text again on `'.$asset['id'].'` with a darker colour on light areas (or white on dark ones), a `scrim` in that zone, or a pill/glass panel.';
        }

        // Polishing loops cost more than they give: from the 4th layout on the same visual the
        // measured hints stop and the agent is told to deliver.
        $round = collect($creation->steps)->filter(fn ($s) => ($s['name'] ?? '') === 'add_text' && ! isset($s['error']) && (($s['input']['asset_id'] ?? '') === $asset['id']))->count() + 1;
        if ($still && $round >= 4) {
            $content = "`{$new}` = `{$asset['id']}` with the text set (layout {$round} on this visual). That is enough polishing: deliver `{$new}` now. Only call add_text again if a word is misspelled or text covers the product or a face.";
        }

        return ['summary' => "{$new} ← {$asset['id']} + ".count($lines).' text', 'content' => $still ? [['type' => 'text', 'text' => $content.' Review it:'], $this->imageBlock($file['path'])] : $content];
    }

    /**
     * Models return near-ratio sizes (Gemini's "4:5" is 896×1152). Text and logos are set on
     * the exact poster frame at full size, so delivery's crop cannot cut a line or the logo.
     *
     * @return string|null temp file to use as the base (caller deletes it), or null when the image already fits
     */
    private function exactFrame(array $asset): ?string
    {
        $format = config('creations.poster_formats.'.($asset['aspect'] ?? ''));
        if ($asset['kind'] !== 'image' || empty($format['width'])) {
            return null;
        }

        $data = MediaStore::read($asset['path'])['data'];
        [$w, $h] = getimagesizefromstring($data) ?: [0, 0];
        if ($w === $format['width'] && $h === $format['height']) {
            return null;
        }

        $tmp = sys_get_temp_dir().'/frame-'.uniqid('', true).'.jpg';
        file_put_contents($tmp, $this->formatter->fit($data, $format['width'], $format['height'])['data']);

        return $tmp;
    }

    private function addLogo(Creation $creation, array $in): array
    {
        $logo = collect($creation->inputs)->firstWhere('role', 'logo') ?? throw new AiException('No logo was attached to this job.');
        $id = (string) ($in['asset_id'] ?? '');
        $asset = $creation->asset($id) ?? throw new AiException("Unknown asset [{$id}].");
        $still = $asset['kind'] === 'image';

        $logoPath = ($in['remove_background'] ?? true) ? $this->logoCutout($creation, $logo) : MediaStore::absolute($logo['path']);
        $base = $this->exactFrame($asset);
        $placed = $this->logos->place(
            $base ?? MediaStore::absolute($asset['path']), $logoPath,
            (string) ($in['position'] ?? 'top_left'), (float) ($in['width'] ?? 0.16), (string) ($in['plate'] ?? 'none'), $still,
        );
        $file = MediaStore::put("creations/{$creation->public_id}/work", file_get_contents($placed['path']), $still ? 'image/png' : 'video/mp4');
        @unlink($placed['path']);
        $base && @unlink($base);

        $new = $creation->addAsset([
            'kind' => $asset['kind'], 'source' => 'generated', 'provider' => 'logo',
            'path' => $file['path'], 'prompt' => "logo {$in['position']} on {$asset['id']}",
            'aspect' => $asset['aspect'] ?? null, 'duration' => $asset['duration'] ?? null,
            'reserved' => [...($asset['reserved'] ?? []), $placed['box']], // add_text keeps clear of it
            'note' => 'logo', 'cost' => 0.0,
        ]);

        $content = "`{$new}` = `{$asset['id']}` with the original logo placed ({$in['position']}, ".round(((float) ($in['width'] ?? 0.16)) * 100).'% wide). Deliver `'.$new.'`.';

        return ['summary' => "{$new} ← {$asset['id']} + logo", 'content' => $still ? [['type' => 'text', 'text' => $content.' Review it:'], $this->imageBlock($file['path'])] : $content];
    }

    /**
     * The logo with its plain outer background removed (made once per job and
     * kept next to the upload); the original when there is nothing to remove.
     */
    private function logoCutout(Creation $creation, array $logo): string
    {
        $path = dirname($logo['path']).'/logo-cutout.png';

        if (! Storage::disk('public')->exists($path)) {
            $png = $this->cutout->cut(MediaStore::read($logo['path'])['data']);

            if ($png === null) {
                return MediaStore::absolute($logo['path']);
            }

            Storage::disk('public')->put($path, $png);
        }

        return MediaStore::absolute($path);
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
                $clip['duration'] = $this->nearest($provider->durations(), (int) $clip['duration']);
                $cost = $this->cost->video($clip['provider'], $clip['duration']);
                $this->guardBudget($creation, $cost + array_sum(array_column($tasks, 'cost')));
                $tasks[$i] = ['provider' => $provider, 'name' => $clip['provider'], 'id' => $provider->submit($clip['prompt'], $creation->reelFormat()['aspect'], $clip['duration'], $frame), 'clip' => $clip, 'cost' => $cost];
            } catch (AiException|ConnectionException $e) {
                $paused = $this->ai->noteFailure((string) ($clip['provider'] ?? ''), $e->getMessage());
                $report[$i] = 'clip #'.($i + 1).": FAILED to start — {$e->getMessage()}"
                    .($paused ? ' — this video model is out of quota/credits and paused; do not retry it: use another video model, or animate_still for the remaining beats.' : '');
            }
        }

        $pending = $tasks;
        $done = 0;
        $providers = collect($tasks)->pluck('provider');
        $interval = max(0, (int) $providers->map->pollInterval()->min());
        $deadline = time() + (int) $providers->map->pollTimeout()->max();

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
                        'duration' => (int) $task['clip']['duration'], 'note' => $task['clip']['purpose'] ?? '', 'cost' => $task['cost'],
                    ]);
                    $creation->increment('cost_usd', $task['cost']);
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
            'content' => "Clips (cannot be previewed here; assume each followed its prompt):\n".implode("\n", $report)
                ."\n".sprintf('Media budget left: $%.2f.', $this->budgetLeft($creation->refresh())),
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

        if ($key === 'auto') {
            $aspect = in_array($asset['aspect'] ?? null, self::ASPECTS, true) ? $asset['aspect'] : '1:1';
            $format = ['label' => $aspect] + config("creations.poster_formats.{$aspect}");
        }

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

    /**
     * @return array{0: int, 1: int, 2: int} ordered, min and max seconds
     */
    private function reelLength(Creation $creation): array
    {
        $ordered = $creation->reelDuration();
        $tolerance = config('creations.reel.duration_tolerance');

        return [$ordered, max(1, $ordered - $tolerance), $ordered + $tolerance];
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

        [$ordered, $min, $max] = $this->reelLength($creation);

        if (! $ids) {
            throw new AiException('Pass at least one clip.');
        }

        if ($total > $max) {
            throw new AiException("Clips add up to {$total} s; this reel must be at most {$max} s (ordered {$ordered} s). Drop or shorten clips and deliver again.");
        }

        if ($total < $min) {
            throw new AiException("Clips add up to {$total} s; this reel must be at least {$min} s (ordered {$ordered} s). Add clips and deliver again.");
        }

        $creation->update(['reel_clips' => $ids]);

        return ['summary' => count($ids)." clips, {$total} s", 'content' => "Reel accepted ({$total} s). It will be assembled after you finish."];
    }

    private function finish(Creation $creation, array $in): array
    {
        // Giving up with nothing delivered fails the job; refuse that once so the agent retries
        // (rate limits clear within a minute) or switches model. A second finish is accepted.
        $steps = collect($creation->steps);
        $refused = $steps->contains(fn ($s) => ($s['name'] ?? '') === 'finish' && isset($s['error']));
        $delivered = $creation->outputs || $steps->contains(fn ($s) => in_array($s['name'] ?? '', ['deliver_poster', 'deliver_reel'], true) && ! isset($s['error']));
        $mediaFailed = $steps->contains(fn ($s) => in_array($s['name'] ?? '', ['generate_image', 'generate_videos'], true) && isset($s['error']));
        if (! $delivered && $mediaFailed && ! $refused) {
            throw new AiException('Nothing has been delivered yet, so the client would get nothing. If an image or video model said "try again later", call it again now (the tool waits and retries); if it is out of credits, use another available model; for reels, animate_still can finish the beats. Call finish only after delivering — calling it again now gives up the job.');
        }

        $creation->update(['summary' => (string) ($in['summary'] ?? '')]);

        return ['summary' => 'done', 'content' => 'OK'];
    }

    // --------------------------------------------------------------- helpers

    private function budgetLeft(Creation $creation): float
    {
        return $this->credits->mediaBudget($creation) - array_sum(array_column($creation->assets, 'cost'));
    }

    private function guardBudget(Creation $creation, float $cost): void
    {
        $left = $this->budgetLeft($creation);

        if ($cost > $left + 0.0001) {
            throw new AiException(sprintf('Over the media budget: this needs $%.2f but only $%.2f is left. %s Use fewer or shorter clips, a cheaper model, or deliver with what you have.', $cost, max(0, $left), $this->priceList($creation)));
        }
    }

    private function priceList(Creation $creation): string
    {
        $prices = array_map(fn ($p) => "{$p} image \$".number_format($this->cost->image($p), 3), $this->ai->available('image'));

        if ($creation->type === Creation::REEL) {
            foreach ($this->ai->available('video') as $p) {
                $prices[] = "{$p} video \$".number_format($this->cost->video($p, 1), 3).'/s';
            }
        }

        return 'Prices: '.implode(', ', $prices).'.';
    }

    /**
     * @param  list<int>  $options
     */
    private function nearest(array $options, int $value): int
    {
        return collect($options)->sortBy(fn ($o) => [abs($o - $value), -$o])->first();
    }

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
