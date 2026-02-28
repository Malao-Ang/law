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
        Schema::create('regulation_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained('regulations')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('regulation_sections')->nullOnDelete();
            $table->enum('section_type', ['chapter', 'part', 'section', 'clause', 'sub_clause', 'schedule']);
            $table->string('section_number', 50);
            $table->string('section_label')->nullable();
            $table->longText('content_html');
            $table->text('content_text');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index('regulation_id');
            $table->index('parent_id');
            $table->index('section_type');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulation_sections');
    }
};
