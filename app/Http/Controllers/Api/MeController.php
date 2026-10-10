<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user() ? new MeResource($request->user()) : null]);
    }

    public function updateBrand(Request $request): MeResource
    {
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('creations.uploads.max_kb')],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->brand_name = $data['brand_name'] ?? $user->brand_name;

        if ($request->boolean('remove_logo') && $user->logo_path) {
            Storage::disk('public')->delete($user->logo_path);
            $user->logo_path = null;
        }

        if ($request->hasFile('logo')) {
            if ($user->logo_path) {
                Storage::disk('public')->delete($user->logo_path);
            }
            $user->logo_path = $request->file('logo')->store('logos', 'public');
        }

        $user->save();

        return new MeResource($user);
    }

    /**
     * Public app metadata: poster formats, reel spec, plans.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'poster_formats' => collect(config('creations.poster_formats'))
                ->map(fn ($f, $key) => ['id' => $key] + collect($f)->only(['label', 'platforms', 'width', 'height'])->all())
                ->values(),
            'reel' => collect(config('creations.reel'))->only(['width', 'height', 'max_seconds'])->all(),
            'uploads' => config('creations.uploads'),
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get(['id', 'slug', 'name', 'price', 'period_days', 'credits', 'features']),
            'credit_prices' => config('pricing.credits'),
            'usd_mnt' => config('pricing.usd_mnt'),
            'payments_fake' => (bool) config('qpay.fake'),
        ]);
    }
}
