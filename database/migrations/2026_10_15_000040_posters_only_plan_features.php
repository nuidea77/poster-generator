<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reels түр хаалттай: багцуудын тайлбараас reels-ийг хасаж, постерын тоогоор бичнэ (үнэ, кредит хэвээр).
return new class extends Migration
{
    private const FEATURES = [
        'free' => ['1 постер үнэгүй (12 кредит)', 'Нэг хэмжээ сонгоно', 'Лого, бүтээгдэхүүний зураг ашиглана'],
        'pro' => ['Сард 320 кредит', '≈ 26 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Кредитийн үнэ хамгийн хямд'],
        'pro-yearly' => ['Жилд 3,840 кредит (сард 320)', '≈ сард 26 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Кредитийн үнэ хамгийн хямд'],
    ];

    private const BEFORE = [
        'free' => ['1 постер үнэгүй (12 кредит)', 'Нэг хэмжээ сонгоно', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Reels-д багц шаардлагатай'],
        'pro' => ['Сард 320 кредит', '≈ 2 reels + 6 постер, эсвэл 26 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Кредитийн үнэ хамгийн хямд'],
        'pro-yearly' => ['Жилд 3,840 кредит (сард 320)', '≈ сард 2 reels + 6 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Кредитийн үнэ хамгийн хямд'],
    ];

    // Багц бүрийн тайлбарыг slug-аар шинэчилнэ.
    public function up(): void
    {
        $this->write(self::FEATURES);
    }

    // Өмнөх (reels-тэй) тайлбарыг сэргээнэ.
    public function down(): void
    {
        $this->write(self::BEFORE);
    }

    private function write(array $features): void
    {
        foreach ($features as $slug => $list) {
            DB::table('plans')->where('slug', $slug)->update(['features' => json_encode($list, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }
};
