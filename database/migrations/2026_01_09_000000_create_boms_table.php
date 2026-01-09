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
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 100)->nullable()->index();
            $table->string('sheet', 255)->nullable();
            $table->string('customer_code', 100)->nullable()->index();
            $table->string('part_no', 150)->nullable()->index();
            $table->string('description', 255)->nullable();
            $table->string('material_1_code', 120)->nullable();
            $table->string('material_1_desc', 255)->nullable();
            $table->string('material_2_code', 120)->nullable();
            $table->string('material_2_desc', 255)->nullable();
            $table->string('material_3_code', 120)->nullable();
            $table->string('material_3_desc', 255)->nullable();
            $table->string('material_4_code', 120)->nullable();
            $table->string('material_4_desc', 255)->nullable();
            $table->string('colour_code_1', 120)->nullable();
            $table->string('colour_code_2', 120)->nullable();
            $table->string('colour_code_3', 120)->nullable();
            $table->string('colour_code_4', 120)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};
