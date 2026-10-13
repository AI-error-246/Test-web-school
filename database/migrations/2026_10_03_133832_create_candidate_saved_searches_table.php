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
        Schema::create('candidate_saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();
            $table->index(['candidate_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_saved_searches');
    }
};
