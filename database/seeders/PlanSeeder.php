<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Three credit plans. A poster costs 12 credits (+3 per extra size), a reel 55.
     * Prices keep ≥2× margin on the worst-case API cost (docs/PRICING.md).
     */
    public function run(): void
    {
        $common = ['Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана'];

        foreach ([
            [
                'slug' => 'free', 'name' => 'Үнэгүй', 'price' => 0, 'period_days' => 1, 'credits' => 67, 'sort' => 0,
                'features' => ['67 кредит, нэг удаа', '= 1 постер + 1 reels', ...$common],
            ],
            [
                'slug' => 'standard', 'name' => 'Стандарт', 'price' => 99000, 'period_days' => 30, 'credits' => 80, 'sort' => 1,
                'features' => ['Сард 80 кредит', '≈ 1 reels + 2 постер, эсвэл 6 постер', ...$common],
            ],
            [
                'slug' => 'pro', 'name' => 'Про', 'price' => 249000, 'period_days' => 30, 'credits' => 220, 'sort' => 2,
                'features' => ['Сард 220 кредит', '≈ 3 reels + 4 постер, эсвэл 18 постер', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }

        // Earlier unlimited plans: keep for existing payments, hide from sale.
        Plan::whereIn('slug', ['monthly', 'quarterly', 'yearly'])->update(['is_active' => false]);
    }
}
