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
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'original_filename')) {
                $table->string('original_filename')->nullable()->after('content');
            }
            if (!Schema::hasColumn('documents', 'file_type')) {
                $table->string('file_type')->nullable()->after('original_filename');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['original_filename', 'file_type']);
        });
    }
};
