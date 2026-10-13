<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Төрөл тус бүрийн хязгаараас credit-д суурилсан төлбөр рүү шилжинэ.
return new class extends Migration
{
    // Багц, захиалга, бүтээлд credit-ийн багануудыг нэмнэ.
    public function up(): void
    {
        // Багц нь төрөл тус бүрийн хязгаарын оронд хугацаа тутамд credit олгоно.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('credits')->default(0)->after('period_days');
            $table->dropColumn(['poster_limit', 'reel_limit']);
        });

        // Захиалгын хугацаа бүр өөрийн credit-тэй; ашиглаагүй нь хугацаатайгаа хамт дуусна.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('credits')->default(0)->after('ends_at');
            $table->unsignedInteger('credits_used')->default(0)->after('credits');
        });

        // Бүтээл: суутгасан credit, аль захиалгаас хэдийг авсан (буцаахад), бодит API өртөг ба cache token.
        Schema::table('creations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->unsignedInteger('credits')->default(0)->after('type'); // суутгасан
            $table->json('charges')->nullable()->after('credits');         // [{subscription_id, credits}]
            $table->decimal('cost_usd', 10, 4)->default(0)->after('output_tokens');
            $table->unsignedInteger('cache_read_tokens')->default(0)->after('output_tokens');
            $table->unsignedInteger('cache_write_tokens')->default(0)->after('cache_read_tokens');
        });
    }

    // Credit-ийн багануудыг хасаж, хуучин хязгаар ба subscription_id-г сэргээнэ.
    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropColumn(['credits', 'charges', 'cost_usd', 'cache_read_tokens', 'cache_write_tokens']);
            $table->foreignId('subscription_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['credits', 'credits_used']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('credits');
            $table->unsignedInteger('poster_limit')->nullable();
            $table->unsignedInteger('reel_limit')->nullable();
        });
    }
};
