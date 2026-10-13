<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

// Худалдаалах багцуудыг үүсгэнэ / шинэчилнэ (slug-аар, давтан ажиллуулж болно).
class PlanSeeder extends Seeder
{
    /**
     * Free tier, мөн Standard ($20/сар) ба Pro ($100/сар), сараар эсвэл жилээр. Постер 12 credit (нэмэлт хэмжээ бүр +3), reel 120.
     * Хамгийн үнэтэй загвараар үнэлсэн: хамгийн муу үед ≥2×, ердийн үед ≥3× margin (docs/PRICING.md).
     */
    public function run(): void
    {
        // Бүх төлбөртэй багцад нийтлэг онцлогууд.
        $common = ['Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана'];

        foreach ([
            [
                'slug' => 'free', 'name' => 'Үнэгүй', 'price' => 0, 'period_days' => 1, 'credits' => 12, 'sort' => 0, // яг нэг хэмжээтэй нэг постер
                'features' => ['1 постер үнэгүй (12 кредит)', 'Нэг хэмжээ сонгоно', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Reels-д багц шаардлагатай'],
            ],
            // Сард $20 ба $100 (1$ ≈ 3,600₮). Нэг credit ≥1,111₮ байна: хамгийн үнэтэй загвартай
            // үед хамгийн муу тохиолдолд ≥2×, ердийн үед ≥3× margin-ийн доод хязгаар.
            [
                'slug' => 'standard', 'name' => 'Стандарт', 'price' => 72000, 'period_days' => 30, 'credits' => 60, 'sort' => 1,
                'features' => ['Сард 60 кредит', '≈ 5 постер', ...$common],
            ],
            [
                'slug' => 'pro', 'name' => 'Про', 'price' => 360000, 'period_days' => 30, 'credits' => 320, 'sort' => 2,
                'features' => ['Сард 320 кредит', '≈ 2 reels + 6 постер, эсвэл 26 постер', ...$common, 'Кредитийн үнэ хамгийн хямд'],
            ],
            // Жилийн: 12 сарын credit-ийг нэг дор, 365 хоног хүчинтэй; хөнгөлөлтийг мөн адил доод хязгаар хязгаарлана.
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

        // Өмнөх хязгааргүй багцууд: байгаа төлбөрүүдийн төлөө үлдээж, худалдаанаас нууна.
        Plan::whereIn('slug', ['monthly', 'quarterly', 'yearly'])->update(['is_active' => false]);
    }
}
