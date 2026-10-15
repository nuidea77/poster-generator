<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Бүтээлд хэрэглэгчийн үнэлгээ (таалагдсан/үгүй) ба санал хүсэлтийг нэмнэ.
return new class extends Migration
{
    // rating: 1 = таалагдсан, -1 = таалагдаагүй; feedback: юуг сайжруулах вэ.
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->tinyInteger('rating')->nullable()->after('outputs');
            $table->timestamp('rated_at')->nullable()->after('rating');
            $table->string('feedback', 500)->nullable()->after('rated_at');
            $table->index(['rating', 'type']);
        });
    }

    // Үнэлгээний багануудыг хасна.
    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropIndex(['rating', 'type']);
            $table->dropColumn(['rating', 'rated_at', 'feedback']);
        });
    }
};
