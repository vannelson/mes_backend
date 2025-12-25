<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('production_area')->nullable();
            $table->string('machine_type')->nullable();
            $table->string('printing_type')->nullable();
            $table->string('machine_no', 50)->nullable();
            $table->string('cost_center_old', 50)->nullable();
            $table->string('cost_center_new', 50)->nullable();
            $table->string('average_speed', 100)->nullable();
            $table->string('max_colors', 50)->nullable();
            $table->string('die_cut_stations', 50)->nullable();
            $table->string('max_die_cut_width_mm', 50)->nullable();
            $table->string('max_repeat_length_mm', 50)->nullable();
            $table->string('max_material_width_mm', 50)->nullable();
            $table->string('diecut_type', 100)->nullable();
            $table->string('rotary', 50)->nullable();
            $table->string('varnish', 50)->nullable();
            $table->string('inline_slitting', 50)->nullable();
            $table->string('auto_inspection', 50)->nullable();
            $table->string('auto_inspection_slitting', 50)->nullable();
            $table->string('manual_inspection', 50)->nullable();
            $table->string('machine_counting', 50)->nullable();
            $table->string('manual_counting', 50)->nullable();
            $table->string('manual_slitting', 50)->nullable();
            $table->string('packing', 50)->nullable();
            $table->string('lamination', 50)->nullable();
            $table->string('units', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
