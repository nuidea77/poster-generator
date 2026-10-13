<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Services\Agent\SkillLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Админ: skill-үүдийг (bundled болон DB-д хадгалсан) удирдах.
class SkillController extends Controller
{
    // Skill-үүдийн эх сурвалж нь SkillLibrary.
    public function __construct(private SkillLibrary $skills) {}

    // Бүх skill-ийн индекс.
    public function index(): JsonResponse
    {
        return response()->json($this->skills->index());
    }

    // Нэг skill-ийн эх агуулгыг буцаана; байхгүй бол 404.
    public function show(string $name): JsonResponse
    {
        $source = $this->skills->source($name) ?? abort(404);

        return response()->json(['name' => $name] + $source);
    }

    /**
     * Bundled skill-ийг засах: ижил нэртэй DB override болж хадгалагдана.
     */
    public function updateBundled(Request $request, string $name): JsonResponse
    {
        $bundled = $this->skills->bundled()[$name] ?? abort(404);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:200000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        return response()->json(Skill::updateOrCreate(['name' => $name], $data + ['origin' => $bundled['origin'] ?: null]));
    }

    /**
     * Засварыг хаяж, bundled файл руу буцна.
     */
    public function resetBundled(string $name): Response
    {
        isset($this->skills->bundled()[$name]) || abort(404);
        Skill::where('name', $name)->delete();

        return response()->noContent();
    }

    // Шинэ custom skill үүсгэнэ.
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response()->json(Skill::create($data), 201);
    }

    /**
     * Claude skill импортлох (name/description frontmatter-тай SKILL.md).
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:400', 'mimetypes:text/plain,text/markdown,text/x-markdown,application/octet-stream'],
        ]);

        // Frontmatter-ийг задлана; нэр байхгүй бол файлын нэрээс slug гаргана.
        $parsed = SkillLibrary::parse((string) file_get_contents($request->file('file')->getRealPath()));
        $name = Str::slug($parsed['name'] ?: pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME));

        // Задалсан утгыг энгийн skill-ийн дүрмээр шалгана.
        $data = validator(
            ['name' => $name, 'description' => $parsed['description'], 'content' => $parsed['body'], 'origin' => 'Imported Claude skill "'.$parsed['name'].'"'],
            $this->rules() + ['origin' => ['nullable', 'string', 'max:255']],
            ['description.required' => 'SKILL.md-д description frontmatter алга.', 'name.not_in' => 'Ийм нэртэй skill системд аль хэдийн байна.'],
        )->validate();

        return response()->json(Skill::create($data), 201);
    }

    // Custom skill-ийг засна.
    public function update(Request $request, Skill $skill): JsonResponse
    {
        $skill->update($request->validate($this->rules($skill)));

        return response()->json($skill);
    }

    // Custom skill-ийг устгана.
    public function destroy(Skill $skill): Response
    {
        $skill->delete();

        return response()->noContent();
    }

    // Custom skill-ийн дүрэм: нэр нь bundled skill-тэй давхцахгүй, давтагдахгүй байна.
    private function rules(?Skill $skill = null): array
    {
        $bundled = array_keys($this->skills->bundled());

        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::notIn($bundled), Rule::unique('skills', 'name')->ignore($skill)],
            'description' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:200000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
