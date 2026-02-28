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
        Schema::create('regulations', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500);
            $table->enum('regulation_type', ['regulation', 'announcement', 'rule', 'guideline', 'order']);
            $table->date('enacted_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->enum('status', ['active', 'amended', 'repealed'])->default('active');
            $table->longText('full_html')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('file_type', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            $table->index('regulation_type');
            $table->index('status');
            $table->index('enacted_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulations');
    }
};
