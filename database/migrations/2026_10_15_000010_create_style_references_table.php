<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Админы сонгосон жишээ постер/зургийн сан: агент зургийн моделд хэв маягийн жишээ болгон өгнө.
return new class extends Migration
{
    // style_references хүснэгтийг үүсгэнэ.
    public function up(): void
    {
        Schema::create('style_references', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->index(); // food, beauty, fashion ... other
            $table->string('style')->nullable(); // чөлөөт шошго: "Swiss", "Editorial Warmth"
            $table->json('tags')->nullable();
            $table->text('notes')->nullable(); // юуг нь зээлэх вэ
            $table->string('path'); // public диск дээрх зураг
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    // style_references хүснэгтийг устгана.
    public function down(): void
    {
        Schema::dropIfExists('style_references');
    }
};
