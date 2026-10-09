<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunCreativeAgent;
use App\Models\AgentRun;
use App\Services\AI\AiManager;
use App\Services\ContentGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(AgentRun::latest()->limit(30)->get());
    }

    public function store(Request $request, AiManager $ai): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'language' => ['required', Rule::in(array_keys(ContentGenerator::LANGUAGES))],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:200'],
        ]);

        abort_unless($ai->isConfigured('anthropic'), 422, 'ANTHROPIC_API_KEY is not set — the agent needs Claude.');

        $run = AgentRun::create(['prompt' => $data['prompt'], 'language' => $data['language']]);

        foreach ($request->file('images', []) as $i => $file) {
            $path = $file->store('uploads/'.now()->format('Y/m'), 'public');
            $run->addAsset([
                'id' => 'img_u'.($i + 1),
                'kind' => 'image',
                'url' => '/storage/'.$path,
                'source' => 'upload',
                'note' => $data['notes'][$i] ?? '',
            ]);
        }

        RunCreativeAgent::dispatch($run);

        return response()->json($run->fresh(), 202);
    }

    public function show(AgentRun $agentRun): JsonResponse
    {
        return response()->json($agentRun);
    }

    public function destroy(AgentRun $agentRun): Response
    {
        $agentRun->delete();

        return response()->noContent();
    }
}
