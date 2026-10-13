<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Бүтээлд reel-ийн урт (duration) баганыг нэмнэ.
return new class extends Migration
{
    // Хэрэглэгчийн сонгосон reel-ийн урт (секунд); урт тус бүрээр үнэлнэ.
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration')->nullable()->after('formats');
        });
    }

    // duration баганыг хасна.
    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropColumn('duration');
        });
    }
};
