<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Админы бичсэн custom skill-үүдийн хүснэгт.
return new class extends Migration
{
    // skills хүснэгтийг үүсгэнэ.
    public function up(): void
    {
        // Хэрэглэгчийн бичсэн custom skill-үүд; bundled skill-үүд resources/ai/skills-д байна.
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description', 500);
            $table->text('content');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    // skills хүснэгтийг устгана.
    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
