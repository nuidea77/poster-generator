<?php

namespace App\Http\Requests;

use App\Models\Creation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxKb = config('creations.uploads.max_kb');

        return [
            'type' => ['required', Rule::in([Creation::POSTER, Creation::REEL])],
            'formats' => ['required_if:type,poster', 'array', 'min:1'],
            'formats.*' => ['distinct', Rule::in(array_keys(config('creations.poster_formats')))],
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
        ];
    }

    public function messages(): array
    {
        return [
            'formats.required_if' => 'Дор хаяж нэг хэмжээ сонгоно уу.',
            'prompt.required' => 'Юу хийлгэхээ бичнэ үү.',
            'prompt.min' => 'Бриф хэт богино байна.',
            'images.max' => 'Бүтээгдэхүүний зураг :max-аас ихгүй байна.',
        ];
    }
}
