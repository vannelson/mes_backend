<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_master_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_master_id')->constrained('calibration_masters')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('file_name', 255);
            $table->string('file_path', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('source_sheet_name', 120)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->unsignedSmallInteger('source_col')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_master_images');
    }
};
