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
        Schema::create('section_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_section_id')->constrained('regulation_sections')->cascadeOnDelete();
            $table->foreignId('target_section_id')->constrained('regulation_sections')->cascadeOnDelete();
            $table->enum('reference_type', ['amends', 'repeals', 'refers_to', 'replaced_by']);
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index('source_section_id');
            $table->index('target_section_id');
            $table->index('reference_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_references');
    }
};
