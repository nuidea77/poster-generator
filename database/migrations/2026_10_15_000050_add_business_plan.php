<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Стандарт (≈5 постер) ба Про (≈26 постер)-ийн дунд "Бизнес" багц (≈12 постер), сараар ба жилээр.
return new class extends Migration
{
    private const PLANS = [
        ['slug' => 'business', 'name' => 'Бизнес', 'price' => 175000, 'period_days' => 30, 'credits' => 150, 'sort' => 2,
            'features' => ['Сард 150 кредит', '≈ 12 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана']],
        ['slug' => 'business-yearly', 'name' => 'Бизнес', 'price' => 2000000, 'period_days' => 365, 'credits' => 1800, 'sort' => 5,
            'features' => ['Жилд 1,800 кредит (сард 150)', '≈ сард 12 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', '5% хямд']],
    ];

    // Дараалал: Үнэгүй, Стандарт, Бизнес, Про; дараа нь жилийнхүүд ижил дарааллаар.
    private const SORT = ['free' => 0, 'standard' => 1, 'business' => 2, 'pro' => 3, 'standard-yearly' => 4, 'business-yearly' => 5, 'pro-yearly' => 6];

    public function up(): void
    {
        foreach (self::PLANS as $plan) {
            DB::table('plans')->updateOrInsert(['slug' => $plan['slug']], ['features' => json_encode($plan['features'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()] + $plan);
        }
        foreach (self::SORT as $slug => $sort) {
            DB::table('plans')->where('slug', $slug)->update(['sort' => $sort]);
        }
    }

    // Бизнес багцыг идэвхгүй болгоно (захиалсан хэрэглэгчийн мөр устахгүйн тулд устгахгүй).
    public function down(): void
    {
        DB::table('plans')->whereIn('slug', array_column(self::PLANS, 'slug'))->update(['is_active' => false]);
    }
};
