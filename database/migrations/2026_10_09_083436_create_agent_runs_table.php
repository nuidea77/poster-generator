<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->text('prompt');
            $table->string('language', 8)->default('mn');
            $table->string('status')->default('queued'); // queued | running | done | failed
            $table->json('assets');     // uploaded + generated images/videos, keyed by short id
            $table->json('steps');      // tool calls and notes, in order
            $table->json('outputs');    // generation ids created by the agent
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
