<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Three credit plans. A poster costs 12 credits (+3 per extra size), a reel 120.
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
            [
                // Sized for an average customer: 10 posters + 5 reels a month.
                'slug' => 'standard', 'name' => 'Стандарт', 'price' => 900000, 'period_days' => 30, 'credits' => 720, 'sort' => 1,
                'features' => ['Сард 720 кредит', '≈ 10 постер + 5 reels', ...$common],
            ],
            [
                'slug' => 'pro', 'name' => 'Про', 'price' => 2070000, 'period_days' => 30, 'credits' => 1800, 'sort' => 2,
                'features' => ['Сард 1,800 кредит', '≈ 25 постер + 12 reels', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
            // Yearly: 12 months of credits at once, valid 365 days. Discounts stay above the
            // margin floor (≥1,111₮ per credit keeps reels at ≥3× typical cost).
            [
                'slug' => 'standard-yearly', 'name' => 'Стандарт', 'price' => 9720000, 'period_days' => 365, 'credits' => 8640, 'sort' => 3,
                'features' => ['Жилд 8,640 кредит (сард 720)', '≈ сард 10 постер + 5 reels', ...$common, '10% хямд'],
            ],
            [
                'slug' => 'pro-yearly', 'name' => 'Про', 'price' => 24000000, 'period_days' => 365, 'credits' => 21600, 'sort' => 4,
                'features' => ['Жилд 21,600 кредит (сард 1,800)', '≈ сард 25 постер + 12 reels', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }

        // Earlier unlimited plans: keep for existing payments, hide from sale.
        Plan::whereIn('slug', ['monthly', 'quarterly', 'yearly'])->update(['is_active' => false]);
    }
}
