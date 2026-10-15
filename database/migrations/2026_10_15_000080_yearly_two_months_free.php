<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Жилийн багц = сарын үнэ × 10 ("2 сар үнэгүй", ≈17% хямд). Одоо байгаа захиалгад нөлөөлөхгүй.
return new class extends Migration
{
    private const PRICES = [
        'standard-yearly' => [720000, 800000, '7% хямд'],
        'business-yearly' => [1750000, 2000000, '5% хямд'],
        'pro-yearly' => [3600000, 4270000, 'Кредитийн үнэ хамгийн хямд'],
    ];

    public function up(): void
    {
        foreach (self::PRICES as $slug => [$price, , $old]) {
            $this->set($slug, $price, $old, '2 сар үнэгүй (17% хямд)');
        }
    }

    public function down(): void
    {
        foreach (self::PRICES as $slug => [, $price, $old]) {
            $this->set($slug, $price, '2 сар үнэгүй (17% хямд)', $old);
        }
    }

    // Үнийг тавьж, онцлогийн жагсаалтын сүүлчийн хямдралын мөрийг солино.
    private function set(string $slug, int $price, string $from, string $to): void
    {
        $plan = DB::table('plans')->where('slug', $slug)->first();
        if (! $plan) {
            return;
        }
        $features = array_map(fn ($f) => $f === $from ? $to : $f, json_decode($plan->features, true) ?: []);
        DB::table('plans')->where('slug', $slug)->update(['price' => $price, 'features' => json_encode($features, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
};
