<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Захиалгын огноог TIMESTAMP-аас DATETIME болгож өргөсгөнө.
return new class extends Migration
{
    // MySQL TIMESTAMP 2038 онд дуусдаг; үнэгүй олголтын ends_at ~100 жилийн дараа.
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dateTime('starts_at')->change();
            $table->dateTime('ends_at')->change();
        });
    }

    // TIMESTAMP руу буцаана.
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('starts_at')->change();
            $table->timestamp('ends_at')->change();
        });
    }
};
