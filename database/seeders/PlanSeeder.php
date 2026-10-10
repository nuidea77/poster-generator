<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Three plans: a free trial and two unlimited paid plans.
     * Example prices — set real ones from the admin panel.
     */
    public function run(): void
    {
        $unlimited = ['Хязгааргүй постер', 'Хязгааргүй reels видео', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана'];

        foreach ([
            [
                'slug' => 'free', 'name' => 'Үнэгүй', 'price' => 0, 'period_days' => 1, 'poster_limit' => 1, 'reel_limit' => 1, 'sort' => 0,
                'features' => ['1 постер', '1 reels видео', 'Instagram · Facebook бүх хэмжээ', 'Картгүй, шууд эхэлнэ'],
            ],
            ['slug' => 'monthly', 'name' => '1 сар', 'price' => 49000, 'period_days' => 30, 'poster_limit' => null, 'reel_limit' => null, 'sort' => 1, 'features' => $unlimited],
            ['slug' => 'yearly', 'name' => '1 жил', 'price' => 449000, 'period_days' => 365, 'poster_limit' => null, 'reel_limit' => null, 'sort' => 2, 'features' => [...$unlimited, '2 сар үнэгүй (сарын үнээр тооцвол)']],
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }

        // Earlier 3-month plan: keep for existing payments, hide from sale.
        Plan::where('slug', 'quarterly')->update(['is_active' => false]);
    }
}
