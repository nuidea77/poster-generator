<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\AI\AiManager;
use App\Services\ContentGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GenerateController extends Controller
{
    public function __construct(private ContentGenerator $generator) {}

    public function config(AiManager $ai): JsonResponse
    {
        return response()->json($ai->describe() + [
            'ffmpeg' => VideoController::ffmpegAvailable(),
        ]);
    }

    public function poster(Request $request): JsonResponse
    {
        $data = $request->validate($this->commonRules() + [
            'format' => ['required', Rule::in(['1:1', '4:5', '9:16', '16:9'])],
        ]);

        $content = $this->generator->poster($data, $data['text_provider']);

        $generation = Generation::create([
            'type' => 'poster',
            'prompt' => $data['prompt'],
            'text_provider' => $data['text_provider'],
            'image_provider' => $data['image_provider'],
            'options' => collect($data)->only(['language', 'style', 'format'])->all(),
            'content' => $content,
        ]);

        return response()->json($generation, 201);
    }

    public function reel(Request $request): JsonResponse
    {
        $data = $request->validate($this->commonRules() + [
            'duration' => ['required', 'integer', 'min:5', 'max:90'],
            'scenes' => ['required', 'integer', 'min:2', 'max:12'],
        ]);

        $content = $this->generator->reel($data, $data['text_provider']);

        $generation = Generation::create([
            'type' => 'reel',
            'prompt' => $data['prompt'],
            'text_provider' => $data['text_provider'],
            'image_provider' => $data['image_provider'],
            'options' => collect($data)->only(['language', 'style', 'duration', 'scenes'])->all(),
            'content' => $content,
        ]);

        return response()->json($generation, 201);
    }

    public function image(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'aspect' => ['required', Rule::in(['1:1', '4:5', '9:16', '16:9'])],
            'provider' => ['required', Rule::in(AiManager::IMAGE_PROVIDERS)],
        ]);

        return response()->json([
            'url' => $this->generator->image($data['prompt'], $data['aspect'], $data['provider']),
        ]);
    }

    /**
     * Use your own photo instead of an AI generated one.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $path = $request->file('image')->store('uploads/'.now()->format('Y/m'), 'public');

        return response()->json(['url' => '/storage/'.$path]);
    }

    private function commonRules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:2000'],
            'language' => ['required', Rule::in(array_keys(ContentGenerator::LANGUAGES))],
            'style' => ['nullable', 'string', 'max:300'],
            'text_provider' => ['required', Rule::in(AiManager::TEXT_PROVIDERS)],
            'image_provider' => ['required', Rule::in(AiManager::IMAGE_PROVIDERS)],
        ];
    }
}
