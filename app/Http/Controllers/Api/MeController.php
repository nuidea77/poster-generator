<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\Plan;
use App\Services\Billing\CostMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Одоогийн хэрэглэгч, түүний брэнд болон аппын нийтийн мэдээлэл.
class MeController extends Controller
{
    // Нэвтэрсэн хэрэглэгчийг, нэвтрээгүй бол null буцаана.
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user() ? new MeResource($request->user()) : null]);
    }

    // Брэндийн нэр, лого шинэчилнэ; хуучин логог дискнээс устгана.
    public function updateBrand(Request $request): MeResource
    {
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('creations.uploads.max_kb')],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->brand_name = $data['brand_name'] ?? $user->brand_name;

        // Логог хасах хүсэлт.
        if ($request->boolean('remove_logo') && $user->logo_path) {
            Storage::disk('public')->delete($user->logo_path);
            $user->logo_path = null;
        }

        // Шинэ лого: хуучныг устгаад орлуулна.
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
     * Аппын нийтийн мэдээлэл: poster-ийн формат, reel-ийн тохиргоо, багцууд.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'poster_formats' => collect(config('creations.poster_formats'))
                ->map(fn ($f, $key) => ['id' => $key] + collect($f)->only(['label', 'hint', 'width', 'height'])->all())
                ->values(),
            'reel' => [
                'formats' => collect(config('creations.reel.formats'))->map(fn ($f, $key) => ['id' => $key] + $f)->values(),
                'durations' => collect(config('creations.reel.durations'))->map(fn ($hint, $s) => ['id' => $s, 'label' => "{$s} сек", 'hint' => $hint])->values(),
            ],
            'uploads' => config('creations.uploads'),
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get(['id', 'slug', 'name', 'price', 'period_days', 'credits', 'features']),
            'credit_prices' => app(CostMeter::class)->creditPrices(),
            'usd_mnt' => config('pricing.usd_mnt'),
            'payments_fake' => (bool) config('qpay.fake'),
        ]);
    }
}
