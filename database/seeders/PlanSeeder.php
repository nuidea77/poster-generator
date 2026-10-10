<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Free tier plus Standard ($20/mo) and Pro ($100/mo), monthly or yearly. A poster costs 12 credits (+3 per extra size), a reel 120.
     * Priced for the most expensive models: ≥2× margin worst case, ≥3× typical (docs/PRICING.md).
     */
    public function run(): void
    {
        $common = ['Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана'];

        foreach ([
            [
                'slug' => 'free', 'name' => 'Үнэгүй', 'price' => 0, 'period_days' => 1, 'credits' => 12, 'sort' => 0, // exactly one poster in one size
                'features' => ['1 постер үнэгүй (12 кредит)', 'Нэг хэмжээ сонгоно', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Reels-д багц шаардлагатай'],
            ],
            // $20 and $100 a month (1$ ≈ 3,600₮). Credits keep ≥1,111₮ per credit, the
            // floor for ≥2× worst-case and ≥3× typical margin with the most expensive models.
            [
                'slug' => 'standard', 'name' => 'Стандарт', 'price' => 72000, 'period_days' => 30, 'credits' => 60, 'sort' => 1,
                'features' => ['Сард 60 кредит', '≈ 5 постер', ...$common],
            ],
            [
                'slug' => 'pro', 'name' => 'Про', 'price' => 360000, 'period_days' => 30, 'credits' => 320, 'sort' => 2,
                'features' => ['Сард 320 кредит', '≈ 2 reels + 6 постер, эсвэл 26 постер', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
            // Yearly: 12 months of credits at once, valid 365 days; discount limited by the same floor.
            [
                'slug' => 'standard-yearly', 'name' => 'Стандарт', 'price' => 800000, 'period_days' => 365, 'credits' => 720, 'sort' => 3,
                'features' => ['Жилд 720 кредит (сард 60)', '≈ сард 5 постер', ...$common, '7% хямд'],
            ],
            [
                'slug' => 'pro-yearly', 'name' => 'Про', 'price' => 4270000, 'period_days' => 365, 'credits' => 3840, 'sort' => 4,
                'features' => ['Жилд 3,840 кредит (сард 320)', '≈ сард 2 reels + 6 постер', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }

        // Earlier unlimited plans: keep for existing payments, hide from sale.
        Plan::whereIn('slug', ['monthly', 'quarterly', 'yearly'])->update(['is_active' => false]);
    }
}
