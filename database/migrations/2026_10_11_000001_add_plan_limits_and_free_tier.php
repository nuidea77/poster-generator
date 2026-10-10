<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null = unlimited. A plan priced 0 is the free tier: its limits are lifetime, not per period.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('poster_limit')->nullable()->after('period_days');
            $table->unsignedInteger('reel_limit')->nullable()->after('poster_limit');
        });

        // Usage is counted per subscription (null = free tier). Deleted creations still count.
        Schema::table('creations', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropSoftDeletes();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['poster_limit', 'reel_limit']);
        });
    }
};
