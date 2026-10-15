<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\CostMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Одоогийн хэрэглэгч, түүний брэнд болон аппын нийтийн мэдээлэл.
class MeController extends Controller
{
    // Нэвтэрсэн хэрэглэгчийг, нэвтрээгүй бол null буцаана.
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user() ? new MeResource($request->user()) : null]);
    }

    /**
     * Брэндийн багцыг шинэчилнэ: зөвхөн илгээсэн талбаруудыг өөрчилнө (хоосон утга = цэвэрлэх).
     * Хуучин клиентэд зориулж multipart-аар logo / remove_logo-г бас хүлээн авна.
     */
    public function updateBrand(Request $request): MeResource
    {
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:100'],
            'brand_colors' => ['nullable', 'array', 'max:5'],
            'brand_colors.*' => ['string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'brand_fonts' => ['nullable', 'string', 'max:100'],
            'brand_tone' => ['nullable', 'string', 'max:100'],
            'brand_notes' => ['nullable', 'string', 'max:1000'],
            'logo' => self::logoRules(),
            'remove_logo' => ['sometimes', 'boolean'],
        ], [
            'brand_colors.max' => 'Брэндийн өнгө :max-аас ихгүй байна.',
            'brand_colors.*.regex' => 'Өнгийг #1E3A8A хэлбэрийн 6 оронтой hex кодоор оруулна уу.',
            'brand_notes.max' => 'Тэмдэглэл :max тэмдэгтээс ихгүй байна.',
        ]);

        $user = $request->user();

        foreach (['brand_name', 'brand_fonts', 'brand_tone', 'brand_notes'] as $field) {
            if ($request->exists($field)) {
                $user->{$field} = filled($data[$field] ?? null) ? trim($data[$field]) : null;
            }
        }

        // Өнгийг '#'-гүй том үсэгтэй болгож, давхардлыг хасна.
        if ($request->exists('brand_colors')) {
            $colors = collect($data['brand_colors'] ?? [])
                ->map(fn ($c) => strtoupper(ltrim($c, '#')))
                ->unique()->values()->all();
            $user->brand_colors = $colors ?: null;
        }

        if ($request->boolean('remove_logo')) {
            $this->forgetLogo($user);
        }

        if ($request->hasFile('logo')) {
            $this->replaceLogo($user, $request->file('logo'));
        }

        $user->save();

        return new MeResource($user);
    }

    // Брэндийн логог хадгална (хуучныг дискнээс устгана).
    public function uploadLogo(Request $request): MeResource
    {
        $request->validate(['logo' => ['required', ...array_slice(self::logoRules(), 1)]]);

        $user = $request->user();
        $this->replaceLogo($user, $request->file('logo'));
        $user->save();

        return new MeResource($user);
    }

    // Хадгалсан брэндийн логог устгана.
    public function deleteLogo(Request $request): MeResource
    {
        $user = $request->user();
        $this->forgetLogo($user);
        $user->save();

        return new MeResource($user);
    }

    // Бүтээлийн лого оруулахтай ижил шалгалт (StoreCreationRequest).
    private static function logoRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('creations.uploads.max_kb')];
    }

    // Шинэ логог хадгалж, хуучныг устгана.
    private function replaceLogo(User $user, UploadedFile $file): void
    {
        $this->forgetLogo($user);
        $user->brand_logo_path = $file->store('logos', 'public');
    }

    // Хадгалсан логог дискнээс устгаж, замыг цэвэрлэнэ.
    private function forgetLogo(User $user): void
    {
        if ($user->brand_logo_path) {
            Storage::disk('public')->delete($user->brand_logo_path);
        }
        $user->brand_logo_path = null;
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
