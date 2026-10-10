<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Example prices — set real ones from the admin panel.
     */
    public function run(): void
    {
        $features = ['Хязгааргүй постер', 'Хязгааргүй reels видео', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана'];

        foreach ([
            ['slug' => 'monthly', 'name' => '1 сар', 'price' => 49000, 'period_days' => 30, 'sort' => 1],
            ['slug' => 'quarterly', 'name' => '3 сар', 'price' => 129000, 'period_days' => 90, 'sort' => 2],
            ['slug' => 'yearly', 'name' => '1 жил', 'price' => 449000, 'period_days' => 365, 'sort' => 3],
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['features' => $features, 'is_active' => true]);
        }
    }
}
