<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Засвар: бэлэн постерыг хэрэглэгчийн хүсэлтээр засварлаж шинэ хувилбар болгоно.
return new class extends Migration
{
    // parent_id: засварлаж буй эх бүтээл; revision: хэрэглэгчийн засварын хүсэлт.
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('user_id')->constrained('creations')->nullOnDelete();
            $table->text('revision')->nullable()->after('prompt');
        });
    }

    // Засварын багануудыг хасна.
    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('revision');
        });
    }
};
