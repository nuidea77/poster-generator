<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // v1 tables replaced by `creations`.
        Schema::dropIfExists('generations');
        Schema::dropIfExists('agent_runs');

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->string('brand_name')->nullable()->after('is_admin');
            $table->string('logo_path')->nullable()->after('brand_name');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('price'); // MNT
            $table->unsignedSmallInteger('period_days');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('provider')->default('qpay');
            $table->string('sender_invoice_no')->unique();
            $table->string('invoice_id')->nullable()->unique();
            $table->string('callback_token', 64)->unique();
            $table->unsignedInteger('amount');
            $table->string('status')->default('pending')->index(); // pending | paid | expired | failed
            $table->text('qr_image')->nullable();
            $table->text('qr_text')->nullable();
            $table->string('short_url')->nullable();
            $table->json('urls')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['user_id', 'ends_at']);
        });

        Schema::create('creations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // poster | reel
            $table->json('formats')->nullable();
            $table->text('prompt');
            $table->json('product')->nullable();
            $table->string('status')->default('queued'); // queued | running | assembling | done | failed
            $table->string('stage')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('inputs');   // uploaded logo / product images
            $table->json('assets');   // internal: generated media with provider + prompt
            $table->json('steps');    // internal: agent log
            $table->json('outputs');  // public: delivered posters / reel
            $table->json('reel_clips')->nullable(); // ordered clip ids chosen by the agent
            $table->text('summary')->nullable();
            $table->text('error_detail')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creations');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('plans');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'brand_name', 'logo_path']);
        });
    }
};
