<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StyleReference;
use App\Services\Media\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// Админ: хэв маягийн жишээ сан — жагсаах, зураг оруулах, засах, идэвхжүүлэх/унтраах, устгах.
class StyleReferenceController extends Controller
{
    // Моделд илгээх хүсэлтийг жижиг байлгахын тулд урт талыг нь үүнээс хэтрүүлэхгүй.
    public const MAX_SIDE = 1536;

    // Жишээнүүдийг шинэ нь эхэндээ, ангиллаар шүүж буцаана.
    public function index(Request $request): JsonResponse
    {
        $request->validate(['category' => ['nullable', Rule::in(StyleReference::CATEGORIES)]]);

        $refs = StyleReference::query()
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->latest('id')
            ->get();

        return response()->json($refs);
    }

    // Нэг эсвэл хэд хэдэн зургийг нийтлэг ангилал/хэв маягтайгаар оруулна.
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required_without:image', 'array', 'max:20'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'image' => ['required_without:images', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'title' => ['nullable', 'string', 'max:255'],
        ] + $this->rules(false));

        $files = $request->file('images') ?? [$request->file('image')];
        $created = [];

        foreach ($files as $file) {
            [$bytes, $mime] = $this->downscale($file);
            $stored = MediaStore::put('references', $bytes, $mime);
            $created[] = StyleReference::create([
                // Олон файл нэг дор оруулахад гарчгийг файлын нэрээс авна.
                'title' => count($files) === 1 && ! empty($data['title']) ? $data['title'] : $this->titleFrom($file),
                'category' => $data['category'],
                'style' => $data['style'] ?? null,
                'tags' => $data['tags'] ?? null,
                'notes' => $data['notes'] ?? null,
                'path' => $stored['path'],
                'enabled' => $data['enabled'] ?? true,
            ]);
        }

        return response()->json($created, 201);
    }

    // Талбаруудыг шинэчилнэ (зөвхөн ирсэн талбарыг).
    public function update(Request $request, StyleReference $reference): JsonResponse
    {
        $reference->update($request->validate(['title' => ['sometimes', 'string', 'max:255']] + $this->rules(true)));

        return response()->json($reference);
    }

    // Идэвхтэй/идэвхгүй төлөвийг сэлгэнэ.
    public function toggle(StyleReference $reference): JsonResponse
    {
        $reference->update(['enabled' => ! $reference->enabled]);

        return response()->json($reference);
    }

    // Мөрийг файлтай нь устгана.
    public function destroy(StyleReference $reference): Response
    {
        Storage::disk('public')->delete($reference->path);
        $reference->delete();

        return response()->noContent();
    }

    // Оруулах, засах үеийн нийтлэг шалгалт.
    private function rules(bool $partial): array
    {
        return [
            'category' => [$partial ? 'sometimes' : 'required', Rule::in(StyleReference::CATEGORIES)],
            'style' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['boolean'],
        ];
    }

    // Файлын нэрээс гарчиг гаргана ("diag-band_dish.jpg" → "diag band dish").
    private function titleFrom(UploadedFile $file): string
    {
        $name = trim((string) preg_replace('/[_\-]+/', ' ', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));

        return mb_substr($name !== '' ? $name : 'Жишээ', 0, 255);
    }

    /**
     * Урт тал нь MAX_SIDE-аас том бол GD-ээр жижигрүүлж ижил форматаар дахин кодлоно.
     *
     * @return array{0: string, 1: string} [bytes, mime]
     */
    private function downscale(UploadedFile $file): array
    {
        $data = (string) file_get_contents($file->getRealPath());
        $mime = match ($file->getMimeType()) {
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            default => 'image/jpeg',
        };
        $src = @imagecreatefromstring($data);

        if (! $src) {
            abort(422, 'Зургийг уншиж чадсангүй.');
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = self::MAX_SIDE / max($w, $h);

        if ($scale >= 1) {
            return [$data, $mime];
        }

        imagepalettetotruecolor($src);
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        // PNG/WebP-ийн тунгалаг хэсгийг хадгална.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        ob_start();
        match ($mime) {
            'image/png' => imagepng($dst, null, 6),
            'image/webp' => imagewebp($dst, null, 88),
            default => imagejpeg($dst, null, 90),
        };

        return [(string) ob_get_clean(), $mime];
    }
}
