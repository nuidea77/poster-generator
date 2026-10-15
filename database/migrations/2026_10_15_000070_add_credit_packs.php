<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Багцаас гадна нэг удаагийн кредит (pack): захиалга биш, хугацаа нь сунгахгүй, period_days = хүчинтэй хоног.
return new class extends Migration
{
    private const PACKS = [
        ['slug' => 'credits-12', 'name' => '12 кредит', 'price' => 15600, 'credits' => 12, 'sort' => 10, 'features' => ['≈ 1 постер']],
        ['slug' => 'credits-36', 'name' => '36 кредит', 'price' => 45000, 'credits' => 36, 'sort' => 11, 'features' => ['≈ 3 постер']],
        ['slug' => 'credits-120', 'name' => '120 кредит', 'price' => 150000, 'credits' => 120, 'sort' => 12, 'features' => ['≈ 10 постер']],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // subscription = сар/жилийн багц; pack = нэмэлт кредит.
            $table->string('kind', 20)->default('subscription')->after('slug');
        });

        foreach (self::PACKS as $pack) {
            DB::table('plans')->updateOrInsert(['slug' => $pack['slug']], ['kind' => 'pack', 'period_days' => 90, 'is_active' => true,
                'features' => json_encode($pack['features'], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()] + $pack);
        }
    }

    public function down(): void
    {
        DB::table('plans')->where('kind', 'pack')->update(['is_active' => false]);
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
