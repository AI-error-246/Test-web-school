<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('application_status_histories', function (Blueprint $table) {
            $table->timestampTz('candidate_read_at')->nullable();
        });
        DB::table('application_status_histories')->update(['candidate_read_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_status_histories', function (Blueprint $table) {
            $table->dropColumn('candidate_read_at');
        });
    }
};
