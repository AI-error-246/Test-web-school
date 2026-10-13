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
        Schema::create('candidate_saved_jobs', function (Blueprint $table): void {
            $table->foreignUuid('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('job_postings')->cascadeOnDelete();
            $table->timestampTz('saved_at');
            $table->primary(['candidate_id', 'job_id']);
            $table->index(['candidate_id', 'saved_at']);
        });
        Schema::create('candidate_job_views', function (Blueprint $table): void {
            $table->foreignUuid('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('job_postings')->cascadeOnDelete();
            $table->timestampTz('viewed_at');
            $table->primary(['candidate_id', 'job_id']);
            $table->index(['candidate_id', 'viewed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_job_views');
        Schema::dropIfExists('candidate_saved_jobs');
    }
};
