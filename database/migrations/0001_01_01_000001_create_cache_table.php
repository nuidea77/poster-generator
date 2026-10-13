<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Database cache driver-ийн хүснэгтүүд (Laravel-ийн анхдагч).
return new class extends Migration
{
    /**
     * Migration-ийг ажиллуулна.
     */
    public function up(): void
    {
        // Cache-ийн утгууд, хугацаа дуусах цагтай.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        // Cache::lock()-ийн atomic lock-ууд.
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    /**
     * Migration-ийг буцаана: cache хүснэгтүүдийг устгана.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
