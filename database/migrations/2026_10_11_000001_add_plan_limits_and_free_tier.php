<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Багцад төрөл тус бүрийн хязгаар ба free tier нэмнэ (дараа нь credit-ээр солигдсон).
return new class extends Migration
{
    // Багцын хязгаар ба бүтээлийн subscription холбоосыг нэмнэ.
    public function up(): void
    {
        // null = хязгааргүй. 0 үнэтэй багц нь free tier: хязгаар нь хугацаа тутмын биш, насан туршийн.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('poster_limit')->nullable()->after('period_days');
            $table->unsignedInteger('reel_limit')->nullable()->after('poster_limit');
        });

        // Хэрэглээг захиалга тус бүрээр тоолно (null = free tier). Устгасан бүтээл ч тоологдоно.
        Schema::table('creations', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->softDeletes();
        });
    }

    // Нэмсэн багана, foreign key, soft delete-ийг хасна.
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
