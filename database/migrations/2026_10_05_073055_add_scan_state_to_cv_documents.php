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
        Schema::table('cv_documents', function (Blueprint $table) {
            $table->string('scan_status', 16)->default('PENDING');
            $table->string('scan_sha256', 64)->nullable();
            $table->string('scan_engine', 40)->nullable();
            $table->timestampTz('scanned_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cv_documents', function (Blueprint $table) {
            $table->dropColumn(['scan_status', 'scan_sha256', 'scan_engine', 'scanned_at']);
        });
    }
};
