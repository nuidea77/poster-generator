<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Хэрэглэгчийн брэндийн багц: өнгө, фонт, өнгө аяс, тэмдэглэл; хадгалсан лого brand_logo_path болно.
return new class extends Migration
{
    // Брэндийн талбаруудыг нэмж, хуучин logo_path-ийг brand_logo_path болгон нэрлэнэ (хадгалсан лого алдагдахгүй).
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('logo_path', 'brand_logo_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('brand_colors')->nullable()->after('brand_name');
            $table->string('brand_fonts', 100)->nullable()->after('brand_colors');
            $table->string('brand_tone', 100)->nullable()->after('brand_fonts');
            $table->text('brand_notes')->nullable()->after('brand_tone');
        });
    }

    // Брэндийн талбаруудыг хасаж, логоны баганын нэрийг буцаана.
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['brand_colors', 'brand_fonts', 'brand_tone', 'brand_notes']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('brand_logo_path', 'logo_path');
        });
    }
};
