<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Services\Agent\SkillLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SkillController extends Controller
{
    public function __construct(private SkillLibrary $skills) {}

    public function index(): JsonResponse
    {
        return response()->json($this->skills->index());
    }

    public function show(string $name): JsonResponse
    {
        $content = $this->skills->get($name) ?? abort(404);

        return response()->json(['name' => $name, 'content' => $content]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response()->json(Skill::create($data), 201);
    }

    public function update(Request $request, Skill $skill): JsonResponse
    {
        $skill->update($request->validate($this->rules($skill)));

        return response()->json($skill);
    }

    public function destroy(Skill $skill): Response
    {
        $skill->delete();

        return response()->noContent();
    }

    private function rules(?Skill $skill = null): array
    {
        $bundled = array_column(array_filter($this->skills->index(), fn ($s) => $s['source'] === 'bundled'), 'name');

        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::notIn($bundled), Rule::unique('skills', 'name')->ignore($skill)],
            'description' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:20000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
