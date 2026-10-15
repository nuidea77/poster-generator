<?php

namespace App\Services\Agent;

use App\Models\Creation;
use App\Services\AI\AiManager;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Vertex;
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
 * Claude Fable бүтээлч ажлыг гүйцэтгэнэ: брийф болон харилцагчийн лого /
 * бүтээгдэхүүний зургийг уншиж, skill ачаалж, asset бүрт аль зураг эсвэл
 * видео загварыг дуудахаа шийдэж, зураг бүрийг хянаад, бэлэн постер эсвэл
 * reel-ийн дараалсан клипүүдийг хүлээлгэн өгнө.
 */
class CreativeAgent
{
    // Зураг үүсгэхэд зөвшөөрөгдсөн харьцаанууд.
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

    /**
     * Agent-ийн үндсэн давталт: Claude-ыг дуудаж, tool дуудлагуудыг гүйцэтгэж,
     * finish хүртэл (эсвэл max_steps дуусах хүртэл) үргэлжлүүлнэ.
     */
    public function run(Creation $creation): void
    {
        // Ажил бүрт дор хаяж нэг зургийн загвар хэрэгтэй; бүгд зогссон бол Fable-ийн token үрэхгүй.
        if (! $this->ai->available('image')) {
            throw new AiException('No image model available (all out of quota/credits).');
        }

        $creation->update(['model' => config('ai.agent.model')]);
        $creation->setStage('planning', 5);

        // Бүх run-ийн турш тогтмол: Fable-ийн thinking блокууд яг тэр system prompt-той
        // холбогддог тул ажлын дундуур skill эсвэл director prompt засахад өөрчлөгдөх ёсгүй.
        $system = $this->systemPrompt();
        $tools = $this->tools($creation);
        $messages = [['role' => 'user', 'content' => $this->brief($creation)]];

        for ($step = 0; $step < config('ai.agent.max_steps'); $step++) {
            $response = $this->claude($system, $tools, $messages);

            // Алхам бүрийн token хэрэглээ болон өртгийг ажил дээр хуримтлуулна.
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

            // Thinking блокуудыг өөрчлөлтгүй буцааж дамжуулах ёстой.
            $messages[] = ['role' => 'assistant', 'content' => $response['content']];

            $results = [];
            $finished = false;
            // max_tokens-д тасарсан хариу хагас бичигдсэн tool дуудлагаар дуусна (хоосон эсвэл
            // эвдэрсэн input): ажлыг зогсоохын оронд зөвхөн тэр дуудлагаас татгалзаж үргэлжлүүлнэ.
            $cutOff = ($response['stop_reason'] ?? null) === 'max_tokens';
            $truncated = $cutOff ? collect($response['content'])->last(fn ($b) => $b['type'] === 'tool_use')['id'] ?? null : null;
            // Нэг хариун доторх generate_image дуудлагуудыг зэрэг ажиллуулна.
            $images = $this->generateImagesAtOnce($creation, array_values(array_filter($response['content'], fn ($b) => ($b['id'] ?? null) !== $truncated)));

            // Блок бүрийг дарааллаар нь: текстийг тэмдэглэл болгож, tool_use-ийг гүйцэтгэнэ.
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
                    // Квот/кредит дууссан зургийн загварыг түр зогсоож, agent-д өөр загвар сонгохыг хэлнэ.
                    if ($block['name'] === 'generate_image' && $this->ai->noteFailure((string) ($input['provider'] ?? ''), $e->getMessage())) {
                        $e = new AiException($e->getMessage().' — this model is out of quota/credits and is paused; use another image model.');
                    }
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => $e->getMessage()];
                    $creation->addStep(['type' => 'tool', 'name' => $block['name'], 'input' => $input, 'error' => $e->getMessage()]);
                    $failed = true;
                }

