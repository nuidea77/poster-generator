<?php

namespace App\Http\Requests;

use App\Models\Creation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Шинэ бүтээл (poster эсвэл reel) эхлүүлэх хүсэлтийн шалгалт.
class StoreCreationRequest extends FormRequest
{
    // Зөвхөн нэвтэрсэн хэрэглэгч бүтээл эхлүүлнэ.
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    // Reel нэг л формат авна, poster хэд хэдэн формат авч болно.
    public function rules(): array
    {
        $maxKb = config('creations.uploads.max_kb');
        $reel = $this->input('type') === Creation::REEL;

        return [
            'type' => ['required', Rule::in(config('creations.reels_enabled') ? [Creation::POSTER, Creation::REEL] : [Creation::POSTER])],
            'formats' => ['required_if:type,poster', 'array', 'min:1', ...($reel ? ['max:1'] : [])],
            'formats.*' => ['distinct', Rule::in(array_keys(config($reel ? 'creations.reel.formats' : 'creations.poster_formats')))],
            'duration' => ['nullable', 'integer', Rule::in(array_keys(config('creations.reel.durations')))],
            'prompt' => ['required', 'string', 'min:5', 'max:3000'],
            'product' => ['nullable', 'array'],
            'product.name' => ['nullable', 'string', 'max:150'],
            'product.price' => ['nullable', 'string', 'max:50'],
            'product.description' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', "max:{$maxKb}"],
            'use_saved_logo' => ['sometimes', 'boolean'],
            'remember_logo' => ['sometimes', 'boolean'],
            'images' => ['nullable', 'array', 'max:'.config('creations.uploads.max_product_images')],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', "max:{$maxKb}"],
            'references' => ['nullable', 'array', 'max:'.config('creations.uploads.max_reference_images')],
            'references.*' => ['image', 'mimes:jpg,jpeg,png,webp', "max:{$maxKb}"],
        ];
    }

    // Монгол алдааны мессежүүд.
    public function messages(): array
    {
        return [
            'type.in' => 'Reels тун удахгүй нээгдэнэ. Одоогоор зөвхөн постер хийнэ.',
            'formats.required_if' => 'Дор хаяж нэг хэмжээ сонгоно уу.',
            'prompt.required' => 'Юу хийлгэхээ бичнэ үү.',
            'prompt.min' => 'Бриф хэт богино байна.',
            'images.max' => 'Бүтээгдэхүүний зураг :max-аас ихгүй байна.',
            'references.max' => 'Жишээ зураг :max-аас ихгүй байна.',
        ];
    }
}
