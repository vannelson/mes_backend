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
        Schema::create('diecuts', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 100)->nullable()->index();
            $table->string('sheet', 255)->nullable();
            $table->string('diecut_no', 150)->nullable()->index();
            $table->string('diecut_type', 50)->nullable()->index();
            $table->string('width', 50)->nullable();
            $table->string('length', 50)->nullable();
            $table->string('no_of_ups', 50)->nullable();
            $table->string('rev', 50)->nullable();
            $table->string('radius', 100)->nullable();
            $table->string('perforate', 100)->nullable();
            $table->string('int_ud', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diecuts');
    }
};
