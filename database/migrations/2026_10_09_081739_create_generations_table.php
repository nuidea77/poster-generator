<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // poster | reel
            $table->text('prompt');
            $table->string('text_provider');
            $table->string('image_provider');
            $table->json('options');
            $table->json('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
