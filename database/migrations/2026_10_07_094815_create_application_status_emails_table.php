<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('application_status_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('history_id')->unique()->constrained('application_status_histories')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->foreignUuid('candidate_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name');
            $table->string('job_title');
            $table->string('company_name');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('mode', 10);
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('preview_path')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'id']);
            $table->index(['candidate_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_status_emails');
    }
};
