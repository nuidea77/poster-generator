<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Database queue-ийн хүснэгтүүд (Laravel-ийн анхдагч): RunCreation зэрэг job-ууд энд дараалалд орно.
return new class extends Migration
{
    /**
     * Migration-ийг ажиллуулна.
     */
    public function up(): void
    {
        // Хүлээгдэж буй queue job-ууд.
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        // Bus::batch()-ийн job багцууд.
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        // Амжилтгүй болсон job-ууд (exception-тай нь).
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Migration-ийг буцаана: queue хүснэгтүүдийг устгана.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
