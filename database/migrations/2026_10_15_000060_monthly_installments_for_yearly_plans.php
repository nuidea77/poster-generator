<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Жилийн багцын кредит сар бүр хуваагдаж орно: нэг төлбөр 12 сарын үе (installment 0..11) үүсгэнэ.
return new class extends Migration
{
    private const FEATURES = [
        'standard-yearly' => ['Сар бүр 60 кредит, 12 сар', '≈ сард 5 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', '7% хямд'],
        'business-yearly' => ['Сар бүр 150 кредит, 12 сар', '≈ сард 12 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', '5% хямд'],
        'pro-yearly' => ['Сар бүр 320 кредит, 12 сар', '≈ сард 26 постер', 'Instagram · Facebook бүх хэмжээ', 'Лого, бүтээгдэхүүний зураг ашиглана', 'Кредитийн үнэ хамгийн хямд'],
    ];

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // 0 = төлсөн даруй ашиглагдах үе; 1..11 = жилийн багцын дараагийн сарууд (эхлэх өдрөөсөө ашиглагдана).
            $table->unsignedTinyInteger('installment')->default(0)->after('payment_id');
            $table->unique(['payment_id', 'installment']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['payment_id']);
        });

        foreach (self::FEATURES as $slug => $list) {
            DB::table('plans')->where('slug', $slug)->update(['features' => json_encode($list, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unique('payment_id');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['payment_id', 'installment']);
            $table->dropColumn('installment');
        });
    }
};
