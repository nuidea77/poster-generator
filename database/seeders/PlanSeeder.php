<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Three credit plans. A poster costs 14 credits (+4 per extra size), a reel 130.
     * Priced for the most expensive models: ≥2× margin worst case, ≥3× typical (docs/PRICING.md).
     */
    public function run(): void
    {
        $common = ['Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана'];

        foreach ([
            [
                'slug' => 'free', 'name' => 'Үнэгүй', 'price' => 0, 'period_days' => 1, 'credits' => 144, 'sort' => 0,
                'features' => ['144 кредит, нэг удаа', '= 1 постер + 1 reels', ...$common],
            ],
            [
                'slug' => 'standard', 'name' => 'Стандарт', 'price' => 199000, 'period_days' => 30, 'credits' => 160, 'sort' => 1,
                'features' => ['Сард 160 кредит', '≈ 1 reels + 2 постер, эсвэл 11 постер', ...$common],
            ],
            [
                'slug' => 'pro', 'name' => 'Про', 'price' => 499000, 'period_days' => 30, 'credits' => 440, 'sort' => 2,
                'features' => ['Сард 440 кредит', '≈ 3 reels + 3 постер, эсвэл 31 постер', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }

        // Earlier unlimited plans: keep for existing payments, hide from sale.
        Plan::whereIn('slug', ['monthly', 'quarterly', 'yearly'])->update(['is_active' => false]);
    }
}
