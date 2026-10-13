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
        Schema::create('employer_application_notes', function (Blueprint $table) {
            $table->foreignId('application_id')->primary()->constrained('applications')->cascadeOnDelete();
            $table->foreignUuid('employer_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employer_application_notes');
    }
};
