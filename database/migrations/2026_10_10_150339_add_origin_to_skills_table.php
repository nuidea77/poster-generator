<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Skill-д эх сурвалж (origin) нэмж, content-ийг longText болгоно.
return new class extends Migration
{
    // origin баганыг нэмж, content-ийг томсгоно.
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('origin')->nullable()->after('description'); // жишээ нь импортолсон Claude skill-ийн нэр
            $table->longText('content')->change(); // импортолсон Claude skill том байж болно
        });
    }

    // origin баганыг хасна (content longText хэвээр үлдэнэ).
    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
