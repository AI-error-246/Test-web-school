<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->enum('role', ['candidate', 'employer', 'admin']);
            $table->enum('status', ['ACTIVE', 'BLOCKED'])->default('ACTIVE');
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();
        });
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('university')->nullable();
            $table->string('major')->nullable();
            $table->unsignedTinyInteger('study_year')->nullable();
            $table->text('introduction')->nullable();
            $table->text('skills_text')->nullable();
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('owner_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('logo_key')->nullable();
            $table->enum('visibility', ['VISIBLE', 'HIDDEN'])->default('VISIBLE');
            $table->timestampsTz();
        });
        Schema::create('cv_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('candidate_id')->constrained('candidate_profiles', 'user_id')->restrictOnDelete();
            $table->string('display_name');
            $table->string('storage_key')->unique();
            $table->string('original_filename');
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('size_bytes');
            $table->timestampTz('uploaded_at');
            $table->timestampTz('archived_at')->nullable();
            $table->unique(['id', 'candidate_id']);
        });
        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
        });
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('job_categories')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->text('benefits')->nullable();
            $table->enum('work_mode', ['ONSITE', 'REMOTE', 'HYBRID'])->default('ONSITE');
            $table->decimal('allowance_min', 14, 2)->nullable();
            $table->decimal('allowance_max', 14, 2)->nullable();
            $table->string('currency', 3)->nullable()->default('VND');
            $table->timestampTz('deadline_at')->nullable();
            $table->enum('publication_state', ['DRAFT', 'OPEN', 'CLOSED'])->default('DRAFT');
            $table->enum('moderation_state', ['NOT_SUBMITTED', 'PENDING', 'APPROVED', 'REJECTED', 'HIDDEN'])->default('NOT_SUBMITTED');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['company_id', 'created_at']);
            $table->index(['publication_state', 'moderation_state', 'deadline_at']);
        });
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_postings')->restrictOnDelete();
            $table->foreignUuid('candidate_id')->constrained('candidate_profiles', 'user_id')->restrictOnDelete();
            $table->unsignedBigInteger('cv_id');
            $table->foreign(['cv_id', 'candidate_id'])->references(['id', 'candidate_id'])->on('cv_documents')->restrictOnDelete();
            $table->string('name_snapshot');
            $table->string('email_snapshot');
            $table->string('phone_snapshot');
            $table->string('university_snapshot');
            $table->string('major_snapshot');
            $table->string('job_title_snapshot');
            $table->string('company_name_snapshot');
            $table->enum('status', ['SUBMITTED', 'REVIEWING', 'SELECTED', 'REJECTED'])->default('SUBMITTED');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('applied_at');
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['candidate_id', 'job_id']);
            $table->index(['job_id', 'status']);
            $table->index(['candidate_id', 'applied_at']);
        });
        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->foreignUuid('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->timestampTz('changed_at');
            $table->index(['application_id', 'changed_at']);
        });
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('admin_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('job_postings')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 50);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30);
            $table->text('reason')->nullable();
            $table->unsignedInteger('reviewed_version')->nullable();
            $table->timestampTz('created_at');
        });
        Schema::create('account_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('reason');
            $table->timestampTz('created_at');
        });
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('job_id')->constrained('job_postings')->restrictOnDelete();
            $table->string('reason');
            $table->text('description');
            $table->enum('status', ['OPEN', 'IN_REVIEW', 'RESOLVED', 'DISMISSED'])->default('OPEN');
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE job_postings ADD CONSTRAINT allowance_valid CHECK ((allowance_min IS NULL OR allowance_min >= 0) AND (allowance_max IS NULL OR allowance_max >= 0) AND (allowance_min IS NULL OR allowance_max IS NULL OR allowance_min <= allowance_max) AND ((allowance_min IS NULL AND allowance_max IS NULL) OR currency IS NOT NULL))');
            DB::statement('ALTER TABLE moderation_actions ADD CONSTRAINT moderation_one_target CHECK ((job_id IS NULL) <> (company_id IS NULL))');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT report_resolution_complete CHECK (status NOT IN ('RESOLVED', 'DISMISSED') OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution_note IS NOT NULL AND length(trim(resolution_note)) > 0))");
            DB::statement('ALTER TABLE cv_documents ADD CONSTRAINT cv_positive_size CHECK (size_bytes > 0 AND size_bytes <= 5242880)');
        }
    }

    public function down(): void
    {
        foreach (['reports', 'account_status_histories', 'moderation_actions', 'application_status_histories', 'applications', 'job_postings', 'locations', 'job_categories', 'cv_documents', 'companies', 'candidate_profiles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
