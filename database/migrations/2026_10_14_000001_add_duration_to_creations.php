<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Reel length the customer picked (seconds); priced per length.
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration')->nullable()->after('formats');
        });
    }

    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropColumn('duration');
        });
    }
};
