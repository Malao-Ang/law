<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained('regulations')->cascadeOnDelete();
            $table->foreignId('amendment_regulation_id')->nullable()->constrained('regulations')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('regulation_sections')->nullOnDelete();
            $table->enum('amendment_type', ['add', 'modify', 'delete', 'replace']);
            $table->longText('old_content_html')->nullable();
            $table->longText('new_content_html')->nullable();
            $table->date('amendment_date');
            $table->string('gazette_reference')->nullable();
            $table->timestamps();
            
            $table->index('regulation_id');
            $table->index('amendment_regulation_id');
            $table->index('section_id');
            $table->index('amendment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_amendments');
    }
};
