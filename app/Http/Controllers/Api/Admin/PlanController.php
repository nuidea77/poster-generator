<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('sort')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Plan::create($this->validated($request))], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($this->validated($request, $plan));

        return response()->json(['data' => $plan]);
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan)],
            'name' => ['required', 'string', 'max:100'],
            // 0 = the free tier; paid plans go through QPay, which needs a real amount.
            'price' => ['required', 'integer', 'min:0', fn ($attr, $value, $fail) => $value > 0 && $value < 100 ? $fail('Үнэ 0 (үнэгүй) эсвэл 100₮-өөс дээш байна.') : null],
            'period_days' => ['required', 'integer', 'min:1', 'max:3660'],
            'credits' => ['required', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:200'],
            'is_active' => ['boolean'],
            'sort' => ['integer', 'min:0'],
        ]);
    }
}