                $finished = $finished || ($block['name'] === 'finish' && ! $failed);
            }

            // finish амжилттай, эсвэл model tool дуудахаа больсон бол дуусгана.
            if ($finished || (! $cutOff && ($response['stop_reason'] ?? null) !== 'tool_use')) {
                return;
            }

            $messages[] = ['role' => 'user', 'content' => $results ?: 'Your reply hit the length limit before any tool call. Think more briefly and continue with the next tool call.'];
        }

        throw new AiException('Agent stopped: too many steps.');
    }

    // ---------------------------------------------------------------- Claude

    /** Anthropic Messages API-г нэг удаа дуудаж, JSON хариуг буцаана. */
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
            // Fable анхнаасаа adaptive байдлаар боддог; гүнийг effort-оор тохируулна.
            'output_config' => ['effort' => $agent['effort']],
            'messages' => $this->withCacheBreakpoint($messages),
        ];

        // Server-side fallback: beta header + fallbacks: "default".
        if ($agent['fallbacks']) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        if (($agent['via'] ?? 'anthropic') === 'vertex') {
            // Vertex дээр: загвар URL-д, anthropic_version биед; service account-ийн token-оор нэвтэрнэ.
            // Server-side fallback нь зөвхөн Claude API-д байдаг тул энд хасна.
            $vertex = Vertex::fromConfig();
            if (! $vertex->ready(true)) {
                throw new AiException('Claude via Vertex needs a service account: set VERTEX_CREDENTIALS.');
            }
            unset($body['model'], $body['fallbacks']);
            $body['anthropic_version'] = 'vertex-2023-10-16';
            $response = $vertex->authorize(Http::timeout($agent['timeout']))
                ->post($vertex->anthropicUrl($agent['vertex_model'] ?: $agent['model']), $body);
        } else {
            $response = Http::timeout($agent['timeout'])
                ->withHeaders($headers)
                ->post(rtrim($cfg['base_url'], '/').'/v1/messages', $body);
        }

        if ($response->failed()) {
            throw new AiException('Claude: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Яриаг хамгийн сүүлийн мессеж хүртэл cache-лэнэ, ингэснээр алхам бүр түүхийг
     * cache-ийн үнээр дахин уншина. Хадгалсан түүхэд хүрэхгүй (хуулбар дээр ажиллана).
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

    /** creative-director.md-д skill-ийн жагсаалтыг {{SKILLS}}-ийн оронд оруулна. */
    private function systemPrompt(): string
    {
        return str_replace('{{SKILLS}}', $this->skills->prompt(), file_get_contents(resource_path('ai/creative-director.md')));
    }

    /** Footer-ийн санаанууд; зэрэг хийгдэж буй постерууд бүгд нэг ижил зурвас руу орохгүйн тулд ажлаар ээлжилнэ. */
    public const FOOTERS = [
        'open: no strip — the facts in small plain type straight on a calm, dark foot of the picture, plain icons, no dividers',
        'open + scrim bottom: facts in one quiet row over a soft dark fade, plain icons',
        'pill: a rounded bar inset from the edges in white or a light tint with dark text',
        'band: a full-width strip in the deepest tone of the palette, plain icons, dividers',
        'open, centred: the facts centred in one or two short lines under the CTA, like a signature',
        'pill in the accent-free dark tone with circle icons',
    ];

    /** poster-design §4-ийн composition map-ууд; харилцагчийн постеруудад ээлжилнэ. */
    public const MAPS = ['hero_type', 'split', 'frame_arch', 'bottom_heavy', 'centred_icon', 'diagonal', 'grid', 'full_bleed', 'radial', 'axial', 'product_callouts'];

    /**
     * Харилцагчийн сүүлийн постерууд ямар байсныг (map, style, palette, font) болон
     * сүүлд хамгийн бага хэрэглэсэн map-ийг гаргана, ингэснээр энэ нь өөр болно.
     *
     * @return list<string>
     */
    private function recentDesigns(Creation $creation): array
    {
        $recent = Creation::where('user_id', $creation->user_id)->where('type', Creation::POSTER)->where('status', Creation::DONE)
            ->where('id', '!=', $creation->id)->latest('id')->limit(6)->get(['id', 'steps']);
        // Алхмуудаас deliver_poster-ийн design, сүүлчийн амжилттай add_text-ийн гарчиг/footer/box-ыг сэргээнэ.
        $designs = $recent->map(function (Creation $c) {
            $steps = collect($c->steps);
            $design = $steps->first(fn ($s) => ($s['name'] ?? '') === 'deliver_poster' && isset($s['input']['design']))['input']['design'] ?? [];
            $text = $steps->last(fn ($s) => ($s['name'] ?? '') === 'add_text' && ! isset($s['error']))['input'] ?? [];
            $head = collect($text['items'] ?? [])->first(fn ($it) => in_array($it['role'] ?? '', ['display', 'title'], true));

            return array_filter($design + [
                'type' => $head ? ($head['font'] ?? '').' '.($head['position'] ?? '').'/'.($head['align'] ?? '') : null,
                'footer' => isset($text['footer_style']) ? $text['footer_style'].' '.($text['footer_color'] ?? '') : null,
                'boxes' => collect($text['items'] ?? [])->filter(fn ($it) => ! empty($it['box']) && ($it['position'] ?? '') !== 'footer')->count() ?: null,
            ]);
        })->filter()->values();

        if ($designs->isEmpty()) {
            return [];
        }

        $used = $designs->pluck('map')->filter()->countBy();
        // Хамгийн бага хэрэглэсэн map; тэнцвэл ажлын id-гаар ээлжилж, ашиглаагүй map-ууд ээлжээ авна.
        $offset = $creation->id % count(self::MAPS);
        $next = collect(self::MAPS)->keys()->sortBy(fn ($i) => [$used[self::MAPS[$i]] ?? 0, ($i - $offset + count(self::MAPS)) % count(self::MAPS)])->map(fn ($i) => self::MAPS[$i])->first();

        $lines = ['', '## Variety — this client\'s last posters (newest first)'];
        foreach ($designs as $d) {
            $lines[] = '- '.collect($d)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ');
        }
        $lines[] = "Make this one clearly different: another composition map (suggested: `{$next}`), another style direction, palette, headline font/treatment and footer look — while staying on brand. A feed of look-alike posters is a failure.";

        return $lines;
    }

    /**
     * Agent-ийн анхны user мессеж: оролтын зургууд (лого, reference, бүтээгдэхүүн)
     * болон ажлын тодорхойлолт (формат, урт, бүтээгдэхүүн, брийф, footer санаа).
     */
    private function brief(Creation $creation): array
    {
        $content = [];

        // Харилцагчийн оруулсан зураг бүрийг үүргийн шошго, asset id-тай нь хавсаргана.
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

        // Постер: формат бүрийн эцсийн хэмжээ; reel: чиглэл, харьцаа, захиалсан урт.
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

        // Постерт: олон янз байдлын сануулга ба ажлын id-гаар ээлжилсэн footer санаа.
        if ($creation->type === Creation::POSTER && ! config('ai.agent.free_layout')) {
            array_push($lines, ...$this->recentDesigns($creation));
            $lines[] = '';
            $lines[] = '## Footer idea for this poster';
            $lines[] = 'Suggested (not required — pick another if the picture asks for it, but do not default to a band): '.self::FOOTERS[$creation->id % count(self::FOOTERS)].'.';
        }

        // Медиа төсөв ба үнийн жагсаалт: agent төлөвлөгөөгөө үүнд багтаана.
        // poster-design нь найруулгын загвар, ялгаатай байх дүрэм, footer-ийн хувилбаруудыг агуулдаг;
        // agent үүнийг уншихаа мартаж байсан (бүгд split + band болсон) тул брифт шууд хавсаргана.
        if ($creation->type === Creation::POSTER && ($design = $this->skills->get('poster-design'))) {
            $lines[] = '';
            $lines[] = '## Skill `poster-design` (already loaded — follow it; do not call load_skill for it)';
            $lines[] = $design;
        }

        $lines[] = '';
        $lines[] = '## Budget';
        $lines[] = sprintf('Media budget for this job: $%.2f in total for images and video. %s Generation tools refuse requests beyond it, so plan within it.', $this->credits->mediaBudget($creation), $this->priceList($creation));

        $content[] = ['type' => 'text', 'text' => implode("\n", $lines)];

        return $content;
    }

    // ----------------------------------------------------------------- tools (хэрэгслүүд)

    /**
     * Энэ ажилд хэрэгтэй tool-уудын тодорхойлолт: ашиглах боломжтой загвар,
     * ажлын төрөл (постер/reel), лого байгаа эсэхээс хамаарна.
     */
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

        // Видео загвар бүрийн зөвшөөрөгдсөн клипийн уртыг (durations()) тайлбарт оруулна.
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

        // Зөвхөн reel-д: зургийг видео загваргүйгээр клип болгох үнэгүй tool.
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

        // Бүх бичвэрийг кодоор (TextOverlay) давхарлана; тайлбар нь постер/reel-ээс хамаарна.
        $tools[] = $this->tool('add_text',
            $creation->type === Creation::REEL
                ? 'Burn real typography onto one of your clips (exact letters incl. Mongolian Cyrillic and ₮, bundled fonts, timed fade or slide-up). Returns a new clip id with the same length and sound — deliver that one instead. Use it for the hook title, prices, dates, places and the CTA, over live-action clips or on cards. Times are seconds from the clip start; use \\n for a second line.'
                : 'Set the poster\'s words in real fonts on one of your images (exact letters incl. Mongolian Cyrillic and ₮ — no misspellings). Generate the visual WITHOUT text but with calm zones for it, then call this and deliver the returned image. start/end/animation are ignored on images; use \\n for a second line.',
            [
                'asset_id' => ['type' => 'string', 'description' => $creation->type === Creation::REEL ? 'Clip id' : 'Image id'],
                'scrim' => ['type' => 'string', 'enum' => TextOverlay::SCRIMS, 'description' => 'Soft gradient from the frame edge behind the text zones, when the photo is too busy or bright for the text; none if the zone is already calm'],
                'scrim_color' => ['type' => 'string', 'description' => 'Hex of the scrim: 000000 (dark, for white text) or a light/brand tone for dark text'],
                'footer_color' => ['type' => 'string', 'description' => 'Hex of the footer strip (band/pill); ignored for open'],
                'footer_style' => ['type' => 'string', 'enum' => ['open', 'band', 'pill'], 'description' => 'How the contact facts sit — your design choice per poster, vary it: open = no strip, plain type on a calm foot of the picture (scrim bottom if needed); band = full-width strip; pill = rounded bar inset from the edges'],
                'footer_dividers' => ['type' => 'boolean', 'description' => 'thin lines between facts on one row'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => 'Exact words; wrap a key word in *stars* to draw it in accent_color'],
                            'role' => ['type' => 'string', 'enum' => ['display', 'title', 'price', 'subtitle', 'small'], 'description' => 'display = a huge hero number or 1–2 short words (15, -30%, 2026), about twice a title'],
                            'position' => ['type' => 'string', 'enum' => ['top', 'center', 'lower_third', 'bottom', 'footer'], 'description' => 'footer = the contact facts at the very bottom (address, phone, hours, web, social), styled by footer_style'],
                            'align' => ['type' => 'string', 'enum' => ['center', 'left', 'right']],
                            'same_line' => ['type' => 'boolean', 'description' => 'true = on the same row as the previous item. The row follows the items\' align: all the same align (all center / all left / all right) = one tight group placed that way; mixed (left … right, or left / center / right) = spread to the edges, 3–4 items in equal columns. Usually false'],
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
                            'icon_style' => ['type' => 'string', 'enum' => ['plain', 'circle'], 'description' => 'circle = the icon inside a disc of the text colour; plain = the bare icon'],
                            'icon_color' => ['type' => 'string', 'description' => 'Hex of the glyph inside a circle icon: the band/panel colour behind it (default dark or white for contrast)'],
                        ],
                        'required' => ['text', 'role', 'position', 'align', 'same_line', 'font', 'color', 'box', 'box_color', 'box_shape', 'start', 'end', 'animation', 'icon'],
                        'additionalProperties' => false,
                    ],
                ],
            ]);

        // add_logo-г зөвхөн харилцагч лого оруулсан үед санал болгоно.
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
                    // Харилцагчийн дараагийн постеруудад юуг өөрөөр хийхийг хэлэхийн тулд хадгална.
                    'design' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['map', 'style', 'palette', 'type', 'idea'], 'properties' => [
                        'map' => ['type' => 'string', 'enum' => self::MAPS, 'description' => 'the composition map used (poster-design §4)'],
                        'style' => ['type' => 'string', 'description' => 'the style direction, e.g. Swiss Industrial, Editorial Warmth, bold retail'],
                        'palette' => ['type' => 'string', 'description' => 'main colours as hex, e.g. 1E3A8A, FFFFFF, FF6B4A'],
                        'type' => ['type' => 'string', 'description' => 'headline font and treatment, e.g. condensed caps two-tone'],
                        'idea' => ['type' => 'string', 'description' => 'the big idea in a few words'],
                    ]],
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

    /** Strict tool тодорхойлолт: бүх талбар заавал, нэмэлт талбаргүй. */
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
     * Tool-ийн нэрээр харгалзах handler руу чиглүүлнэ.
     *
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

    /** load_skill: skill-ийн бүтэн текст, эсвэл reference өгсөн бол түүний нэг лавлах файл. */
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

    /** generate_image: нэг зураг үүсгэж хадгална (rate limit-д дахин оролдоно). */
    private function generateImage(Creation $creation, array $in): array
    {
        $job = $this->prepareImage($creation, $in);

        return $this->storeImage($creation, $job, $this->retryRateLimit(fn () => $job['provider']->generateImage($job['prompt'], $job['aspect'], $job['references'])));
    }

    /**
     * "Try again later" маягийн rate limit (429) түр зуурынх: дуудлагыг унагаахын оронд
     * хүлээгээд хэд дахин оролдоно (квот/төлбөрийн алдааг дахин оролдохгүй).
     */
    private function retryRateLimit(callable $call): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $call();
            } catch (AiException $e) {
                // 3 удаагийн дараа, эсвэл rate limit биш алдааг шууд дамжуулна.
                if ($attempt >= 3 || ! preg_match('/try again later|rate limit|Resource exhausted\\.|Resource has been exhausted/i', $e->getMessage())) {
                    throw $e;
                }
                sleep(config('ai.rate_limit_wait', 10) * ($attempt + 1)); // 10 + 20 + 30 s: минутын квот дахин дүүрнэ
            }
        }
    }

    /**
     * Fable нэг алхамд хэд хэдэн зураг хүсвэл нэг нэгээр нь биш, бүгдийг зэрэг
     * (Http::pool) хүснэ. Үр дүн tool_use id-гаар түлхүүрлэгдэнэ: tool result,
     * эсвэл тэр дуудлагад мэдээлэх exception.
     *
     * @return array<string, array|Throwable>
     */
    private function generateImagesAtOnce(Creation $creation, array $content): array
    {
        $blocks = array_filter($content, fn ($b) => ($b['type'] ?? null) === 'tool_use' && $b['name'] === 'generate_image');

        // Ганц зургийг ердийн call() замаар үүсгэнэ.
        if (count($blocks) < 2) {
            return [];
        }

        $jobs = $out = [];
        $reserved = 0.0;

        // Бэлтгэх (төсөв шалгах) үед өмнөх зургуудын өртгийг нөөцөлж, төсвөөс хэтрэхгүй байлгана.
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

        // Эхний оролдлого нь pool-ын хариуг задлана; rate limit-ийн дахин оролдлого шинээр хүснэ.
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
     * Лого яг хэлбэр, хэмжээгээ хадгалах ёстой тул загварт хэзээ ч өгөхгүй:
     * зөвхөн add_logo-оор байрлуулна.
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

    /** Зэрэг үүсгэсэн зургийн үр дүн: амжилттай бол буцаана, exception бол шиднэ. */
    private function settled(array|Throwable $result): array
    {
        return $result instanceof Throwable ? throw $result : $result;
    }

    /**
     * Нэг generate_image дуудлагыг шалгаж, өртгийг нь нөөцөлнө ($reserved = тухайн
     * алхамд аль хэдийн зөвшөөрсөн зургуудын өртөг).
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

    /** Үүссэн зургийг хадгалж asset болгон бүртгэж, өртгийг тооцоод agent-д хянуулахаар буцаана. */
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

    /** animate_still: зургийг ffmpeg-ээр хөдөлгөөнгүй/камерын удаан хөдөлгөөнтэй клип болгоно (үнэгүй). */
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

    /** add_text: TextOverlay-аар жинхэнэ фонтоор бичвэрийг зураг/клип дээр давхарлана. */
    private function addText(Creation $creation, array $in): array
    {
        $id = (string) ($in['asset_id'] ?? $in['clip_id'] ?? '');
        $asset = $creation->asset($id) ?? throw new AiException("Unknown asset [{$id}].");
        $still = $asset['kind'] === 'image';

        if ($creation->type === Creation::REEL && $still) {
            throw new AiException('In a reel, add_text needs a clip (use animate_still first for a card).');
        }

        // Кирилл заримдаа JSON control escape болж ирдэг: "Ба"-ийн оронд "\b" + "a", "Ф"-ийн оронд "\f",
        // "Т"-ийн оронд "\t" — тэгээд string тасарна. Дараагийн дуудлага зөв байхаар яг үүнийг хэлнэ.
        $broken = collect($in['items'] ?? [])->first(fn ($it) => is_string($it['text'] ?? null) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\t/', $it['text']));
        if ($broken) {
            throw new AiException('The text arrived broken: '.json_encode($broken['text'], JSON_UNESCAPED_UNICODE).' contains a control character where a Cyrillic letter belongs (the JSON escapes \b \f \t \r instead of Б Ф Т Р). Nothing was drawn. Send the complete call again and type every Cyrillic letter directly (Б, Ф, Т, Р, Н), never as an escape.');
        }

        // Санамсаргүй хэсэгчилсэн дуудлага (нэг зураг дээр бүтэн layout-ын дараа 1–3 item) render үрнэ;
        // постерын add_text бүрт бүх item-ийг бүтнээр нь илгээх ёстой.
        $layouts = collect($creation->steps)
            ->filter(fn ($s) => ($s['name'] ?? '') === 'add_text' && ! isset($s['error']) && (($s['input']['asset_id'] ?? '') === $id));
        $before = $layouts->map(fn ($s) => count($s['input']['items'] ?? []))->max() ?? 0;
        $count = count($in['items'] ?? []);
        if ($still && $before >= 4 && $count < $before / 2) {
            // Сүүлийн бүтэн layout-ыг буцааж өгнө: засвар нь таах давталт биш, нэг хуулж-засах үйлдэл болно.
            $last = collect($layouts->last()['input']['items'] ?? [])->map(fn ($it) => collect($it)->only(['text', 'role', 'position', 'align', 'same_line', 'font', 'color', 'box', 'box_color', 'box_shape', 'icon'])->all())->values();
            throw new AiException("This call has only {$count} of the {$before} items you set before on `{$id}`. add_text always redraws the WHOLE poster on the clean visual — it does not patch. To change a few items, send this last layout again with your edits applied (and the same scrim/footer settings): ".json_encode($last, JSON_UNESCAPED_UNICODE));
        }

        // Model \u0437\u0430\u0440\u0438\u043c\u0434\u0430\u0430 \u043a\u0438\u0440\u0438\u043b\u043b\u0438\u0439\u0433 "\u0410" escape-\u044d\u044d\u0440 (\u044d\u0441\u0432\u044d\u043b "u0410"-\u0438\u0439\u043d \u04e9\u043c\u043d\u04e9 \u0438\u043b\u04af\u04af CR-\u0442\u0430\u0439) \u0438\u043b\u0433\u044d\u044d\u0434\u044d\u0433; \u0436\u0438\u043d\u0445\u044d\u043d\u044d \u04af\u0441\u0433\u044d\u044d\u0440 \u0441\u043e\u043b\u0438\u043d\u043e.
        $in['items'] = array_map(fn ($item) => is_array($item) && is_string($item['text'] ?? null)
            ? [...$item, 'text' => preg_replace_callback('/(?:\\\\+|\r)u([0-9a-fA-F]{4})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $item['text'])]
            : $item, array_values($in['items'] ?? []));

        // Постерын яг хүрээн дээр бичнэ; лого зэрэг reserved хэсгээс зайлсхийнэ.
        $base = $this->exactFrame($asset);
        $tmp = $this->text->burn($base ?? MediaStore::absolute($asset['path']), $in['items'], (float) ($asset['duration'] ?? 0), $still, $asset['reserved'] ?? [], (string) ($in['scrim'] ?? 'none'), (string) ($in['scrim_color'] ?? '000000'), (string) ($in['footer_color'] ?? ''), (string) ($in['footer_style'] ?? 'open'), (bool) ($in['footer_dividers'] ?? false));
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
        // Дизайны зөвлөмж өгөхгүй: layout-ыг agent өөрөө шийднэ (буцсан зургийг өөрөө хянана).

        // Өнгөлгөөний давталт өгөөжөөсөө илүү үнэтэй: нэг зураг дээрх 4 дэх layout-аас эхлэн
        // agent-д хүлээлгэн өгөхийг хэлнэ.
        $round = collect($creation->steps)->filter(fn ($s) => ($s['name'] ?? '') === 'add_text' && ! isset($s['error']) && (($s['input']['asset_id'] ?? '') === $asset['id']))->count() + 1;
        if ($still && $round >= 4) {
            $content = "`{$new}` = `{$asset['id']}` with the text set (layout {$round} on this visual). That is enough polishing: deliver `{$new}` now. Only call add_text again if a word is misspelled or text covers the product or a face.";
        }

        return ['summary' => "{$new} ← {$asset['id']} + ".count($lines).' text', 'content' => $still ? [['type' => 'text', 'text' => $content.' Review it:'], $this->imageBlock($file['path'])] : $content];
    }

    /**
     * Загварууд ойролцоо харьцаатай хэмжээ буцаадаг (Gemini-ийн "4:5" нь 896×1152). Бичвэр, лого
     * постерын яг хүрээн дээр бүтэн хэмжээгээр тавигддаг тул хүлээлгэн өгөх үеийн тайралт мөр, логог таслахгүй.
     *
     * @return string|null суурь болгох түр файл (дуудагч устгана), эсвэл зураг аль хэдийн тохирвол null
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

    /** add_logo: харилцагчийн жинхэнэ лого файлыг LogoOverlay-аар pixel-exact байрлуулна. */
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
            'reserved' => [...($asset['reserved'] ?? []), $placed['box']], // add_text үүнээс зайтай байна
            'note' => 'logo', 'cost' => 0.0,
        ]);

        $content = "`{$new}` = `{$asset['id']}` with the original logo placed ({$in['position']}, ".round(((float) ($in['width'] ?? 0.16)) * 100).'% wide). Deliver `'.$new.'`.';

        return ['summary' => "{$new} ← {$asset['id']} + logo", 'content' => $still ? [['type' => 'text', 'text' => $content.' Review it:'], $this->imageBlock($file['path'])] : $content];
    }

    /**
     * Гаднах энгийн дэвсгэрийг нь авсан лого (ажил бүрт нэг удаа хийж, оруулсан файлын
     * хажууд хадгална); авах зүйлгүй бол эх файл.
     */
    private function logoCutout(Creation $creation, array $logo): string
    {
        $path = dirname($logo['path']).'/logo-cutout.png';

        if (! Storage::disk('public')->exists($path)) {
            $png = $this->cutout->cut(MediaStore::read($logo['path'])['data']);

            // Авах дэвсгэр олдоогүй: эх файлыг ашиглана.
            if ($png === null) {
                return MediaStore::absolute($logo['path']);
            }

            Storage::disk('public')->put($path, $png);
        }

        return MediaStore::absolute($path);
    }

    /**
     * generate_videos: бүх клипийг зэрэг илгээж, дуусах хүртэл poll хийж, татаж хадгална.
     * Амжилтгүй клипүүдийг бүхэлд нь унагаалгүй тайланд оруулна.
     */
    private function generateVideos(Creation $creation, array $in): array
    {
        $clips = array_values($in['clips'] ?? []);

        if (! $clips) {
            throw new AiException('No clips requested.');
        }

        $creation->setStage('filming', 25);

        // Provider клипүүдийг зэрэг render хийхийн тулд эхлээд бүгдийг илгээнэ.
        $tasks = [];
        $report = [];

        foreach ($clips as $i => $clip) {
            try {
                $frame = filled($clip['first_frame_image_id'] ?? null) ? $this->readAsset($creation, $clip['first_frame_image_id'], 'image') : null;
                $provider = $this->ai->video($clip['provider']);
                // Уртыг загварын дэмждэг хамгийн ойр утга руу шилжүүлж, нийт төсвийг шалгана.
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

        // Poll-ын давтамж нь хамгийн богино interval, хугацаа нь хамгийн урт timeout-оор.
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

                // Бэлэн клипийг татаж, asset болгон бүртгэж, өртгийг нэмнэ.
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

                // Явц: 25%-аас 80% хүртэл дууссан клипийн тоогоор.
                $creation->setStage('filming', 25 + (int) (55 * $done / count($clips)));
            }
        }

        // Хугацаандаа дуусаагүй клипүүд.
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

    /** deliver_poster: зургийг захиалсан форматын яг pixel хэмжээгээр тайрч, output болгоно. */
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

        // auto: тогтмол харьцаагүй, хүлээлгэн өгсөн зургийн харьцааг авна.
        if ($key === 'auto') {
            $aspect = in_array($asset['aspect'] ?? null, self::ASPECTS, true) ? $asset['aspect'] : '1:1';
            $format = ['label' => $aspect] + config("creations.poster_formats.{$aspect}");
        }

        $poster = $this->formatter->fit(MediaStore::read($asset['path'])['data'], $format['width'], $format['height']);
        $file = MediaStore::put("creations/{$creation->public_id}", $poster['data'], $poster['mime']);

        // Форматыг дахин хүлээлгэн өгвөл өмнөх output-ыг орлоно.
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
     * Захиалсан reel-ийн урт ба зөвшөөрөгдөх хүрээ (± duration_tolerance).
     *
     * @return array{0: int, 1: int, 2: int} захиалсан, хамгийн бага ба их секунд
     */
    private function reelLength(Creation $creation): array
    {
        $ordered = $creation->reelDuration();
        $tolerance = config('creations.reel.duration_tolerance');

        return [$ordered, max(1, $ordered - $tolerance), $ordered + $tolerance];
    }

    /**
     * deliver_reel: клипүүдийн дарааллыг хадгална; нийт урт захиалсан хүрээнд байх ёстой.
     * Reel-ийг finish-ийн дараа угсарна.
     */
    private function deliverReel(Creation $creation, array $in): array
    {
        $ids = array_values($in['clip_ids'] ?? []);
        $total = 0;

        // Бүгд видео клип эсэхийг шалгаж, нийт уртыг тооцно.
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

    /** finish: ажлыг дуусгаж, дотоод хураангуйг хадгална. */
    private function finish(Creation $creation, array $in): array
    {
        // Юу ч хүлээлгэн өгөөгүй бууж өгвөл ажил амжилтгүй болно; agent дахин оролдох (rate limit
        // минутын дотор арилна) эсвэл загвар солихын тулд нэг удаа татгалзана. Хоёр дахь finish-ийг хүлээн авна.
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

    // --------------------------------------------------------------- туслах функцууд

    /** Медиа төсвөөс үлдсэн USD: төсөв хасах бүх asset-ийн өртөг. */
    private function budgetLeft(Creation $creation): float
    {
        return $this->credits->mediaBudget($creation) - array_sum(array_column($creation->assets, 'cost'));
    }

    /** Өртөг үлдсэн төсвөөс хэтэрвэл AiException шиднэ (0.0001 нь float-ийн бөөрөнхийлөлтийн зай). */
    private function guardBudget(Creation $creation, float $cost): void
    {
        $left = $this->budgetLeft($creation);

        if ($cost > $left + 0.0001) {
            throw new AiException(sprintf('Over the media budget: this needs $%.2f but only $%.2f is left. %s Use fewer or shorter clips, a cheaper model, or deliver with what you have.', $cost, max(0, $left), $this->priceList($creation)));
        }
    }

    /** Agent-д харуулах үнийн жагсаалт: зураг бүрийн үнэ, reel-д видеоны секундын үнэ. */
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
     * Хамгийн ойр утга; тэнцвэл томыг нь сонгоно.
     *
     * @param  list<int>  $options
     */
    private function nearest(array $options, int $value): int
    {
        return collect($options)->sortBy(fn ($o) => [abs($o - $value), -$o])->first();
    }

    /**
     * Явцын хувийг шинэчилнэ. Постер: 10% + үүссэн зураг (50% хүртэл) + хүлээлгэн өгсөн формат (35% хүртэл).
     */
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
     * Asset-ийн файлыг уншина; төрөл ($kind) таарахгүй бол алдаа.
     *
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
     * Claude-ийн image блок; хүсэлтийг жижиг байлгахын тулд ≤1024 px болгож JPEG болгоно.
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
