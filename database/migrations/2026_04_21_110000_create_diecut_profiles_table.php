<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diecut_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profile_code', 150);
            $table->string('normalized_code', 180)->unique();
            $table->string('base_normalized_code', 180)->nullable()->index();
            $table->string('diecut_type', 100)->nullable()->index();
            $table->decimal('height_mm', 12, 3)->nullable();
            $table->decimal('width_mm', 12, 3)->nullable();
            $table->decimal('interval_ud_mm', 12, 3)->nullable();
            $table->decimal('interval_lr_mm', 12, 3)->nullable();
            $table->decimal('column_count', 12, 3)->nullable();
            $table->decimal('no_of_ups', 12, 3)->nullable();
            $table->decimal('default_tool_life_pcs', 18, 3)->nullable();
            $table->decimal('default_tool_life_press', 18, 3)->nullable();
            $table->string('rev', 50)->nullable();
            $table->string('status', 50)->nullable()->index();
            $table->string('source_sheet', 255)->nullable();
            $table->string('source_batch', 100)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diecut_profiles');
    }
};
