<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('regulation_categories')->nullOnDelete();
            $table->timestamps();
            
            $table->index('parent_id');
        });
        
        Schema::create('regulation_category', function (Blueprint $table) {
            $table->foreignId('regulation_id')->constrained('regulations')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('regulation_categories')->cascadeOnDelete();
            $table->primary(['regulation_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_category');
        Schema::dropIfExists('regulation_categories');
    }
};
