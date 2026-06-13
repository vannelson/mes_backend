<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_masters', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_name', 120)->nullable()->index();
            $table->unsignedSmallInteger('sheet_order')->default(0);
            $table->unsignedInteger('source_row')->nullable();
            $table->string('reference_no', 120)->nullable()->index();
            $table->text('name_type')->nullable();
            $table->text('function')->nullable();
            $table->text('image')->nullable();
            $table->text('identification_number')->nullable();
            $table->text('measurement_range')->nullable();
            $table->text('inherent_accuracy')->nullable();
            $table->text('usage_accuracy')->nullable();
            $table->text('owner_location')->nullable();
            $table->string('frequency_label', 120)->nullable()->index();
            $table->unsignedSmallInteger('frequency_interval_months')->nullable()->index();
            $table->date('last_calibration_date')->nullable()->index();
            $table->date('next_calibration_date')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_masters');
    }
};
